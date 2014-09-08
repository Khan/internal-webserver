"""Utilities for interacting with BigQuery."""

import cPickle
import datetime
import json
import os
import subprocess


_DATA_DIRECTORY = os.path.join(os.getenv('HOME'), 'bq_data/')


def _get_data_filename(report, yyyymmdd):
    """Gets the filename in which old data might be stored.

    Takes a report name and a date stamp.  The file returned is not guaranteed
    to exist.
    """
    return os.path.join(_DATA_DIRECTORY, report + '_' + yyyymmdd + '.pickle')


def get_daily_data(report, yyyymmdd):
    """Gets old data for a particular report.

    Returns the data in the format saved (see save_daily_data or the caller),
    or None if there is no old data for that report on that day.
    """
    filename = _get_data_filename(report, yyyymmdd)
    if not os.path.exists(filename):
        return None
    else:
        with open(filename) as f:
            return cPickle.load(f)


def save_daily_data(data, report, yyyymmdd):
    """Saves the data for a report to be used in the future.

    This will create the relevant directories if they don't exist, and clobber
    any existing data with the same timestamp.  "data" can be anything
    pickleable, but in general will likely be of the format returned from
    _query_bigquery, namely a list of dicts fieldname -> value.
    """
    filename = _get_data_filename(report, yyyymmdd)
    if not os.path.isdir(os.path.dirname(filename)):
        os.makedirs(os.path.dirname(filename))
    with open(filename, 'w') as f:
        cPickle.dump(data, f)


def process_past_data(report, end_date, history_length, keyfn):
    """Get and process the past data for a particular report.

    Returns a list of dicts, one for each day, in most-recent-first order, with
    keys of the form returned by keyfn(row), and values the same type of rows
    returned by `bq`.  If there is no data, the dict will be empty.
    'history_length' is the number of days of data to include, not counting the
    current one.
    """
    historical_data = []
    for i in xrange(history_length + 1):
        old_yyyymmdd = (end_date - datetime.timedelta(i)).strftime("%Y%m%d")
        old_data = get_daily_data(report, old_yyyymmdd)
        # Save it by url_route for easy lookup.
        if old_data:
            historical_data.append({keyfn(row): row for row in old_data})
        else:
            # If we're missing data, put in a placeholder.  This will get
            # carried through and eventually become a space in the graph.
            historical_data.append({})
    # We construct historical_data with the most recent data first, but display
    # the sparklines with the most recent data last.
    historical_data.reverse()
    return historical_data


def query_bigquery(sql_query):
    """Use the 'bq' tool to run a query, and return the results as
    a json list (each row is a dict).

    We do naive type conversion to int and float, when possible.

    This requires 'pip install bigquery' be run on this machine.
    """
    # We could probably do 'import bq' and call out directly, but
    # I couldn't figure out an easy way to do this.  Ah well.
    # To avoid having to deal with paging (which I think the command-line bq is
    # not very good at anyway), we just get a bunch of rows.  We probably only
    # want to display the first 100 or so, but the rest may be useful to save.
    data = subprocess.check_output(['bq', '-q', '--format=json', '--headless',
                                    'query', '--max_rows=10000', sql_query])
    table = json.loads(data)

    for row in table:
        for key in row:
            if row[key] is None:
                row[key] = '(None)'
            else:
                try:
                    row[key] = int(row[key])
                except ValueError:
                    try:
                        row[key] = float(row[key])
                    except ValueError:
                        pass

    return table
