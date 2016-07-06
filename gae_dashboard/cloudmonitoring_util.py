"""Utility functions for sending data to Cloud Monitoring.

Cloud Monitoring monitors and alerts based on timeseries data for App
Engine and Compute Engine. We also send it custom metrics based on
data in graphite, our timeseries-graphing tool.
"""

import calendar
import json
import httplib
import logging
import os
import re
import socket
import time

import apiclient.discovery
import apiclient.errors
import httplib2
import oauth2client.client

import alertlib


def to_rfc3339(time_t):
    """Format a time_t in seconds since the UNIX epoch per RFC 3339."""
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(time_t))


def from_rfc3339(iso_string):
    """Parse a time-string in RFC3339 into a time_t."""
    # This is suprisingly hard to get right, since strptime assumes
    # the local timezone by default.  I use a technique from
    # http://aboutsimon.com/2013/06/05/datetime-hell-time-zone-aware-to-unix-timestamp/
    time_t = time.strptime(re.sub(r'\.\d{3}Z$', 'GMT', iso_string),
                           '%Y-%m-%dT%H:%M:%S%Z')
    return calendar.timegm(time_t)


def _call_with_retries(fn, num_retries=9):
    """Run fn (a network command) up to 9 times for non-fatal errors."""
    for i in xrange(num_retries + 1):     # the last time, we re-raise
        try:
            return fn()
        except (socket.error, httplib.HTTPException,
                oauth2client.client.Error):
            if i == num_retries:
                raise
            pass
        except apiclient.errors.HttpError as e:
            if i == num_retries:
                raise
            code = int(e.resp['status'])
            if code == 403 or code >= 500:     # 403: rate-limiting probably
                pass
            else:
                # cloud-monitoring API seems to put more content in 'content'
                if hasattr(e, 'content'):
                    print 'CLOUD-MONITORING ERROR: %s' % e.content
                raise
        time.sleep(0.5)     # wait a bit before the next request


def get_cloud_service(service_name, version_number):
    # Load the private key that we need to get data from Cloud compute.
    # This will (properly) raise an exception if this file
    # isn't installed (it's acquired from the Cloud Platform Console).
    with open(os.path.expanduser('~/cloudmonitoring_secret.json')) as f:
        json_key = json.load(f)

    def get_service():
        credentials = oauth2client.client.SignedJwtAssertionCredentials(
            json_key['client_email'], json_key['private_key'],
            'https://www.googleapis.com/auth/%s' % service_name)
        http = credentials.authorize(httplib2.Http())
        return apiclient.discovery.build(serviceName=service_name,
                                         version=version_number, http=http)

    return _call_with_retries(get_service)


def execute_with_retries(request, num_retries=9):
    """Run request.execute() up to 9 times for non-fatal errors."""
    return _call_with_retries(request.execute, num_retries=num_retries)


def send_timeseries_to_cloudmonitoring(google_project_id, data, dry_run=False):
    """data is a list of 4tuples: (metric-name, metric-labels, value, time)."""
    # alertlib is set up to only send one timeseries per call.  But we
    # want to send all the timeseries in a single call, so we have to
    # do some hackery.
    timeseries_data = []
    alert = alertlib.Alert('gae-dashboard metrics')
    old_send_datapoints = alert.send_datapoints_to_stackdriver
    alert.send_datapoints_to_stackdriver = lambda timeseries, *a, **kw: (
        timeseries_data.extend(timeseries))

    # This will put the data into timeseries_data but not actually send it.
    try:
        for (metric_name, metric_labels, value, time_t) in data:
            logging.info("Sending %s (%s) %s %s",
                         metric_name, metric_labels, value, time_t)
            alert.send_to_stackdriver(metric_name, value,
                                      metric_labels=metric_labels,
                                      project=google_project_id,
                                      when=time_t)
    finally:
        alert.send_datapoints_to_stackdriver = old_send_datapoints

    # Now we do the actual send.
    logging.debug("Sending to stackdriver: %s", timeseries_data)
    if timeseries_data and not dry_run:
        alert.send_datapoints_to_stackdriver(timeseries_data,
                                             project=google_project_id)

    return len(timeseries_data)
