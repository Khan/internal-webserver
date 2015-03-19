#!/usr/bin/env python

"""Send statistics from App Engine's usage reports to graphite.

This script reads an App Engine usage report in CSV format from stdin,
then takes all records since the input start_date, parses them, and
emits the data to graphite.

Usage:
  ./load_usage_reports.py < billing_history.csv

The expected CSV input looks like what /billing/history.csv?app_id=
returned as of October 2012:

  Date,Name,Unit,Used
  2012-10-11,Frontend Instance Hours,Hour,"X,XXX.XX"
  2012-10-11,Backend Instance Hours,Hour,XXX.XX
  ...

Records are sent to graphite under the keys webapp.gae.dashboard.usage.*
"""

import argparse
import csv
import datetime
import os
import re
import sys

import pytz

import graphite_util


_LAST_RECORD_DB = os.path.join(os.getenv('HOME'), 'usage_report_time.db')


def _time_t_of_latest_record():
    """time_t of the most recently stored dashboard record.

    This data is stored in a file.  We could consider this a small database.

    Returns:
        The time_t (# of seconds since the UNIX epoch) or None if
        there is no previous record.
    """
    if os.path.exists(_LAST_RECORD_DB):
        with open(_LAST_RECORD_DB) as f:
            return int(f.read().strip())
    return None


def _write_time_t_of_latest_record(records):
    """Find the record with the latest time-t and write it to the db."""
    epoch = datetime.datetime.utcfromtimestamp(0)
    latest_record = max(records, key=lambda r: r['utc_datetime'])
    with open(_LAST_RECORD_DB, 'w') as f:
        print >>f, int((latest_record['utc_datetime'] - epoch).total_seconds())


def _munge_key(key):
    """Turns the key into a graphite-friendly key name.

    The GAE report has names like "Search Document Storage" and
    "Dedicated Memcache (10k ops/s/GB, 1GB unit)".  Thse are not
    friendly keys for graphite!  We convert them into names like
    search_document_storage and dedicated_memcache, which is not
    great but much better.
    """
    # Ignore any parentheticals.
    key = re.sub('[^\w\s].*', '', key)
    key = re.sub('\s+', '_', key.strip())
    return key.lower()


def _reports_since_dt(csvreader, cutoff_dt):
    """Return usage reports that are from after the given cutoff date.

    Arguments:
        csvreader: an iterable where each iteration returns the usage report
            fields as a dict, with keys: Date, Name, Unit, Used.
        cutoff_dt: a string of the form 'YYYY-MM-DD'.  Only records from
            cvsreader that have a date after cutoff_dt are returned.

    Returns:
      An iterator where each element is a triple (<date>, <key>, <value>).
      Value is a float or an int.

      For example:
         (datetime.datetime(2012, 10, 15, 0, 0, 0), 'Frontend', 31000.23)
    """
    seen_dts = set()
    for row in csvreader:
        if not row:
            # Skip blank lines.
            continue

        # NOTE: reports might not be in order. For example, the lines for
        # 2012-10-09 might be before those for 2012-10-13. Because of this, we
        # iterate over all of the input rather than stopping at a certain
        # point. This is fine since the input is at most a few months of data,
        # as of October 2012.
        if row['Date'] <= cutoff_dt:
            continue

        if row['Date'] not in seen_dts:
            print 'Found usage report for: %s' % row['Date']
            seen_dts.add(row['Date'])

        used_str = row['Used'].replace(',', '')  # float() can't parse a comma
        used_num = float(used_str) if '.' in used_str else int(used_str)
        dt = datetime.datetime.strptime(row['Date'], '%Y-%m-%d')
        # All appengine dates are pacific time, I've been informed, so
        # we set the tzinfo using that.
        dt -= pytz.timezone('America/Los_Angeles').utcoffset(dt)
        yield (dt, row['Name'], used_num)


def main(csv_iter):
    """Parse App Engine usage report CSV and bring a mongo db collection
    up-to-date with it.

    csv_input is any object that returns a line of the usage report CSV for
    each iteration. This includes the header line containing field names.
    """
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n', 1)[0])
    parser.add_argument('--graphite_host',
                        default='carbon.hostedgraphite.com:2004',
                        help=('host:port to send stats to graphite '
                              '(using the pickle protocol). '
                              '[default: %(default)s]'))
    parser.add_argument('-v', '--verbose', action='store_true', default=False,
                        help='print report on stdout')
    parser.add_argument('-n', '--dry-run', action='store_true', default=False,
                        help='do not store report in the database')
    args = parser.parse_args()

    csvreader = csv.DictReader(csv_iter)

    start_date = _time_t_of_latest_record()
    if start_date is None:
        print 'No record of previous fetches; importing all records as new.'
        start_date = datetime.date(2000, 1, 1)
    else:
        start_date = datetime.date.fromtimestamp(start_date)
    start_date = start_date.strftime('%Y-%m-%d')

    print 'Importing usage reports starting from %s' % start_date

    records_to_add = []
    for (dt, key, value) in _reports_since_dt(csvreader, start_date):
        records_to_add.append({'utc_datetime': dt, _munge_key(key): value})

    if args.verbose:
        print records_to_add

    print 'Importing %s documents' % len(records_to_add)

    if args.dry_run:
        print 'Skipping import during dry-run.'
        records_to_add = []
    elif records_to_add:
        graphite_util.maybe_send_to_graphite(args.graphite_host, 'usage',
                                             records_to_add)

    if records_to_add:
        _write_time_t_of_latest_record(records_to_add)


if __name__ == "__main__":
    main(sys.stdin)
