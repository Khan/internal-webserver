#!/usr/bin/env python

"""Runs bigquery over the logs and extracts data that it sends to graphite.

Most data that we log we also send to graphite at the time that we log
it, so this post-processing step isn't necessary.  But some log
messages aren't created by us -- for instance, OOM errors -- and the
only want to process those logs for graphite is to do it post-facto.
That is what this script does.

This script runs over the requestlogs_hourly logs, so there's a delay
between when something is logged and when it shows up at graphite.  If
you run it twice without a new requestlogs_hourly log showing up, the
second time is a noop.
"""

import logging
import os
import time

import bq_util
import graphite_util


_NOW = int(time.time())
_LAST_RECORD_DB = os.path.expanduser('~/logs_to_graphite_time.db')


def _time_t_of_latest_record():
    """time_t of the most recently stored dashboard record.

    This data is stored in a file.  We could consider this a small database.

    Returns:
        The time_t (# of seconds since the UNIX epoch in UTC) or None if
        there is no previous record.
    """
    if os.path.exists(_LAST_RECORD_DB):
        with open(_LAST_RECORD_DB) as f:
            return int(f.read().strip())
    return None


def _write_time_t_of_latest_record(time_t):
    """Given the record with the latest time-t, write it to the db."""
    with open(_LAST_RECORD_DB, 'w') as f:
        print >>f, time_t


def report_out_of_memory_errors(yyyymmdd_hh, graphite_host):
    # This bigquery query is modified from email_bq_data.py
    query = """\
SELECT module_id, integer(app_logs.time) as time_t
FROM [logs_hourly.requestlogs_%s]
WHERE app_logs.message CONTAINS 'Exceeded soft private memory limit'
      AND module_id IS NOT NULL
""" % (yyyymmdd_hh)
    data = bq_util.query_bigquery(query)

    records = [('webapp.%(module_id)s_module.stats.oom' % row,
                (row['time_t'], 1))
               for row in data]

    if not graphite_host:
        logging.info('Would send to graphite: %s' % records)
    else:
        graphite_util.send_to_graphite(graphite_host, records)


def main(graphite_host):
    """graphite_host can be None to not actually send the data to graphite."""
    # The hourly logs only go back in time a week.
    start_time_t = _time_t_of_latest_record() or _NOW - 86400 * 7
    time_t = start_time_t

    for time_t in xrange(start_time_t, _NOW, 3600):
        # We use gmtime since bigquery stores all its data in UTC.
        yyyymmdd_hh = time.strftime('%Y%m%d_%H', time.gmtime(time_t))
        logging.info("Collecting stats for %s" % yyyymmdd_hh)

        try:
            report_out_of_memory_errors(yyyymmdd_hh, graphite_host)
        except bq_util.BQException, why:
            if 'Not found' in str(why):
                # The bigquery logs haven't caught up to this time-t yet.
                break
            else:
                raise

    _write_time_t_of_latest_record(time_t)


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--graphite_host',
                        default='carbon.hostedgraphite.com:2004',
                        help=('host:port to send stats to graphite '
                              '(using the pickle protocol). '
                              '(Default: %(default)s)'))
    parser.add_argument('--dry-run', '-n', action='store_true',
                        help="Show what we would do but don't do it.")
    parser.add_argument('-v', '--verbose', action='store_true',
                        help="Give more verbose output")
    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)
    else:
        logging.getLogger().setLevel(logging.INFO)

    main(None if args.dry_run else args.graphite_host)
