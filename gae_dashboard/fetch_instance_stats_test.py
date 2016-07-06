import unittest

import fetch_instance_stats


class TestInstanceIsFailed(unittest.TestCase):
    def test_unhealthy_over_threshold_returns_true(self):
        serial_port_output = 'STATUS=HEALTH_CHECK_UNHEALTHY\n' * 10
        serial_port_output_lines = serial_port_output.split('\n')
        unhealthy_count_threshold = 5
        self.assertTrue(fetch_instance_stats._instance_is_failed(
            serial_port_output_lines, unhealthy_count_threshold))

    def test_unhealthy_under_threshold_returns_false(self):
        serial_port_output = 'STATUS=HEALTH_CHECK_UNHEALTHY\n' * 4
        serial_port_output_lines = serial_port_output.split('\n')
        unhealthy_count_threshold = 5
        self.assertFalse(fetch_instance_stats._instance_is_failed(
            serial_port_output_lines, unhealthy_count_threshold))

    def test_not_unhealthy_returns_false(self):
        serial_port_output = 'STATUS=HEALTH_CHECK_HEALTHY\n' * 10
        serial_port_output_lines = serial_port_output.split('\n')
        unhealthy_count_threshold = 5
        self.assertFalse(fetch_instance_stats._instance_is_failed(
            serial_port_output_lines, unhealthy_count_threshold))

    def test_unhealthy_long_time_ago_returns_false(self):
        serial_port_output = 'STATUS=HEALTH_CHECK_UNHEALTHY\n' * 10
        serial_port_output += 'newline\n' * 100
        serial_port_output_lines = serial_port_output.split('\n')
        unhealthy_count_threshold = 5
        self.assertFalse(fetch_instance_stats._instance_is_failed(
            serial_port_output_lines, unhealthy_count_threshold))


class TestGetInstancesFromResponse(unittest.TestCase):
    def test_returns_only_instances_matching_name(self):
        instance_list_response = {
            'items':
                {
                    'zones/1': {
                        'instances': [
                            {'name': 'instance1'}, {'name': 'instance2'},
                            {'name': 'e'}
                        ]
                    }
                }
        }
        name_substring = 'instance'
        instances = (fetch_instance_stats.
                     _get_instances_matching_name_from_response(
                         instance_list_response,
                         name_substring))
        self.assertEqual(2, len(instances))
        self.assertTrue(all('instance' in instance.instance_name
                        for instance in instances))


class TestMain(unittest.TestCase):
    def setUp(self):
        self.sent_to_cloud_monitoring = {}
        self.mock_origs = {}   # used to unmock if needed

        def new_send_to_stackdriver(alert, metric_name, value=1,
                                    metric_labels=None):
            module_id = metric_labels['module_id']
            self.sent_to_cloud_monitoring[module_id] = (metric_name, value)

        def new_get_instances_list_from_cloud_compute(service, project_id):
            instance_list_response = {
                'items':
                    {
                        'zones/1': {
                            'instances': [
                                {'name': 'gae-react--render-instance1'},
                                {'name': 'gae-react--render-instance2'},
                                {'name': 'err1'}, {'name': 'err2'}
                            ]
                        }
                    }
            }
            return instance_list_response

        def new_get_serial_port_output_lines_from_cloud_compute(service,
                                                                project_id,
                                                                gce_instance):
            if 'instance' in gce_instance.instance_name:
                serial_port_output = 'STATUS=HEALTH_CHECK_UNHEALTHY\n' * 10
                serial_port_output_lines = serial_port_output.split('\n')
                return serial_port_output_lines

            serial_port_output = 'STATUS=HEALTH_CHECK_UNHEALTHY\n' * 2
            serial_port_output_lines = serial_port_output.split('\n')
            return serial_port_output_lines

        self.mock(fetch_instance_stats.alertlib.Alert, 'send_to_stackdriver',
                  new_send_to_stackdriver)
        self.mock(fetch_instance_stats,
                  '_get_instances_list_from_cloud_compute',
                  new_get_instances_list_from_cloud_compute)
        self.mock(fetch_instance_stats,
                  '_get_serial_port_output_lines_from_cloud_compute',
                  new_get_serial_port_output_lines_from_cloud_compute)

    def mock(self, container, var_str, new_value):
        if hasattr(container, var_str):
            oldval = getattr(container, var_str)
            self.mock_origs[(container, var_str)] = oldval
            self.addCleanup(lambda: setattr(container, var_str, oldval))
        else:
            self.mock_origs[(container, var_str)] = None
            self.addCleanup(lambda: delattr(container, var_str))
        setattr(container, var_str, new_value)

    def test_sent_errors_to_stackdriver(self):
        """Mock out the appropriate functions, make a list of what would be
        sent to Stackdriver"""

        fetch_instance_stats.main('proj_id', False)
        self.assertEqual({'react-render': ('gce.failed_instance_count', 2),
                          'vm': ('gce.failed_instance_count', 0)},
                         self.sent_to_cloud_monitoring)

if __name__ == '__main__':
    unittest.main()
