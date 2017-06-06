#!/usr/bin/env python
# TODO(colin): fix these lint errors (http://pep8.readthedocs.io/en/release-1.7.x/intro.html#error-codes)
# pep8-disable:E122,E124
"""Email weekly performance report

Report contains:
- page performance from bq
- uptime metrics from pingdom
- degraded performance metrics form bq
"""
import argparse
import collections
import cStringIO
import datetime
import email.mime.image
import email.mime.multipart
import email.mime.text
import json
import os
import smtplib
import subprocess

import bq_util
import email_uptime
import jinja2
import matplotlib.pyplot as plt
import pytz
import requests

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

    dir_prefix = os.path.abspath(os.path.dirname(__file__))

    with open(os.path.join(dir_prefix, 'pingdom_password')) as f:
        pingdom_password = f.read().strip()

    with open(os.path.join(dir_prefix, 'pingdom_api_key')) as f:
        pingdom_api_key = f.read().strip()

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
    return uptime * 100


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

    by_page_results = bq_util.query_bigquery(by_page_query)
    by_country_results = bq_util.query_bigquery(by_country_query)
    server_usa_results = bq_util.query_bigquery(server_usa_query)

    by_page_data = {"type": "page_load.by_page",
                    "data": dict([(d["page_load_page"], d["page_load_time"])
                                  for d in by_page_results]),
    }
    by_country_data = {"type": "page_load.by_country",
                       "data": dict([(d["elog_country"], d["page_load_time"])
                                     for d in by_country_results]),
    }
    server_usa_data = {"type": "page_load.server_usa",
                       "data": dict([(d["page_load_page"], d["page_load_time"])
                                     for d in server_usa_results]),
    }

    return [by_page_data, by_country_data, server_usa_data]


def save_weekly_data_to_disk(metrics, start_date=None, end_date=None):
    """Given an iterable of metrics, write them to a file as JSON.

    In addition to the metrics themselves, we record the start_date and
    end_date associated with the data.

    start_date and end_date are expected to be datetime objects if given.
    They default to eight days ago and yesterday, respectively.

    Maintaining this file is useful for graphing multiple weeks of data.
    """
    start_date = (start_date or _DEFAULT_START_TIME).strftime("%Y%m%d")
    end_date = (end_date or _DEFAULT_END_TIME).strftime("%Y%m%d")

    weekly_data = {
        "start_date": start_date,
        "end_date": end_date,
        "metrics": metrics,
    }

    with open(_PAGE_LOAD_DATABASE, 'r') as f:
        contents = json.load(f)

    contents.append(weekly_data)

    with open(_PAGE_LOAD_DATABASE, 'w') as f:
        json.dump(contents, f)


def ensure_weekly_data(start_time=None, end_time=None):
    """Ensure we have data from start_time to end_time stored.

    We store data for page load performance, uptime, and degraded service.
    If given, start_time and end_time are expected to be datetime objects.
    They default to eight days ago and yesterday, respectively.
    """
    if os.path.isfile(_PAGE_LOAD_DATABASE):
        with open(_PAGE_LOAD_DATABASE, 'r') as f:
            data = json.load(f)
    else:
        with open(_PAGE_LOAD_DATABASE, 'w') as f:
            json.dump([], f)
        data = []

    start_time = start_time or _DEFAULT_START_TIME
    end_time = end_time or _DEFAULT_END_TIME

    for record in data:
        if record['start_date'] == start_time.strftime('%Y%m%d'):
            return

    table_name = build_page_load_temp_table(start_date=start_time,
                                            end_date=end_time)
    metrics = get_weekly_page_load_data_from_bigquery(table_name)

    uptime_metric = {
        "type": "uptime",
        "data": calculate_pingdom_uptime(get_uptime_stats_from_pingdom(
            start_time=start_time, end_time=end_time))
    }

    degraded_metric = {
        "type": "degraded",
        "data": degraded_data(start_time=start_time, end_time=end_time),
    }

    metrics.append(uptime_metric)
    metrics.append(degraded_metric)

    save_weekly_data_to_disk(metrics, start_date=start_time, end_date=end_time)


def get_metric_type_for_start_date(type_, start_date):
    """Helper method to read a particular metric from the database file."""

    with open(_PAGE_LOAD_DATABASE, 'r') as f:
        all_data = json.load(f)

    for record in all_data:
        if record['start_date'] == start_date.strftime('%Y%m%d'):
            for metric in record['metrics']:
                if metric['type'] == type_:
                    return metric['data']


def build_graphs_from_page_load_database(save_graphs=False):
    """Returns a dict of titles to PNGs of page load perf data in string form.

    The images are drawn by matplotlib using the data in _PAGE_LOAD_DATABASE.
    """

    # TODO(nabil|amos): graph pingdom uptime and service degradation data.

    chart_data = {
        'page_load.by_page': [],
        'page_load.by_country': [],
        'page_load.server_usa': [],
    }

    with open(_PAGE_LOAD_DATABASE, 'r') as f:
        all_data = json.load(f)

    # TODO(nabil): limit the number of weeks we graph if it gets hard to read
    date_pairs = [(datetime.datetime.strptime(d['start_date'], '%Y%m%d'),
                   datetime.datetime.strptime(d['end_date'], '%Y%m%d'))
                  for d in all_data]

    for start_date, end_date in date_pairs:
        for type_ in chart_data:
            chart_data[type_].append({
                'end_date': end_date,
                'data': get_metric_type_for_start_date(type_, start_date)
            })

    title_to_image = {}

    for title, graph_data in chart_data.items():
        key_to_times = collections.defaultdict(list)
        dates = []

        for weekly_data in graph_data:
            dates.append(weekly_data['end_date'])

            # Convert a list of dicts to a dict of lists. We weakly expect the
            # dicts to have the same keys; the graphs might look kind of weird
            # if this assumption is violated, which would happen if we alter
            # the data we collect.
            # TODO(nabil): figure out what to do in that case.
            # It's not clear the people reading the report will want to look at
            # graphs including the old data if requirements change in that way,
            # so never fixing this and using a new database file each time we
            # change pages/countries we measure or care about might make sense.
            for key, page_load_time in weekly_data['data'].iteritems():
                key_to_times[key].append(page_load_time)

        plt.figure()  # clear implicit state

        for key, page_load_times in key_to_times.iteritems():
            plt.plot(dates, page_load_times, label=key)

        plt.ylim(ymin=0)
        plt.xticks(dates, [date.strftime('%b %-d %Y') for date in dates])
        plt.title(title + ': 90th percentile')
        plt.legend()
        plt.xlabel('data for week ending on this date')
        plt.ylabel('seconds until page is usable')

        img_data = cStringIO.StringIO()
        plt.savefig(img_data, format='png')
        if save_graphs:
            plt.savefig(os.path.expanduser('~/' + title + '.png'))
        img_data.seek(0)
        title_to_image[title] = img_data.read()

    return title_to_image


def degraded_data(start_time=None, end_time=None):
    return email_uptime.average_uptime_for_period(
        start_time or _DEFAULT_START_TIME,
        end_time or _DEFAULT_END_TIME,
        email_uptime.DEFAULT_SEC_PER_CHUNK,
        email_uptime.DEFAULT_DOWN_THRESHOLD,
    )


def get_change_data(start_date=None):
    start_date = start_date or _DEFAULT_START_TIME

    change_data = {
        'page_load.by_page': [],
        'page_load.by_country': [],
        'page_load.server_usa': [],
    }

    # hardcoded baseline numbers for comparison each week, manually chosen as
    # the highest value from the first four weeks of data. The intention is to
    # gradually reduce these numbers as we improve page load performance.
    baseline = {
        'page_load.by_page': {
            'scratchpad_page': 5.62,
            'login_page': 3.923,
            'curation_page': 6.153,
            'signup_page': 3.992,
            'article_page': 12.41,
            'logged_out_homepage': 11.063,
            'exercise_page': 10.23,
            'logged_in_homepage': 9.728,
            'video_page': 11.215,
        },
        'page_load.by_country': {
            'CN': 11.298,
            'US': 6.946,
            'ID': 12.773,
            'BR': 12.103,
            'IN': 15.081,
            'MX': 11.615,
        },
        'page_load.server_usa': {
            'scratchpad_page': 4.66,
            'login_page': 3.485,
            'curation_page': 6.189,
            'signup_page': 3.237,
            'article_page': 10.476,
            'logged_out_homepage': 6.143,
            'exercise_page': 11.688,
            'logged_in_homepage': 8.112,
            'video_page': 13.263,
        },
    }

    for title in change_data:
        metric = get_metric_type_for_start_date(title, start_date)
        for key, value in metric.items():
            if key in baseline[title]:
                change_data[title].append({
                    'key': key,
                    'baseline': baseline[title][key],
                    'current': value,
                })

    return change_data


def html_template():
    env = jinja2.Environment(
        loader=jinja2.PackageLoader('__main__', ''),
        autoescape=jinja2.select_autoescape(['html', 'xml'])
    )
    return env.get_template('email_reliability_metrics.html')


def send_email(start_time=None, end_time=None, dry_run=False,
               save_graphs=False):
    start_time = start_time or _DEFAULT_START_TIME
    end_time = end_time or _DEFAULT_END_TIME
    one_week_ago = start_time - datetime.timedelta(days=7)

    pingdom_data = {
        'current': get_metric_type_for_start_date('uptime', start_time),
        'last': get_metric_type_for_start_date('uptime', one_week_ago),
    }
    degraded_data = {
        'current': get_metric_type_for_start_date('degraded', start_time),
        'last': get_metric_type_for_start_date('degraded', one_week_ago),
    }
    change_data = get_change_data(start_date=start_time)

    id_to_display_name = {
        'page_load.server_usa': 'By page type, only US server requests',
        'page_load.by_page': 'By page type',
        'page_load.by_country': 'By country',
    }

    template = html_template()
    html = template.render(
        id_to_display_name=id_to_display_name,
        date=end_time,
        change_data=change_data,
        degraded=degraded_data,
        uptime=pingdom_data,
        default_message="Data unavailable")

    message = email.mime.multipart.MIMEMultipart('related')
    body = email.mime.text.MIMEText(html, 'html')
    message.attach(body)
    for name, data in build_graphs_from_page_load_database(
            save_graphs=save_graphs).items():
        fname = name + '.png'
        mime = email.mime.image.MIMEImage(data)
        mime.add_header('Content-ID', '<%s>' % name)
        mime.add_header('Content-Disposition', 'inline; filename="%s"' % fname)
        message.attach(mime)

    message['subject'] = 'Weekly Reliability Metrics for {}'.format(
        end_time.strftime('%b %-d %Y'))
    message['from'] = '"bq-cron-reporter" <toby-admin+bq-cron@khanacademy.org>'
    recipients = ('nabil@khanacademy.org', 'amos@khanacademy.org')
    message['to'] = ', '.join(recipients)

    if dry_run:
        print message.as_string()
    else:
        s = smtplib.SMTP('localhost')
        s.sendmail('toby-admin+bq-cron@khanacademy.org', recipients,
                   message.as_string())
        s.quit()


def main():
    def valid_unix_time(unix_time):
        try:
            return datetime.datetime.fromtimestamp(int(unix_time),
                                                   pytz.timezone('US/Pacific'))
        except ValueError:
            raise argparse.ArgumentTypeError("Not a unix time: %s" % unix_time)

    parser = argparse.ArgumentParser()
    parser.add_argument('--dry-run', '-n', action='store_true',
                        help="Don't actually send email.")
    parser.add_argument('--start-time', metavar='Unix time',
                        default=_DEFAULT_START_TIME, type=valid_unix_time,
                        help=('Calculate and save reliability metrics '
                              'starting from this time. Should be given as a '
                              'unix time. (Default: eight days ago.)'))
    parser.add_argument('--end-time', metavar='Unix time',
                        default=_DEFAULT_END_TIME, type=valid_unix_time,
                        help=('Calculate and save reliability metrics ending '
                              'at this time. Should be given as a unix time. '
                              '(Default: one day ago.)'))
    parser.add_argument('--save-graphs', action='store_true',
                        help='Save generated graphs to the user\'s home dir.')

    args = parser.parse_args()

    ensure_weekly_data(start_time=args.start_time, end_time=args.end_time)
    send_email(start_time=args.start_time, end_time=args.end_time,
               save_graphs=args.save_graphs, dry_run=args.dry_run)


if __name__ == '__main__':
    main()
