#!/usr/bin/env python

"""Send HostedGraphite metrics to Cloud Monitoring.

This script is configured with a mapping from graphite metrics to
Cloud Monitoring timeseries. When run, it exports the youngest data
point for each graphite metric to Cloud Monitoring.

We don't have to worry about sending the same data twice as long as
the window size stays constant because the Cloud Monitoring API
ignores datapoints older than the youngest data in each of its
timeseries, and we ensure that a datapoint's timestamp is stable for a
given window size. See the NOTE for --window-size in the "Usage"
section below.


Description:

Khan Academy has all sorts of time-series data in HostedGraphite
describing deployments, user events on the production website, server
health, error counts, billed resources, and so on.

Google's Cloud Monitoring integrates nicely with App Engine, which
runs www.khanacademy.org, and has some very nice alerting features to
notify developers via HipChat, PagerDuty, email, &c. when things go
wrong.

This script is the bridge that enables Cloud Monitoring to trigger
alerts based on time-series data in HostedGraphite. It's expected that
a cron job will periodically run this script.


Usage:

Export the youngest data point found within the last 5 minutes for
each configured graphite metric:

  ./graphite_bridge.py

For testing, enabled verbose mode and dry-run mode. A dry-run will
read data from graphite, but won't write to Cloud Monitoring:

  ./graphite_bridge.py -nv

There's also a testing mode to send data to Cloud Monitoring. One
datapoint will be written to the TEST_WRITE timeseries:

  ./graphite_bridge.py -vt

By default the last 5 minutes of data is read from graphite. Override
this with --window-seconds.

NOTE: this feature makes it possible to write the same data twice to
Cloud Monitoring. If you first use a large window size, then a small
window size, the small window's timestamp may be younger for the same
datapoint. E.g., a 24-hour window has 10-minute buckets, and a
5-minute window has 5-second buckets, so a 1-second old datapoint will
appear 10-minutes old for the large window, and 5-seconds old for the
small window.

This example reads the last hour's data:

  ./graphite_bridge.py --window-seconds=3600


Intended usage:

It's expected this script will be run periodically as a cron job. How
frequently to run it depends on how frequently new data shows up in
graphite that should be exported to Cloud Monitoring, since only the
youngest datapoint for each graphite metric is sent.

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
        Metric(
            'webapp.gae.dashboard.instances.default_module.average_latency_ms',
            name='default_module.average_latency_ms'),
        Metric(
            'webapp.gae.dashboard.instances.batch_module.average_latency_ms',
            name='batch_module.average_latency_ms'),
        Metric(_historical_ratio('webapp.gae.dashboard.instances'
                                 '.batch_module.average_latency_ms',
                                 timeshift='7d'),
               name='batch_module.average_latency_ms.week_over_week'),
        ]
    # Sanity check. This will raise errors on too-long names.
    for metric in metrics:
        cloudmonitoring_util.custom_metric(metric.name)
    return metrics


def _historical_ratio(target, timeshift='7d'):
    """Build the graphite target for a metric compared to itself in the past.

    A value of 1.0 in this series means that current data matches
    historical data.
    """
    return ('absolute('
            '  divideSeries('
            '    diffSeries('
            '      %(target)s,'
            '      timeShift(%(target)s, "%(timeshift)s")),'
            '    timeShift(%(target)s, "%(timeshift)s")))'
            % {'target': target, 'timeshift': timeshift}).replace(' ', '')


def _send_to_cloudmonitoring(google_project_id, data, dry_run=False):
    for target, datapoints in data.iteritems():
        value, timestamp = datapoints[0]
        logging.info('Sending %s %s %s' % (target, value, timestamp))
    if not dry_run:
        cloudmonitoring_util.send_to_cloudmonitoring(google_project_id, data)
    return len(data)


def _graphite_to_cloudmonitoring(graphite_host, google_project_id, metrics,
                                window_seconds=300, dry_run=False):
    targets = [m.target for m in metrics]
    from_str = '-%ss' % window_seconds
    response = graphite_util.fetch(graphite_host, targets, from_str=from_str)
    
    outbound = {}
    assert len(response) == len(metrics)
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
        
        # Ignore empty buckets. The Render URL API divides the window
        # of data in equally-sized buckets. If there isn't a datapoint
        # in a timespan bucket (e.g., a 5-second period) the value is
        # None (null in JSON).
        datapoints = [p for p in datapoints if p[0] is not None]

        # Ignore metrics without any datapoints, only empty buckets.
        if not datapoints:
            logging.info('Ignoring target with no data: %s' % item['target'])
            continue
        
        # We'll only send the youngest datapoint for each target.
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
        outbound[metric.name] = [(value, timestamp)]
    
    # Load data to Cloud Monitoring.
    if outbound:
        _send_to_cloudmonitoring(google_project_id, outbound, dry_run=dry_run)
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
    parser.add_argument('--window-seconds', default='300', type=int,
                        help=('window of time to read from graphite. '
                              'The most recent datapoint is sent to Cloud '
                              'Monitoring [default: %(default)s]'))
    parser.add_argument('-v', '--verbose', action='store_true', default=False,
                        help='enable verbose logging')
    parser.add_argument('-n', '--dry-run', action='store_true', default=False,
                        help='do not write metrics to Cloud Monitoring')
    parser.add_argument('-t', '--test-write', action='store_true',
                        default=False,
                        help=('write a datapoint to the Cloud Monitoring '
                              'timeseries named "write_test"'))
    args = parser.parse_args()
    
    if args.verbose:
        logging.basicConfig(level=logging.DEBUG)
    
    if args.test_write:
        data = {'write_test': [(math.sin(time.time()), int(time.time()))]}
        _send_to_cloudmonitoring(args.project_id, data, dry_run=args.dry_run)
    else:
        data = _graphite_to_cloudmonitoring(
            args.graphite_host, args.project_id, _default_metrics(),
            dry_run=args.dry_run, window_seconds=args.window_seconds)
    if args.dry_run:
        print "Would send %d datapoint(s)" % len(data)
    else:
        print "Sent %d datapoint(s)" % len(data)


if __name__ == "__main__":
    main()
