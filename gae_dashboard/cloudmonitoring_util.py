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
<<<<<<< 699d76d4e71fc840c1847a25a45c49ccb12a562a
=======
            elif (code == 400 and
                    'Timeseries data must be more recent' in str(e)):
                # This error just means we uploaded the same data
                # twice by accident (probably because the first time
                # the connection to google died before we got their ACK).
                # We just pretend the call magically succeeded.
                return
>>>>>>> Added fetch_instance_stats to get the number of failed vm instances.
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


<<<<<<< 699d76d4e71fc840c1847a25a45c49ccb12a562a
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
=======
# TODO(csilvers): rewrite to use alertlib's send_to_stackdriver()?
def send_to_cloudmonitoring(project_id, metric_map):
    """Send lightweight metrics to the Cloud Monitoring API.

    This required $HOME/cloudmonitoring_secret.json exist and hold the
    JSON credentials for a Google Cloud Platform service account. See
    aws-config/toby/setup.sh.

    Arguments:
        project_id: project ID of a Google Cloud Platform project
            with the Cloud Monitoring API enabled.
        metric_map: dict mapping each metric timeseries name to a list
            of one datapoint.  Each datapoint is a (value, timestamp)
            2-tuple. For example, { "metric": [(0.123, 1428603130)] }.

    """
    service = get_cloud_service('monitoring', 'v3')

    # Using what the Cloud Monitoring API calls lightweight metrics,
    # we can only send one data point per write request. That's OK for
    # now, since we only have a few series.
    for name, datapoints in metric_map.iteritems():
        assert len(datapoints) == 1, datapoints  # ensure one point per metric
        value, timestamp = datapoints[0]
        # TODO(chris): use labeled metrics and batch sending datapoints.
        # Documentation for this is at
        #   https://developers.google.com/resources/api-libraries/documentation/monitoring/v3/python/latest/monitoring_v3.projects.timeSeries.html
        write_request = service.projects().timeSeries().create(
            name='projects/%s' % project_id,
            body={
                'timeSeries': [{
                    'metricKind': 'gauge',
                    'metric': {
                        'type': custom_metric(name),
                    },
                    'points': [{
                        'interval': {
                            'startTime': to_rfc3339(timestamp),
                            'endTime': to_rfc3339(timestamp),
                        },
                        'value': {
                            'doubleValue': value,
                        }
                    }]
                }]
            })
        _ = execute_with_retries(write_request)  # ignore the response
>>>>>>> Added fetch_instance_stats to get the number of failed vm instances.
