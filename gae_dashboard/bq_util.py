"""Utilities for interacting with BigQuery."""

import datetime
import json
import os
import pickle
import random
import sys
import subprocess


_DATA_DIRECTORY = os.path.join(os.getenv('HOME'), 'bq_data/')


class BQException(Exception):
    """An error trying to fetch data from bigquery."""
    pass


def call_bq(subcommand_list, project, return_output=True,
            raise_exception=True, **kwargs):
    """subcommand_list is, e.g. ['query', '--allow_large_results', ...]."""
    _BQ = ['bq', '-q', '--headless', '--project_id', project]
    try:
        if return_output:
            output = subprocess.check_output(_BQ + ['--format=json'] +
                                             subcommand_list,
                                             **kwargs)
            if not output:    # apparently 'bq query' does this when 0 results
                return None
            try:
                return json.loads(output)
            except ValueError as e:
                print('Unexpected output from BQ call: %s' % output)
                raise
        else:
            subprocess.check_call(_BQ + ['--format=none'] + subcommand_list,
                                  **kwargs)
            return
    except subprocess.CalledProcessError as e:
        if raise_exception:
            print('BQ call failed with this output: %s' % e.output)
            raise
        else:
            # Do not raise an exception in this case.
            return e.output


def does_table_exist(table_name):
    """Takes in a table name and checks if that table exists in BigQuery."""
    result = call_bq(
        ['show', table_name], project='khan-academy', raise_exception=False)

    if "Not found: Table" in result:
        return False
    else:
        return True


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
        with open(filename, 'rb') as f:
            return pickle.load(f)


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
    with open(filename, 'wb') as f:
        pickle.dump(data, f, protocol=2)


def get_daily_data_from_disk_or_bq(query, report, yyyymmdd):
    """Attempts to get the requested data from disk, otherwise querying BQ.

    If BigQuery is hit, the result will be cached to disk so that future
    queries for the same data don't need to go to BigQuery.
    """
    daily_data = get_daily_data(report, yyyymmdd)
    if not daily_data:
        print("-- Running query for %s on %s --" % (report, yyyymmdd))
        print(query)
        daily_data = query_bigquery(query)
        save_daily_data(daily_data, report, yyyymmdd)
    else:
        print("-- Using cached data for %s on %s --" % (report, yyyymmdd))

    return daily_data


def process_past_data(report, end_date, history_length, keyfn):
    """Get and process the past data for a particular report.

    Returns a list of dicts, one for each day, in most-recent-first order, with
    keys of the form returned by keyfn(row), and values the same type of rows
    returned by `bq`.  If there is no data, the dict will be empty.
    'history_length' is the number of days of data to include, not counting the
    current one.
    """
    historical_data = []
    for i in range(history_length + 1):
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


def query_bigquery(sql_query, gdrive=False, retries=2, job_name=None,
                   project='khanacademy.org:deductive-jet-827'):
    """Use the 'bq' tool to run a query, and return the results as
    a json list (each row is a dict).

    We do naive type conversion to int and float, when possible.

    The bq tool fails every once in a while for flaky reasons, so by default we
    retry the query a few times.

    This requires 'pip install bigquery' be run on this machine.

    If you'd like to view the results of this query in the bigquery web UI, you
    may wish to pass a unique string as the `job_name` param. Note that if the
    first attempt at this query fails, we'll overwrite the job_name with a
    randomly generated one.
    """
    # We could probably do 'import bq' and call out directly, but
    # I couldn't figure out an easy way to do this.  Ah well.
    # To avoid having to deal with paging (which I think the command-line bq is
    # not very good at anyway), we just get a bunch of rows.  We probably only
    # want to display the first 100 or so, but the rest may be useful to save.

    table = None
    error_msg = None

    for i in range(1 + retries):
        try:
            if not job_name:
                # We specify the job-name (randomly) so we can cancel it.
                job_name = 'bq_util_%s' % random.randint(0, sys.maxsize)

            # call_bq can return None when there are no results for
            # the query.  We map that to [].
            table = call_bq(['--job_id', job_name] +
                            (['--enable_gdrive'] if gdrive else []) +
                            ['query', '--max_rows=10000', sql_query],
                            project=project) or []
            job_name = None     # to indicate the job has finished
            break
        except subprocess.CalledProcessError as why:
            print("-- Running query failed with retcode %d --" % why.returncode)
            error_msg = why.output
        finally:
            if job_name:
                try:        # Cancel the job if it's still running
                    call_bq(['--nosync', 'cancel', job_name],
                            project=project, return_output=False)
                except subprocess.CalledProcessError:
                    print("That's ok, it just means the job canceled itself.")
                    pass    # probably means the job finished already

            # Clear out job_name so it will be regenerated if this query failed
            job_name = None

    if table is None:
        raise BQException("-- Query failed after %d retries: %s --"
                          % (retries, error_msg))

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
                except TypeError:
                    # Row maybe a list
                    pass

    return table
