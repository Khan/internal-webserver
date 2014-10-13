#!/usr/bin/env python

"""Outputs a .json file for display in in-browser charts.

The format of the JSON file is meant to be easily consumed by HighCharts.

See: http://api.highcharts.com/highcharts

To see how this data is consumed, check out gae_dashboard/index.html
"""

import argparse
import collections
import datetime
import json
import os
import time

import bq_util

# Report on the previous day by default
_DEFAULT_DAY = datetime.datetime.now() - datetime.timedelta(1)

_OUTPUT_FILENAME = os.path.join(os.path.join(os.path.dirname(__file__)),
                                'webroot', 'perf_chart_data.json')


# Tuples of (chart title, elog_url_route) for the resource being tracked for
# latency.
LATENCY_QUERIES = (
    ("practice_task_start_latency",
        'api.main:/api/internal/user/task/practice/'
            '(?P<exercise_name>[^/]{1,})'),
    ("mastery_task_start_latency",
        'api.main:/api/internal/user/task/mastery'),
    ("booster_task_start_latency",
        'api.main:/api/internal/user/task/booster'),
    ("problem_attempt_latency",
        'api.main:/api/internal/user/exercises/'
            '(?P<exercise_name>[^/]{1,})/problems/'
            '(?P<problem_number>\\\\d+)/attempt [POST]'),
    ("profile_widgets_latency",
        'api.main:/api/internal/user/(?P<user_data_key>[^/]{1,})/'
            'profile/widgets'),
    ("user_badges_latency",
        'api.main:/api/internal/user/badges'),
    ("user_mission_latency",
        'api.main:/api/internal/user/mission/(?P<mission_slug>[^/]{1,})'),
    ("user_mission_switch_latency",
        'api.main:/api/internal/user/mission/'
            '(?P<mission_slug>[^/]{1,})/switch [POST]'),
    ("cs_browse_top_latency",
        'api.main:/api/internal/scratchpads/top'),
    ("get_feedback_for_focus_latency",
        'api.main:/api/internal/discussions/(?P<focus_kind>[^/]{1,})/'
            '(?P<focus_id>[^/]{1,})/(?P<feedback_type>[^/]{1,})')
)

LATENCY_QUERY_PATTERN = """
SELECT
  COUNT(*) as count,
  NTH(10, QUANTILES(latency)) as latency_10th,
  NTH(50, QUANTILES(latency)) as latency_50th,
  NTH(90, QUANTILES(latency)) as latency_90th,
  NTH(95, QUANTILES(latency)) as latency_95th,
  NTH(99, QUANTILES(latency)) as latency_99th,
FROM [logs.requestlogs_%(yyyymmdd)s]
WHERE status=200 AND elog_url_route="%(elog_url_route)s"
"""


def get_series_data(report, query_pattern, elog_url_route,
                    end_date, preceding_days=14):
    """Get the data for preceding_days days before end_date and end_date.

    This means that for preceding_days=n, this returns a list of n+1 data
    points.

    Returned as a dict of column name in the query to a list of
    (datetime, value) pairs of that column over time.
    """
    series = collections.defaultdict(list)
    for i in xrange(-preceding_days, 1):
        old_date = end_date + datetime.timedelta(days=i)
        old_yyyymmdd = old_date.strftime('%Y%m%d')

        query = query_pattern % {
            'yyyymmdd': old_yyyymmdd,
            'elog_url_route': elog_url_route
        }

        daily_data = bq_util.get_daily_data_from_disk_or_bq(query, report,
                                                            old_yyyymmdd)
        assert len(daily_data) == 1, daily_data

        for key, val in daily_data[0].iteritems():
            series[key].append((old_date, val))

    return dict(series)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--date', metavar='YYYYMMDD',
                        default=_DEFAULT_DAY.strftime("%Y%m%d"),
                        help=('Date to get reports for, specified as YYYYMMDD '
                              '(default "%(default)s")'))
    args = parser.parse_args()
    date = datetime.datetime.strptime(args.date, "%Y%m%d")

    charts_data = []

    for report, elog_url_route in LATENCY_QUERIES:
        series_data = get_series_data(report, LATENCY_QUERY_PATTERN,
                                      elog_url_route, date)

        count_by_day = {}
        for (dt, val) in series_data.pop('count'):
            count_by_day[dt] = val

        # We transform the series data into a format compatible with HighCharts
        charts_data.append({
            'chart': {
                'zoomType': 'x',
            },
            'title': {
                'text': report
            },
            'subtitle': {
                'text': elog_url_route
            },
            'xAxis': {
                'type': 'datetime'
            },
            'yAxis': {
                'title': {
                    'text': 'Latency in Seconds'
                }
            },
            'series': [{
                # JS timestamps are in milliseconds, not seconds
                'data': [{
                    'x': time.mktime(dt.timetuple()) * 1000,
                    'y': 0 if val == '(None)' else val,
                    'name': 'Total count for day: %d' % count_by_day[dt]
                } for (dt, val) in values],
                'name': column_name
            } for (column_name, values) in sorted(series_data.iteritems())]
        })

    output_data = {
        'charts': charts_data
    }

    if not os.path.isdir(os.path.dirname(_OUTPUT_FILENAME)):
        os.makedirs(os.path.dirname(_OUTPUT_FILENAME))

    with open(_OUTPUT_FILENAME, 'w') as f:
        json.dump(output_data, f, sort_keys=True,
                  indent=4, separators=(',', ': '))


if __name__ == '__main__':
    main()
