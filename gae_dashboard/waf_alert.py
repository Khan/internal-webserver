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

# The size of the period of time to query. We are monitoring every 60 minutes.
WAF_PERIOD = 60 * 60

# Spike percentage determined from WAF Investigation:
# https://app.mode.com/editor/khanacademy/reports/f58d1ec83e48/presentation
SPIKE_PERCENTAGE = 0.03

TABLE_FORMAT = "%Y%m%d"
TS_FORMAT = "%Y-%m-%d %H:%M:%S"

ALERT_CHANNEL = "#infrastructure-sre"

BLOCKED_REQ = """
#standardSQL
SELECT
  COUNT(*) AS blocked,
  APPROX_TOP_COUNT(client_ip,1)[OFFSET(0)].value AS ip,
  100 * APPROX_TOP_COUNT(client_ip,1)[OFFSET(0)].count /
   COUNT(*) AS ip_percentage,
  APPROX_TOP_COUNT(waf.message,1)[OFFSET(0)].value AS message,
  100 * APPROX_TOP_COUNT(waf.message,1)[OFFSET(0)].count /
   COUNT(*) AS message_percentage,
  APPROX_TOP_COUNT(request_user_agent,1)[OFFSET(0)].value
   AS request_user_agent,
  100 * APPROX_TOP_COUNT(request_user_agent,1)[OFFSET(0)].count
   / COUNT(*) AS request_user_agent_percentage,
FROM
    `{fastly_waf_log_tables}`
  WHERE
    TIMESTAMP(timestamp) BETWEEN TIMESTAMP('{start_timestamp}')
    AND TIMESTAMP('{end_timestamp}')
    AND blocked=1
    AND _TABLE_SUFFIX BETWEEN '{start_ymd}' AND '{end_ymd}'
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
  AND _TABLE_SUFFIX BETWEEN '{start_ymd}' AND '{end_ymd}'
"""


# Detects a WAF attack and alerts to Slack channel if there is an attack.
def waf_detect(endtime):
    waf_log_name = (
        BQ_PROJECT
        + "."
        + FASTLY_DATASET
        + "."
        + FASTLY_WAF_LOG_TABLE_PREFIX
        + "_*"
    )
    log_name = (
        BQ_PROJECT + "." + FASTLY_DATASET + "." +
        FASTLY_LOG_TABLE_PREFIX + "_*"
    )
    start = endtime - datetime.timedelta(seconds=WAF_PERIOD)
    date = endtime.date()

    start_ymd = start.strftime(TABLE_FORMAT)
    end_ymd = endtime.strftime(TABLE_FORMAT)

    blocked_info = BLOCKED_REQ.format(
        fastly_waf_log_tables=waf_log_name,
        start_timestamp=start.strftime(TS_FORMAT),
        end_timestamp=endtime.strftime(TS_FORMAT),
        end_ymd=end_ymd,
        start_ymd=start_ymd
    )
    blocked_results = bq_util.query_bigquery(blocked_info, project=BQ_PROJECT)

    # When an empty query is returned, these are of types ('None').
    blocked = blocked_results[0]["blocked"]
    ip = blocked_results[0]["ip"]
    ip_percentage = blocked_results[0]["ip_percentage"]
    request_user_agent = blocked_results[0]["request_user_agent"]
    message = blocked_results[0]["message"]
    message_percentage = blocked_results[0]["message_percentage"]
    request_user_agent_percentage = (
        blocked_results[0]["request_user_agent_percentage"]
    )

    if ip_percentage == "(None)":
        return

    total_info = TOTAL_REQ.format(
        fastly_log_tables=log_name,
        start_timestamp=start.strftime(TS_FORMAT),
        end_timestamp=endtime.strftime(TS_FORMAT),
        end_ymd=end_ymd,
        start_ymd=start_ymd
    )
    total_results = bq_util.query_bigquery(total_info, project=BQ_PROJECT)

    total = total_results[0]["total"]
    percentage = float(100 * float(blocked) / float(total))

    start_time = start.strftime("%H:%M")
    end_time = endtime.strftime("%H:%M")

    if percentage > SPIKE_PERCENTAGE:
        msg = """
        :exclamation: *Spike in WAF blocking* :exclamation:
        *Date and Time:* {date} {start_time} - {end_time}
        *Blocked requests:* {percentage:.2f}% ({blocked} / {total})
        *IP:* <https://db-ip.com/{ip}|{ip}> ({ip_percentage:.1f}% of blocks)
        *Request User Agent:* {request_user_agent} \
        ({request_user_agent_percentage:.1f}% of blocks)
        *WAF Block Reason:* {message} ({message_percentage:.1f}% of blocks)
        """.format(
            percentage=percentage,
            blocked=blocked,
            total=total,
            ip=ip,
            request_user_agent=request_user_agent,
            date=date,
            message=message,
            ip_percentage=ip_percentage,
            message_percentage=message_percentage,
            request_user_agent_percentage=request_user_agent_percentage,
            start_time=start_time,
            end_time=end_time,
        )

        alertlib.Alert(msg).send_to_slack(ALERT_CHANNEL)


def main():
    # For demoing alert against specific attack time
    # python waf_alert.py --endtime '2021-07-23 10:00:00'
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
        endtime = args.endtime
    else:
        endtime = datetime.datetime.utcnow()
    assert endtime > datetime.datetime(
        2021, 7, 20
    ), "Blocked column was introduced on 2021-07-20"
    waf_detect(endtime)


if __name__ == "__main__":
    main()
