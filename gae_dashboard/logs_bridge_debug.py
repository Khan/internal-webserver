#!/usr/bin/env python
"""Simple script to help debug alerts raised by logs_bridge.

The logs_bridge.py script is optimized for calculating a bunch of different
metrics on our logs all at once. This optimized code is a bit difficult to
parse through if you are investigating a single alert and are trying to get to
the bottom of an issue.

This script is meant to help with that process, by providing a simple way to
run logs bridge for a single metric at an arbitrary start time.

A typical use case for this script is: an alert for
logs.status.400.week_over_week is triggered overnight at 2am. Given the start
time of the alert, a developer could use this script to run logs_bridge for
that particular metric at that particular time:

    ./logs_bridge_debug.py --metric logs.status.400.week_over_week \
            --start_time `gdate -d "2am today" +%s`

Using the bigquery links output by this script, the developer should be able to
identify which requests caused this alert to occur.
"""


import logging
import logs_bridge

INTERVAL_SECONDS = 300
TABLE_EXPIRATION_SECONDS = 60 * 60

DAYS_AGO = 7


def main(config_filename, metric_name, start_time_t,
        time_interval_seconds):
    config = [c for c in logs_bridge.load_config(config_filename)
              if c['metricName'] == metric_name]
    if not config:
        raise RuntimeError(
                "Couldn't find metric %s in config file" % metric_name)

    bigquery_values = logs_bridge.get_values_from_bigquery(
            config, start_time_t, time_interval_seconds,
            TABLE_EXPIRATION_SECONDS)

    logging.info("Step 3: Normalize and clean data. Final results:")
    for (metric_name, metric_labels, value) in bigquery_values:
        logging.info("  %s: %s: %.3f, " % (metric_name, metric_labels, value))


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--metric', required=True,
                        help=('A single metricName to examine from the '
                              'configuration file (ex: '
                              '"logs.status.400.week_over_week")'))

    parser.add_argument('--start_time', type=int, required=True,
                        help=('Start time to examine, specified as seconds '
                              'since unix epoch (ex: `gdate -d "3am today" '
                              '+%%s`)'))

    parser.add_argument('-c', '--config', default='logs_bridge.config.json',
                        help=('JSON file holding the stats to extract from '
                              'the logs'))

    parser.add_argument('--window-seconds', default=INTERVAL_SECONDS, type=int,
                        help=('window of time to read from the logs. '
                              'This should not be longer than the frequency '
                              'this script is run [default: %(default)s]'))

    args = parser.parse_args()

    logs_format = '[%(levelname)s] %(message)s'
    logging.basicConfig(format=logs_format, level=logging.DEBUG)

    main(args.config, args.metric, args.start_time, args.window_seconds)
