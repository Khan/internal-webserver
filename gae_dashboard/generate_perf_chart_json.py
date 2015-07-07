#!/usr/bin/env python

"""Outputs a .json file for display in in-browser charts.

The format of the JSON file is meant to be easily consumed by HighCharts.

See: http://api.highcharts.com/highcharts

To see how this data is consumed, check out gae_dashboard/index.html
"""

import argparse
import cgi
import collections
import datetime
import json
import os
import time

import bq_util

# Report on the previous day by default
_DEFAULT_DAY = datetime.datetime.now() - datetime.timedelta(1)

_OUTPUT_FILENAME_PATTERN = os.path.join(os.path.dirname(__file__), 'webroot',
    'perf_chart_data%(suffix)s.json')

# Breakdown metrics by different country codes. 'world' is a special value
# which does not specify a country breakdown (aggregates across all countries).
_DEFAULT_COUNTRIES = ['world', 'CN']

# Determined with this BigQuery:
# SELECT elog_country, count(*) as cnt
# FROM [logs.requestlogs_20150627]
# group by elog_country
# order by cnt desc
_VALID_COUNTRIES = [
    'world',
    'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AN', 'AO', 'AR', 'AS', 'AT',
    'AU', 'AW', 'AX', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI',
    'BJ', 'BM', 'BN', 'BO', 'BR', 'BS', 'BT', 'BU', 'BW', 'BY', 'BZ', 'CA',
    'CD', 'CF', 'CG', 'CH', 'CI', 'CL', 'CM', 'CN', 'CO', 'CR', 'CV', 'CW',
    'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG', 'ES',
    'ET', 'FI', 'FJ', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF', 'GG',
    'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GT', 'GU', 'GW', 'GY',
    'HK', 'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IQ', 'IS',
    'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KN', 'KR', 'KW', 'KY',
    'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY',
    'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MN', 'MO', 'MP',
    'MQ', 'MR', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE',
    'NG', 'NI', 'NL', 'NO', 'NP', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH',
    'PK', 'PL', 'PR', 'PS', 'PT', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW',
    'SA', 'SB', 'SC', 'SE', 'SG', 'SI', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR',
    'SS', 'SV', 'SX', 'SZ', 'TC', 'TD', 'TG', 'TH', 'TJ', 'TL', 'TM', 'TN',
    'TO', 'TR', 'TT', 'TW', 'TZ', 'UA', 'UG', 'US', 'UY', 'UZ', 'VC', 'VE',
    'VG', 'VI', 'VN', 'VU', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW', 'ZZ',
 ]

# Tuples of (chart title, elog_url_route) for the resource being tracked for
# latency.
LATENCY_QUERIES = (
    ("practice_task_start_latency",
        'api.main:/api/internal/user/task/practice/<exercise_name>'),
    ("mastery_task_start_latency",
        'api.main:/api/internal/user/task/mastery'),
    ("booster_task_start_latency",
        'api.main:/api/internal/user/task/booster'),
    ("compute_recommended_learning_tasks_for_mission",
        'api.main:/api/internal/user/mission/<mission_slug>/descriptors'),
    ("problem_attempt_latency",
        'api.main:/api/internal/user/exercises/<exercise_name>/'
            'problems/<int:problem_number>/attempt [POST]'),
    ("profile_widgets_latency",
        'api.main:/api/internal/user/<kaid>/profile/widgets'),
    ("user_badges_latency",
        'api.main:/api/internal/user/badges'),
    ("user_mission_latency",
        'api.main:/api/internal/user/mission/<mission_slug>'),
    ("user_mission_switch_latency",
        'api.main:/api/internal/user/mission/<mission_slug>/switch [POST]'),
    ("cs_browse_top_latency",
        'api.main:/api/internal/scratchpads/top'),
    ("get_feedback_for_focus_latency",
        'api.main:/api/internal/discussions/<focus_kind>/<focus_id>/'
            '<feedback_type>'),
    ("log_compatability_latency",
        'api.main:/api/internal/user/videos/<youtube_id>/log_compatability'),
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
WHERE status=200 AND elog_url_route="%(elog_url_route)s" %(country_filter)s
"""


def get_series_data(report, query_pattern, elog_url_route,
                    end_date, country='world', preceding_days=14):
    """Get the data for preceding_days days before end_date and end_date.

    This means that for preceding_days=n, this returns a list of n+1 data
    points.

    Returned as a dict of column name in the query to a list of
    (datetime, value) pairs of that column over time.
    """
    country_filter = ''
    file_suffix = ''
    if country != 'world':
        country_filter = "AND elog_country='%s'" % country
        file_suffix = country

    series = collections.defaultdict(list)
    for i in xrange(-preceding_days, 1):
        old_date = end_date + datetime.timedelta(days=i)
        old_yyyymmdd = old_date.strftime('%Y%m%d')

        query = query_pattern % {
            'yyyymmdd': old_yyyymmdd,
            'elog_url_route': elog_url_route,
            'country_filter': country_filter
        }

        # TODO(mattfaus): You could do this in a single query by doing
        # FROM table_date_range(logs.requestlogs_, start, end) and adding the
        # YYYYMMDD as a column and grouping by that. Hopefully BigQuery would
        # parallelize these groups well and return faster than the sum of these
        # individual queries. Be careful with the on-disk caching mechanics
        # inside bq_util, though.
        daily_data = bq_util.get_daily_data_from_disk_or_bq(
            query, report + file_suffix, old_yyyymmdd)
        assert len(daily_data) == 1, daily_data

        for key, val in daily_data[0].iteritems():
            series[key].append((old_date, val))

    return dict(series)


def query_and_write_data(date, country):
    """Run all queries broken down by country or not.

    If country is 'world', the latency queries are not restricted to a country,
    they aggregate data from all countries. The output is written to a file
    like perf_chart_dataXX.json, where XX is the country code or '' for the
    world.
    """
    charts_data = []

    for report, elog_url_route in LATENCY_QUERIES:
        series_data = get_series_data(report, LATENCY_QUERY_PATTERN,
                                      elog_url_route, date, country)

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
                # Ugly hack: Highcharts requires HTML symbols like < and > to
                # be escaped, but it has a bug where it sometimes displays the
                # escape sequence (e.g. &lt;) instead of the intended
                # character. See here for more details:
                # http://forum.highcharts.com/viewtopic.php?f=9&t=7299&p=35653
                # However, it looks like the bug doesn't show up as long as the
                # string contains a space character, so we add a space to the
                # end of the string so every url_route is shown correctly.
                'text': cgi.escape(elog_url_route) + ' '
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

    output_filename = _OUTPUT_FILENAME_PATTERN % {
        'suffix': country if country != 'world' else '',
    }

    if not os.path.isdir(os.path.dirname(output_filename)):
        os.makedirs(os.path.dirname(output_filename))

    with open(output_filename, 'w') as f:
        json.dump(output_data, f, sort_keys=True,
                  indent=4, separators=(',', ': '))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--date', metavar='YYYYMMDD',
                        default=_DEFAULT_DAY.strftime("%Y%m%d"),
                        help=('Date to get reports for, specified as YYYYMMDD '
                              '(default "%(default)s")'))
    parser.add_argument('--country', '-c', action='append',
                        choices=_VALID_COUNTRIES, default=_DEFAULT_COUNTRIES,
                        help=('Two letter country code to breakdown metrics '
                              'by. You may specify "world" to indicate no '
                              'breakdown. You may also repeate this parameter '
                              'to breakdown by separate countries. '
                              '(default: %(default)s)'))

    args = parser.parse_args()
    date = datetime.datetime.strptime(args.date, "%Y%m%d")

    for country in args.country:
        query_and_write_data(date, country)


if __name__ == '__main__':
    main()
