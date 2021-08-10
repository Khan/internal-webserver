#!/usr/bin/env python
"""
Periodically checks blocked requests which indicates either
an attack or a problem with the WAF rules.
"""

import datetime

import alertlib
import bq_util

import argparse

BQ_PROJECT = "khanacademy.org:deductive-jet-827"
FASTLY_DATASET = "fastly"
FASTLY_WAF_LOG_TABLE_PREFIX = "khanacademy_dot_org_waf_logs"
FASTLY_LOG_TABLE_PREFIX = "khanacademy_dot_org_logs"

# The size of the period of time to query. We are monitoring every 5 minutes.
WAF_PERIOD = 5 * 60

# Spike percentage determined from WAF Investigation:
# https://app.mode.com/editor/khanacademy/reports/f58d1ec83e48/presentation
SPIKE_PERCENTAGE = 0.17

TABLE_FORMAT = "%Y%m%d"
TS_FORMAT = "%Y-%m-%d %H:%M:%S"

ALERT_CHANNEL = "#bot-testing"

BLOCKED_REQ = """
#standardSQL
SELECT
  SUM(x.blocked) AS blocked,
  APPROX_TOP_COUNT(x.b_ip,1)[OFFSET(0)].value AS ip,
  APPROX_TOP_COUNT(x.message,1)[OFFSET(0)].value AS message,
  100 * SUM(x.unique_messages)/SUM(x.total_messages) AS message_percentage,
  APPROX_TOP_COUNT(x.b_rua,1)[OFFSET(0)].value AS rua,
  100 * SUM(x.unique_ip)/SUM(x.total_ip) AS ip_percentage,
  100 * SUM(x.unique_rua)/SUM(x.total_rua) AS rua_percentage
FROM (
  SELECT
    COUNT(*) AS blocked,
    APPROX_TOP_COUNT(waf.message,1)[OFFSET(0)].value AS message,
    APPROX_TOP_COUNT(waf.message,1)[OFFSET(0)].count AS unique_messages,
    COUNT(waf.message) AS total_messages,
    APPROX_TOP_COUNT(client_ip,1)[OFFSET(0)].value AS b_ip,
    APPROX_TOP_COUNT(request_user_agent,1)[OFFSET(0)].value AS b_rua,
    APPROX_TOP_COUNT(request_user_agent,1)[OFFSET(0)].count AS unique_rua,
    APPROX_TOP_COUNT(client_ip,1)[OFFSET(0)].count AS unique_ip,
    COUNT(client_ip) AS total_ip,
    COUNT(request_user_agent) AS total_rua
  FROM
    `{fastly_waf_log_tables}`
  WHERE
    TIMESTAMP(timestamp) BETWEEN TIMESTAMP('{start_timestamp}')
    AND TIMESTAMP('{end_timestamp}') AND blocked=1
  GROUP BY
    TIMESTAMP(timestamp))x
"""

TOTAL_REQ = """
#standardsql
SELECT
  count(*) AS total
FROM
  `{fastly_log_tables}`
WHERE
  TIMESTAMP(timestamp) BETWEEN TIMESTAMP('{start_timestamp}')
  AND TIMESTAMP('{end_timestamp}')
"""


def waf_detect(end):
    ymd = end.strftime(TABLE_FORMAT)
    waf_log_name = (
        BQ_PROJECT + "." + FASTLY_DATASET + "." + FASTLY_WAF_LOG_TABLE_PREFIX
        + "_" + ymd
    )
    log_name = (
        BQ_PROJECT + "." + FASTLY_DATASET + "." + FASTLY_LOG_TABLE_PREFIX +
        "_" + ymd
    )
    start = end - datetime.timedelta(seconds=WAF_PERIOD)
    date = end.date()

    blocked_info = BLOCKED_REQ.format(
        fastly_waf_log_tables=waf_log_name,
        start_timestamp=start.strftime(TS_FORMAT),
        end_timestamp=end.strftime(TS_FORMAT),
    )
    blocked_results = bq_util.query_bigquery(blocked_info, project=BQ_PROJECT)

    total_info = TOTAL_REQ.format(
        fastly_log_tables=log_name,
        start_timestamp=start.strftime(TS_FORMAT),
        end_timestamp=end.strftime(TS_FORMAT),
    )
    total_results = bq_util.query_bigquery(total_info, project=BQ_PROJECT)

    # When an empty query is returned, these are of types ('None').
    total = total_results[0]["total"]
    blocked = blocked_results[0]["blocked"]
    percentage = float(100 * float(blocked) / float(total))

    ip = blocked_results[0]["ip"]
    ip_percentage = blocked_results[0]["ip_percentage"]
    request_user_agent = blocked_results[0]["rua"]
    message = blocked_results[0]["message"]

    if ip_percentage == "(None)":
        return

    start_time = start.strftime("%H:%M")
    end_time = end.strftime("%H:%M")

    if percentage > SPIKE_PERCENTAGE:
        msg = """
        :exclamation: *Possible WAF alert* :exclamation:
        *Date and Time:* {date}, {start_time} - {end_time}
        *Blocked requests:* {percentage:.2f}%
        *IP:* {ip}
        *Request User Agent:* {request_user_agent}
        *WAF Block Reason:* {message}
        """.format(
            date=date,
            percentage=percentage,
            ip=ip,
            request_user_agent=request_user_agent,
            message=message,
            start_time=start_time,
            end_time=end_time,
        )
        alertlib.Alert(msg).send_to_slack(ALERT_CHANNEL)


def main():
    # For demoing alert against specific attack time
    # python waf_alert.py --endtime '2021-07-23 10:03:00'
    # NOTE: Dates will only work for after 2021-07-20
    parser = argparse.ArgumentParser(
        description="Process the date and time that we want to investigate."
    )
    parser.add_argument(
        "--endtime",
        help="Date and time that we want to investigate. \
         For example, `python waf_alert.py --endtime '2021-07-23 10:03:00'``",
        type=lambda s: datetime.datetime.strptime(s, TS_FORMAT),
    )
    args = parser.parse_args()

    if args.endtime:
        now = args.endtime
    else:
        now = datetime.datetime.utcnow()
    assert now > datetime.datetime(
        2021, 7, 20
    ), "Blocked column was introduced on 2021-07-20"
    waf_detect(now)


if __name__ == "__main__":
    main()
