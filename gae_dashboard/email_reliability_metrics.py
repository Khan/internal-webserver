#!/usr/bin/env python

import datetime
import json
import os
import pytz
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
