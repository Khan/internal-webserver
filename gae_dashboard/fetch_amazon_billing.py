#!/usr/bin/env python

"""Fetch stats about amazon billing and send them to graphite.

We get the billing numbers from S3's ka-aws-billing-reports bucket.
Records are sent to graphite under the keys
   aws.billing.dashboard.<product_code>
<product_code> is e.g. 'AWSDataTransfer', 'AmazonEC2', 'AmazonS3'.
"""

import collections
import csv
import datetime
import time

import boto.s3.connection

import graphite_util
import secrets


class S3FileNotFound(Exception):
    pass


def get_csv_contents_from_s3(month=None, year=None):
    """Get Amazon billing data from S3 as an array of strings.

    By default, we retrieve the billing data of the current month and year.
    """
    now = datetime.datetime.now()

    year = str(year or now.year)
    # AWS naming convention: leading zero for single-digit months
    month = str(month or now.month).zfill(2)

    # 759597320137 is KA's internal AWS "PayerAccountId". This field appears in
    # every row of each billing CSV, as well as the names of S3 objects.
    csv_key = "759597320137-aws-billing-csv-%s-%s.csv" % (year, month)

    # TODO(nabil[2016-12-23]): generate s3 key, use it below and in secrets.py
    conn = boto.s3.connection.S3Connection(
        secrets.youtube_export_s3_access_key,
        secrets.youtube_export_s3_secret_key)
    billing_bucket = conn.get_bucket('ka-aws-billing-reports')
    value = billing_bucket.get_key(csv_key)

    if value is None:
        raise S3FileNotFound(csv_key)

    return value.get_contents_as_string().splitlines()


def collate_data(csv_file_contents):
    """Given the contents of an Amazon billing csv, return data for graphite.

    The input `csv_file_contents` is expected to be an array of strings.
    The return value is a list of tuples suitable for sending to graphite,
    of the form (product_code, (unix_time, cost of AWS product)).
    """
    now = int(time.time())
    total_cost_dict = collections.defaultdict(lambda: 0, {})

    reader = csv.DictReader(csv_file_contents)
    for row in reader:
        product_code = row['ProductCode']
        row_cost = row['TotalCost']

        if product_code and row_cost:
            total_cost_dict[product_code] += float(row_cost)

    return [("aws.billing.dashboard.%s" % product_code, (now, cost))
            for product_code, cost in total_cost_dict.iteritems()]


def main(graphite_host, month=None, year=None, verbose=False, dry_run=False):
    csv_file_contents = get_csv_contents_from_s3(month=month, year=year)
    graphite_data = collate_data(csv_file_contents)

    if dry_run:
        graphite_host = None      # so we don't actually send to graphite
        verbose = True            # print what we would have done

    if verbose:
        if graphite_host:
            print "--> Sending to graphite:"
        else:
            print "--> Would send to graphite:"
        print '\n'.join(str(v) for v in graphite_data)

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
    parser.add_argument('--month', '-m',
                        help=('Send data to graphite for the given month.'
                              '(Default: current month.)'))
    parser.add_argument('--year', '-y',
                        help=('Send data to graphite for the given year.'
                              '(Default: current year.)'))
    args = parser.parse_args()

    main(args.graphite_host, month=args.month, year=args.year,
         dry_run=args.dry_run, verbose=args.verbose)
