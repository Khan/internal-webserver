#!/usr/bin/env python

"""Fetch Google Cloud Platform cost data from bigquery and send it to graphite.

GCP cost data lives in project "khan", dataset "1_gae_billing",
table "gcp_billing_export_v1_00A183_1C5C74_24422A".
"00A183_1C5C74_24422A" is our billing account id, common across all projects.

Export was set up following this guide:
https://support.google.com/cloud/answer/7233314?hl=en&ref_topic=7106112

Empirically, the "regular interval" that link refers to for billing data export
appears to be hourly, so this script is expected to be run hourly as well.

Additionally, to fetch data which is only reported after a delay of a few days,
this script is expected to be run daily with an end-time of "2 days ago".
See aws-config:toby/crontab

We send the cost of the day's compute resources so far to graphite under keys
("gcp.<project>.usage.<service_description>.<sku_description>"
 ".daily_cost_so_far_usd")
where <project> is e.g. 'khan', 'khan_academy', 'khan_onionproxy';
<service_description> is e.g. 'Compute_Engine', 'CloudPubSub';
and <sku_description> is e.g. 'Datastore_Storage', 'Frontend_Instances'.

Note that hyphens, spaces, and slashes are all replaced with underscores.
This is done to make metrics searchable in graphite here:
https://www.hostedgraphite.com/app/metrics/#
"""


import calendar
import datetime
import re

import bq_util
import cloudmonitoring_util
import graphite_util


# By default, get all of today's data by reporting end-time of today's UTC date
_DEFAULT_END_TIME = datetime.datetime.utcnow()
_DEFAULT_END_TIME_T = calendar.timegm(_DEFAULT_END_TIME.timetuple())
_DEFAULT_END_DATE_STRING = cloudmonitoring_util.to_rfc3339(_DEFAULT_END_TIME_T)
# just pass a YYYY-MM-DD string by dropping T and everything after it
_DEFAULT_END_DATE_STRING = _DEFAULT_END_DATE_STRING.split('T')[0]


class DateParseException(Exception):
    """An error trying to parse a string into a datetime."""
    pass


def get_google_services_costs(start_time_t, end_time_t):
    """Return a list of dicts of GCP costs from start_time_t to end_time_t.

    start_time_t and end_time_t should be unix times represented as ints.

    Each dict in the list contains a daily_cost_so_far_usd, project_name,
    service_description, and sku_description, e.g.:
    {u'daily_cost_so_far_usd': 7.396287000000001,
     u'service_description': u'Cloud Pub/Sub',
     u'project_name': u'khan-academy',
     u'sku_description': u'Message Operations'}
    """

    query = """\
SELECT SUM(cost) AS daily_cost_so_far_usd, project.name, service.description,
    sku.description
FROM [1_gae_billing.gcp_billing_export_v1_00A183_1C5C74_24422A]
WHERE cost > 0
AND usage_start_time >= timestamp('%s')
AND usage_end_time <= timestamp('%s')
GROUP BY project.name, service.description, sku.description
""" % (start_time_t, end_time_t)

    return bq_util.query_bigquery(query)


def format_for_graphite(raw_data, timestamp):
    """Given a list of dicts of Google Cloud costs, return data for graphite.

    Returns an list of (graphite_key, (unix_time, cost)) tuples, where the
    graphite_key contains: project_name; service_description; sku_description.

    The unix_time in each tuple for graphite is given by the timestamp
    argument, expected to be an int.
    """

    data = []

    for d in raw_data:
        key = ("gcp.%(project_name)s.usage.%(service_description)s."
               "%(sku_description)s.daily_cost_so_far_usd") % d
        # replace characters that may interfere with searching for metrics at:
        # https://www.hostedgraphite.com/app/metrics/#
        key = re.sub(r'[^A-Za-z0-9_.]', '_', key)
        data.append((key, (timestamp, d['daily_cost_so_far_usd'])))

    return data


def main(graphite_host, start_time_t, end_time_t, verbose=False,
         dry_run=False):
    raw_data = get_google_services_costs(start_time_t, end_time_t)
    graphite_data = format_for_graphite(raw_data, end_time_t)

    if dry_run:
        graphite_host = None      # so we don't actually send to graphite
        verbose = True            # print what we would have done

    if verbose:
        if graphite_host:
            print "--> Sending to graphite:"
        else:
            print "--> Would send to graphite:"
        print '\n'.join([str(x) for x in graphite_data])

    if not dry_run:
        graphite_util.send_to_graphite(graphite_host, graphite_data)


def get_start_time_and_end_time(timestamp):
    """Given a date string timestamp, return datetimes (start_time, end_time).

    Returns now for the end_time if `timestamp` is later in the current day.

    If the timestamp is on a future date, this function raises a ValueError.

    If the `timestamp` cannot be parsed as either a YYYY-MM-DD date string or a
    YYYY-MM-DDTHH:MM:SS date string, this function raises a DateParseException.

    All times UTC.
    """

    try:
        # case YYYY-MM-DD -- get all data for the given date
        start_time_t = cloudmonitoring_util.from_rfc3339(timestamp +
                                                         "T00:00:00UTC")
        start_time = datetime.datetime.utcfromtimestamp(start_time_t)
        end_time = start_time + datetime.timedelta(days=1)
    except ValueError:
        try:
            # case YYYY-MM-DDTHH:MM:SS -- get data from midnight to timestamp
            end_time_t = cloudmonitoring_util.from_rfc3339(timestamp + "UTC")
            end_time = datetime.datetime.utcfromtimestamp(end_time_t)
            start_time = end_time.replace(hour=0, minute=0, second=0)
        except ValueError:
            raise DateParseException("Can't parse as datetime: %s" % timestamp)

    end_time = min(end_time, datetime.datetime.utcnow())

    if start_time > end_time:
        raise ValueError("cannot get data for future datetime: %s" % timestamp)

    return start_time, end_time


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--graphite_host',
                        default='carbon.hostedgraphite.com:2004',
                        help=('host:port to send stats to graphite '
                              '(using the pickle protocol). '
                              '(Default: %(default)s)'))
    parser.add_argument('--verbose', '-v', action='store_true',
                        help="Show more information about what we're doing.")
    parser.add_argument('--dry-run', '-n', action='store_true',
                        help="Show what we would do but don't do it.")
    parser.add_argument('--end-time', metavar='YYYY-MM-DD[THH:MM:SS]',
                        default=_DEFAULT_END_DATE_STRING,
                        help=('Send cost data to graphite up until end-time,'
                              ' specified as a UTC date string of form either'
                              ' YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS.'
                              ' Start time is midnight of the same UTC day.'
                              ' If HH:MM:SS is not given, the end-time is '
                              ' considered to be midnight the next day, to get'
                              ' all data for the given date.'
                              ' (Default: the current UTC date.)'))
    args = parser.parse_args()

    start_time, end_time = get_start_time_and_end_time(args.end_time)

    start_time_t = calendar.timegm(start_time.timetuple())
    end_time_t = calendar.timegm(end_time.timetuple())

    main(args.graphite_host, start_time_t, end_time_t,
         dry_run=args.dry_run, verbose=args.verbose)
