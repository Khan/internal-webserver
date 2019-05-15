#!/usr/bin/env python3
"""A script for alerting pageload pangolin alerts.

This script does a very simplistic check for Performance and
error regressions.
"""

import datetime
from dataclasses import dataclass
from typing import List

import bq_util


BQ_PROJECT = 'khanacademy.org:deductive-jet-827'

# from kpi-dashboard: lib/performance/page.buckets.sql
PERFORMANCE_QUERY = """
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
      'combined' AS country,
      date,
      speed,
      SUM(countries.weight * weights.weight * (count / weights.sampled_at)) AS count
    FROM
      `khanacademy.org:deductive-jet-827.infrastructure.perf_kpi_buckets`
      LEFT JOIN countries USING (country)
      LEFT JOIN weights USING (page_name)
    GROUP BY
      page_name,
      date,
      speed),
  by_country AS (
    SELECT
      page_name,
      country,
      date,
      speed,
      SUM(weights.weight * (count / weights.sampled_at)) as count
    FROM
      `khanacademy.org:deductive-jet-827.infrastructure.perf_kpi_buckets`
      LEFT JOIN weights USING (page_name)
    WHERE
        date >= "{start_date}"
        AND date <= "{end_date}"
    GROUP BY
      page_name,
      country,
      date,
      speed)
SELECT
  page_name,
  country,
  date,
  ARRAY_AGG(STRUCT(speed, ROUND(count, 1) AS count)) AS speeds
FROM combined
GROUP by page_name, country, date
UNION ALL
SELECT
  page_name,
  country,
  date,
  ARRAY_AGG(STRUCT(speed, ROUND(count, 1) AS count)) AS speeds
FROM by_country
GROUP by page_name, country, date
"""

DB_DATE_FMT = '%Y-%m-%d'


class DataSource:
    """Data source which we get the performance/regression alerts.
    """

    def filter(self, start_date: datetime.date, end_date: datetime.date):
        """Return a supset of data specific by start/end date"""
        raise NotImplementedError()


class PerformanceDataSource(DataSource):
    """Getting performance data from the timeframe required
    """

    def __init__(self, start_date: datetime.date, end_date: datetime.date):
        query = PERFORMANCE_QUERY.format(
            start_date=start_date.strftime(DB_DATE_FMT),
            end_date=end_date.strftime(DB_DATE_FMT)
        )
        self.data = bq_util.query_bigquery(query, gdrive=True)


@dataclass
class Alert:
    start_date: datetime.date
    page_name: str
    reason: str
    delta: float


def find_alerts(data: DataSource) -> List[Alert]:
    return []


def main():
    end = datetime.datetime.utcnow()
    start = end - datetime.timedelta(days=3)

    data = PerformanceDataSource(start, end)

    alerts = find_alerts(data)
    for a in alerts:
        print(a)


if __name__ == '__main__':
    main()
