#!/usr/bin/env python

import bq_util
import datetime
import json
import os
import pytz
import requests
import subprocess


_TODAY = datetime.datetime.now(pytz.timezone('US/Pacific'))  # toby's timezone
_DEFAULT_START_TIME = _TODAY - datetime.timedelta(days=8)
_DEFAULT_END_TIME = _TODAY - datetime.timedelta(days=1)

_PAGE_LOAD_DATABASE = os.path.expanduser('~/email_reliability_metrics.db')


def get_uptime_stats_from_pingdom(start_time=None, end_time=None):
    """Retrieve uptime statistics from start_time to end_time for all pages.

    start_time and end_time are expected to be datetime objects if given.
    They default to eight days ago and yesterday, respectively.
    """
    # found via clicking each page at https://my.pingdom.com/reports/uptime
    page_name_to_checkid = {
        'article_page': 2573648,
        'curation_page': 2573682,
        'exercise_page': 1133052,
        'logged_out_home_page': 1131592,
        'knowledge_map': 1133051,
        'login_page': 2573649,
        'scratchpad_page': 2573695,
        'signup_page': 2573651,
        'video_page': 1133049,
    }

    uptime_data = {}

    url_template = ('https://api.pingdom.com/api/2.0/summary.average/%s'
                    '?includeuptime=true&from=%s&to=%s')

    start_time = (start_time or _DEFAULT_START_TIME).strftime('%s')
    end_time = (end_time or _DEFAULT_END_TIME).strftime('%s')

    pingdom_username = 'it@khanacademy.org'

    dir_prefix = '~/internal-webserver/gae_dashboard/'

    with open(os.path.expanduser(dir_prefix + 'pingdom_password')) as f:
        pingdom_password = f.read()

    with open(os.path.expanduser(dir_prefix + 'pingdom_api_key')) as f:
        pingdom_api_key = f.read()

    for page_name, checkid in page_name_to_checkid.iteritems():
        url = url_template % (checkid, start_time, end_time)
        response = requests.get(url,
                                auth=(pingdom_username, pingdom_password),
                                headers={
                                    'app-key': pingdom_api_key,
                                })

        response_json = json.loads(response.text)
        uptime_data[page_name] = response_json['summary']['status']

    return uptime_data


def calculate_pingdom_uptime(uptime_data):
    totalup = sum([stats['totalup'] for stats in uptime_data.values()])
    totaldown = sum([stats['totaldown'] for stats in uptime_data.values()])

    uptime = float(totalup) / (totalup + totaldown)

    return '%.2f' % (uptime * 100)


def build_page_load_temp_table(start_date=None, end_date=None):
    """Builds the temporary table with all relevant page load performance data.

    Returns the name of the table so it can be used in other functions which
    perform the queries to get the data we want for this report.

    The temp table is built from all request logs in the time from start_date
    to end_date. start_date and end_date are expected to be datetime objects
    if given. They default to eight days ago and yesterday, respectively.

    If the table already exists, this function exits successfully and returns
    the name of the table without rebuilding it.
    """

    start_date = (start_date or _DEFAULT_START_TIME).strftime("%Y%m%d")
    end_date = (end_date or _DEFAULT_END_TIME).strftime("%Y%m%d")

    temp_table_name = (
        'khan-academy:logs_streaming_tmp_analysis.email_reliability_%s_%s'
        % (start_date, end_date))

    # If the temp table already exists, don't redo the expensive query.
    try:
        bq_util.call_bq(['show', temp_table_name], project='khan-academy')
        return temp_table_name
    except subprocess.CalledProcessError:  # means 'table does not exist'
        pass

    bq_util.call_bq(['mk', temp_table_name], project='khan-academy',
                    return_output=False)

    query = """\
SELECT * FROM (
  SELECT
    FLOAT(REGEXP_EXTRACT(app_logs.message,
      r'stats.time.client.sufficiently_usable_ms\.\w+\.\w+:(\d+);'
    ))/1000 AS page_load_time,
    REGEXP_EXTRACT(app_logs.message,
      r'stats.time.client.sufficiently_usable_ms\.\w+\.(\w+):\d+;'
    ) AS page_load_page,
    REGEXP_EXTRACT(app_logs.message,
      r'stats.time.client.sufficiently_usable_ms\.(server|client).+;'
    ) AS page_load_nav_type,
    elog_country
  FROM TABLE_DATE_RANGE([khanacademy.org:deductive-jet-827:logs.requestlogs_],
                      TIMESTAMP('%(start_date)s'),
                      TIMESTAMP('%(end_date)s'))
  WHERE
    elog_device_type IS NOT NULL
    AND elog_device_type != 'bot/dev'
)
WHERE
  page_load_time IS NOT NULL
  AND (
    (page_load_nav_type = 'server' AND page_load_time < 30)
    OR (page_load_nav_type = 'client' AND page_load_time < 20)
  )
""" % {
    "start_date": start_date,
    "end_date": end_date,
}

    # Modeled from logs_bridge._run_bigquery
    # Apparently the commandline doesn't like newlines in the script.
    # Reformat for the commandline.
    query = query.replace('\n', ' ')

    bq_util.call_bq(['query', '--destination_table', temp_table_name,
                     '--allow_large_results', query],
                    project='khanacademy.org:deductive-jet-827',
                    return_output=False)

    return temp_table_name


def get_weekly_page_load_data_from_bigquery(page_load_table_name):
    """Given the temp table to query, calculate weekly page load perf data.

    We calculate the 90th percentile of page load performance by country,
    by page, and for US server navigation load time.

    This is similar to what is displayed in this stackdriver dashboard:
    https://app.google.stackdriver.com/monitoring/1037059/
    page-load-performance-90th-percentile?project=khan-academy
    """

    by_country_query = """\
SELECT
    FLOAT(NTH(91, QUANTILES(page_load_time, 101))) AS page_load_time,
    elog_country
FROM
    [%s]
WHERE
    elog_country IN ('US', 'ID', 'CN', 'IN', 'BR', 'MX')
GROUP BY
    elog_country
""" % page_load_table_name

    by_page_query = """\
SELECT
    FLOAT(NTH(91, QUANTILES(page_load_time, 101))) AS page_load_time,
    page_load_page
FROM
    [%s]
GROUP BY
    page_load_page
""" % page_load_table_name

    server_usa_query = """\
SELECT
    FLOAT(NTH(91, QUANTILES(page_load_time, 101))) AS page_load_time,
    page_load_page
FROM
    [%s]
WHERE
    page_load_nav_type = 'server'
    AND elog_country = 'US'
GROUP BY
    page_load_page
""" % page_load_table_name

    results = {
        'by_country': bq_util.query_bigquery(by_country_query),
        'by_page': bq_util.query_bigquery(by_page_query),
        'server_usa': bq_util.query_bigquery(server_usa_query),
    }

    return results


def save_weekly_page_load_data_to_disk(page_load_data, start_date=None,
                                       end_date=None):
    """Given a dictionary of page_load_data, write it to a file as JSON.

    In addition to the page_load_data itself, we record the start_date and
    end_date associated with the data.

    start_date and end_date are expected to be datetime objects if given.
    They default to eight days ago and yesterday, respectively.

    Maintaining this file is useful for graphing multiple weeks of data.
    """
    start_date = (start_date or _DEFAULT_START_TIME).strftime("%Y%m%d")
    end_date = (end_date or _DEFAULT_END_TIME).strftime("%Y%m%d")

    page_load_data["start_date"] = start_date
    page_load_data["end_date"] = end_date

    if os.path.isfile(_PAGE_LOAD_DATABASE):
        with open(_PAGE_LOAD_DATABASE, 'r') as f:
            contents = json.load(f)
    else:
        contents = []

    contents.append(page_load_data)

    with open(_PAGE_LOAD_DATABASE, 'w') as f:
        json.dump(contents, f)
