#!/usr/bin/env python

"""Fetch usage stats from cloudstorage and send them to graphite.

These stats are the information used for billing, but is not the
(monthly) billing info itself.  We have this usage info set to
auto-export to cloud storage once a day (the usage stats
are per-day).  Records are sent to graphite under the keys
   webapp.gcp.<project>.usage.<service>.<stat>.seconds  # or bytes, etc
   webapp.gcp.<project>.usage.<service>.<stat>.cost     # in USD
   webapp.gcp.<project>.usage.<service>.<stat>.credit   # in USD

<service> is, e.g., 'appengine' or 'computeengine'.  'stat' is
something like 'backend_instances'.
"""

import cStringIO
import calendar
import csv
import datetime
import json
import os
import re
import time

import apiclient.discovery
import apiclient.errors
import httplib2
import oauth2client.client

import graphite_util


_LAST_RECORD_DB = os.path.expanduser('~/dashboard_usage_date.db')


class UsageRecordNotFound(Exception):
    pass


def _date_of_latest_record():
    """date of the most recently stored usage record, as a YYYY-MM-DD string.

    This data is stored in a file.  We could consider this a small database.
    """
    if os.path.exists(_LAST_RECORD_DB):
        with open(_LAST_RECORD_DB) as f:
            return f.read().strip()
    return None


def _write_date_of_latest_record(date_string):
    """Update with the last YYYY-MM-DD we successfully recorded stats for."""
    with open(_LAST_RECORD_DB, 'w') as f:
        print >>f, date_string


def _get_service():
    # Load the private key that we need to write data to Cloud
    # Monitoring. This will (properly) raise an exception if this file
    # isn't installed (it's acquired from the Cloud Platform Console).
    with open(os.path.expanduser('~/cloudmonitoring_secret.json')) as f:
        json_key = json.load(f)

    credentials = oauth2client.client.SignedJwtAssertionCredentials(
        json_key['client_email'], json_key['private_key'],
        'https://www.googleapis.com/auth/devstorage.read_only')
    http = credentials.authorize(httplib2.Http())
    return apiclient.discovery.build(serviceName="storage",
                                     version="v1", http=http)


def _get_usage_info(service, date, verbose=False):
    """date should be a YYYY-MM-DD string.  Returns a list of dicts."""
    # The info we want is at, e.g.
    # https://console.developers.google.com/m/cloudstorage/b/ka_billing_export/o/khanacademy.org-2015-07-27.csv
    bucketname = 'ka_billing_export'
    filename = 'khanacademy.org-%s.csv' % date
    if verbose:
        print 'Fetching %s from bucket %s' % (filename, bucketname)
    try:
        csv_contents = service.objects().get_media(
            bucket=bucketname, object=filename).execute()
    except apiclient.errors.HttpError as e:
        if e.resp['status'] == '404':
            raise UsageRecordNotFound(date)
        raise

    return list(csv.DictReader(cStringIO.StringIO(csv_contents)))


def _parse_date(time_string):
    """The time_string looks like "2015-07-26T00:00:00-07:00"."""
    (iso_string, tz_offset) = (time_string[:-6], time_string[-6:])
    tm = time.strptime(iso_string + 'GMT', '%Y-%m-%dT%H:%M:%S%Z')
    time_t = calendar.timegm(tm)
    # Apply the tz offset ourself.
    time_t -= int(tz_offset[:3]) * 3600 + int(tz_offset[4:]) * 60
    # We want the time to be the *end time* -- the very end of the
    # day.  We do 11:59:59.
    return time_t + 24 * 60 * 60 - 1


def _line_item_to_graphite_name(line_item):
    # The CSV file has line-items like
    # com.google.cloud/services/app-engine/BackendInstances'
    # com.google.cloud/services/app-engine/SSL_SNIs
    (_, service, name) = line_item.rsplit('/', 2)
    service = service.replace('-', '')       # app-engine -> appengine
    name = name.replace('-', '_')
    # We replace CamelCase with underscores.
    name = re.sub(r'([a-z0-9])([A-Z])',
                  lambda m: m.group(1) + '_' + m.group(2).lower(),
                  name)
    name = name.lower()     # replace SSL -> ssl
    return '%s.%s' % (service, name)


def _project_number_to_id(project_number):
    """CSV files say 'Project': '1299732...'.  We want 'khan-academy'."""
    # TODO(csilvers): figure out the API for this, rather than hard-coding.
    # I got this by going to
    #    https://console.developers.google.com/project
    # and clicking on each of the project-names in turn.  This took me
    # to the overview page, where the project number is at the
    # top of the page.
    return {
        '124072386181': 'khan',
        '179486897809': 'khan-academy',
        '191764748492': 'khan-snippets',
        '399312203402': 'Khan Webhooks',
        '408733179346': 'khan-academy-test',
        '823754693112': 'weekly-snippets-hrd',
        '845400056859': 'ka-spdytest',
    }.get(project_number, project_number)


def _parse_one_record(record):
    """Given a csv line, return a list of (key, (time_t, count)) pairs."""
    time_t = _parse_date(record['Start Time'])
    project_id = _project_number_to_id(record['Project']).lower().replace(
        '-', '_').replace(' ', '_')
    key_suffix = _line_item_to_graphite_name(record['Line Item'])
    key_prefix = 'webapp.gcp.%s.usage.%s' % (project_id, key_suffix)

    try:
        count = int(record['Measurement1 Total Consumption'] or 0)
    except ValueError:
        count = float(record['Measurement1 Total Consumption'])
    count_suffix = record['Measurement1 Units'].lower().replace('-', '_')
    cost = float(record['Cost'] or 0)
    credit = float(record.get('Credit1 Amount') or 0)

    return [(key_prefix + '.' + count_suffix, (time_t, count)),
            (key_prefix + '.cost', (time_t, cost)),
            (key_prefix + '.credit', (time_t, credit)),
    ]


def get_and_parse_usage_info(service, date_string, graphite_host,
                             dry_run=False, verbose=False):
    usage_info = _get_usage_info(service, date_string)

    graphite_data = []
    for record in usage_info:
        graphite_data.extend(_parse_one_record(record))

    if dry_run:
        graphite_host = None      # so we don't actually send to graphite
        verbose = True            # print what we would have done

    if verbose:
        if graphite_host:
            print "--> Sending to graphite:"
        else:
            print "--> Would send to graphite:"
        print '\n'.join(str(v) for v in graphite_data)
    graphite_util.send_to_graphite(graphite_host, graphite_data)


def main(graphite_host, verbose=False, dry_run=False):
    service = _get_service()

    last_write_date = _date_of_latest_record()
    if last_write_date:
        start_date = (datetime.datetime.strptime(last_write_date, "%Y-%m-%d") +
                      datetime.timedelta(days=1))
    else:
        start_date = datetime.datetime.now() - datetime.timedelta(days=30)

    # We keep reading records until we run out.
    while True:
        date_string = start_date.strftime("%Y-%m-%d")
        try:
            get_and_parse_usage_info(service, date_string, graphite_host,
                                     dry_run=dry_run, verbose=verbose)
        except UsageRecordNotFound:
            break

        print "Parsed usage records for %s" % date_string
        if not dry_run:
            _write_date_of_latest_record(date_string)
        start_date += datetime.timedelta(days=1)

    print "Done!"


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
    args = parser.parse_args()

    main(args.graphite_host, dry_run=args.dry_run, verbose=args.verbose)
