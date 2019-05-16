#!/usr/bin/env python
"""A script for alerting pageload pangolin alerts.

This script does a very simplistic check for Performance and
error regressions.
"""

import argparse
from collections import defaultdict, namedtuple
import datetime

import numpy as np

import alertlib
import bq_util


DATA_TYPE_PERF = 'Performance'
DATA_TYPE_ERROR = 'Error'

ALERT_PARAMETER = {
    DATA_TYPE_PERF: {
        "window": 7,
        "threshold": 6,
        "min_alert_value": None
    },
    DATA_TYPE_ERROR: {
        "window": 7,
        "threshold": 20,
        "min_alert_value": 100
    }
}

MIN_COUNT_PER_DAY = 500
# ALERT_CHANNEL = '#infra-regression'
ALERT_CHANNEL = '#boris-bot-test'

BQ_PROJECT = 'khanacademy.org:deductive-jet-827'

# from kpi-dashboard: lib/performance/page.buckets.sql
PERFORMANCE_QUERY = """\
#standardSQL
WITH
  countries AS (
    SELECT
      country,
      weight
    FROM
      `khanacademy.org:deductive-jet-827.infrastructure.perf_kpi_country_weights`),
  weights AS (
    SELECT
      page AS page_name,
      IFNULL(weight, 1) AS weight,
      IFNULL(sampled, 1.0) as sampled_at
    FROM
      `khanacademy.org:deductive-jet-827.infrastructure.perf_kpi_team_weights`),
  combined AS (
    SELECT
      page_name,
      date,
      speed,
      SUM(countries.weight * weights.weight * (count / weights.sampled_at))
             AS count
    FROM
      `khanacademy.org:deductive-jet-827.infrastructure.perf_kpi_buckets`
      LEFT JOIN countries USING (country)
      LEFT JOIN weights USING (page_name)
    WHERE
        date >= "{start_date}"
        AND date <= "{end_date}"
    GROUP BY
      page_name,
      date,
      speed)
SELECT
  page_name,
  date,
  ARRAY_AGG(STRUCT(speed, ROUND(count, 1) AS count)) AS speeds
FROM combined
GROUP by page_name, date
"""

# from kpi-dashboard: lib/performance/route.errors.sql
ERROR_QUERY = """\
#standardSQL
SELECT
  re.count AS error_count,
  re.route AS route,
  re.date AS date
FROM `khanacademy.org:deductive-jet-827.infrastructure.route_errors` AS re
WHERE
    re.date >= "{start_date}"
    AND re.date <= "{end_date}"
    AND re.is_user_facing
ORDER BY re.date ASC
"""

DB_DATE_FMT = '%Y-%m-%d'


class DataSource:
    """Data source which we get the performance/regression alerts.
    """

    def __init__(self, start_date, end_date):
        self.data = {}  # type: Dict[str, Dict[date, float]]

    @property
    def keys(self):
        return self.data.keys()

    def get_key_data(self, key):
        return self.data[key]


class PerformanceDataSource(DataSource):
    """Getting performance data from the timeframe required
    """
    BAD_SPEED_NAMES = {'slow', 'unacceptable'}

    def get_acceptable_or_faster(self, row):
        assert 'speeds' in row
        wanted_speeds = filter(
            lambda spds: spds['speed'] not in self.BAD_SPEED_NAMES,
            row['speeds']
        )
        return sum(map(lambda spds: float(spds['count']), wanted_speeds))

    def get_acceptable_or_faster_percent(self, row):
        faster_count = self.get_acceptable_or_faster(row)
        all_count = sum(map(lambda spds: float(spds['count']), row['speeds']))
        if all_count <= MIN_COUNT_PER_DAY:
            return None
        return faster_count / all_count

    def get_page_accceptable_percent(self, bq_data):
        page_name_date = defaultdict(dict)
        for row in bq_data:
            date_speed = page_name_date[row['page_name']]
            date_speed[row['date']
                       ] = self.get_acceptable_or_faster_percent(row)
        return page_name_date

    def __init__(self, start_date, end_date):
        report_name = 'performance_daily'
        report_date = start_date.strftime('%Y%m%d')
        query = PERFORMANCE_QUERY.format(
            start_date=start_date.strftime(DB_DATE_FMT),
            end_date=end_date.strftime(DB_DATE_FMT)
        )
        bq_data = bq_util.get_daily_data_from_disk_or_bq(
            query, report_name, report_date
        )
        self.data = self.get_page_accceptable_percent(bq_data)


class ErrorDataSource(DataSource):
    """Getting error data from the timeframe required
    """

    def __init__(self, start_date, end_date):
        report_name = 'error_daily'
        report_date = start_date.strftime('%Y%m%d')
        query = ERROR_QUERY.format(
            start_date=start_date.strftime(DB_DATE_FMT),
            end_date=end_date.strftime(DB_DATE_FMT)
        )
        bq_data = bq_util.get_daily_data_from_disk_or_bq(
            query, report_name, report_date
        )

        error_data = defaultdict(dict)
        for row in bq_data:
            error_data[row['route']][row['date']] = row['error_count']
        self.data = error_data


Alert = namedtuple("Alert", [
    "alert_type",  # type: str
    "key",  # type: str
    "drop",  # type: bool
    "reason"  # type: str
])


def find_alerts(data_type, key, data, threshold, window, min_alert_value):
    """Trigger alert based on variance from last week

    From our research, we settled on looking at variance, with a
    window of 7 days.

    Research is documented at
    https://docs.google.com/document/d/1c-T5KU4TaeUbJbzJeyAH0eJKb24y1M3adW6bVF1NIuc/edit#heading=h.gefi31qqd7t

    This execute the pandas code similar to:
    https://stackoverflow.com/questions/47164950/compute-rolling-z-score-in-pandas-dataframe

        r = x.rolling(window=7)
        m = r.mean().shift(1)
        s = r.std(ddof=0).shift(1)
        z = (x-m)/s
    """
    # all data must present in order for alert to trigger (high detecion value)
    if [d for d in data.values() if d is None]:
        print("{}: Skipping with insufficient data".format(key))
        return None

    data_array = [data[k] for k in sorted(data.keys())]

    windowed_data = data_array[-window-1:-1]
    last_value = data_array[-1]

    # filter for min alert value
    if min_alert_value is not None:
        if last_value <= min_alert_value:
            return None

    mean = np.mean(windowed_data)
    std = np.std(windowed_data)
    if std <= 0:
        print("{}: Skipping with zero variance".format(key))
        return None

    last_zscore = (last_value - mean) / std
    perc_change = 100. * (last_value - mean) / mean

    print(
        "Comparing {key}: ".format(key=key) +
        "mean={mean:.2f} -> last_value={last_value:.2f} ".format(**locals()) +
        "(z: {last_zscore:.2f}, pec: {perc_change:.2f}%)".format(**locals())
    )
    if last_zscore < -threshold:
        return Alert(
            alert_type=data_type,
            drop=True,
            key=key,
            reason="""
Metrics was significantly dropped compare last {window} days threshold.

Average: {mean:.2f} -> Yesterday: {last_value:.2f}
({perc_change:.2f}% change, variance: {last_zscore:.2f} < {threshold})
            """.format(**locals())
        )
    elif last_zscore > threshold:
        return Alert(
            alert_type=data_type,
            drop=False,
            key=key,
            reason="""\
Metrics was significantly increase compare to last {window} days threshold.

Average: {mean:.2f} -> Yesterday: {last_value:.2f}
({perc_change:.1f}% change, variance: {last_zscore:.2f} > {threshold})
            """.format(**locals())
        )
    return None


def alert_message(alert, date):
    date_str = date.strftime("%Y-%m-%d")
    if alert.alert_type == DATA_TYPE_ERROR:
        if not alert.drop:
            return """\
:warning: *Error alert* for {date_str}
We noticed a rise in error for `{a.key}`.

{a.reason}

Check the <https://kpi-infrastructure.appspot.com/team|kpi-dashbaord> for detail.
            """.format(a=alert, date_str=date_str)
        else:
            # Do not alert on error improvement
            return
    elif alert.alert_type == DATA_TYPE_PERF:
        if alert.drop:
            return """\
:warning: *Performance alert* for {date_str}
We noticed a drop in performance for `{a.key}`.

{a.reason}

Check the <https://kpi-infrastructure.appspot.com/performance#page-section|kpi-dashbaord> for detail.
            """.format(a=alert, date_str=date_str)
        else:
            return """\
:white_check_mark: *Performance high-five* for {date_str}
We noticed a better performance for `{a.key}`.

{a.reason}

Check the <https://kpi-infrastructure.appspot.com/performance#page-section|kpi-dashbaord> for detail.
            """.format(a=alert, date_str=date_str)
    raise KeyError("Unknown data type: {}".format(alert.alert_type))


def main(args):
    end = args.date
    # We need an extra day to look at the past information

    all_data = {
        DATA_TYPE_PERF: PerformanceDataSource,
        DATA_TYPE_ERROR: ErrorDataSource
    }

    for data_type, data_source in all_data.items():
        if args.error and data_type != DATA_TYPE_ERROR:
            continue
        if args.perf and data_type != DATA_TYPE_PERF:
            continue

        window = args.window if args.window \
            else ALERT_PARAMETER[data_type]['window']
        threshold = args.threshold if args.threshold \
            else ALERT_PARAMETER[data_type]['threshold']
        min_alert_value = ALERT_PARAMETER[data_type]['min_alert_value']

        start = end - datetime.timedelta(days=window+1)
        data = data_source(start, end)

        for page in data.keys:
            alert = find_alerts(data_type, page, data.get_key_data(page),
                                window=window, threshold=threshold,
                                min_alert_value=min_alert_value
                                )
            if alert:
                alert_msg = alert_message(alert, args.date)
                if alert_msg:
                    print("{key}: Sending alert: {alert}".format(
                        key=page, alert=alert))
                    alertlib.Alert(alert_msg).send_to_slack(args.channel)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--window', '-w', type=int, metavar='n',
                        help="Specify window of days to use")
    parser.add_argument('--threshold', '-t', type=int, metavar='n',
                        help="Specify variance threshold")
    parser.add_argument('--date', '-d',
                        type=lambda s: datetime.datetime.strptime(
                            s, '%Y-%m-%d'),
                        default=datetime.datetime.utcnow(),
                        help='End date of the query')
    parser.add_argument('--channel', '-c',
                        default=ALERT_CHANNEL,
                        help='Which channel to send alert to')
    parser.add_argument('--error', action='store_true',
                        help='Only run error filters')
    parser.add_argument('--perf', action='store_true',
                        help='Only run performance filters')
    args = parser.parse_args()
    main(args)
