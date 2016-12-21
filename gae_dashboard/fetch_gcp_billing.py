#!/usr/bin/env python

"""Fetch Google Cloud Platform cost data from bigquery and send it to graphite.

GCP cost data lives in project "khan", dataset "1_gae_billing",
table "gcp_billing_export_00A183_1C5C74_24422A".
"00A183_1C5C74_24422A" is our billing account id, common across all projects.

Export was set up following this guide:
https://support.google.com/cloud/answer/7233314?hl=en&ref_topic=7106112

Empirically, the "regular interval" that link refers to for billing data export
appears to be hourly, so this script is expected to be run hourly as well.

We send the cost of the day's compute resources so far to graphite under keys
gcp.<project>.usage.<product_name>.<resource>.daily_cost_so_far_usd
where <project> is e.g. 'khan', 'khan_academy', 'khan_onionproxy';
<product_name> is e.g. 'Compute_Engine', 'CloudPubSub';
and <resource> is e.g. 'Datastore_Storage', 'Frontend_Instances'.

Note that hyphens, spaces, and slashes are all replaced with underscores.
This is done to make metrics searchable in graphite here:
https://www.hostedgraphite.com/app/metrics/#
"""


import datetime
import re
import time

import bq_util
import graphite_util


# Report on today by default
_DEFAULT_START_TIME = datetime.datetime.utcnow()
_DEFAULT_END_TIME = _DEFAULT_START_TIME + datetime.timedelta(days=1)

_DEFAULT_START_DATE_STRING = _DEFAULT_START_TIME.strftime('%Y-%m-%d')
_DEFAULT_END_DATE_STRING = _DEFAULT_END_TIME.strftime('%Y-%m-%d')


def get_google_services_costs(start_time, end_time):
    """Return a list of dicts of GCP costs from start_time to end_time.

    start_time and end_time should be YYYY-MM-DD strings, or a Unix timestamp.

    Each dict in the list contains a daily_cost_so_far_usd, project_name,
    product, and resource_type, e.g.:
    {u'daily_cost_so_far_usd': 7.396287000000001,
     u'product': u'Cloud Pub/Sub',
     u'project_name': u'khan-academy',
     u'resource_type': u'Message Operations'}
    """

    query = """\
SELECT SUM(cost) AS daily_cost_so_far_usd, project.name, product, resource_type
FROM [1_gae_billing.gcp_billing_export_00A183_1C5C74_24422A]
WHERE cost > 0
AND start_time >= timestamp('%s')
AND end_time < timestamp('%s')
GROUP BY project.name, product, resource_type
""" % (start_time, end_time)

    return bq_util.query_bigquery(query)


def format_for_graphite(raw_data):
    """Given a list of dicts of Google Cloud costs, return data for graphite.

    Returns an list of (graphite_key, (unix_time, cost)) tuples, where the
    graphite_key contains the project name, product, and resource type.
    """

    now = int(time.time())
    data = []

    for d in raw_data:
        key = ("gcp.%s.usage.%s.%s.daily_cost_so_far_usd" %
               (d['project_name'], d['product'], d['resource_type']))
        # key = ("gcp.%(project_name)s.usage.%(product)s.%(resource_type)s"
        #        ".daily_cost_so_far_usd") % d
        # replace characters that may interfere with searching for metrics at:
        # https://www.hostedgraphite.com/app/metrics/#
        key = re.sub(r'[^A-Za-z0-9_.]', '_', key)
        data.append((key, (now, d['daily_cost_so_far_usd'])))

    return data


def main(graphite_host, start_time, end_time, verbose=False, dry_run=False):
    raw_data = get_google_services_costs(start_time, end_time)
    graphite_data = format_for_graphite(raw_data)

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
    parser.add_argument('--start-time', metavar='YYYY-MM-DD',
                        default=_DEFAULT_START_DATE_STRING,
                        help=('Send cost data to graphite from start-time,'
                              ' specified as a Unix time or YYYY-MM-DD string.'
                              ' (Default: today\'s UTC time at midnight.)'))
    parser.add_argument('--end-time', metavar='YYYY-MM-DD',
                        default=_DEFAULT_END_DATE_STRING,
                        help=('Send cost data to graphite up until end-time,'
                              ' specified as a Unix time or YYYY-MM-DD string.'
                              ' (Default: tomorrow\'s UTC time at midnight.)'))
    args = parser.parse_args()

    main(args.graphite_host, args.start_time, args.end_time,
         dry_run=args.dry_run, verbose=args.verbose)

