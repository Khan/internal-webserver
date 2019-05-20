#!/usr/bin/env python
"""A script for alerting pageload pangolin alerts.

This script does a very simplistic check for Performance and
error regressions.
"""

import argparse
from collections import defaultdict, namedtuple
import datetime
import itertools

import numpy as np

import alertlib
import bq_util

# Some explaination on the parameters:
AlertParameters = namedtuple("AlertParameters", [
    # Number of days to look back at.
    # Generally we want this to be multiple of 7 to include weekend variance.
    # Increasing will make the alert less noisy.
    "window",  # type: int
    # Number of stanard deviation over the past days_window we accept.
    # We want this number > 1 (i.e. outside normal bound of the window).
    # Increasing will make the alert less noisy.
    "threshold",  # type: int
    # Minimal value to raise alert
    # Useful for filtering noisy errors.
    "min_alert_value",  # type: Maybe[int]
])

DATA_TYPE_PERF = 'Performance'
DATA_TYPE_ERROR = 'Server Error'
DATA_TYPE_CLIENT_ERROR = 'Client Error'

ALERT_PARAMETER = {
    DATA_TYPE_PERF: AlertParameters(
        window=7,
        threshold=6,
        min_alert_value=None
    ),
    DATA_TYPE_ERROR: AlertParameters(
        window=14,  # Since server errors are a lot spiker over week
        threshold=20,
        min_alert_value=100
    ),
    DATA_TYPE_CLIENT_ERROR: AlertParameters(
        window=7,
        threshold=6,
        min_alert_value=100
    )
}

MIN_COUNT_PER_DAY = 500
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

# from kpi-dashboard: lib/team/route.errors.sql
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

# from kpi-dashboard: lib/team/sentry.file.errors.sql
SENTRY_ERROR_QUERY = """\
#standardSQL
SELECT
  `timestamp` AS date,
  error_count,
  initiative_counts
FROM `khanacademy.org:deductive-jet-827.infrastructure.sentry_errors`
WHERE
    project = 'prod-js'
    AND `timestamp` >= "{start_date}"
    AND `timestamp` <= "{end_date}"
ORDER BY `timestamp` ASC
"""

DB_DATE_FMT = '%Y-%m-%d'


class DataSource:
    """Data source which we get the performance/regression alerts.
    """

    def __init__(self, start_date, end_date):
        self.data = {}  # type: Dict[str, Dict[date, float]]

    def readable_metrics(self, metric):
        """Given a metric, return a readble format."""
        raise NotImplementedError()

    def fill_all_days(self, value):
        """For days that is missing data, backfill with suitable values"""
        all_dates = set(itertools.chain(*map(
            lambda date_data: date_data.keys(),
            self.data.values()
        )))
        min_date = datetime.datetime.strptime(min(all_dates), DB_DATE_FMT)
        max_date = datetime.datetime.strptime(max(all_dates), DB_DATE_FMT)
        day_count = (max_date - min_date).days
        days_expected = {
            (min_date + datetime.timedelta(days=days)).strftime(DB_DATE_FMT)
            for days in range(day_count+1)
        }
        # assert max(days_expected) == max(all_dates)

        for k in self.data.keys():
            date_data = self.data[k]
            days_avaliable = set(date_data.keys())
            days_missing = days_expected - days_avaliable
            for date in days_missing:
                # print("{}: missing date {}".format(k, date))
                date_data[date] = value

    @property
    def keys(self):
        return self.data.keys()

    def get_key_data(self, key):
        return self.data[key]


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
        self.fill_all_days(None)

    def readable_metrics(self, metric):
        """Given a metric, return a readble format."""
        return "{:.1f}% acceptable or faster".format(100. * metric)

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
        self.fill_all_days(0)

    def readable_metrics(self, metric):
        """Given a metric, return a readble format."""
        return "{} errors per day".format(metric)


class SentryErrorDataSource(DataSource):
    """Getting error data from the timeframe required
    """

    def __init__(self, start_date, end_date):
        report_name = 'sentry_error_daily'
        report_date = start_date.strftime('%Y%m%d')
        query = SENTRY_ERROR_QUERY.format(
            start_date=start_date.strftime(DB_DATE_FMT),
            end_date=end_date.strftime(DB_DATE_FMT)
        )
        bq_data = bq_util.get_daily_data_from_disk_or_bq(
            query, report_name, report_date
        )

        error_data = defaultdict(dict)
        # truncating datetime string to date string
        date_length = 10

        for row in bq_data:
            for initiatives in row['initiative_counts']:
                for file in initiatives['files']:
                    if file['name'] == '<Unknown>':
                        continue
                    error_data[file['name']][row['date'][:date_length]] = (
                        int(file['count'])
                    )
        self.data = error_data
        self.fill_all_days(0)

    def readable_metrics(self, metric):
        """Given a metric, return a readble format."""
        return "{} errors per day".format(metric)


Alert = namedtuple("Alert", [
    "alert_type",  # type: str
    "key",  # type: str
    "drop",  # type: bool
    "reason"  # type: str
])


def find_alerts(data_type, key, data_source, threshold, window,
                min_alert_value):
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
    data = data_source.get_key_data(key)

    # all data must present in order for alert to trigger (high detecion value)

    days_with_data = [k for k in sorted(data.keys()) if data[k] is not None]
    if len(days_with_data) < window+1:
        print("{}: Skipping with insufficient data (days={})".format(
            key, days_with_data))
        return None

    data_array = [data[k] for k in days_with_data]

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

    readable_mean = data_source.readable_metrics(mean)
    readable_last_value = data_source.readable_metrics(last_value)

    print(
        "Comparing {key}: ".format(key=key) +
        "mean={readable_mean} -> last_value={readable_last_value} ".format(
            **locals()) +
        "(z: {last_zscore:.2f}, pec: {perc_change:.2f}%)".format(**locals())
    )
    if last_zscore < -threshold:
        return Alert(
            alert_type=data_type,
            drop=True,
            key=key,
            reason="""
Metrics dropped significantly compare to last {window} days.

Average: {readable_mean} -> Yesterday: {readable_last_value}
({perc_change:.2f}% change, variance: {last_zscore:.2f} < {threshold})
            """.format(**locals())
        )
    elif last_zscore > threshold:
        return Alert(
            alert_type=data_type,
            drop=False,
            key=key,
            reason="""\
Metrics increased significantly compare to last {window} days.

Average: {readable_mean} -> Yesterday: {readable_last_value}
({perc_change:.1f}% change, variance: {last_zscore:.2f} > {threshold})
            """.format(**locals())
        )
    return None


def alert_message(alert, date):
    date_str = date.strftime("%Y-%m-%d")
    if alert.alert_type in {DATA_TYPE_ERROR, DATA_TYPE_CLIENT_ERROR}:
        if not alert.drop:
            return """\
:warning: *{a.alert_type} alert* for {date_str}
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
        DATA_TYPE_ERROR: ErrorDataSource,
        DATA_TYPE_CLIENT_ERROR: SentryErrorDataSource
    }

    for data_type, data_source in all_data.items():
        if args.error and data_type != DATA_TYPE_ERROR:
            continue
        if args.perf and data_type != DATA_TYPE_PERF:
            continue
        if args.clienterror and data_type != DATA_TYPE_CLIENT_ERROR:
            continue

        window = args.window if args.window \
            else ALERT_PARAMETER[data_type].window
        threshold = args.threshold if args.threshold \
            else ALERT_PARAMETER[data_type].threshold
        min_alert_value = ALERT_PARAMETER[data_type].min_alert_value

        start = end - datetime.timedelta(days=window+1)
        data = data_source(start, end)

        for key in data.keys:
            alert = find_alerts(data_type, key, data,
                                window=window, threshold=threshold,
                                min_alert_value=min_alert_value
                                )
            if alert:
                alert_msg = alert_message(alert, args.date)
                if alert_msg:
                    print("{key}: Sending alert: {alert}".format(
                        key=key, alert=alert))
                    alertlib.Alert(alert_msg).send_to_slack(args.channel)


if __name__ == '__main__':
    yesterday = datetime.datetime.utcnow() - datetime.timedelta(days=1)
    parser = argparse.ArgumentParser()
    parser.add_argument('--window', '-w', type=int, metavar='n',
                        help="Specify window of days to use")
    parser.add_argument('--threshold', '-t', type=int, metavar='n',
                        help="Specify variance threshold")
    parser.add_argument('--date', '-d',
                        type=lambda s: datetime.datetime.strptime(
                            s, '%Y-%m-%d'),
                        default=yesterday,
                        help='End date of the query')
    parser.add_argument('--channel', '-c',
                        default=ALERT_CHANNEL,
                        help='Which channel to send alert to')
    parser.add_argument('--error', action='store_true',
                        help='Only run error filters')
    parser.add_argument('--perf', action='store_true',
                        help='Only run performance filters')
    parser.add_argument('--clienterror', action='store_true',
                        help='Only run client error filters')
    args = parser.parse_args()
    main(args)
