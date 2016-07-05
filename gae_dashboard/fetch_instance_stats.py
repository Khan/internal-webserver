import alertlib
import cloudmonitoring_util
import json


def _get_num_failed_matches(response, name_substring):
    num_failed = 0
    for zone_name, zone in response['items'].iteritems():
        for instance in zone.get('instances', {}):
            if (name_substring in instance['name'] and
                    instance['status'] == 'TERMINATED'):
                num_failed += 1
    return num_failed


def _get_from_cloud_compute(service, project_id):
    request = service.instances().aggregatedList(project=project_id)
    response = cloudmonitoring_util.execute_with_retries(request)
    return response


def _send_num_failed_instances_to_cloud_monitoring(num_failed_instances,
                                                   module_id):
    metric_name = 'gce.number_failed_instances'
    metric_labels = {'module_id': module_id}
    alert = alertlib.Alert('Instance failure metrics')
    alert.send_to_stackdriver(metric_name, metric_labels=metric_labels)

if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--project-id', '-p', default='khan-academy',
                        help=('The cloud-monitoring project-id to fetch '
                              'stats for (Default: %(default)s)'))
    args = parser.parse_args()

    service = cloudmonitoring_util.get_cloud_service('compute', 'v1')
    response = _get_from_cloud_compute(service, args.project_id)

    num_react_render_failed = _get_num_failed_matches(response,
                                                      'gae-react--render')
    num_vm_failed = _get_num_failed_matches(response, 'gae-vm-')

    _send_num_failed_instances_to_cloud_monitoring(num_react_render_failed,
                                                   'react-render')
    _send_num_failed_instances_to_cloud_monitoring(num_vm_failed,
                                                   'vm')
