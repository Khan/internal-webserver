#!/usr/bin/env python

"""Send metrics extracted from App Engine logs to Cloud Monitoring.

Cloud Monitoring has a feature where it can automatically create
metrics from App Engine logs, called "log based metrics."  But this
feature is very limited in what it can do.  This file implements our
own version of logs-based metrics which is more powerful in the
following ways:

1) Can normalize the metric by the current number of requests
2) Can provide a ratio of the value of the metric now vs a week ago
3) Can provide a ration of the value of the metric now vs at a similar
   time delta from the previous deploy (used for deploy-time metrics)
4) Aggregates different versions into the same metric (this is the default
   here, it's not even possible in stackdriver).  Optionally aggregate
   different modules as well.
5) Can disaggregate on arbitrary logs fields like elog_browser.
6) Can have the metric-value be extracted from the logs, rather than
   always being 1 (to extract timing information, for example).
7) Uses our logs format, complete with elog_* fields, rather than the
   GAE format with protoPayload and the like.

This script uses a configuration file to describe what how to extract
a metric from the logs and what to name it on Cloud Monitoring.

It's expected this script will be run periodically as a cron job every
minute.
"""

import json
import os
import re
import time

import bq_util


# This maps from the query fields that users are allowed to access to
# the bigquery snippet that populates that query field in the innermost
# subquery.
_QUERY_FIELDS = {
    'status': 'status',
    'log_messages': 'GROUP_CONCAT_UNQUOTED(app_logs.message) WITHIN RECORD',
}

# This maps from the %(...)s disaggregators in a metric-name, to the
# bigquery field that represents it.
_GROUP_BY = {
    '%(module)s': 'module_id',
    '%(browser)s': 'elog_browser',
}

_NOW = int(time.time())
_LAST_RECORD_DB = os.path.expanduser('~/logs_bridge_time.db')


def _time_t_of_latest_successful_run():
    """time_t of the most recent successfully logs-bridge run.

    This data is stored in a file.  We could consider this a small database.

    Returns:
        The time_t (# of seconds since the UNIX epoch in UTC) or None if
        there is no previous record.
    """
    if os.path.exists(_LAST_RECORD_DB):
        with open(_LAST_RECORD_DB) as f:
            return int(f.read().strip())
    return None


def _write_time_t_of_latest_successful_run(time_t):
    with open(_LAST_RECORD_DB, 'w') as f:
        print >>f, time_t


def _load_config(config_name="logs_bridge.config.json"):
    """If config_name is a relative path, it's relative to this dir."""
    if not os.path.isabs(config_name):
        config_name = os.path.join(os.path.dirname(__file__), config_name)

    # Read the file, ignoring any lines that start with `//`.
    with open(config_name) as f:
        config_lines = [l for l in f.readlines() if not l.startswith('//')]
    config_contents = ''.join(config_lines)
    return json.loads(config_contents)


def _create_subquery(config_entry, start_time_t, time_interval_seconds):
    innermost_from = """(
        SELECT 'now' as when, %s, %s
        FROM [logs_streaming.last_hour]
        WHERE start_time >= %d and start_time < %d
    )""" % (', '.join(_GROUP_BY.itervalues()),
            ', '.join('%s as %s' % (v, k)
                      for (k, v) in _QUERY_FIELDS.iteritems()),
            start_time_t, start_time_t + time_interval_seconds)

    if config_entry.get('normalizeByDaysAgo'):
        days_ago = config_entry['normalizeByDaysAgo']
        old_time_t = start_time_t - 86400 * days_ago
        if days_ago <= 7:
            # We can use the hourly log, which we keep around for a week.
            old_log = time.strftime('logs_hourly.requestlogs_%Y%m%d_%H',
                                    time.gmtime(old_time_t))
        else:
            old_log = time.strftime('logs.requestlogs_%Y%m%d',
                                    time.gmtime(old_time_t))

        innermost_from += """, (
            SELECT 'some days ago' as when, %s, %s
            FROM [%s]
            WHERE start_time >= %d and start_time < %d
        )""" % (', '.join(_GROUP_BY.itervalues()),
                ', '.join('%s as %s' % (v, k)
                          for (k, v) in _QUERY_FIELDS.iteritems()),
                old_log, old_time_t, old_time_t + time_interval_seconds)

    if config_entry.get('normalizeByLastDeploy'):
        raise NotImplementedError("Augment innermost-from in this case too")

    selectors = [
        _GROUP_BY[group_by]
        for group_by in re.findall(r'%\([^)]*\)s', config_entry['metricName'])
    ]

    subquery = ("SELECT '%s' as metricName, when, "
                "COUNT(*) as num_requests, FLOAT(%s) as num"
                % (config_entry['metricName'], config_entry['query']))
    for selector in selectors:
        subquery += ', %s' % selector
    subquery += ' FROM %s' % innermost_from
    subquery += ' GROUP BY %s' % ', '.join(selectors + ['when'])
    return '(%s)' % subquery


def _run_bigquery(config, start_time_t, time_interval_seconds,
                  verbose=False):
    """config is as described in logs_bridge.config.json."""
    # TODO(csilvers): use table decorators to limit how much we read.

    # Construct our ginormo query based on the config file.  In order
    # to support different types of aggregation, each config entry
    # because a different sub-query.
    subqueries = [_create_subquery(entry, start_time_t, time_interval_seconds)
                  for entry in config]
    query = 'SELECT * FROM %s' % ',\n'.join(subqueries)
    if verbose:
        print 'BIGQUERY QUERY: %s' % query

    r = bq_util.query_bigquery(query)
    if verbose:
        print 'BIGQUERY RESULTS: %s' % r

    return r


def _get_values_from_bigquery(config, start_time_t, time_interval_seconds=60,
                              verbose=False):
    bigquery_results = _run_bigquery(config, start_time_t,
                                     time_interval_seconds, verbose)
    # A single result looks like:
    #   {u'module_id': u'multithreaded',
    #    u'num': 10.0,
    #    u'when': u'now',
    #    u'num_requests': 69441,
    #    u'metricName': u'logs.404.%(module)s'}
    # We need to convert this to {'logs.404.multithreaded': 10.0}.
    retval = {}

    # Key each result by its resolved metric name.
    results_by_name_and_when = {}
    for result in bigquery_results:
        # This replaces '(module)s' with result['module_id'], e.g.
        resolved_metric_name = result['metricName']
        for (s, selector) in _GROUP_BY.iteritems():
            if s in resolved_metric_name:
                # A special case: bigquery doesn't store module-id for default
                if s == '%(module)s' and result[selector] == '(None)':
                    resolve_to = 'default'
                else:
                    resolve_to = result[selector]
                resolved_metric_name = resolved_metric_name.replace(
                    s, resolve_to)
        key = (resolved_metric_name, result['when'])
        assert key not in result, "%s is not a unique key!" % key
        results_by_name_and_when[key] = result

    for config_entry in config:
        for ((resolved_metric_name, when), result) in \
                results_by_name_and_when.iteritems():
            if (result['metricName'] != config_entry['metricName'] or
                   when != 'now'):
                continue

            value = result['num']

            # Normalize if asked.
            if config_entry.get('normalizeByRequests'):
                value /= result['num_requests']

            if config_entry.get('normalizeByDaysAgo'):
                old_result = results_by_name_and_when[
                    (resolved_metric_name, 'some days ago')]
                old_value = old_result['num']
                # Weird to normalize by two things, but possible.
                if config_entry.get('normalizeByRequests'):
                    old_value /= old_result['num_requests']
                value /= old_value

            if config_entry.get('normalizeByLastDeploy'):
                last_deploy_result = results_by_name_and_when[
                    (resolved_metric_name, 'last deploy')]
                last_deploy_value = last_deploy_result['num']
                if config_entry.get('normalizeByRequests'):
                    last_deploy_value /= last_deploy_result['num_requests']
                value /= last_deploy_value

            retval[resolved_metric_name] = value

    return retval


if __name__ == '__main__':
    print _get_values_from_bigquery(_load_config(), time.time() - 120,
                                    verbose=True)
