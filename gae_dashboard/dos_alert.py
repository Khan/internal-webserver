#!/usr/bin/env python
"""Check request logs to look for an in progress DoS attack.

This script does a very simplistic check for DoS attack and notifies us via
slack if it notices anything that looks like a DoS attack.

Note: we are only checking for the most simple types of attacks - a single
client requesting the same URL repeatedly.

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


BQ_PROJECT = 'khanacademy.org:deductive-jet-827'

# Alert if we're getting more than this many reqs per sec from a single client.
MAX_REQS_SEC = 2

# The size of the period of time to query.
PERIOD = 5 * 60

QUERY_TEMPLATE = """\
SELECT
  ip,
  resource,
  user_agent,
  COUNT(*) AS count
FROM
  `logs_hourly.requestlogs_*`
WHERE
  module_id NOT IN ( 'batch', 'react-render')
  AND start_time_timestamp >= TIMESTAMP('{START_TIMESTAMP}')
  AND start_time_timestamp < TIMESTAMP('{END_TIMESTAMP}')
  AND STRPOS(resource, 'countBrandNewNotifications') = 0
  AND NOT STARTS_WITH(resource, '/_ah/')
  AND _TABLE_SUFFIX BETWEEN '{START_TABLE}' and '{END_TABLE}'
GROUP BY
  ip,
  resource,
  user_agent
HAVING
  count > {MAX_COUNT}
ORDER BY
  count DESC
"""

ALERT_TEMPLATE = """\
*Possible DoS alert*
IP: {ip}
Reqs in last 5 minutes: {count}
URL: {resource}
User agent: {user_agent}

Consider blacklisting IP using appengine firewall.
"""


def main():
    end = datetime.datetime.utcnow()
    start = end - datetime.timedelta(seconds=PERIOD)
    table_format = '%Y%m%d_%H'
    ts_format = '%Y-%m-%d %H:%M:%S'
    query = QUERY_TEMPLATE.format(
        **{'START_TABLE': start.strftime(table_format),
           'END_TABLE': end.strftime(table_format),
           'START_TIMESTAMP': start.strftime(ts_format),
           'END_TIMESTAMP': end.strftime(ts_format),
           'MAX_COUNT': MAX_REQS_SEC * PERIOD})
    results = bq_util.call_bq(['query', '--nouse_legacy_sql', query],
                              project=BQ_PROJECT)

    for row in results:
        msg = ALERT_TEMPLATE.format(**row)
        alertlib.Alert(msg).send_to_slack('#infrastructure-sre')


if __name__ == '__main__':
    main()
