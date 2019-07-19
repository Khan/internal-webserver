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

import alertlib
import bq_util
import dos_logs_util


BQ_PROJECT = 'khanacademy.org:deductive-jet-827'

# Alert if we're getting more than this many reqs per sec from a single client.
MAX_REQS_SEC = 4

# The size of the period of time to query.
PERIOD = 5 * 60

TABLE_FORMAT = '%Y%m%d_%H'
TS_FORMAT = '%Y-%m-%d %H:%M:%S'

ALERT_CHANNEL = '#infrastructure-sre'

QUERY_TEMPLATE = """\
SELECT
  ip,
  resource,
  user_agent,
  COUNT(*) AS count
FROM
  ({request_logs_query})
WHERE
  module_id NOT IN ( 'batch', 'react-render')
  AND start_time_timestamp >= TIMESTAMP('{start_timestamp}')
  AND start_time_timestamp < TIMESTAMP('{end_timestamp}')
  AND NOT(resource CONTAINS 'countBrandNewNotifications')
  AND LEFT(resource, 5) != '/_ah/'
GROUP BY
  ip,
  resource,
  user_agent
HAVING
  count > {max_count}
ORDER BY
  count DESC
"""

ALERT_TEMPLATE = """\
*Possible DoS alert*
IP: <https://db-ip.com/{ip}|{ip}>
Reqs in last 5 minutes: {count}
URL: {resource}
User agent: {user_agent}

Consider blacklisting IP using appengine firewall.

<{log_link}|Stackdriver logs of reqs from IP>
"""

SCRATCHPAD_QUERY_TEMPLATE = """\
SELECT
  ip,
  FIRST(elog_user_kaid) as sample_kaid,
  COUNT(bingo_conversion_events) AS COUNT
FROM
  FLATTEN(({request_logs_query}), bingo_conversion_events)
WHERE
  bingo_conversion_events = 'scratchpad_new_created'
  AND start_time_timestamp >= TIMESTAMP('{start_timestamp}')
  AND start_time_timestamp < TIMESTAMP('{end_timestamp}')
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
scratchpads in the last 5 minutes. A sample user's profile for each IP can be
found through the links below.\n
"""

SCRATCHPAD_ALERT_ENTRY_TEMPLATE = """\
IP: <https://db-ip.com/{ip}|{ip}>
Count: {count}
Sample: <https://www.khanacademy.org/profile/{sample_kaid}/projects|{sample_kaid}>
"""

# Alert if there are more than this many new scratchpads created by an IP in
# the given period.
MAX_SCRATCHPADS = 50


def dos_detect(start, end):
    query = QUERY_TEMPLATE.format(
            request_logs_query=(
                dos_logs_util.latest_logs_for_ddos_query(PERIOD)),
            start_table=start.strftime(TABLE_FORMAT),
            end_table=end.strftime(TABLE_FORMAT),
            start_timestamp=start.strftime(TS_FORMAT),
            end_timestamp=end.strftime(TS_FORMAT),
            max_count=(MAX_REQS_SEC * PERIOD))
    results = bq_util.call_bq(['query', query], project=BQ_PROJECT)

    for row in results:
        log_link = 'https://console.cloud.google.com/logs/viewer?project=khan-academy&folder=&organizationId=733120332093&minLogLevel=0&expandAll=false&advancedFilter=resource.type%3D%22gae_app%22%0AlogName%3D%22projects%2Fkhan-academy%2Flogs%2Fappengine.googleapis.com%252Frequest_log%22%0AprotoPayload.ip%3D%22{}%22'.format(row['ip'])
        msg = ALERT_TEMPLATE.format(log_link=log_link, **row)
        alertlib.Alert(msg).send_to_slack(ALERT_CHANNEL)


def scratchpad_detect(start, end):
    scratchpad_query = SCRATCHPAD_QUERY_TEMPLATE.format(
            request_logs_query=(
                dos_logs_util.latest_logs_for_ddos_query(PERIOD)),
            start_table=start.strftime(TABLE_FORMAT),
            end_table=end.strftime(TABLE_FORMAT),
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
    end = datetime.datetime.utcnow()
    start = end - datetime.timedelta(seconds=PERIOD)

    dos_detect(start, end)
    scratchpad_detect(start, end)


if __name__ == '__main__':
    main()
