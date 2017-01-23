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
7) Can only send the top N results to Stackdriver for the most popular label
   names (e.g. when there are many label names such as for url routes, and
   you only want the top 20 by most requests). Note that this can include the
   top N labels from time 'now' and the top N labels from time 'some days ago'
   for a possible maximum of 2N label results.
8) Uses our logs format, complete with elog_* fields, rather than the
   GAE format with protoPayload and the like.

This script uses a configuration file to describe what how to extract
a metric from the logs and what to name it on Cloud Monitoring.

It's expected this script will be run periodically as a cron job every
minute.
"""

import json
import logging
import os
import random
import re
import sys
import time

import bq_util
import cloudmonitoring_util


# This maps from the bigquery table fields and aliases that users are
# allowed to reference as part of the 'query' entry in the config
# file, to the bigquery snippet that populates that field/alias in the
# innermost subquery.
_QUERY_FIELDS = {
    'status': 'status',
    'ip': 'ip',
    'log_messages': 'GROUP_CONCAT_UNQUOTED(app_logs.message) WITHIN RECORD',
    'latency': 'latency',
    'task_queue_name': 'task_queue_name',
}

# This maps from the possible values for the 'labels' entry in the
# config file, to the bigquery field(s) that represents it.  Each
# bigquery field should be in angle-brackets.  The labels can have
# other text which will be copied verbatim.
# For us, labels always have type 'string'.
_LABELS = {
    'module_id': '<module_id>',
    'browser': '<elog_browser>',
    'device': '<elog_device_type>',
    'KA_APP': '<elog_KA_APP>',
    'os': '<elog_os>',
    'lang': '<elog_ka_locale>',
    'route': '<module_id>:<elog_url_route>',
}

_LABEL_RE = re.compile(r'<([^>]*)>')   # matches the text inside '<...>'

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


def _bigquery_fields_for_labels(labels=_LABELS.keys()):
    """All bigquery fields references in _LABELS.values(), comma-separated."""
    retval = set()
    for label in labels:
        retval.update(_LABEL_RE.findall(_LABELS[label]))
    return ', '.join(sorted(retval))


def load_config(config_name):
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
    with a table decorator to restrict the time.  Instead of using the
    logs_streaming.last_hour, which is a view on logs_all_time that
    uses the `@-3600000-` table decorator, we make our own
    table-decorator that starts only a few minutes in the past.
    Because it can take a while for logs to stream into this table, we
    don't put an end-time on, though in theory it could be
    start_time_t + delta + <some slack>.  Since we only tend to run
    this script in the recent past, having end-time be "now" is
    probably ok.  Note that the dates on table decorators refer to
    when the logline was inserted into bigquery, not when the relevant
    request either started or ended.  So it's a useful optimization
    but not a complete substitute for a last_time check.
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


def _query_for_rows_in_time_range(config, start_time_t, time_interval_seconds):
    """Return a query that yields all rows needed for this config + time."""
    froms = ["""(
        SELECT 'now' as when, %s, %s
        FROM %s
        WHERE end_time >= %d and end_time < %d
    )""" % (_bigquery_fields_for_labels(),
            ', '.join('%s as %s' % (v, k)
                      for (k, v) in _QUERY_FIELDS.iteritems()),
            _tables_for_time(start_time_t, time_interval_seconds),
            start_time_t, start_time_t + time_interval_seconds)
    ]

    all_days_agos = set(c.get('normalizeByDaysAgo') for c in config) - {None}
    for days_ago in all_days_agos:
        old_time_t = start_time_t - 86400 * days_ago
        froms.append("""(
            SELECT 'some days ago' as when, %s, %s
            FROM %s
            WHERE end_time >= %d and end_time < %d
        )""" % (_bigquery_fields_for_labels(),
                ', '.join('%s as %s' % (v, k)
                          for (k, v) in _QUERY_FIELDS.iteritems()),
                _tables_for_time(old_time_t, time_interval_seconds),
                old_time_t, old_time_t + time_interval_seconds))

    if any(c.get('normalizeByLastDeploy') for c in config):
        raise NotImplementedError("Augment innermost-from in this case too")

    return 'SELECT * from %s' % "\n,".join(froms)


def _create_subquery(config_entry, start_time_t, time_interval_seconds,
                     table_name):
    """Return a query that captures all loglines matching the config-entry.

    We look through table_name to find all requests that *ended*
    between start_time_t and start_time_t + time_interval_seconds.
    ("start_time_t" is a bit of a confusing name).  We want "ended"
    because that's when a request gets written to the logs.  As an
    example, if a request took 10 minutes to run, if we were filtering
    on start_time we'd miss it unless we happened to be running this
    script on data 10 minutes in the past.  Usually we run it on data
    1 minute in the past, so we'd miss it.

    This subquery also returns all the data needed for normalization
    by num-requests, etc.
    """
    label_names = config_entry.get('labels', [])

    group_by = _bigquery_fields_for_labels(label_names)
    if group_by:
        group_by = group_by + ', when'
    else:
        group_by = 'when'

    # num_requests_by_field is the total number of requests broken down by
    # field. E.g. if this metric is broken down by browser,
    # num_requests_by_field would be for a particular browser such as the
    # total number of requests for Chrome.
    subquery = ("SELECT '%s' as metricName, COUNT(*) as "
                "num_requests_by_field, FLOAT(%s) as num, %s"
                % (config_entry['metricName'], config_entry['query'],
                   group_by))
    subquery += ' FROM [%s]' % table_name
    subquery += ' GROUP BY %s' % group_by
    subquery += ' HAVING num is not null'
    return '(%s)' % subquery


def _run_bigquery(config, start_time_t, time_interval_seconds,
        time_table_expiration):
    """config is as described in logs_bridge.config.json."""
    # First, create a temporary table that's just the rows from
    # start_time_t to start_time_t + time_interval_seconds.
    # We'll give it a random name so we can run multiple copies of
    # this script at the same time.
    temp_table_name = (
        'khan-academy:logs_streaming_tmp_analysis.logs_bridge_%d_%04d'
        % (start_time_t, random.randint(0, 9999)))
    # We assume that this script will not run for longer than
    # time_interval_seconds; if it did, it would continually be
    # falling behind!
    temp_table_query = _query_for_rows_in_time_range(config, start_time_t,
                                                     time_interval_seconds)
    # Apparently the commandline doesn't like newlines in the script.
    # Reformat for the commandline.
    temp_table_query = temp_table_query.replace('\n', ' ')

    logging.debug("Creating the temporary table for querying over by running "
                  + temp_table_query)
    bq_util.call_bq(['mk', '--expiration', str(time_table_expiration),
                     temp_table_name],
                    project='khan-academy',
                    return_output=False,
                    stdout=open(os.devnull, 'w'))
    bq_util.call_bq(['query', '--destination_table', temp_table_name,
                     '--allow_large_results', temp_table_query],
                    project='khanacademy.org:deductive-jet-827',
                    return_output=False)

    logging.info("Step 1: Created temp table with all loglines. "
            "View it on bigquery:")
    logging.info("  https://bigquery.cloud.google.com/table/%s?tab=preview"
            % (temp_table_name))

    subqueries = [_create_subquery(entry, start_time_t, time_interval_seconds,
                                   temp_table_name)
                  for entry in config]

    # num_requests is the total number of requests in the specified
    # time period (either `now` or some other time). In order to get
    # this value, we sum the total number of requests for each field
    # (e.g. we sum the total number of requests for each browser)
    # partioned by the time, `when`.
    query = ('SELECT *, SUM(num_requests_by_field) OVER(PARTITION BY when)'
             ' as num_requests FROM %s' % ',\n'.join(subqueries))
    logging.debug('BIGQUERY QUERY: %s' % query)

    job_name = 'logs_bridge_query_%s' % random.randint(0, sys.maxint)
    r = bq_util.query_bigquery(query, job_name=job_name)
    logging.info('Step 2: Counting # of logs that match metric:')
    logging.info('  https://bigquery.cloud.google.com/results/khanacademy.org:deductive-jet-827:%s' % job_name)
    logging.debug('BIGQUERY RESULTS: %s' % r)

    return r


def _maybe_filter_out_label_values(config, results_by_metric_and_when):
    """Filter out label values if the config specifies such a filter.

    Return `results_by_metric_and_when` with the following
    modifications.  For each config entry, if `labelValues` is not
    specified, do nothing.  Otherwise, if `labelValues` is a list,
    keep only the label values that are in `labelValues`.

    If `labelValues` is "TOP_VALUES(x)", where x is a number, then
    take the x label-values with the highest metric-value where when =
    'now', and the x label-values with the highest metric-value where
    when = 'some days ago', and merge them together.

    If `labelValues` is "MOST_POPULAR(x)", where x is a number, then
    take the x label-values that occurred in the most requests where
    when = 'now', and again where when = 'some days ago', and merge.

    **WARNING**: when using TOP_VALUES or MOST_POPULAR, the results
    can be unreliable for label-values near the cut-off.  Here is what
    can happen: suppose we have "MOST_POPULAR(5)", and two different
    labels A and B flip-flop being the 5th most popular, with a value
    of around 100 for each.  Sometimes A wins and we send data for it,
    something near 100.  Sometimes it doesn't win and we send no data,
    which stackdriver treats as 0.  Stackdriver does some averaging to
    get 25, and boom it seems like A is way below expectations!

    TODO(alexanderforsyth): This function should eventually aggregate
    the remaining label values into an "Other" category. It does not
    do so because of difficulty calculating the query value of the
    "Other" label value with the current implementation and
    assumptions as mentioned in the config file.  When determining the
    top metric label values, the label values are sorted in descending
    order according to `unique_labels_sorting_field` (which currently
    supports either `num` or `num_requests_by_field` with the latter
    as default if it is not specified).
    """
    metric_to_label_values_to_keep = {
        config_entry['metricName']: config_entry.get('labelValues', None)
        for config_entry in config
    }

    # Modified results_by_metric_and_when that this function returns.
    return_dict = {}

    # Map metric_name -> label_list where time is 'now' and label_list is
    # formatted like: [(label_sorting_value, metric_label_value, result)...]
    # This is used for MOST_POPULAR() and TOP_VALUES().
    now_results_by_metric = {}

    # Map same as above, but where time is 'some days ago'.
    then_results_by_metric = {}

    # Add all metric results directly to the return_dict if the metric should
    # not have infrequent label values removed according to the config.
    # Otherwise, build up the [time]_results_by_metric dicts in
    # order to determine the top label values for each metric.
    for (metric_name, metric_label_values, when), result in \
            results_by_metric_and_when.iteritems():
        label_values_to_keep = metric_to_label_values_to_keep[metric_name]

        # Easy case: no filtering going on!
        if label_values_to_keep is None:
            return_dict[(metric_name, metric_label_values, when)] = result

        # Pretty-easy case: they explicitly say what values to keep.
        elif isinstance(label_values_to_keep, list):
            if list(metric_label_values) in label_values_to_keep:
                return_dict[(metric_name, metric_label_values, when)] = result
            # A special-case: when there's only one label to list
            # values for, label_values_to_keep can be a list of
            # strings, rather than a list of 1-tuples.
            elif (len(metric_label_values) == 1 and
                    metric_label_values[0] in label_values_to_keep):
                return_dict[(metric_name, metric_label_values, when)] = result

        # Harder case: we have to keep the N most popular.  For now we
        # just collect popularity data, and will aggregate it later.
        elif label_values_to_keep.startswith('MOST_POPULAR('):
            d = (result['num_requests_by_field'], metric_label_values, result)
            if when == 'now':
                now_results_by_metric.setdefault(metric_name, []).append(d)
            else:
                then_results_by_metric.setdefault(metric_name, []).append(d)

        # Equally hard case: keep the N with the largest values.
        elif label_values_to_keep.startswith('TOP_VALUES('):
            d = (result['num'], metric_label_values, result)
            if when == 'now':
                now_results_by_metric.setdefault(metric_name, []).append(d)
            else:
                then_results_by_metric.setdefault(metric_name, []).append(d)

        else:
            raise ValueError("Unexpected value for labelValues: %s"
                             % label_values_to_keep)

    # Now for the TOP_VALUES and MOST_POPULAR cases, we need to look
    # through the aggregated data to pick the top ones.
    for (metric_name, now_label_list) in now_results_by_metric.items():
        # Sort both the 'now' and 'then' lists.
        then_label_list = then_results_by_metric.get(metric_name, [])
        now_label_list.sort(reverse=True)
        then_label_list.sort(reverse=True)

        # The labelNames field is either MOST_POPULAR(#) or TOP_VALUES(#).
        # In either case, we can extract out the number easily enough.
        label_values_to_keep = metric_to_label_values_to_keep[metric_name]
        count = int(re.findall(r'\d+', label_values_to_keep)[0])

        # Get the top label values, unioning over now and some days ago.
        top_label_values = {v for (_, v, _) in (now_label_list[:count] +
                                                then_label_list[:count])}

        # Finally, add top label value results to the return dict.
        # TODO(alexanderforsyth): combine the non-top label values into an
        # 'Other' label value instead of just not returning them.
        for (_, label_value, result) in now_label_list:
            if label_value in top_label_values:
                return_dict[(metric_name, label_value, 'now')] = result

        for (_, label_value, result) in then_label_list:
            if label_value in top_label_values:
                return_dict[(metric_name, label_value, 'some days ago')] = (
                    result)

    return return_dict


def get_values_from_bigquery(config, start_time_t, time_interval_seconds,
        time_table_expiration):
    """Return a list of (metric-name, metric-labels, values) triples."""
    bigquery_results = _run_bigquery(config, start_time_t,
                                     time_interval_seconds,
                                     time_table_expiration)
    # A single result looks like:
    #   {u'module_id': u'multithreaded',
    #    u'num': 10.0,
    #    u'when': u'now',
    #    u'num_requests': 69441,
    #    u'num_requests_by_field: 1001
    #    u'metricName': u'logs.status'}

    # Key each result by its resolved metric name.
    results_by_metric_and_when = {}
    for result in bigquery_results:
        def field_to_value(field):
            # A special case: bigquery doesn't store module-id for
            # default.  "(None)" is how bq_io translates `None`.
            value = result[field]
            if field == 'module_id' and value == '(None)':
                return 'default'
            # bq_io can turn labels like '8' into an int; we want them
            # to be strings so we turn them back.
            return str(value)

        config_entry = next(c for c in config
                            if c['metricName'] == result['metricName'])
        label_values = []
        label_names = config_entry.get('labels', [])
        for label in label_names:
            label_value_template = _LABELS[label]
            # Now we want to replace each `<field>` in the template
            # with its value.
            label_value = _LABEL_RE.sub(lambda m: field_to_value(m.group(1)),
                                        label_value_template)
            label_values.append(label_value)
        key = (config_entry['metricName'], tuple(label_values), result['when'])
        assert key not in result, "%s is not a unique key!" % key
        results_by_metric_and_when[key] = result

    results_by_metric_and_when = (
        _maybe_filter_out_label_values(config, results_by_metric_and_when))

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
                    last_week_key = (metric_name, metric_label_values,
                                     'some days ago')
                    # if number of requests for this key was 0 a week ago, the
                    # week over week ratio is a divide by zero; so, we ignore.
                    # TODO(alexanderforsyth): better would be to aggregate
                    # all browsers, etc. with count < X into an
                    # 'Other category', which is more useful than sending
                    # that some browser has 3 users, for example
                    if last_week_key not in results_by_metric_and_when:
                        continue
                    old_result = results_by_metric_and_when[last_week_key]
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
            label_names = config_entry.get('labels', [])
            metric_labels = dict(zip(label_names,
                                     metric_label_values))
            retval.append((metric_name, metric_labels, value))

    return retval


def _send_to_stackdriver(google_project_id, bigquery_values,
                         start_time_t, time_interval_seconds, dry_run):
    """bigquery_values is a list of triples via get_values_from_bigquery."""
    # send_to_cloudmonitoring wants data in a particular format, including
    # the timestamp.  We give all these datapoints the timestamp at the
    # *end* of our time-range: start_time_t + time_interval_seconds
    time_t = start_time_t + time_interval_seconds

    # Get the data in the format needed by cloudmonitoring_util.
    data = [(metric_name, metric_labels, value, time_t)
            for (metric_name, metric_labels, value) in bigquery_values]

    return cloudmonitoring_util.send_timeseries_to_cloudmonitoring(
        google_project_id, data, dry_run)


def main(config_filename, google_project_id, time_interval_seconds, dry_run):
    config = load_config(config_filename)

    # We'll collect data minute-by-minute until we've collected data
    # from the time range (two-minutes-ago, one-minute-ago).
    run_until = int(time.time()) - time_interval_seconds * 2

    time_of_last_successful_run = _time_t_of_latest_successful_run()
    if time_of_last_successful_run is None:
        # If there's no record of previous runs, just do the most recent run.
        time_of_last_successful_run = run_until - time_interval_seconds
    if time.time() - time_of_last_successful_run > 45 * 60:
        # Stackdriver doesn't let you insert datapoints that are more than
        # an hour old, and we would never catch up from being so far behind
        # anyway.  So we just declare bankruptcy.
        logging.error('Last successful run was too long ago (at time %s). '
                      'Declaring bankruptcy and skipping ahead to time %s.'
                      % (time_of_last_successful_run,
                         run_until - time_interval_seconds))
        time_of_last_successful_run = run_until - time_interval_seconds

    while time_of_last_successful_run < run_until:
        start_time = time_of_last_successful_run + time_interval_seconds
        # Get rid of entries we shouldn't run now (because they're only
        # run hourly, e.g.).
        current_config = [e for e in config
                          if _should_run_query(e, start_time,
                                               time_of_last_successful_run)]

        bigquery_values = get_values_from_bigquery(current_config, start_time,
                                                    time_interval_seconds,
                                                    time_interval_seconds)

        # TODO(csilvers): compute ALL facet-totals for counting-stats.

        num_metrics = _send_to_stackdriver(
            google_project_id, bigquery_values, start_time,
            time_interval_seconds, dry_run)

        time_of_last_successful_run = start_time

        if dry_run:
            logging.info("Time %s: would write %s metrics to stackdriver",
                         start_time + time_interval_seconds, num_metrics)
        else:
            logging.info("Time %s: wrote %s metrics to stackdriver",
                         start_time + time_interval_seconds, num_metrics)
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
    logs_format = '[%(asctime)s %(levelname)s] %(message)s'
    if args.verbose >= 2:
        logging.basicConfig(format=logs_format, level=logging.DEBUG)
    elif args.verbose == 1 or args.dry_run:
        logging.basicConfig(format=logs_format, level=logging.INFO)

    main(args.config, args.project_id, args.window_seconds, args.dry_run)
