#!/usr/bin/env python

"""Fetch bigquery cost data from bigquery and send it to graphite.

In both projects "khan" and "khan-academy", bigquery cost data lives in:
exported_stackdriver_bigquery_logs.cloudaudit_googleapis_com_data_access_DATE
where DATE is a YYYYMMDD string.

View all data exports here: https://console.cloud.google.com/logs/exports
These were set up using the following guide:
https://cloud.google.com/logging/docs/export/configure_export_v2

We send the cost of the day's queries so far to graphite under the keys
  gcp.<project>.usage.bigquery.daily_query_cost_so_far_usd
where <project> is "khan" or "khan_academy". Note that for consistency, we map
"khanacademy.org:deductive-jet-827" to "khan", as it appears in other metrics.
Similarly, we map 'khan-academy' to 'khan_academy'.

TODO(nabil): the export UI above does not allow for export of other projects;
although they likely do not have large costs, it would be nice to figure out
how to get their data as well, or else confirm that they do not use bigquery.
"""

import datetime
import re
import time

import bq_util
import graphite_util


# Report on today by default
_DEFAULT_DAY = datetime.datetime.utcnow().strftime("%Y%m%d")

# Bigquery query cost is $5 per TB processed
# see: https://cloud.google.com/bigquery/pricing#queries
# TODO(nabil): scrape the cost from the pricing page instead of hardcoding
_COST_PER_BYTE_INVERSE = "(1024 * 1024 * 1024 * 1024 / 5)"


def get_bigquery_costs(date, projects=['khanacademy.org:deductive-jet-827',
                                       'khan-academy']):
    """Return the aggregate cost of bigquery queries on the given date.

    date is expected to be given as a YYYYMMDD string.

    Returns a dict which maps the project name to cost for the given date.

    Note that project 'khanacademy.org:deductive-jet-827' is renamed 'khan'
    for consistency with other metrics. Example return value:
    {'khan_academy': 0,
     'khan': 63.15}
    """

    field_name = ("protopayload_google_cloud_audit_auditlog."
                 "servicedata_google_cloud_bigquery_logging_v1_auditdata."
                 "jobCompletedEvent.job.jobStatistics.totalBilledBytes")

    table_name = ("exported_stackdriver_bigquery_logs"
                 ".cloudaudit_googleapis_com_data_access_%s") % date

    data = {}

    # TODO(nabil): figure out how to deal with tiered billing.
    # See https://cloud.google.com/bigquery/pricing#high-compute
    # It seems likely that we simply multiply by this field when non-null:
    # `protopayload_google_cloud_audit_auditlog.
    # servicedata_google_cloud_bigquery_logging_v1_auditdata.
    # jobCompletedEvent.job.jobStatistics.billingTier`
    # However, there are no non-null examples, so it would be good to confirm.
    for project in projects:
        query = ("SELECT SUM(%s) / %s as daily_query_cost_so_far_usd "
                "FROM [%s]") % (field_name, _COST_PER_BYTE_INVERSE, table_name)

        raw_data = bq_util.query_bigquery(query, project=project)
        cost = raw_data[0]['daily_query_cost_so_far_usd']

        # if we didn't find anything, send a cost of 0
        # see bq_util.query_bigquery for why this is not just None
        if cost == '(None)':
            cost = 0

        # rename this 'khan' for consistency with other metrics
        # attempting to look up biquery data under 'khan' fails, however
        if project == 'khanacademy.org:deductive-jet-827':
            project = 'khan'

        # replace characters that may interfere with searching for metrics at:
        # https://www.hostedgraphite.com/app/metrics/#
        project = re.sub(r'[^A-Za-z0-9_.]', '_', project)

        data[project] = cost

    return data


def format_for_graphite(project_to_cost_so_far):
    """Given a map of projects to bq query costs, return data for graphite.

    Returns an array of (graphite_key, (unix_time, cost)) tuples.
    """
    now = int(time.time())
    graphite_key_template = "gcp.%s.usage.bigquery.daily_query_cost_so_far_usd"
    data = []
    for project, cost in project_to_cost_so_far.iteritems():
        data.append((graphite_key_template % project, (now, cost)))

    return data


def main(graphite_host, date, verbose=False, dry_run=False):
    project_to_cost_so_far = get_bigquery_costs(date)
    graphite_data = format_for_graphite(project_to_cost_so_far)

    if dry_run:
        graphite_host = None      # so we don't actually send to graphite
        verbose = True            # print what we would have done

    if verbose:
        if graphite_host:
            print "--> Sending to graphite:"
        else:
            print "--> Would send to graphite:"
        print graphite_data

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
    parser.add_argument('--date', metavar='YYYYMMDD',
                        default=_DEFAULT_DAY,
                        help=('Send bigquery cost data to graphite for the '
                              'given date, specified as YYYYMMDD.'
                              '(Default: today\'s UTC date.)'))
    args = parser.parse_args()

    main(args.graphite_host, args.date,
         dry_run=args.dry_run, verbose=args.verbose)
