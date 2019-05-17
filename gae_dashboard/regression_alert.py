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

MIN_COUNT_PER_DAY = 500
DAYS_WINDOW = 7
THRESHOLD = 6
ALERT_CHANNEL = '#infra-regression'

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

DB_DATE_FMT = '%Y-%m-%d'


class DataSource:
    """Data source which we get the performance/regression alerts.
    """

    def readable_metrics(self, metric):
        """Given a metric, return a readble format."""
        raise NotImplementedError()

    def filter(self, start_date, end_date):
        """Return a supset of data specific by start/end date"""
        raise NotImplementedError()


class PerformanceDataSource(DataSource):
    """Getting performance data from the timeframe required
    """
    BAD_SPEED_NAMES = {'slow', 'unacceptable'}

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
        self.data = self.__get_page_accceptable_percent(bq_data)

    def readable_metrics(self, metric):
        """Given a metric, return a readble format."""
        return "{:.1f}%% acceptable or faster".format(metric)

    def __get_acceptable_or_faster(self, row):
        assert 'speeds' in row
        wanted_speeds = filter(
            lambda spds: spds['speed'] not in self.BAD_SPEED_NAMES,
            row['speeds']
        )
        return sum(map(lambda spds: float(spds['count']), wanted_speeds))

    def __get_acceptable_or_faster_percent(self, row):
        faster_count = self.__get_acceptable_or_faster(row)
        all_count = sum(map(lambda spds: float(spds['count']), row['speeds']))
        if all_count <= MIN_COUNT_PER_DAY:
            return None
        return faster_count / all_count

    def __get_page_accceptable_percent(self, bq_data):
        page_name_date = defaultdict(dict)
        for row in bq_data:
            date_speed = page_name_date[row['page_name']]
            date_speed[row['date']
                       ] = self.__get_acceptable_or_faster_percent(row)
        return page_name_date

    @property
    def page_names(self):
        return self.data.keys()

    def get_page_data(self, page_name):
        return self.data[page_name]


Alert = namedtuple("Alert", [
    "alert_type",  # type: str
    "page_name",  # type: str
    "drop",  # type: bool
    "reason"  # type: str
])


def find_alerts(page_name, data_source, threshold=THRESHOLD, window=DAYS_WINDOW):
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
    data = data_source.get_page_data(page_name)

    # all data must present in order for alert to trigger (high detecion value)
    if [d for d in data.values() if d is None]:
        print("{}: Skipping with insufficient data".format(page_name))
        return None

    data_array = [data[k] for k in sorted(data.keys())]

    windowed_data = data_array[-window-1:-1]
    last_value = data_array[-1]

    mean = np.mean(windowed_data)
    std = np.std(windowed_data)
    if std <= 0:
        print("{}: Skipping with zero variance".format(page_name))
        return None

    last_zscore = (last_value - mean) / std
    perc_change = 100. * (last_value - mean) / mean

    print(
        "Comparing {page_name}: ".format(page_name=page_name) +
        "mean={mean} -> last_value={last_value} ".format(
            mean=data_source.readable_metrics(mean),
            last_value=data_source.readable_metrics(last_value)
        ) +
        "(z: {last_zscore:.2f}, pec: {perc_change:.2f}%)".format(**locals())
    )
    if last_zscore < -threshold:
        return Alert(
            alert_type='Performance',
            drop=True,
            page_name=page_name,
            reason="""
Performance was significantly worse compare last {window} days threshold.

Average: {mean:.2f} -> Yesterday: {last_value:.2f}
({perc_change:.2f}% change, variance: {last_zscore:.2f} < {threshold})
            """.format(**locals())
        )
    elif last_zscore > threshold:
        return Alert(
            alert_type='Performance',
            drop=False,
            page_name=page_name,
            reason="""\
Performance was significantly better compare to last {window} days threshold.

Average: {mean:.2f} -> Yesterday: {last_value:.2f}
({perc_change:.1f}% change, variance: {last_zscore:.2f} > {threshold})
            """.format(**locals())
        )
    return None


def alert_message(alert, date):
    date_str = date.strftime("%Y-%m-%d")
    if alert.drop:
        return """\
:warning: *Performance alert* for {date_str}
We noticed a drop in performance for `{a.page_name}`.

{a.reason}

Check the <https://kpi-infrastructure.appspot.com/performance#page-section|kpi-dashbaord> for detail.
        """.format(a=alert, date_str=date_str)
    else:
        return """\
:white_check_mark: *Performance high-five* for {date_str}
We noticed a better performance for `{a.page_name}`.

{a.reason}

Check the <https://kpi-infrastructure.appspot.com/performance#page-section|kpi-dashbaord> for detail.
        """.format(a=alert, date_str=date_str)


def main(args):
    end = args.date
    # We need an extra day to look at the past information
    start = end - datetime.timedelta(days=args.window+1)

    data = PerformanceDataSource(start, end)

    for page in data.page_names:
        alert = find_alerts(page, data,
                            window=args.window, threshold=args.threshold
                            )
        if alert:
            print("{page}: Sending alert: {alert}".format(
                page=page, alert=alert))
            alertlib.Alert(alert_message(alert, args.date)).send_to_slack(
                args.channel)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--window', '-w', type=int, metavar='n',
                        help="Specify window of days to use",
                        default=DAYS_WINDOW)
    parser.add_argument('--threshold', '-t', type=int, metavar='n',
                        help="Specify variance threshold",
                        default=THRESHOLD)
    parser.add_argument('--date', '-d',
                        type=lambda s: datetime.datetime.strptime(
                            s, '%Y-%m-%d'),
                        default=datetime.datetime.utcnow(),
                        help='End date of the query')
    parser.add_argument('--channel', '-c',
                        default=ALERT_CHANNEL,
                        help='Which channel to send alert to')
    args = parser.parse_args()
    main(args)
