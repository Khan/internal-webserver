#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Email our current uptime computed from BQ.

This script uses data from logs in BigQuery to compute our current uptime --
see get_uptime_for_day for the details of the calculation -- and emails a
summary of the last week's uptime to infrastructure-blackhole.  It runs on the
7 days preceding the current UTC-day, so it should likely be run at least 2
hours after UTC-midnight, to ensure all the logs have made it to BQ.
"""
import datetime
import email.mime.text
import smtplib

import bq_util


# See get_uptime_for_day for what these mean, and how they were chosen.
DEFAULT_DOWN_THRESHOLD = 0.0025
DEFAULT_SEC_PER_CHUNK = 10


def get_uptime_for_day(dt, sec_per_chunk, down_threshold):
    """
    Gets the uptime for the given day, as a percentage.

    We compute the downtime by dividing the day into short buckets, and
    computing the percentage of those buckets at which more than some small
    fraction of requests failed with 5xx errors.  The intention is to filter
    out the baseline error rate, and only notice points where the error rate is
    significantly higher than usual, but to do so in a way that is consistent
    over time -- thus the fixed threshold.  The uptime is then 1 minus the
    downtime.

    Arguments:
        dt: the day on which to operate, as a datetime.date (or
            datetime.datetime, in which case the time part will be ignored)
        sec_per_chunk: the number of seconds to use as the resolution at which
            to decide whether we were "down".  It should be an integer fraction
            of a day (because that makes the query simple).  It shouldn't be
            too short, as then the data will be too noisy, but neither should
            it be too long, as then we won't detect short-lived issues.  The
            default, 10 seconds, seems like a good compromise -- that
            represents a minimum of ~10k requests in a bucket, so that a single
            error is well below the threshold -- but is short enough to detect
            even small blips.
        down_threshold: the percentage of requests that must fail for us to
            consider ourselves "down".  For historical comparability, we want
            it to be constant, but I selected the value of 0.0025 as a nice
            round number approximately 2 sigma above the mean error rate in a
            10s bucket, as of when I started this report, in mid-July 2016.  If
            our baseline rate changes significantly, we may want to adjust
            this, although that will affect our historical data.
    """
    yyyymmdd = dt.strftime('%Y%m%d')
    yyyy_mm_dd = dt.strftime('%Y-%m-%d')
    chunks_per_day = 24 * 60 * 60 / sec_per_chunk
    query = """
    SELECT COUNT(*) / %(chunks_per_day)s AS downtime
    FROM (
      SELECT
        SEC_TO_TIMESTAMP(INTEGER(start_time / %(sec_per_chunk)s)
                         * %(sec_per_chunk)s) AS t,
        COUNT(*) AS reqs,
        SUM(status >= 500) AS errs,
      FROM [logs.requestlogs_%(yyyymmdd)s]
      WHERE
        instance_key IS NOT NULL
        -- We only include requests that actually started on the day in
        -- question.  This will exclude a few stragglers, so our data might be
        -- a little off for the last bucket of the day.
        -- TODO(benkraft): include a little of the next day's data to fix this.
        AND SEC_TO_TIMESTAMP(INTEGER(start_time)) > TIMESTAMP('%(yyyy_mm_dd)s')
        -- TODO(benkraft): exclude automated requests, priming, etc.
      GROUP BY t)
    WHERE errs / reqs > %(down_threshold)s
    """ % {
        'yyyymmdd': yyyymmdd,
        'yyyy_mm_dd': yyyy_mm_dd,
        'chunks_per_day': chunks_per_day,
        'sec_per_chunk': sec_per_chunk,
        'down_threshold': down_threshold,
    }
    results = bq_util.get_daily_data_from_disk_or_bq(query, 'uptime', yyyymmdd)
    # Convert from a downtime fraction to an uptime percentage for display.
    return 100 * (1 - results[0]['downtime'])


def average_uptime_for_period(start_dt, end_dt, sec_per_chunk, down_threshold):
    """Computes average uptime for days d in start_dt <= d < end_dt.

    We do this by computing the daily uptime, and averaging, to avoid extra BQ
    queries.
    """
    delta = end_dt - start_dt
    assert delta.seconds == 0
    assert delta.microseconds == 0
    assert delta.days > 0
    uptimes = []
    for day in xrange(delta.days):
        uptimes.append(get_uptime_for_day(start_dt + datetime.timedelta(day),
                                          sec_per_chunk, down_threshold))
    return float(sum(uptimes)) / len(uptimes)


def _daily_uptime_table_row_data(date, sec_per_chunk, down_threshold):
    uptime_today = get_uptime_for_day(date, sec_per_chunk, down_threshold)
    uptime_last_week = get_uptime_for_day(date - datetime.timedelta(7),
                                          sec_per_chunk, down_threshold)
    if uptime_today >= uptime_last_week:
        change_style = 'color: green;'
        change_symbol = '▴'
    else:
        change_style = 'color: red;'
        change_symbol = '▾'
    change = abs(uptime_today - uptime_last_week)
    return """
    <tr>
        <td style="width: 50%%;"><strong>%(day)s (%(date)s):</strong></td>
        <td>%(uptime_today).3f%%</td>
        <td style="%(change_style)s">%(change_symbol)s%(change).3f%%</td>
    </tr>
    """ % {
        'day': date.strftime('%A'),
        'date': date.strftime('%b %d'),
        'uptime_today': uptime_today,
        'change_style': change_style,
        'change_symbol': change_symbol,
        'change': change,
    }


def daily_uptime_email_body(end_date, sec_per_chunk=DEFAULT_SEC_PER_CHUNK,
                            down_threshold=DEFAULT_DOWN_THRESHOLD):
    """Returns HTML for the uptime email.

    The email covers the week preceding but not including the end_date, and
    displays the weekly and monthly uptime, the daily uptime for
    each day in the week (including a comparison to the same day the previous
    week (to ignore weekly effects)), and a brief explanation of the numbers.

    TODO(benkraft): Add some/all of the following to the email, once we have a
    better idea of how/whether we use it, and what numbers are useful.
    - sparklines
    - comparisons for the weekly/monthly uptime
    - comparisons to last month/year/whenever
    - comparisons to a longer baseline than just a single day
    TODO(benkraft): Send a summary to slack, again once we have a better idea
    of things.
    """
    return """
    <h3 style="text-align: center;">
        <span style="padding: 10px;">Weekly uptime: %(weekly).3f%%</span>
        <span style="padding: 10px;">Monthly uptime: %(monthly).3f%%</span>
    </h3>
    <div style="width: 100%%;">
        <table style="margin: 0px auto; text-align: right;"
               cellspacing="0" cellpadding="3" border="0">
        %(table_data)s
        </table>
    </div>

    <p>
        The above numbers are the percentage of time in the given period (of
        UTC time) when at least %(down_threshold_pct)s%% of requests to the
        site fail with 5xx errors &mdash; that is, the percentage of time at
        which errors are noticeably above some baseline rate.  The changes are
        in percentage points, compared to last week.  "Monthly" here means
        "last 30 days".  See <a href="%(source_url)s">the source</a> for more
        details.
    </p>
    """ % {
        # Super wasteful, but hey, everything is cached anyway!
        'weekly': average_uptime_for_period(end_date - datetime.timedelta(7),
                                            end_date, sec_per_chunk,
                                            down_threshold),
        'monthly': average_uptime_for_period(end_date - datetime.timedelta(30),
                                             end_date, sec_per_chunk,
                                             down_threshold),
        'table_data': ''.join(
            _daily_uptime_table_row_data(end_date - datetime.timedelta(d),
                                         sec_per_chunk, down_threshold)
            for d in range(7, 0, -1)),
        'down_threshold_pct': down_threshold * 100,
        'source_url': 'https://github.com/Khan/internal-webserver/'
                      'blob/master/gae_dashboard/email_uptime.py'
    }


def send_uptime_email(end_date):
    """Send the email from daily_uptime_email_body to infra-blackhole."""
    body = daily_uptime_email_body(end_date)
    msg = email.mime.text.MIMEText(body, 'html')
    to = "infrastructure-blackhole@khanacademy.org"
    msg['Subject'] = "Weekly uptime report"
    msg['From'] = '"bq-cron-reporter" <toby-admin+bq-cron@khanacademy.org>'
    msg['To'] = to
    s = smtplib.SMTP('localhost')
    s.sendmail('toby-admin+bq-cron@khanacademy.org', to, msg.as_string())
    s.quit()


def main():
    # TODO(benkraft): allow specifying a date and overriding the default
    # parameters
    send_uptime_email(datetime.datetime.utcnow().date())

if __name__ == '__main__':
    main()
