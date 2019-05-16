#!/usr/bin/env python3
"""A script for alerting pageload pangolin alerts.

This script does a very simplistic check for Performance and
error regressions.
"""

from collections import defaultdict, namedtuple
import datetime
import statistics
from typing import List, Dict, DefaultDict, Optional, KeysView, NamedTuple

import bq_util

BQ_PROJECT = 'khanacademy.org:deductive-jet-827'
MIN_COUNT_PER_DAY = 500
DAYS_WINDOW = 7
THRESHOLD = 3


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
      SUM(countries.weight * weights.weight * (count / weights.sampled_at)) AS count
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

    def filter(self, start_date: datetime.date, end_date: datetime.date):
        """Return a supset of data specific by start/end date"""
        raise NotImplementedError()


PageDataType = DefaultDict[str, Dict[datetime.date, float]]


class PerformanceDataSource(DataSource):
    """Getting performance data from the timeframe required
    """
    BAD_SPEED_NAMES = {'slow', 'unacceptable'}

    data: PageDataType

    def get_acceptable_or_faster(self, row) -> float:
        assert 'speeds' in row
        wanted_speeds = filter(
            lambda spds: spds['speed'] not in self.BAD_SPEED_NAMES,
            row['speeds']
        )
        return sum(map(lambda spds: float(spds['count']), wanted_speeds))

    def get_acceptable_or_faster_percent(self, row) -> float:
        faster_count = self.get_acceptable_or_faster(row)
        all_count = sum(map(lambda spds: float(spds['count']), row['speeds']))
        if all_count <= MIN_COUNT_PER_DAY:
            return None
        return faster_count / all_count

    def get_page_accceptable_percent(self, bq_data) -> PageDataType:
        page_name_date: PageDataType = defaultdict(dict)
        for row in bq_data:
            date_speed = page_name_date[row['page_name']]
            date_speed[row['date']
                       ] = self.get_acceptable_or_faster_percent(row)
        return page_name_date

    def __init__(self, start_date: datetime.date, end_date: datetime.date):
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

    @property
    def page_names(self) -> KeysView:
        return self.data.keys()

    def get_page_data(self, page_name: str) -> Dict[datetime.date, float]:
        return self.data[page_name]


class Alert(NamedTuple):
    page_name: str
    reason: str
    delta: float
    mean: float
    lower_bound: float


def find_alerts(page_name: str, data: Dict[datetime.date, float],
                threadhold=THRESHOLD, window=DAYS_WINDOW) -> Optional[Alert]:
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
        print(f"{page_name}: Skipping with insufficient data")
        return None

    data_array = [data[k] for k in sorted(data.keys())]

    windowed_data = data_array[-window-1:-1]
    last_value = data_array[-1]

    mean = statistics.mean(windowed_data)
    std = statistics.stdev(windowed_data)

    lower_bound = mean - (threadhold * std)

    last_zscore = (last_value - mean) / std
    perc_change = 100. * (last_value - mean) / mean

    print(f"Comparing {page_name}: {mean:.2f} -> {last_value:.2f} (std: {last_zscore:.2f}, pec: {perc_change:.2f}%)")
    if data_array[-1] < lower_bound:
        return Alert(
            page_name=page_name,
            reason=f"Drop below threadhold: {mean:.2f} -> {last_value:.2f}",
            delta=last_zscore,
            mean=mean,
            lower_bound=lower_bound
        )


def main():
    end = datetime.datetime.utcnow()
    # We need an extra day to look at the past information
    start = end - datetime.timedelta(days=DAYS_WINDOW+1)

    data = PerformanceDataSource(start, end)

    for page in data.page_names:
        alert = find_alerts(page, data.get_page_data(page))
        if alert:
            print(f"{page}: {alert}")


if __name__ == '__main__':
    main()
