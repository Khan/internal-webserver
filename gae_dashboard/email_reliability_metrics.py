#!/usr/bin/env python

import bq_util
import datetime
import json
import os
import pytz
import random
import requests


def get_weekly_uptime_stats_from_pingdom():
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
                    '?includeuptime=true&from=%s')

    one_week_ago = (datetime.datetime.now(pytz.timezone('US/Pacific'))
                    - datetime.timedelta(days=7)).strftime('%s')

    pingdom_username = 'it@khanacademy.org'

    dir_prefix = '~/internal-webserver/gae_dashboard/'

    with open(os.path.expanduser(dir_prefix + 'pingdom_password')) as f:
        pingdom_password = f.read()

    with open(os.path.expanduser(dir_prefix + 'pingdom_api_key')) as f:
        pingdom_api_key = f.read()

    for page_name, checkid in page_name_to_checkid.iteritems():
        url = url_template % (checkid, one_week_ago)
        response = requests.get(url,
                                auth=(pingdom_username, pingdom_password),
                                headers={
                                    'app-key': pingdom_api_key,
                                })

        response_json = json.loads(response.text)
        uptime_data[page_name] = response_json['summary']['status']

    return uptime_data


def calculate_weekly_uptime(uptime_data):
    totalup = sum([stats['totalup'] for stats in uptime_data.values()])
    totaldown = sum([stats['totaldown'] for stats in uptime_data.values()])

    uptime = float(totalup) / (totalup + totaldown)

    return '%.2f' % (uptime * 100)


def build_temp_table():
    today = datetime.datetime.now(pytz.timezone('US/Pacific'))
    weeks_dates = [(today - datetime.timedelta(days=n)).strftime("%Y%m%d")
                   for n in range(0, 7)]
    tables_to_filter = ["khan:logs.requestlogs_%s" % date
                        for date in weeks_dates]

    query = """\
SELECT page_load_time, elog_country, page_load_page, page_load_nav_type
FROM [%s]
WHERE
    page_load_time IS NOT NULL
    AND elog_device_type IS NOT NULL
    AND elog_device_type != 'bot/dev'
    AND (
      (page_load_nav_type = 'server' AND page_load_time < 30)
      OR (page_load_nav_type = 'client' AND page_load_time < 20)
    )
    """ % ','.join(tables_to_filter)

    # Modeled from logs_bridge._run_bigquery
    # Apparently the commandline doesn't like newlines in the script.
    # Reformat for the commandline.
    query = query.replace('\n', ' ')

    temp_table_name = (
        'khan-academy:logs_streaming_tmp_analysis.email_reliability_%s_%04d'
        % (today.strftime("%Y%m%d"), random.randint(0, 9999)))

    bq_util.call_bq(['mk', temp_table_name],
                    project='khan-academy',
                    return_output=False,
                    stdout=open(os.devnull, 'w'))
    bq_util.call_bq(['query', '--destination_table', temp_table_name,
                     '--allow_large_results', query],
                    project='khanacademy.org:deductive-jet-827',
                    return_output=False)

    return temp_table_name


def get_weekly_page_load_perf_data_from_bigquery(page_load_table_name):
    page_load_time_by_country_query = """\
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

    # TODO: actually build the temp table, do the query, verify results make sense
    # then can turn this into a real diff, including writing the other two queries
    # then work on graphing and emailing
