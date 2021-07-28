#!/usr/bin/env python
"""Periodically checks blocked requests which indicates either an attack or a problem with the WAF rules."""

import re
import datetime
from itertools import groupby

import alertlib
import bq_util

import argparse

BQ_PROJECT = 'khanacademy.org:deductive-jet-827'
FASTLY_DATASET = 'fastly'
FASTLY_WAF_LOG_TABLE_PREFIX = 'khanacademy_dot_org_waf_logs'
FASTLY_LOG_TABLE_PREFIX = 'khanacademy_dot_org_logs'

# The size of the period of time to query. We are monitoring every 5 minutes.
WAF_PERIOD = 5 * 60

TABLE_FORMAT = '%Y%m%d'
TS_FORMAT = '%Y-%m-%d %H:%M:%S'


ALERT_CHANNEL = '#bot-testing'

# The fastly's timestamp field is a string with extra details denoting the time
# zone. BigQuery doesn't understand that part, so we trim that out as the time
# zone is always +0000.
today = datetime.datetime.now()
now = datetime.datetime.utcnow()
ymd = today.strftime(TABLE_FORMAT)
waf_log_name = BQ_PROJECT + '.' + FASTLY_DATASET + '.' + FASTLY_WAF_LOG_TABLE_PREFIX + '_' + ymd
log_name = BQ_PROJECT + '.' + FASTLY_DATASET + '.' + FASTLY_LOG_TABLE_PREFIX + '_' + ymd

QUERY_TEMPLATE = """\
#standardSQL
WITH BLOCKED_REQ AS (
  SELECT
    TIMESTAMP(timestamp) AS t,
    count(*) AS blocked,
    APPROX_TOP_COUNT(waf.message,1)[OFFSET(0)].value AS message,
    APPROX_TOP_COUNT(waf.message,1)[OFFSET(0)].count AS unique_messages,
    count(waf.message) AS total_messages,
    APPROX_TOP_COUNT(client_ip,1)[OFFSET(0)].value AS b_ip,
    APPROX_TOP_COUNT(request_user_agent,1)[OFFSET(0)].value AS b_request_user_agent,
    APPROX_TOP_COUNT(request_user_agent,1)[OFFSET(0)].count AS unique_rua,
    APPROX_TOP_COUNT(client_ip,1)[OFFSET(0)].count AS unique_ip,
    count(client_ip) AS total_ip,
    count(request_user_agent) AS total_rua
  FROM
    `{fastly_waf_log_tables}`
  WHERE
    (TIMESTAMP(timestamp) BETWEEN TIMESTAMP('{start_timestamp}')
    AND TIMESTAMP('{end_timestamp}')) AND blocked=1
  GROUP BY
    t
  ORDER BY
    t DESC
),
TOTAL_REQ AS (
  SELECT
    TIMESTAMP_TRUNC(TIMESTAMP(timestamp), MINUTE) AS t,
    count(*) AS total
  FROM
    `{fastly_log_tables}`
  GROUP BY
    t
  ORDER BY
    t DESC
)
SELECT
  SUM(blocked) AS blocked,
  SUM(total) AS total,
  100 * SUM(blocked) / SUM(total) AS percentage,
  APPROX_TOP_COUNT(b_ip,1)[OFFSET(0)].value AS ip,
  APPROX_TOP_COUNT(message,1)[OFFSET(0)].value AS message,
  100 * SUM(unique_messages)/SUM(total_messages) AS message_percentage,
  APPROX_TOP_COUNT(b_request_user_agent,1)[OFFSET(0)].value AS request_user_agent,
  100 * SUM(unique_ip)/SUM(total_ip) AS ip_percentage,
  100 * SUM(unique_rua)/SUM(total_rua) AS rua_percentage
FROM
  BLOCKED_REQ
  INNER JOIN TOTAL_REQ USING (t)
WHERE
  TIMESTAMP(t) BETWEEN TIMESTAMP('{start_timestamp}')
  AND TIMESTAMP('{end_timestamp}')
"""

def waf_detect(end):
    start = end - datetime.timedelta(seconds=WAF_PERIOD)
    query = QUERY_TEMPLATE.format(
        fastly_log_tables=log_name,fastly_waf_log_tables=waf_log_name,
        start_timestamp=start.strftime(TS_FORMAT),
        end_timestamp=end.strftime(TS_FORMAT),
        )
    results = bq_util.query_bigquery(query, project=BQ_PROJECT)

    # Stop processing if we don't have any flagged IPs
    if not results:
        return


    # TODO Need to come up with a case for when the query returns as empty, ie there are no blocked=1 columns

    # When an empty query is returned, these are of types ['None']. I have not typecasted yet because it harms successful attempts
    percentage = results[0]['percentage']
    blocked = results[0]['blocked']
    total = results[0]['total']
    ip = results[0]['ip']
    ip_percentage = results[0]['ip_percentage']
    request_user_agent = results[0]['request_user_agent']
    message = results[0]['message']
    message_percentage = results[0]['message_percentage']
    rua_percentage = results[0]['rua_percentage']

    if ip_percentage == '(None)':
        return

    start_time = start.strftime("%H:%M")
    end_time = end.strftime("%H:%M")

    msg = """
    :exclamation: *Possible WAF alert* :exclamation:
    *Time Range:* {start_time} - {end_time}
    *Blocked requests:* {percentage:.1f}% ({blocked} / {total})
    *IP:* {ip} ({ip_percentage:.1f}% of blocks)
    *Request User Agent:* {request_user_agent} ({rua_percentage:.1f}% of blocks)
    *WAF Block Reason:* {message} ({message_percentage:.1f}% of blocks)
    """.format(percentage=percentage, blocked=blocked, total=total, ip=ip, request_user_agent=request_user_agent, message=message, ip_percentage=ip_percentage, message_percentage=message_percentage, rua_percentage=rua_percentage, start_time=start_time, end_time=end_time)

    alertlib.Alert(msg).send_to_slack(ALERT_CHANNEL)

def main():
    # For demoing alert against specific attack time
    # python waf_alert.py --datetime '2021-07-23 10:03:00'
    # NOTE: Dates will only work for after 2021-07-20
    parser = argparse.ArgumentParser(description='Process the date and time that we want to investigate.')
    parser.add_argument("--datetime", help="Date and time that we want to investigate.",
     type=lambda s: datetime.datetime.strptime(s, TS_FORMAT))
    args = parser.parse_args()

    if args.datetime:
        now = args.datetime
    else:
        now = datetime.datetime.utcnow()
    waf_detect(now)

if __name__ == '__main__':
    main()
