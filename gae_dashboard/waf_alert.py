#!/usr/bin/env python
"""Check request logs to look for an in progress WAF attack.

This script does a very simplistic check for WAF attack and notifies us via
slack if it notices anything that looks like a WAF attack.

Note: we are only checking for two simple types of attacks - a single
client requesting the same URL repeatedly, and scratchpad spam.

The hope is that this alerting will allow us to blacklist the offending IP
address using the appengine firewall.
"""

import re
import datetime
from itertools import groupby

import alertlib
import bq_util

BQ_PROJECT = 'khanacademy.org:deductive-jet-827'
FASTLY_DATASET = 'fastly'
FASTLY_WAF_LOG_TABLE_PREFIX = 'khanacademy_dot_org_waf_logs'
FASTLY_LOG_TABLE_PREFIX = 'khanacademy_dot_org_logs'

# The size of the period of time to query.
WAF_PERIOD = 5 * 60

TABLE_FORMAT = '%Y%m%d'
TS_FORMAT = '%Y-%m-%d %H:%M:%S'


ALERT_CHANNEL = '#bot-testing'

# The fastly's timestamp field is a string with extra details denoting the time
# zone. BigQuery doesn't understand that part, so we trim that out as the time
# zone is always +0000.
today = datetime.datetime.now()
d1 = today.strftime(TABLE_FORMAT)
waf_log_name = BQ_PROJECT + '.' + FASTLY_DATASET + '.' + FASTLY_WAF_LOG_TABLE_PREFIX + '_' + d1
log_name = BQ_PROJECT + '.' + FASTLY_DATASET + '.' + FASTLY_LOG_TABLE_PREFIX + '_' + d1

QUERY_TEMPLATE = """\
#standardSQL
WITH BLOCKED_REQ AS (
  SELECT
    TIMESTAMP_TRUNC(TIMESTAMP(timestamp), MINUTE) AS t,
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

    percentage = results[0]['percentage']
    blocked = results[0]['blocked']
    total = results[0]['total']
    ip = results[0]['ip']
    ip_percentage = results[0]['ip_percentage']
    request_user_agent = results[0]['request_user_agent']
    message = results[0]['message']
    message_percentage = results[0]['message_percentage']
    rua_percentage = results[0]['rua_percentage']

    msg = """
    TEST Possible WAF alert
    {percentage:.1f}% ({blocked} / {total}) of requests blocked in the last 5 minutes
    IP: {ip} ({ip_percentage:.1f}% of blocks)
    Request User Agent: {request_user_agent} ({rua_percentage:.1f}% of blocks)
    WAF Block Reason: {message} ({message_percentage:.1f}% of blocks)
    """.format(percentage=percentage, blocked=blocked, total=total, ip=ip, request_user_agent=request_user_agent, message=message, ip_percentage=ip_percentage, message_percentage=message_percentage, rua_percentage=rua_percentage)

    alertlib.Alert(msg).send_to_slack(ALERT_CHANNEL)

def main():
    now = datetime.datetime.utcnow()
    # For demoing alert against specific attack time
    # ./waf_alert '2021-07-08 11:00:00'
    #if sys.argv[1]:
    #    now = datatime.datetime.parse(sys.argv[1])
    waf_detect(now)

if __name__ == '__main__':
    main()
