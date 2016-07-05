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
import logging
import os
import time

import alertlib
import bq_util


# This maps from the bigquery table fields and aliases that users are
# allowed to reference as part of the 'query' entry in the config
# file, to the bigquery snippet that populates that field/alias in the
# innermost subquery.
_QUERY_FIELDS = {
    'status': 'status',
    'ip': 'ip',
    'log_messages': 'GROUP_CONCAT_UNQUOTED(app_logs.message) WITHIN RECORD',
}

# This maps from the possible values for the 'labels' entry in the
# config file, to the bigquery field that represents it.
# For us, labels always have type 'string'.
_LABELS = {
    'module_id': 'module_id',
    'browser': 'elog_browser',
    'KA_APP': 'elog_KA_APP',
    'os': 'elog_os',
    'lang': 'elog_language',
}

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


def _load_config(config_name):
    """If config_name is a relative path, it's relative to this dir."""
    if not os.path.isabs(config_name):
        config_name = os.path.join(os.path.dirname(__file__), config_name)

    # Read the file, ignoring any lines that start with `//`.
    with open(config_name) as f:
        config_lines = [l for l in f.readlines()
                        if not l.lstrip().startswith('//')]
    config_contents = ''.join(config_lines)
    return json.loads(config_contents)


def _should_run_query(config_entry, start_time_t, time_of_last_successful_run):
    """True if the config-entry's frequency means it should be run now."""
    frequency = config_entry.get('frequency', 'minutely')
    if frequency == 'minutely':
        time_range = 60
    elif frequency == 'hourly':
        time_range = 60 * 60
    elif frequency == 'daily':
        time_range = 60 * 60 * 24
    elif frequency == 'weekly':
        time_range = 60 * 60 * 24 * 7
    else:
        raise ValueError("Unknown frequency '%s' for %s'"
                         % (frequency, config_entry['metricName']))

    # We say to run if our start-time is in a different 'epoch' --
    # defined in terms of the time-range -- than the last successful
    # run.
    last_epoch = int(time_of_last_successful_run / time_range)
    this_epoch = int(start_time_t / time_range)
    return last_epoch != this_epoch


def _tables_for_time(start_time_t, delta):
    """Given a time_t, return a table that best has logs for that time.

    If the time is in the very recent past, we use the streaming logs,
    with a table decorator to restrict the time.
    """
    now = int(time.time())
    start_time_t = int(start_time_t)
    # Give 3 hours for the hourly logs table to be written.
    if now - start_time_t <= 3600 * 3:
        return ('[khan-academy:logs_streaming.logs_all_time@%d-]'
                % (start_time_t * 1000))

    # Otherwise, if it's within the last week and a bit, use the hourly
    # table(s).  (We only keep that table around for a week + 2 hours.)
    if now - start_time_t <= 86400 * 7 + 3600 * 2:
        retval = []
        for time_t in xrange(start_time_t, start_time_t + delta, 3600):
            retval.append(time.strftime('[logs_hourly.requestlogs_%Y%m%d_%H]',
                                        time.gmtime(time_t)))
        return ', '.join(retval)

    # Otherwise just use the appropriate daily logs table(s).
    retval = []
    for time_t in xrange(start_time_t, start_time_t + delta, 86400):
        retval.append(time.strftime('[logs.requestlogs_%Y%m%d]',
                                    time.gmtime(time_t)))
    return ', '.join(retval)


def _create_subquery(config_entry, start_time_t, time_interval_seconds):
    """Return a query that captures all loglines matching the config-entry.

    We look through the streaming logs to find all requests that *ended*
    between start_time_t and start_time_t + time_interval_seconds.
    ("start_time_t" is a bit of a confusing name).  We want "ended"
    because that's when a request gets written to the logs.  As an
    example, if a request took 10 minutes to run, if we were filtering
    on start_time we'd miss it unless we happened to be running this
    script on data 10 minutes in the past.  Usually we run it on data
    1 minute in the past, so we'd miss it.

    This subquery also returns all the data needed for normalization
    by num-requests, etc.

    We use a table decorator to restrict the query, making it faster
    and cheaper.  Note that the dates on table decorators refer to
    when the logline was inserted into bigquery, not when the relevant
    request either started or ended.  So it's not a complete
    substitute for the last_time check.
    """
    # Instead of using the logs_streaming.last_hour, which is a view
    # on logs_all_time that uses the `@-3600000-` table decorator, we
    # make our own table-decorator that starts only a few minutes in
    # the past.  Because it can take a while for logs to stream into
    # this table, we don't put an end-time on, though in theory it
    # could be start_time_t + time_interval_seconds + <some slack>.
    # Since we only tend to run this script in the recent past, having
    # end-time be "now" is probably ok.
    innermost_from = """(
        SELECT 'now' as when, %s, %s
        FROM %s
        WHERE end_time >= %d and end_time < %d
    )""" % (', '.join(_LABELS.itervalues()),
            ', '.join('%s as %s' % (v, k)
                      for (k, v) in _QUERY_FIELDS.iteritems()),
            _tables_for_time(start_time_t, time_interval_seconds),
            start_time_t, start_time_t + time_interval_seconds)

    if config_entry.get('normalizeByDaysAgo'):
        days_ago = config_entry['normalizeByDaysAgo']
        old_time_t = start_time_t - 86400 * days_ago
        innermost_from += """, (
            SELECT 'some days ago' as when, %s, %s
            FROM %s
            WHERE end_time >= %d and end_time < %d
        )""" % (', '.join(_LABELS.itervalues()),
                ', '.join('%s as %s' % (v, k)
                          for (k, v) in _QUERY_FIELDS.iteritems()),
                _tables_for_time(old_time_t, time_interval_seconds),
                old_time_t, old_time_t + time_interval_seconds)

    if config_entry.get('normalizeByLastDeploy'):
        raise NotImplementedError("Augment innermost-from in this case too")

    selectors = [_LABELS[label] for label in config_entry.get('labels', [])]

    subquery = ("SELECT '%s' as metricName, when, "
                "COUNT(*) as num_requests, FLOAT(%s) as num"
                % (config_entry['metricName'], config_entry['query']))
    for selector in selectors:
        subquery += ', %s' % selector
    subquery += ' FROM %s' % innermost_from
    subquery += ' GROUP BY %s' % ', '.join(selectors + ['when'])
    return '(%s)' % subquery


def _run_bigquery(config, start_time_t, time_interval_seconds):
    """config is as described in logs_bridge.config.json."""
    # TODO(csilvers): use table decorators to limit how much we read.

    # Construct our ginormo query based on the config file.  In order
    # to support different types of aggregation, each config entry
    # because a different sub-query.
    subqueries = [_create_subquery(entry, start_time_t, time_interval_seconds)
                  for entry in config]
    query = 'SELECT * FROM %s' % ',\n'.join(subqueries)
    logging.debug('BIGQUERY QUERY: %s' % query)

    logging.info("Sending query to bigquery")
    r = bq_util.query_bigquery(query)
    logging.debug('BIGQUERY RESULTS: %s' % r)

    return r


def _get_values_from_bigquery(config, start_time_t,
                              time_interval_seconds=60):
    """Return a list of (metric-name, metric-labels, values) triples."""
    bigquery_results = _run_bigquery(config, start_time_t,
                                     time_interval_seconds)
    # A single result looks like:
    #   {u'module_id': u'multithreaded',
    #    u'num': 10.0,
    #    u'when': u'now',
    #    u'num_requests': 69441,
    #    u'metricName': u'logs.status'}

    # Key each result by its resolved metric name.
    results_by_metric_and_when = {}
    for result in bigquery_results:
        config_entry = next(c for c in config
                            if c['metricName'] == result['metricName'])
        label_values = []
        for label in config_entry.get('labels', []):
            selector = _LABELS[label]
            # bq_io can turn labels like '8' into an int; we want them
            # to be strings so we turn them back.
            label_value = str(result[selector])
            # A special case: bigquery doesn't store module-id for default.
            # "(None)" is how bq_io translates `None`.
            if label == 'module_id' and label_value == '(None)':
                label_value = 'default'
            label_values.append(label_value)
        key = (config_entry['metricName'], tuple(label_values), result['when'])
        assert key not in result, "%s is not a unique key!" % key
        results_by_metric_and_when[key] = result

    retval = []
    for config_entry in config:
        for ((metric_name, metric_label_values, when), result) in \
                results_by_metric_and_when.iteritems():
            if (result['metricName'] != config_entry['metricName'] or
                   when != 'now'):
                continue

            value = result['num']

            # Normalize if asked.
            try:
                if config_entry.get('normalizeByRequests'):
                    value /= result['num_requests']

                if config_entry.get('normalizeByDaysAgo'):
                    old_result = results_by_metric_and_when[
                        (metric_name, metric_label_values, 'some days ago')]
                    old_value = old_result['num']
                    # Weird to normalize by two things, but possible.
                    if config_entry.get('normalizeByRequests'):
                        old_value /= old_result['num_requests']
                    value /= old_value

                if config_entry.get('normalizeByLastDeploy'):
                    last_deploy_result = results_by_metric_and_when[
                        (metric_name, metric_label_values, 'last deploy')]
                    last_deploy_value = last_deploy_result['num']
                    if config_entry.get('normalizeByRequests'):
                        last_deploy_value /= last_deploy_result['num_requests']
                    value /= last_deploy_value
            except (ZeroDivisionError, TypeError):
                # A TypeError happens when we do '3 / "(None)"' or the reverse.
                continue

            # The value can be 'None' if no rows matched the query.
            # In that case, we just ignore the metric entirely.
            # TODO(csilvers): better would be to set value to 0 for counts,
            #                 but to not-send the value for ratios.
            if value == "(None)":
                continue

            # Get the labels to be a dict of label-name->label-value
            metric_labels = dict(zip(config_entry.get('labels', []),
                                     metric_label_values))
            retval.append((metric_name, metric_labels, value))

    return retval


def _send_to_stackdriver(google_project_id, bigquery_values,
                         start_time_t, time_interval_seconds, dry_run):
    """bigquery_values is a list of triples via _get_values_from_bigquery."""
    # send_to_cloudmonitoring wants data in a particular format, including
    # the timestamp.  We give all these datapoints the timestamp at the
    # *end* of our time-range: start_time_t + time_interval_seconds
    time_t = start_time_t + time_interval_seconds

    # alertlib is set up to only send one timeseries per call.  But we
    # want to send all the timeseries in a single call, so we have to
    # do some hackery.
    timeseries_data = []
    alert = alertlib.Alert('logs-bridge metrics')
    old_send_datapoints = alert.send_datapoints_to_stackdriver
    alert.send_datapoints_to_stackdriver = lambda timeseries, *a, **kw: (
        timeseries_data.extend(timeseries))

    # This will put the data into timeseries_data but not actually send it.
    try:
        for (metric_name, metric_labels, value) in bigquery_values:
            logging.info("Sending %s (%s) %s %s",
                         metric_name, metric_labels, value, time_t)
            alert.send_to_stackdriver(metric_name, value,
                                      metric_labels=metric_labels,
                                      project=google_project_id,
                                      when=time_t)
    finally:
        alert.send_datapoints_to_stackdriver = old_send_datapoints

    # Now we do the actual send.
    logging.debug("Sending to stackdriver: %s", timeseries_data)
    if timeseries_data and not dry_run:
        try:
            alert.send_datapoints_to_stackdriver(timeseries_data,
                                                 project=google_project_id)
        except Exception:
            logging.error("Error sending data to stackdriver: %r",
                          timeseries_data)
            raise

    return len(timeseries_data)


def main(config_filename, google_project_id, time_interval_seconds, dry_run):
    config = _load_config(config_filename)

    # We'll collect data minute-by-minute until we've collected data
    # from the time range (two-minutes-ago, one-minute-ago).
    run_until = int(time.time()) - 120

    time_of_last_successful_run = _time_t_of_latest_successful_run()
    if time_of_last_successful_run is None:
        # If there's no record of previous runs, just do the most recent run.
        time_of_last_successful_run = run_until - time_interval_seconds

    while time_of_last_successful_run < run_until:
        start_time = time_of_last_successful_run + time_interval_seconds
        # Get rid of entries we shouldn't run now (because they're only
        # run hourly, e.g.).
        current_config = [e for e in config
                          if _should_run_query(e, start_time,
                                               time_of_last_successful_run)]

        bigquery_values = _get_values_from_bigquery(current_config, start_time)

        # TODO(csilvers): compute ALL facet-totals for counting-stats.

        num_metrics = _send_to_stackdriver(
            google_project_id, bigquery_values, start_time,
            time_interval_seconds, dry_run)

        logging.info("Time %s: wrote %s metrics to stackdriver",
                     start_time + time_interval_seconds, num_metrics)
        time_of_last_successful_run = start_time
        if not dry_run:
            _write_time_t_of_latest_successful_run(time_of_last_successful_run)


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('-c', '--config', default='logs_bridge.config.json',
                        help=('JSON file holding the stats to extract from '
                              'the logs'))
    parser.add_argument('--project_id', default='khan-academy',
                        help=('project ID of a Google Cloud Platform project '
                              'with the Cloud Monitoring API enabled '
                              '[default: %(default)s]'))
    # In general we run this script minute-ly, over a minute of data.
    parser.add_argument('--window-seconds', default=60, type=int,
                        help=('window of time to read from the logs. '
                              'This should not be longer than the frequency '
                              'this script is run [default: %(default)s]'))
    parser.add_argument('-v', '--verbose', action='count', default=0,
                        help=('enable verbose logging (-vv for very verbose '
                              'logging)'))
    parser.add_argument('-n', '--dry-run', action='store_true', default=False,
                        help='do not write metrics to Cloud Monitoring')
    args = parser.parse_args()

    # default for WARNING, -v for INFO, -vv for DEBUG.
    if args.verbose >= 2:
        logging.basicConfig(level=logging.DEBUG)
    elif args.verbose == 1 or args.dry_run:
        logging.basicConfig(level=logging.INFO)

    main(args.config, args.project_id, args.window_seconds, args.dry_run)
