#!/usr/bin/env python
"""Check request logs to look for an in progress DoS attack.

This script does a very simplistic check for DoS attack and notifies us via
slack if it notices anything that looks like a DoS attack.

Note: we are only checking for two simple types of attacks - a single
client requesting the same URL repeatedly, and scratchpad spam.

The hope is that this alerting will allow us to blacklist the offending IP
address using the appengine firewall.

Note that we exclude URLs like these
`/api/internal/user/profile?kaid=...&projection=%7B%22countBrandNewNotifications%22:1%7D`
which are currently being requested 80 times a minute by some clients. I'm not
sure whether this is due to a bug or by design, but I don't think it's a DoS.
"""

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
FROM
  {fastly_log_tables}
WHERE
  TIMESTAMP(LEFT(timestamp, 19)) >= TIMESTAMP('{start_timestamp}')
  AND TIMESTAMP(LEFT(timestamp, 19)) < TIMESTAMP('{end_timestamp}')
  AND NOT(url CONTAINS 'countBrandNewNotifications')
  AND LEFT(url, 5) != '/_ah/'
  AND at_edge_node
  -- Requests blocked at Fastly should be blocked much quicker than from us
  AND NOT (status == 403 AND time_elapsed <= 200)
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
Consider blacklisting IP using <https://manage.fastly.com/configure/services/2gbXxdf2yULJQiG4ZbnMVG/|Fastly>
Click "View active configuration", then go to "IP block list" under "Settings".

See requests in bq in fastly.khanacademy_dot_org_logs_YYYYMMDD table
"""

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
    results = bq_util.call_bq(['query', query], project=BQ_PROJECT)

    # Stop processing if we don't have any flagged IPs
    if not results:
        return

    # Group by IPs to reduce duplicate alerts during a DDoS attack
    ip_groups = groupby(results, key=lambda row: row['ip'])

    msg = DOS_ALERT_INTRO
    for ip, rows in ip_groups:
        # For each IP, we show some default links...
        msg += DOS_ALERT_IP_INTRO_TEMPLATE.format(ip=ip)
        for row in rows:
            # ... and then list any routes/UAs this IP is spamming
            msg += DOS_ALERT_IP_COUNT_TEMPLATE.format(**row)
        msg += '\n'
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

    scratchpad_results = bq_util.call_bq(['query', scratchpad_query],
                                         project=BQ_PROJECT)

    if len(scratchpad_results) != 0:
        msg = SCRATCHPAD_ALERT_INTRO_TEMPLATE.format(max_count=MAX_SCRATCHPADS)
        msg += '\n'.join(SCRATCHPAD_ALERT_ENTRY_TEMPLATE.format(**row)
                         for row in scratchpad_results)
        alertlib.Alert(msg).send_to_slack(ALERT_CHANNEL)


def main():
    now = datetime.datetime.utcnow()

    dos_detect(now)
    scratchpad_detect(now)


if __name__ == '__main__':
    main()
