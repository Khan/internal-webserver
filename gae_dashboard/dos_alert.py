#!/usr/bin/env python
"""Check request logs to look for an in progress DoS attack.

This script does a very simplistic check for DoS attack and notifies us via
slack if it notices anything that looks like a DoS attack.

Note: we are only checking for two simple types of attacks - a single
client requesting the same URL repeatedly, and scratchpad spam.

The hope is that this alerting will allow us to block the offending IP
address using Fastly IP blocking.

Note that we exclude URLs like these
`/api/internal/user/profile?kaid=...&projection=%7B%22countBrandNewNotifications%22:1%7D`
which are currently being requested 80 times a minute by some clients. I'm not
sure whether this is due to a bug or by design, but I don't think it's a DoS.
"""

import re
import datetime
from itertools import groupby

import alertlib
import bq_util


BQ_PROJECT = 'khanacademy.org:deductive-jet-827'
FASTLY_DATASET = 'fastly'
FASTLY_LOG_TABLE_PREFIX = 'khanacademy_dot_org_logs'

# Alert if we're getting more than this many reqs per sec from a single client.
MAX_REQS_SEC = 10

# The size of the period of time to query.
SCRATCHPAD_PERIOD = 5 * 60
DOS_PERIOD = 5 * 60
CDN_ERROR_PERIOD = 5 * 60

TABLE_FORMAT = '%Y%m%d'
TS_FORMAT = '%Y-%m-%d %H:%M:%S'

ALERT_CHANNEL = '#infrastructure-sre'

# The fastly's timestamp field is a string with extra details denoting the time
# zone. BigQuery doesn't understand that part, so we trim that out as the time
# zone is always +0000.
QUERY_TEMPLATE = """\
SELECT
  client_ip AS ip,
  url,
  request_user_agent AS user_agent,
  COUNT(*) AS count
FROM (
  SELECT
    FIRST(client_ip) AS client_ip,
    FIRST(url) AS url,
    FIRST(request_user_agent) AS request_user_agent,
    SUM(IF(cache_status = 'HIT', 1, 0)) AS hit
  FROM
    {fastly_log_tables}
  WHERE
    TIMESTAMP(LEFT(timestamp, 19)) >= TIMESTAMP('{start_timestamp}')
    AND TIMESTAMP(LEFT(timestamp, 19)) < TIMESTAMP('{end_timestamp}')
    AND NOT(url CONTAINS 'countBrandNewNotifications')
    AND LEFT(url, 5) != '/_ah/'
    -- Requests blocked at Fastly should be blocked much quicker than from us
    -- Note that time_elapsed is in microseconds.
    AND NOT (status == 403 AND time_elapsed <= 500)
    -- Requests that are quick redirects don't make it to our servers, so the
    -- impact is only on Fastly
    AND NOT (status == 308)
  GROUP BY
    request_id)
WHERE
  -- We only care about requests that are not cached.
  hit = 0
GROUP BY
  ip,
  url,
  user_agent
HAVING
  count > {max_count}
ORDER BY
  count DESC
"""

DOS_ALERT_INTRO = "*Possible DoS alert*\n"

DOS_ALERT_IP_INTRO_TEMPLATE = """\n\
*IP: <https://db-ip.com/{ip}|{ip}>* (<https://www.khanacademy.org/devadmin/users?ip={ip}|devadmin/users>)
*Reqs in last 5 minutes:*
"""
DOS_ALERT_IP_COUNT_TEMPLATE = (
    u" \u2022 {count} requsts to _{url}_ with UA `{user_agent}`\n")

DOS_ALERT_FOOTER = """\
Consider blocking IP using <https://manage.fastly.com/configure/services/2gbXxdf2yULJQiG4ZbnMVG/|Fastly>
Click "View active configuration", then go to "IP block list" under "Settings".

See requests in bq in fastly.khanacademy_dot_org_logs_YYYYMMDD table
"""

DOS_SAFELIST_URL_REGEX = [
    # Mobile team will fix in 6.9.0
    # TODO: remove after 2020401
    r'/api/auth2/request_token',
    # Known common browser path below
    # TODO (boris, INFRA-4452) to investigate these paths
    r'/mission/sat/tasks/.*',
    r'/math/.*',
    r'/computing/.*',
    # TODO (boris): 20200403 - legit user still having traffic,silence for now.
    r'/profile/Bloomsburgstudent/.*',
    # A high volume URL triggered by
    # https://github.com/Khan/khanflow-pipelines/blob/main/app/map_datastore_entities/map_datastore_entities.py
    r'/graphql/debugDatastoreMapMutation',
    r'/graphql/deleteTestUser',
    # Backfills
    r'/graphql/\w*[Bb]ackfill\w*',
    r'/graphql/phantomDeletion.*',
    r'/graphql/queueTask.*',
    r'/graphql/sync.*',
    # Learning Equality API use
    r'/graphql/LearningEquality_\w+',
    # We temporaily disable this as we are getting hit hard by extension
    # https://khanacademy.slack.com/archives/C02JH0F7EHY/p1636569182038500
    # TODO (INFRA-6713): Remove this after we filter Graphql rate limiting
    r'/api/internal/graphql/getFullUserProfile',
    # https://khanacademy.atlassian.net/browse/INFRA-6713 this mutation is
    # being rate-limited but we can't see that from the fastly logs - so don't
    # alert on it.
    r'(/api/internal)?/graphql/LoginWithPasswordMutation.*',
    # Fastly SYNTH routes - minimal impact and we use these internally.
    r'/_fastly/.*',
]

SCRATCHPAD_QUERY_TEMPLATE = """\
SELECT
  client_ip AS ip,
  COUNT(*) AS count
FROM
  {fastly_log_tables}
WHERE
  request = 'POST'
  AND url LIKE '/api/internal/scratchpads%'
  AND TIMESTAMP(LEFT(timestamp, 19)) >= TIMESTAMP('{start_timestamp}')
  AND TIMESTAMP(LEFT(timestamp, 19)) < TIMESTAMP('{end_timestamp}')
  AND at_edge_node
  AND status = 200
GROUP BY
  ip
HAVING
  count > {max_count}
ORDER BY
  count DESC
"""

SCRATCHPAD_ALERT_INTRO_TEMPLATE = """\
*Possible Scratchpad DoS alert*

Below is a list of IPs which have submitted more than {max_count} new
scratchpads in the last 5 minutes. A link to query a user by IP is included
below.\n
"""

SCRATCHPAD_ALERT_ENTRY_TEMPLATE = """\
IP: <https://db-ip.com/{ip}|{ip}>
Count: {count}
User by IP: <https://www.khanacademy.org/devadmin/users?ip={ip}>
"""

# Alert if there are more than this many new scratchpads created by an IP in
# the given period.
# 20190809: Bumping limit to 100 to avoid excess noise (Boris)
MAX_SCRATCHPADS = 100


# Heuristic for filtering out alert spikes from analysis at
# https://docs.google.com/spreadsheets/d/1-6fULwlcgtyYnssKgP644IHth7_k8VPGEzyZFfeSZWk/edit#gid=338414679
MAX_CDN_ERROR = 1000

# TODO (boris): We are temporarily upping this during INFRA-4276 from 0.5%
#     to avoid noisy background noise (current Feb max: 2.2%)
MAX_CDN_PERCENT = 0.025

CDN_ERROR_QUERY_TEMPLATE = """\
SELECT
  -- LEFT(16) give minutes - Legacy SQL doesn't have timestamp_trunc
  TIMESTAMP(LEFT(timestamp, 16)) AS minute_bucket,
  COUNT(*) AS traffic_count,
  SUM(CASE
    when status = 503 AND (request_id = '(null)' OR request_id IS NULL)
    THEN 1 END
    ) as err_count
FROM
  {fastly_log_tables}
WHERE
  at_edge_node
  AND TIMESTAMP(LEFT(timestamp, 19)) >= TIMESTAMP('{start_timestamp}')
  AND TIMESTAMP(LEFT(timestamp, 19)) < TIMESTAMP('{end_timestamp}')
GROUP BY
  minute_bucket
HAVING
  err_count / traffic_count > {max_cdn_percent}
  AND err_count > {max_count}
ORDER BY
  minute_bucket
"""

CDN_ALERT_INTRO_TEMPLATE = """\
*CDN error spike*

We saw a spike of CDN errors.  Please check on #1s-and-0s-deploys
to see if a deploy or rollback is happening (see <https://console.cloud.google.com/logs/viewer?project=khan-academy&folder&organizationId=733120332093&minLogLevel=0&expandAll=false&interval=PT1H&resource=gae_app&logName=projects%2Fkhan-academy%2Flogs%2Fcloudaudit.googleapis.com%252Factivity&advancedFilter=resource.type%3D%22gae_app%22%0AlogName%3D%22projects%2Fkhan-academy%2Flogs%2Fcloudaudit.googleapis.com%252Factivity%22%0AprotoPayload.methodName%3D%22google.appengine.v1.Services.UpdateService%22|audit log>).

This might cause any user issues at #zendesk-technical.


"""

CDN_ALERT_ENTRY_TEMPLATE = """\
Time: {minute_bucket} (in GMT)
Error rate: {percent:.2f}% ({err_count} / {traffic_count})

"""


def _fastly_log_tables(start, end, period):
    """Returns logs table name(s) to query from given the period for the logs.

    Previously we simply query from the hourly request logs table, which was
    around 3GB. Querying from the streaming fastly logs can process up to 50GB.
    For a script that runs every 5 minutes, this increases the cost
    significantly.

    To address this, we can assume that all logs timestamped at any given
    moment will arrive at the logs table within 5 mins. Then, we use table
    decorators to reduce the size of the table. A side effect of this is that
    we will have to use legacy SQL, and we can no longer use wild card matching
    on table names.
    """

    _MAX_LOG_DELAY_MS = 5 * 60 * 1000
    _TABLE_STRING_TEMPLATE = """
        [{project}.{dataset}.{table_prefix}_{table_date}@-{latest_duration}-]
    """

    # If a period goes into the previous day, we would also have to
    # consider the log tables from that day as well. This assumes that the
    # period this script is used on is <= 24 hours.
    overlapped_table_dates = [end] if end.day == start.day else [end, start]

    return ', '.join([
        _TABLE_STRING_TEMPLATE.format(
            project=BQ_PROJECT,
            dataset=FASTLY_DATASET,
            table_prefix=FASTLY_LOG_TABLE_PREFIX,
            table_date=table_date.strftime(TABLE_FORMAT),
            latest_duration=(period * 1000 + _MAX_LOG_DELAY_MS)
        ) for table_date in overlapped_table_dates
    ])


def dos_detect(end):
    start = end - datetime.timedelta(seconds=DOS_PERIOD)

    query = QUERY_TEMPLATE.format(
        fastly_log_tables=_fastly_log_tables(start, end, DOS_PERIOD),
        start_timestamp=start.strftime(TS_FORMAT),
        end_timestamp=end.strftime(TS_FORMAT),
        max_count=(MAX_REQS_SEC * DOS_PERIOD))
    results = bq_util.query_bigquery(query, project=BQ_PROJECT)

    # Stop processing if we don't have any flagged IPs
    if not results:
        return

    # Group by IPs to reduce duplicate alerts during a DDoS attack
    ip_groups = groupby(results, key=lambda row: row['ip'])

    msg = DOS_ALERT_INTRO
    any_alerts_seen = False
    for ip, rows in ip_groups:
        alerted_ip = False
        for row in rows:
            to_alert = not any([
                re.match(filter_regex, row['url'])
                for filter_regex in DOS_SAFELIST_URL_REGEX
            ])
            if to_alert:
                # Once for each IP, we show some default links...
                if not alerted_ip:
                    msg += DOS_ALERT_IP_INTRO_TEMPLATE.format(ip=ip)
                    alerted_ip = True

                # ... and then list any routes/UAs this IP is spamming
                msg += DOS_ALERT_IP_COUNT_TEMPLATE.format(**row)
                any_alerts_seen = True
        msg += '\n'
    if not any_alerts_seen:
        return
    msg += DOS_ALERT_FOOTER

    alertlib.Alert(msg).send_to_slack(ALERT_CHANNEL)


def scratchpad_detect(end):
    start = end - datetime.timedelta(seconds=SCRATCHPAD_PERIOD)
    scratchpad_query = SCRATCHPAD_QUERY_TEMPLATE.format(
        fastly_log_tables=_fastly_log_tables(start, end,
                                             SCRATCHPAD_PERIOD),
        start_timestamp=start.strftime(TS_FORMAT),
        end_timestamp=end.strftime(TS_FORMAT),
        max_count=MAX_SCRATCHPADS)

    scratchpad_results = bq_util.query_bigquery(scratchpad_query,
                                                project=BQ_PROJECT)

    if len(scratchpad_results) != 0:
        msg = SCRATCHPAD_ALERT_INTRO_TEMPLATE.format(max_count=MAX_SCRATCHPADS)
        msg += '\n'.join(SCRATCHPAD_ALERT_ENTRY_TEMPLATE.format(**row)
                         for row in scratchpad_results)
        alertlib.Alert(msg).send_to_slack(ALERT_CHANNEL)


def cdn_error_detect(end):
    """Detected 503 CDN error

    We have seen spike of these error during deploy/rollback.  This alert is
    a temporary measure to alert us when we see such error.
    """
    start = end - datetime.timedelta(seconds=CDN_ERROR_PERIOD)
    cdn_query = CDN_ERROR_QUERY_TEMPLATE.format(
        fastly_log_tables=_fastly_log_tables(start, end,
                                             CDN_ERROR_PERIOD),
        start_timestamp=start.strftime(TS_FORMAT),
        end_timestamp=end.strftime(TS_FORMAT),
        max_cdn_percent=MAX_CDN_PERCENT,
        max_count=MAX_CDN_ERROR
    )

    cdn_results = bq_util.query_bigquery(cdn_query, project=BQ_PROJECT)
    if len(cdn_results) != 0:
        msg = CDN_ALERT_INTRO_TEMPLATE
        msg += '\n'.join(

            CDN_ALERT_ENTRY_TEMPLATE.format(**{
                'minute_bucket': row['minute_bucket'],
                'err_count': row['err_count'],
                'traffic_count': row['traffic_count'],
                'percent': (
                    100. * float(row['err_count']) / int(row['traffic_count'])
                )
            })
            for row in cdn_results
        )
        alertlib.Alert(msg).send_to_slack(ALERT_CHANNEL)


def main():
    now = datetime.datetime.utcnow()

    dos_detect(now)
    scratchpad_detect(now)
    cdn_error_detect(now)


if __name__ == '__main__':
    main()
