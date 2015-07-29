"""Utility functions for sending data to Cloud Monitoring.

Cloud Monitoring monitors and alerts based on timeseries data for App
Engine and Compute Engine. We also send it custom metrics based on
data in graphite, our timeseries-graphing tool.
"""

import calendar
import json
import os
import re
import time

import apiclient.discovery
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
    time_t = time.strptime(iso_string.replace('Z', 'GMT').replace('.000', ''),
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
    prefix = 'custom.cloudmonitoring.googleapis.com/'
    maxlen = 100 - len(prefix)
    if len(name) > maxlen:
        raise ValueError('Metric name too long: %d (limit %d): %s'
                         % (len(name), maxlen, name))
    return ('%s%s' % (prefix, re.sub('[^a-zA-Z0-9_.]', '_', name)))


def get_cloudmonitoring_service():
    # Load the private key that we need to write data to Cloud
    # Monitoring. This will (properly) raise an exception if this file
    # isn't installed (it's acquired from the Cloud Platform Console).
    with open(os.path.expanduser('~/cloudmonitoring_secret.json')) as f:
        json_key = json.load(f)

    credentials = oauth2client.client.SignedJwtAssertionCredentials(
        json_key['client_email'], json_key['private_key'],
        'https://www.googleapis.com/auth/monitoring')
    http = credentials.authorize(httplib2.Http())
    service = apiclient.discovery.build(serviceName="cloudmonitoring",
                                        version="v2beta2", http=http)
    return service


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
        write_request = service.timeseries().write(
            project=project_id,
            body={
                'timeseries': [{
                    'timeseriesDesc': {'project': project_id,
                                       'metric': custom_metric(name)},
                    'point': {'start': to_rfc3339(timestamp),
                              'end': to_rfc3339(timestamp),
                              'doubleValue': value}
                }]
            })
        _ = write_request.execute()  # ignore the response
