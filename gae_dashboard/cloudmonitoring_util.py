"""Utility functions for sending data to Cloud Monitoring.

Cloud Monitoring monitors and alerts based on timeseries data for App
Engine and Compute Engine. We also send it custom metrics based on
data in graphite, our timeseries-graphing tool.
"""

import calendar
import json
import httplib
import os
import re
import socket
import time

import apiclient.discovery
import apiclient.errors
import httplib2
import oauth2client.client


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


def custom_metric(name):
    """Make a metric suitable for sending to Cloud Monitoring's API.

    Metric names must not exceed 100 characters.

    It's not clear to me (chris) what the naming requirements are, so
    for now we limit to alphanumeric plus dot and underscore. Invalid
    characters are converted to underscores.
    """
    # Don't guess at automatic truncation. Let the caller decide.
    prefix = 'custom.googleapis.com/'
    maxlen = 100 - len(prefix)
    if len(name) > maxlen:
        raise ValueError('Metric name too long: %d (limit %d): %s'
                         % (len(name), maxlen, name))
    return ('%s%s' % (prefix, re.sub('[^a-zA-Z0-9_.]', '_', name)))


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
            elif (code == 400 and
                      'Timeseries data must be more recent' in str(e)):
                # This error just means we uploaded the same data
                # twice by accident (probably because the first time
                # the connection to google died before we got their ACK).
                # We just pretend the call magically succeeded.
                return
            else:
                # cloud-monitoring API seems to put more content in 'content'
                if hasattr(e, 'content'):
                    print 'CLOUD-MONITORING ERROR: %s' % e.content
                raise
        time.sleep(0.5)     # wait a bit before the next request


def get_cloudmonitoring_service():
    # Load the private key that we need to write data to Cloud
    # Monitoring. This will (properly) raise an exception if this file
    # isn't installed (it's acquired from the Cloud Platform Console).
    with open(os.path.expanduser('~/cloudmonitoring_secret.json')) as f:
        json_key = json.load(f)

    def get_service():
        credentials = oauth2client.client.SignedJwtAssertionCredentials(
            json_key['client_email'], json_key['private_key'],
            'https://www.googleapis.com/auth/monitoring')
        http = credentials.authorize(httplib2.Http())
        return apiclient.discovery.build(serviceName="monitoring",
                                         version="v3", http=http)

    return _call_with_retries(get_service)


def execute_with_retries(request, num_retries=9):
    """Run request.execute() up to 9 times for non-fatal errors."""
    return _call_with_retries(request.execute, num_retries=num_retries)


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
    service = get_cloudmonitoring_service()

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
