#!/usr/bin/env python

"""Send GCE Instance Metrics from the Cloud Compute API to Cloud Monitoring.

GCE Instance Metrics such as instance health are displayed on the cloud console
here: https://console.cloud.google.com/appengine/instances?project=khan-academy
but are not available in Cloud Monitoring. This script collects GCE instance
metrics and sends them to cloud monitoring.

It's expected this script will be run periodically as a cron job every 5
minutes.
"""

import alertlib
import apiclient
import cloudmonitoring_util
import json
import logging


class GCEInstance(object):
    """Simple class to hold gce instance data."""
    def __init__(self, instance_name, zone_name):
        self.instance_name = instance_name
        self.zone_name = zone_name


def _instance_is_failed(serial_port_output, unhealthy_count_threshold):
    """Return true if instance is considered failed.

    Return true if the gce instance is considered to have failed based
    there are at least `unhealthy_count_threshold` lines indicating
    unhealthy instance status in the last 5 * unhealthy_count_threshold
    serial output port lines. The last `5 * x` lines was chosen because not
    every output line is a health check. There can be other messages such as
    heartbeats.
    """
    recent_lines = serial_port_output[-8 * unhealthy_count_threshold:]
    unhealthy_lines = filter(lambda l: 'STATUS=HEALTH_CHECK_UNHEALTHY' in l,
                             recent_lines)
    return len(unhealthy_lines) >= unhealthy_count_threshold


def _get_serial_port_output_lines_from_cloud_compute(service, project_id,
                                                     gce_instance):
    """Return a list of serial port output lines for the gce instance.

    List of serial port output lines for the gce instance is ordered from
    least recent to most recent. With each port output as a separate list
    entry."""
    request = service.instances().getSerialPortOutput(
        project=project_id, zone=gce_instance.zone_name,
        instance=gce_instance.instance_name)
    try:
        response = cloudmonitoring_util.execute_with_retries(request)
    # This can fail, for example when an instance is spinning up.
    except apiclient.errors.HttpError as e:
        return []
    return response['contents'].split('\n')


def _get_instances_matching_name_from_response(instances_list_response,
                                               name_substring):
    """Return a list of GCEInstance objects from an instances response list.

    Returns a list of GCEInstance objects from the cloud compute instances
    response list for all instances whose name includes `name_subtring`. Note
    that this returns only instances matching a name_substring here so that
    the number of instances returned is as small as possible so that the number
    of future operations on each instance (such as a per instance API call) is
    minimized.
    """
    gce_instances = []
    for zone_name, zone in instances_list_response['items'].iteritems():
        zone_name = zone_name.replace('zones/', '')
        for instance in zone.get('instances', {}):
            if name_substring in instance['name']:
                gce_instance = GCEInstance(instance['name'], zone_name)
                gce_instances.append(gce_instance)
    return gce_instances


def _get_instances_list_from_cloud_compute(service, project_id):
    """Get the aggregated GCE instances list via cloud compute API."""
    request = service.instances().aggregatedList(project=project_id)
    response = cloudmonitoring_util.execute_with_retries(request)
    return response


def main(project_id, dry_run):
    service = cloudmonitoring_util.get_cloud_service('compute', 'v1')

    # Get the number of failed GCE instances for each module of interest
    # via the cloud compute API and put it into Stackdriver
    instance_list_response = _get_instances_list_from_cloud_compute(
        service, project_id)

    module_id_to_name_substring = {'react-render': 'gae-react--render',
                                   'vm': 'gae-vm-'}
    for module_id, name_substring in module_id_to_name_substring.iteritems():
        instances = _get_instances_matching_name_from_response(
            instance_list_response, name_substring)

        serial_port_output_lines = [
            _get_serial_port_output_lines_from_cloud_compute(
                service, project_id, instance)
            for instance in instances
        ]

        num_failed_instances = len(
            filter(lambda lines: _instance_is_failed(lines, 5),
                   serial_port_output_lines))

        if dry_run:
            print module_id, num_failed_instances
            continue

        # Send metric to Stackdriver
        metric_name = 'gce.number_failed_instances'
        metric_labels = {'module_id': module_id}
        alert = alertlib.Alert('Instance failure metrics')
        alert.send_to_stackdriver(metric_name, value=num_failed_instances,
                                  metric_labels=metric_labels)

if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--project-id', '-p', default='khan-academy',
                        help=('The cloud-monitoring project-id to fetch '
                              'stats for (Default: %(default)s)'))
    parser.add_argument('-n', '--dry-run', action='store_true', default=False,
                        help='do not write metrics to Cloud Monitoring')
    args = parser.parse_args()
    main(args.project_id, args.dry_run)
