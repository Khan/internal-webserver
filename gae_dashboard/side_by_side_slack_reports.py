#!/usr/bin/env python
"""
A script to pipe side-by-side testing results from bigquery to slack daily.

This script queries bigquery for a count of all side-by-side errors for the
previous day, groups them by responsible team and sends them to slack. It's
intended to be run once a day via cron.

Within each slack message, we provide a link to a mode dashboard where
developers can get a bit more detail for the specific errors they are seeing.
"""
import datetime

import alertlib
import bq_util

import initiatives

COUNTS_BY_OPERATION = """
#standardSQL
SELECT operation_name,
       count(*) AS num_requests
FROM `khanacademy.org:deductive-jet-827.side_by_side.side_by_side_result_*`
WHERE _TABLE_SUFFIX="{date_param}"
GROUP BY 1
ORDER BY 2 DESC;
"""

REPORT_LINK = (
 "https://app.mode.com/khanacademy/reports/19660fc6f110"
 "?param_operation_name={operation_name}&param_date_param={date}"
)


# Returns list of {'operation_name': 'foo', 'num_requests': 1234} dicts.
def fetch_counts_from_bq(date):
    query = COUNTS_BY_OPERATION.format(date_param=date.strftime('%Y%m%d'))
    return bq_util.query_bigquery(query)


def group_by_team(results):
    results_by_team = {}
    for row in results:
        for owner in initiatives.graphql_query_owners(row["operation_name"]):
            if owner not in results_by_team:
                results_by_team[owner] = []
            results_by_team[owner].append(row)
    return results_by_team


def send_to_slack(results_by_team, date):
    date_str = date.strftime("%Y%m%d")
    for team, results in results_by_team.items():
        intro = "*{team}* results for {date}\n".format(
            team=initiatives.title(team), date=date_str)
        intro += "Click on any operation name below for more details:\n"
        msg = ""
        for row in results:
            link = REPORT_LINK.format(
                operation_name=row["operation_name"],
                date=date_str
            )
            msg += (
                "<{link}|{operation_name}>: {num_requests} requests"
                " with side-by-side errors\n".format(
                    operation_name=row["operation_name"],
                    num_requests=row["num_requests"],
                    link=link
                ))

        alertlib.Alert(msg).send_to_slack(
                channel="#goliath-side-by-side-test-results",
                intro=intro,
                sender="Side by Side Spider",
                icon_emoji=":spider:")


def main():
    yesterday = datetime.date.today() - datetime.timedelta(days=1)
    results = fetch_counts_from_bq(yesterday)
    results_by_team = group_by_team(results)
    send_to_slack(results_by_team, yesterday)


if __name__ == '__main__':
    main()
