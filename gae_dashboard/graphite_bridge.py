#!/usr/bin/env python

"""Send HostedGraphite metrics to Cloud Monitoring.

This script is configured with a mapping from graphite metrics to
Cloud Monitoring timeseries. When run, it exports the youngest
complete data point for each graphite metric to Cloud Monitoring (the
youngest data point represents a bucket that's still being filled, so
we skip it).

We don't have to worry about sending the same data twice as long as
the window size stays constant because the Cloud Monitoring API
ignores datapoints older than the youngest complete data point in each
of its timeseries, and we ensure that a datapoint's timestamp is
stable for a given window size. See the NOTE for --window-size in the
"Usage" section below.


Description:

Khan Academy has all sorts of time-series data in HostedGraphite
describing deployments, user events on the production website, server
health, error counts, billed resources, and so on.

Google's Cloud Monitoring integrates nicely with App Engine, which
runs www.khanacademy.org, and has some very nice alerting features to
notify developers via Slack, PagerDuty, email, &c. when things go
wrong.

This script is the bridge that enables Cloud Monitoring to trigger
alerts based on time-series data in HostedGraphite. It's expected that
a cron job will periodically run this script.


Usage:

Export the youngest complete data point found within the last 24 hours
minutes for each configured graphite metric:

  ./graphite_bridge.py

For testing, enabled verbose mode and dry-run mode. A dry-run will
read data from graphite, but won't write to Cloud Monitoring:

  ./graphite_bridge.py -nv

There's also a testing mode to send data to Cloud Monitoring. One
datapoint will be written to the `write_test` timeseries:

  ./graphite_bridge.py -vt

By default the last 24 hours of data is read from graphite. With this
window size, each data point usually represents a 5-minute bucket of
aggregated data. Override this with --window-seconds.

NOTE: this feature makes it possible to write the same data twice to
Cloud Monitoring. If you first use a large window size, then a small
window size, the small window's timestamp may be younger for the same
datapoint. E.g., a 24-hour window has 5-minute buckets, and a 5-minute
window has 5-second buckets, so a 1-second old datapoint will appear
5-minutes old for the large window, and 5-seconds old for the small
window.

This example reads the last hour's data:

  ./graphite_bridge.py --window-seconds=3600


Intended usage:

It's expected this script will be run periodically as a cron job. How
frequently to run it depends on how frequently new data shows up in
graphite that should be exported to Cloud Monitoring, since only the
youngest complete data point for each graphite metric is sent.

"""

import argparse
import logging
import math
import time

import cloudmonitoring_util
import graphite_util


class Metric(object):
    """Wrapper class for metrics that are exported from graphite.

    Has two important properties:
        target: the graphite target for this metric's data.
        name: the preferred name for this metric in other systems. If
            no name is specified, this defaults to the 'target' value.

    """
    def __init__(self, target, name=None):
        self.target = target
        self.name = name or target


def _default_metrics():
    """List of Metrics to export."""

    metrics = [
        # These three metrics are temporarily disabled because the graphite
        # endpoint is telling us one of them is malformed and returns no
        # response at all for the other two, which breaks the rest of this
        # script.
        # It appears we're not actually getting any data into graphite for
        # these either, so there's no additional harm in disabling them to get
        # the rest of the script back up and running.
        # See INFRA-435 for more information on the malformed metric
        # investigation.
        # TODO(colin): figure out what's going on with these, both on the
        # querying and sending data and fix this: INFRA-440.
        # Metric(
        #     'webapp.gae.dashboard.summary.default_module.'
        #     'milliseconds_per_dynamic_request.pct50',
        #     name='default_module.average_latency_ms'),
        # Metric(
        #     'webapp.gae.dashboard.summary.batch_module.'
        #     'milliseconds_per_dynamic_request.pct50',
        #     name='batch_module.average_latency_ms'),
        # _historical_ratio_metric(
        #     'webapp.gae.dashboard.summary.batch_module.'
        #     'milliseconds_per_dynamic_request.pct50',
        #     name='batch_module.average_latency_ms.week_over_week',
        #     timeshift='7d'),

        # Week-over-week change for BigBingo conversions.
        _historical_ratio_metric(
            'webapp.stats.bingo.login:sum',
            name='bingo.login.week_over_week',
            timeshift='7d',),
        _historical_ratio_metric(
            'webapp.stats.bingo.problem_attempt:sum',
            name='bingo.problem_attempt.week_over_week',
            timeshift='7d'),
        _historical_ratio_metric(
            'webapp.stats.bingo.registration:sum',
            name='bingo.registration.week_over_week',
            timeshift='7d'),
        _historical_ratio_metric(
            'webapp.stats.bingo.video_started:sum',
            name='bingo.video_started.week_over_week',
            timeshift='7d'),

        # Failure rate for react-render-server.  We have to go through
        # graphite for this because stackdriver can't do the ratios
        # manually.  TODO(csilvers): do the failure rate per-route too.
        # TODO(csilvers): also measure server errors, timeouts, etc?
        Metric(
            'divideSeries('
            '   sumSeries(webapp.*.stats.react_render_server.cache_miss:sum), '
            '   sumSeries(webapp.*.stats.react_render_server.cache_hit:sum)'
            ')',
            name='react_render_server.failure_pct'),

        # Bigquery daily cost data.
        Metric(
            'sumSeries(gcp.*.usage.bigquery.daily_query_cost_so_far_usd)',
            name='bigquery.usage.daily_cost_so_far'),
        ]
    return metrics


def _historical_ratio_metric(target, name, timeshift='7d'):
    """Build the graphite target for a metric compared to itself in the past.

    The historical ratio measures how different current values are
    from historical values. Many website metrics have weekly patterns,
    so this is useful in comparing, e.g., this Monday against last
    Monday.

    A value of 0.0 in this series means that current data matches
    historical data.
    """
    # The historical ratio is ((current - old) / old). There are two gotchas:
    #
    # 1) We take the absolute value because Cloud Monitoring has only
    # one threshold we should alert on current > old or old > current.
    #
    # 2) In the special case where the current values aren't known
    # they are null. We use keepLastValue to avoid nulls. Otherwise,
    # we'd see values of 1.0 when current is null and old is not null.
    target = ('absolute('
              '  divideSeries('
              '    diffSeries('
              '      keepLastValue(%(target)s),'
              '      keepLastValue(timeShift(%(target)s, "%(timeshift)s"))),'
              '    keepLastValue(timeShift(%(target)s, "%(timeshift)s"))))'
              % {'target': target, 'timeshift': timeshift}).replace(' ', '')
    return Metric(target, name)


def _graphite_to_cloudmonitoring(graphite_host, google_project_id, metrics,
                                 window_seconds=300, dry_run=False):
    targets = [m.target for m in metrics]
    from_str = '-%ss' % window_seconds
    response = graphite_util.fetch(graphite_host, targets, from_str=from_str)

    outbound = []
    assert len(response) == len(metrics), (
        (sorted(r['target'] for r in response),
         sorted(m.name for m in metrics)))
    for metric, item in zip(metrics, response):
        datapoints = item['datapoints']

        # Figure out each target's bucket size returned by graphite. This
        # requires 2 or more datapoints, so we ignore entries without
        # enough data, instead of exporting inaccurate timestamps.
        if len(datapoints) < 2:
            logging.info('Ignoring target with too little data: %s %s'
                         % (item['target'], datapoints))
            continue

        bucket_seconds = datapoints[1][1] - datapoints[0][1]
        logging.debug('Detected bucket size of %ss for %s'
                      % (bucket_seconds, metric.name))

        # Extract valid data with two filters:
        #
        # 1) Ignore the first bucket. This current bucket might still
        # be collecting data, so it's incomplete and we don't want to
        # persist it to Cloud Monitoring, which won't let us
        # subsequently update it.
        #
        # 2) Ignore empty buckets. The Render URL API divides the
        # window of data in equally-sized buckets. If there isn't a
        # datapoint in a timespan bucket (e.g., a 5-second period) the
        # value is None (null in JSON).
        datapoints = [p for p in datapoints[:-1] if p[0] is not None]

        # Ignore metrics without any datapoints, only empty buckets.
        if not datapoints:
            logging.info('Ignoring target with no data: %s' % item['target'])
            continue

        # We'll only send the youngest complete data point for each
        # target. We threw out the youngest, possibly-incomplete
        # bucket above, so we know we're good here.
        value, timestamp = datapoints[-1]

        # Graphite buckets line up depending on when the API call is
        # made. We don't choose how to align them when using a relative
        # time like "all data in the last 5 minutes, i.e., -5min". Since
        # we use the bucket timestamp as the datapoint timestamp, we want
        # it to be stable across script executions. We normalize the
        # buckets by rounding to the next-oldest bucket's beginning,
        # assuming that the first-ever bucket began at the UNIX epoch.
        if timestamp % bucket_seconds != 0:
            timestamp = timestamp - timestamp % bucket_seconds

        # Use a friendly name in place of a (possibly complex) graphite target.
        # The '{}' is because we don't use stackdriver metric-labels yet.
        outbound.append((metric.name, {}, value, timestamp))

    # Load data to Cloud Monitoring.
    cloudmonitoring_util.send_timeseries_to_cloudmonitoring(
        google_project_id, outbound, dry_run=dry_run)
    return outbound


def main():
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n', 1)[0])
    parser.add_argument('--graphite_host',
                        default='www.hostedgraphite.com',
                        help=('host of the graphite Render URL API '
                              '[default: %(default)s]'))
    parser.add_argument('--project_id', default='khan-academy',
                        help=('project ID of a Google Cloud Platform project '
                              'with the Cloud Monitoring API enabled '
                              '[default: %(default)s]'))
    # A 24-hour window in graphite produces 5 minute buckets, even
    # when comparing metrics week-over-week, a consistent default.
    parser.add_argument('--window-seconds', default='86400', type=int,
                        help=('window of time to read from graphite. '
                              'The most recent datapoint is sent to Cloud '
                              'Monitoring [default: %(default)s]'))
    parser.add_argument('-v', '--verbose', action='count', default=0,
                        help=('enable verbose logging (-vv for very verbose '
                              'logging)'))
    parser.add_argument('-n', '--dry-run', action='store_true', default=False,
                        help='do not write metrics to Cloud Monitoring')
    parser.add_argument('-t', '--test-write', action='store_true',
                        default=False,
                        help=('write a datapoint to the Cloud Monitoring '
                              'timeseries named "write_test"'))
    args = parser.parse_args()

    # -v for INFO, -vv for DEBUG.
    if args.verbose >= 2:
        logging.basicConfig(level=logging.DEBUG)
    elif args.verbose == 1:
        logging.basicConfig(level=logging.INFO)

    if args.test_write:
        data = [('write_test', {}, math.sin(time.time()), int(time.time()))]
        cloudmonitoring_util.send_timeseries_to_cloudmonitoring(
            args.project_id, data, dry_run=args.dry_run)
    else:
        data = _graphite_to_cloudmonitoring(
            args.graphite_host, args.project_id, _default_metrics(),
            dry_run=args.dry_run, window_seconds=args.window_seconds)
    if args.dry_run:
        print "Would send %d datapoint(s)" % len(data)
    else:
        print "Sent %d datapoint(s)" % len(data)


if __name__ == "__main__":
    # I put this in to debug where this process is (sometimes) timing out.
    # When `timeout` sends a SIGTERM, we will print the stacktrace first.
    import signal
    import sys
    import traceback
    signal.signal(signal.SIGTERM,
                  lambda sig, stack: (traceback.print_stack(stack),
                                      sys.exit(1)))

    main()
