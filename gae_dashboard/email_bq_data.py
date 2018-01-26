#!/usr/bin/env python

"""Email various analyses -- hard-coded into this file -- from bigquery.

This script will make a bigquery query, possibly massage the data a
bit, and put it into an html table that it emails out.  It can also
graph the data and append the graph to the email as well.

Here's an example of an analysis we could do here:
"Which url-routes took up the most instance hours yesterday?"
"""

import argparse
import cgi
import collections
import datetime
import email
import email.mime.text
import email.utils
import hashlib
import smtplib
import subprocess
import textwrap
import time

import bq_util
import cloudmonitoring_util
import initiatives


# Report on the previous day by default
_DEFAULT_DAY = datetime.datetime.utcnow() - datetime.timedelta(1)

_GOOGLE_PROJECT_ID = 'khan-academy'    # used when sending to stackdriver


def _is_number(s):
    """Return True if s is a numeric type or 'looks like' a number."""
    if isinstance(s, (int, long, float)):
        return True
    if isinstance(s, basestring):
        return s.strip('01234567890.-') == ''
    return False


def _pretty_date(yyyymmdd):
    return '%s-%s-%s' % (yyyymmdd[0:4], yyyymmdd[4:6], yyyymmdd[6:8])


def _by_initiative(data, key='url_route', by_package=False):
    """Return a sequence of (id, data) tuples given a table.

    Table rows are expected to be dicts.

    Rows are assigned to initiatives based on their routes or packages. Routes
    or packages are found in rows using the key.
    """
    if by_package:
        f = initiatives.package_owner
    else:
        f = initiatives.route_owner
    rows = collections.defaultdict(list)
    for row in data:
        i = f(row[key])
        rows[i['id']].append(row)
    return rows.items()


def _convert_table_rows_to_lists(table, order):
    """Given a table where rows are dicts, convert them to lists.

    Each dict (each row in the table) must have the same set of keys.
    Those keys are then listed in the list 'order', which lists the
    keys in the order we want them to be in the table.  We then go
    through the table and convert each dict to a list.  Finally, we
    add a row to the top of the table that has the key-names
    (csv-style).
    """
    retval = []
    for row in table:
        row_contents = row.items()
        row_contents.sort(key=lambda (k, v): order.index(k))
        retval.append([v for (k, v) in row_contents])

    retval.insert(0, list(order))
    return retval


def _render_sparkline(data, width=100, height=20):
    """Given a list of values, render a sparkline to a PNG.

    This takes in a list of numbers, and returns the contents of a PNG as a
    string.  A datapoint may be None if it should be omitted.  It will return
    None if there are not enough datapoints to make a plot.  It requires that
    gnuplot be installed.
    """
    existing_data = [datum for datum in data if datum is not None]
    if len(existing_data) < 3:
        return None
    data_lines = []
    for i, datum in enumerate(data):
        if datum is None:
            data_lines.append("")
        else:
            data_lines.append("%s %s" % (i, datum))
    gnuplot_script = textwrap.dedent(
        """\
        unset border
        unset xtics
        unset ytics
        unset key
        set lmargin 0
        set rmargin 0
        set tmargin 0
        set bmargin 0
        set yrange [%(ymin)s:%(ymax)s]
        set xrange [%(xmin)s:%(xmax)s]
        set terminal pngcairo size 100,20
        plot "-" using 1:2 notitle with lines linetype rgb "black"
        %(data)s
        e
        """
    ) % {
        'data': '\n'.join(data_lines),
        # The bottom of the plot looks better if it has a bit of buffer around
        # the top and bottom data points.  We force the min to be near zero so
        # small increases to something that started out large are viewed in
        # context.
        'ymax': 1.05 * max(existing_data),
        'ymin': -0.05 * max(existing_data),
        # To make the width per time fixed, set the min and max x value so that
        # the plot will include space for any missing data.
        'xmin': 1,
        'xmax': len(data),
    }
    gnuplot_proc = subprocess.Popen(['gnuplot'], stdin=subprocess.PIPE,
                                    stdout=subprocess.PIPE)
    png, _ = gnuplot_proc.communicate(gnuplot_script)
    return png


def _send_email(tables, graph, to, cc=None, subject='bq data', preamble=None,
                dry_run=False):
    """Send an email with the given table and graph.

    Arguments:
       tables: a dict, with headings as keys, and values lists of lists of the
           form: [[1A, 1B], [2A, 2B], ...].  Can be None.  If the heading is
           the empty string, it won't be displayed.  If a table cell value is
           itself a list, it will be plotted as a sparkline.
       graph: TODO(csilvers).  Can be None.
       to: a list of email addresses
       cc: an optional list of email addresses
       subject: subject of the email
       preamble: text to put before the table and graph.
       dry_run: if True, say what we would email but don't actually email it.
    """
    body = []
    if preamble:
        body.append('<p>%s</p>' % preamble)

    if not isinstance(tables, dict):
        tables = {'': tables}

    images = []
    for heading, table in sorted(tables.iteritems()):
        if heading:
            body.append('<h3>%s</h3>' % heading)
        if table and table[0]:
            body.append('<table cellspacing="1" cellpadding="3" border="1"'
                        '       style="table-layout:fixed;font-size:13px;'
                        '              font-family:arial,sans,sans-serif;'
                        '              border-collapse:collapse;border:1px '
                        '              solid rgb(204,204,204)">')
            body.append('<thead>')
            body.append('<tr>')
            for header in table[0]:
                body.append('<th>%s</th>' % header)
            body.append('</tr>')
            body.append('</thead>')
            body.append('<tbody>')
            for row in table[1:]:
                body.append('<tr>')
                for col in row:
                    style = 'padding: 3px 5px 3px 8px;'
                    # If the column isn't a string, convert it to one.
                    if isinstance(col, (int, long)):
                        style += 'text-align: right;'
                    elif isinstance(col, float):
                        style += 'text-align: right;'
                        col = '%.2f' % col     # make the output reasonable
                    elif isinstance(col, list):
                        # If we get a list, plot it as a sparkline.
                        style = 'padding: 0px; text-align: center;'
                        # Just put in a placeholder for the datum, we'll fill
                        # in the image in _embed_images_to_mime().
                        image = _render_sparkline(col)
                        if image:
                            images.append(image)
                            col = '%s'
                        else:
                            # If the image didn't render due to insufficient
                            # data, say so rather than leaving it out.
                            col = '(insufficient data)'
                    else:
                        # The column was a regular string, and might have
                        # HTML-like characters, so escape those.
                        col = cgi.escape(col)
                    body.append('<td style="%s">%s</td>' % (style, col))
                body.append('</tr>')
            body.append('</tbody>')
            body.append('</table>')

    if graph:
        pass

    msg = _embed_images_to_mime('\n'.join(body), images)
    msg['Subject'] = subject
    msg['From'] = '"bq-cron-reporter" <toby-admin+bq-cron@khanacademy.org>'
    msg['To'] = ', '.join(to)
    if cc:
        msg['Cc'] = ', '.join(cc)
    if dry_run:
        print "WOULD EMAIL:"
        print msg.as_string()
        print "--------------------------------------------------"
    else:
        s = smtplib.SMTP('localhost')
        s.sendmail('toby-admin+bq-cron@khanacademy.org', to, msg.as_string())
        s.quit()


def _send_table_to_stackdriver(table, metric_name, metric_label_name,
                               metric_label_col, data_col, dry_run=False):
    """Send week-over-week data to stackdriver.

    For tables that have sparklines, we take the ratio of the most
    recent point on the sparkline to the oldest point (which is
    probably two weeks ago), and send that ratio as a datapoint to
    stackdriver (Google Cloud Monitoring), using the given metric-name
    and metric-label.

    Arguments:
       table: A list of lists of the form:
              [[HEADING_A, HEADING_B], [1A, 1B], [2A, 2B], ...].
           If a table cell value is itself a list, it is interpreted
           as a sparkline.
       metric_name: The name of the metric to use in stackdriver,
           e.g. "webapp.routes.daily_cost".  Think of it as the name
           of a graph in stackdriver.
       metric_label_name: The name of the label to be used with this
           metric, e.g. "url_route".  Think of it as a description of
           what the lines in the stackdriver graph represent.
       metric_label_col: the column of the table that holds the
           label value for a particular row, e.g. "url_route".  This
           should be a string that matches one of the HEADING_X fields.
       data_col: the column of the table that holds the sparkline
           historical data for this row, e.g. "last 2 weeks (per request)".
           This should be a string that matches one of the HEADING_X fields.
       dry_run: if True, say what we would send to stackdriver but don't
           actually send it.
    """
    # Stackdriver doesn't let you send a time more than an hour in the
    # past, so we just set the time to be the start of the current
    # hour.  Hopefully, if the script runs at the same time each day,
    # this will end up with us having the datapoints be at the same
    # time each day.
    time_t = int(time.time() / 3600) * 3600   # round down to the hour

    headings = table[0]
    rows = table[1:]

    metric_label_index = headings.index(metric_label_col)
    data_index = headings.index(data_col)

    stackdriver_input = []
    for row in rows:
        metric_label_value = row[metric_label_index]
        data = row[data_index]
        if data[-1] is None or data[0] is None:
            continue      # we don't have historical data, just bail
        num = data[-1] / data[0]

        # send_timeseries_to_cloudmonitoring() wants 4-tuples:
        #    (metric-name, metric-labels, value, time).
        stackdriver_input.append((metric_name,
                                  {metric_label_name: metric_label_value},
                                  num,
                                  time_t))

    if dry_run:
        print "WOULD SEND TO STACKDRIVER:"
        print stackdriver_input
        print "------------------------------------------------"
    else:
        cloudmonitoring_util.send_timeseries_to_cloudmonitoring(
            _GOOGLE_PROJECT_ID, stackdriver_input)


def _embed_images_to_mime(html, images):
    """Given HTML with placeholders, insert images and return a MIME object.

    "html" should be the HTML body, with %s placeholders for the images.  Other
    % signs must be escaped as %%.  "images" should be a list of PNG images,
    such as those read from a PNG file.  The images will then attached to the
    message, and a MIME object will be returned.
    """
    if not images:
        # For consistency, we should still do a trivial string format to
        # unescape the % signs.  But there's no point jumping through all the
        # hoops of making the multipart MIME.
        return email.MIMEText.MIMEText(html % (), 'html')
    image_tag_template = '<img src="cid:%s" alt=""/>'
    image_tags = []
    image_mimes = []
    for image in images:
        image_id = "%s@khanacademy.org" % hashlib.sha1(image).hexdigest()
        image_tags.append(image_tag_template % image_id)
        image_mime = email.MIMEImage.MIMEImage(image)
        image_mime.add_header('Content-ID', '<%s>' % image_id)
        # Cause the images to only render inline, not as attachments
        # (hopefully; this seems to be a bit buggy in Gmail)
        image_mime.add_header('Content-Disposition', 'inline')
        image_mimes.append(image_mime)
    msg_root = email.MIMEMultipart.MIMEMultipart('related')
    msg_body = email.MIMEText.MIMEText(html % tuple(image_tags), 'html')
    msg_root.attach(msg_body)
    for image_mime in image_mimes:
        msg_root.attach(image_mime)
    return msg_root


# This is a map from module-name to its cost relative to a
# perfect-utilization, non-threaded F1.
# There are two factors to consider here:
# 1) Our machines use an instance_class more powerful than F1, so
# we pay a multiple of the cost.  This is based on the chart at
#   https://cloud.google.com/appengine/pricing#standard_instance_pricing
# 2) Our modules do not have perfect scheduling, so their utilization
# is typically less than 1.  We pay for that unused time, so I spread
# it out over each request.  On the other hand, some of our modules
# are multithreaded, and can handle multiple requests simultaneously.
# In that case, adding up all the latencies *over*estimates the cost.
# Looking at the average utilization handles that case as well.
# We calculate the utilization by periodically running (in bigquery):
"""
SELECT module_id,
       round(sum(utilization * util_weight) / sum(util_weight), 3)
          as avg_utilization from (
    -- sum(latency-pending_time): time spent by all requests for this instance
    -- min(start_time): when the instance was created
    -- max(end_time): when the instance died
    -- util_weight: to give more weight to longer-lived instances
    SELECT module_id,
           sum(latency - pending_time) / (max(end_time) - min(start_time))
             as utilization,
           max(end_time) - min(start_time) as util_weight
    FROM logs.requestlogs_YYYYMMDD
    WHERE instance_key is not null
    GROUP BY module_id, instance_key)
-- lame heuristic to try to discard idle instances, which
-- we're not charged for and can go hours between requests:
WHERE utilization > 0.1
GROUP BY module_id
"""
# Utilization was last updated 2018-01-26 using log data averages from
# 2018-01-18 to 2018-01-24.
# Instance classes were last updated 2018-01-26.
_MODULE_CPU_COUNT = {
    'default': 4 / 0.42,
    'i18n': 6 / 0.223,
    'frontend-highmem': 6 / 0.367,
    'batch': 4 / 0.89,
    'highmem': 8 / 0.157,
    'multithreaded': 4 / 0.182,
    }


def email_instance_hours(date, dry_run=False):
    """Email instance hours report for the given datetime.date object."""
    yyyymmdd = date.strftime("%Y%m%d")
    cost_fn = '\n'.join("WHEN module_id == '%s' THEN latency * %s" % kv
                        for kv in _MODULE_CPU_COUNT.iteritems())
    query = """\
SELECT COUNT(1) as count_,
elog_url_route as url_route,
SUM(CASE %s ELSE 0 END) / 3600 as instance_hours
FROM (
  -- When logs get split into multiple entries, each has latency calculated
  -- from the start of the request to the point where the log line was emitted.
  -- This means the total latency is the maximum value that appears, not the
  -- sum.
  SELECT FIRST(elog_url_route) AS elog_url_route,
         FIRST(module_id) AS module_id,
         MAX(latency) AS latency,
         FIRST(url_map_entry) AS url_map_entry
  FROM [logs.requestlogs_%s]
  GROUP BY request_id
)
WHERE url_map_entry != "" # omit static files
GROUP BY url_route
ORDER BY instance_hours DESC
""" % (cost_fn, yyyymmdd)
    data = bq_util.query_bigquery(query)
    bq_util.save_daily_data(data, "instance_hours", yyyymmdd)
    historical_data = bq_util.process_past_data(
        "instance_hours", date, 14, lambda row: row['url_route'])

    # Munge the table by adding a few columns.
    total_instance_hours = 0.0
    for row in data:
        total_instance_hours += row['instance_hours']

    for row in data:
        row['%% of total'] = row['instance_hours'] / total_instance_hours * 100
        row['per 1k requests'] = row['instance_hours'] / row['count_'] * 1000
        sparkline_data = []
        for old_data in historical_data:
            old_row = old_data.get(row['url_route'])
            if old_row:
                sparkline_data.append(
                    old_row['instance_hours'] / old_row['count_'])
            else:
                sparkline_data.append(None)
        row['last 2 weeks (per request)'] = sparkline_data

    _ORDER = ('%% of total', 'instance_hours', 'count_', 'per 1k requests',
              'last 2 weeks (per request)', 'url_route')

    subject = 'Instance Hours by Route - '
    heading = 'Cost-normalized instance hours by route for %s' % (
        _pretty_date(yyyymmdd))
    all_data = _convert_table_rows_to_lists(data, _ORDER)
    # Let's just send the top most expensive routes, not all of them.
    _send_email({heading: all_data[:50]}, None,
                to=[initiatives.email('infra')],
                subject=subject + 'All',
                dry_run=dry_run)

    # Per-initiative reports
    for initiative_id, initiative_data in _by_initiative(data):
        table = _convert_table_rows_to_lists(initiative_data, _ORDER)
        _send_email({heading: table[:50]}, None,
                    to=[initiatives.email(initiative_id)],
                    subject=subject + initiatives.title(initiative_id),
                    dry_run=dry_run)

    # We'll also send the most-most expensive ones to stackdriver.
    _send_table_to_stackdriver(all_data[:20],
                               'webapp.routes.instance_hours.week_over_week',
                               'url_route', metric_label_col='url_route',
                               data_col='last 2 weeks (per request)',
                               dry_run=dry_run)


def email_rpcs(date, dry_run=False):
    """Email RPCs-per-route report for the given datetime.date object.

    Also email a more urgent message if one of the RPCs is too expensive.
    This indicates a bug that is costing us money.
    """
    yyyymmdd = date.strftime("%Y%m%d")
    rpc_fields = ('Get', 'Put', 'Next', 'RunQuery', 'Delete', 'Commit')

    inits = ["IFNULL(INTEGER(t%s.rpc_%s), 0) AS rpc_%s" % (name, name, name)
             for name in rpc_fields]
    inits.append("IFNULL(tcost.rpc_cost, 0) AS rpc_cost")
    joins = ["LEFT OUTER JOIN ( "
             "SELECT elog_url_route AS url_route, "
             "       SUM(elog_stats_rpc_ops.value) as rpc_%s "
             "FROM [logs.requestlogs_%s] "
             "WHERE elog_stats_rpc_ops.key = 'stats.rpc_ops.%s.count' "
             "GROUP BY url_route) AS t%s "
             "ON t1.url_route = t%s.url_route"
             % (name, yyyymmdd, name, name, name)
             for name in rpc_fields]
    joins.append("LEFT OUTER JOIN ( "
                 "SELECT elog_url_route AS url_route, "
                 "       SUM(elog_stats_rpc_ops.value) AS rpc_cost "
                 "FROM [logs.requestlogs_%s] "
                 "WHERE elog_stats_rpc_ops.key = 'stats.rpc_ops.cost' "
                 "GROUP BY url_route) AS tcost "
                 "ON t1.url_route = tcost.url_route"
                 % yyyymmdd)
    query = """\
SELECT t1.url_route AS url_route,
t1.url_requests AS requests,
%s
FROM (
    SELECT elog_url_route AS url_route, COUNT(1) AS url_requests
    FROM (
        SELECT FIRST(elog_url_route) AS elog_url_route
        FROM [logs.requestlogs_%s]
        GROUP BY request_id
    )
    GROUP BY url_route
) AS t1
%s
ORDER BY tcost.rpc_cost DESC;
""" % (',\n'.join(inits), yyyymmdd, '\n'.join(joins))
    data = bq_util.query_bigquery(query)
    bq_util.save_daily_data(data, "rpcs", yyyymmdd)
    historical_data = bq_util.process_past_data(
        "rpcs", date, 14, lambda row: row['url_route'])

    # Munge the table by getting per-request counts for every RPC stat.
    micropennies = '&mu;&cent;'
    for row in data:
        for stat in rpc_fields:
            row['%s/req' % stat] = row['rpc_%s' % stat] * 1.0 / row['requests']
        row[micropennies + '/req'] = row['rpc_cost'] * 1.0 / row['requests']
        row['$'] = row['rpc_cost'] * 1.0e-8
        sparkline_data = []
        for old_data in historical_data:
            old_row = old_data.get(row['url_route'])
            if old_row and 'rpc_cost' in old_row:
                sparkline_data.append(
                    old_row['rpc_cost'] * 1.0 / old_row['requests'])
            else:
                sparkline_data.append(None)
        row['last 2 weeks (%s/req)' % micropennies] = sparkline_data

        del row['rpc_cost']

    # Convert each row from a dict to a list, in a specific order.
    _ORDER = (['url_route', 'requests', '$', micropennies + '/req',
               'last 2 weeks (%s/req)' % micropennies] +
              ['rpc_%s' % f for f in rpc_fields] +
              ['%s/req' % f for f in rpc_fields])
    all_data = _convert_table_rows_to_lists(data, _ORDER)
    subject = 'RPC calls by route - '
    heading = 'RPC calls by route for %s' % _pretty_date(yyyymmdd)
    _send_email({heading: all_data[:75]}, None,
                to=[initiatives.email('infra')],
                subject=subject + 'All',
                dry_run=dry_run)

    # Per-initiative reports
    for initiative_id, initiative_data in _by_initiative(data):
        table = _convert_table_rows_to_lists(initiative_data, _ORDER)
        # Let's just send the top most expensive routes, not all of them.
        _send_email({heading: table[:75]}, None,
                    to=[initiatives.email(initiative_id)],
                    subject=subject + initiatives.title(initiative_id),
                    dry_run=dry_run)

    # We'll also send the most-most expensive ones to stackdriver.
    _send_table_to_stackdriver(all_data[:20],
                               'webapp.routes.rpc_cost.week_over_week',
                               'url_route', metric_label_col='url_route',
                               data_col='last 2 weeks (%s/req)' % micropennies,
                               dry_run=dry_run)

    # As of 1 Feb 2016, the most expensive RPC route is about $300 a
    # day.  More than $750 a day and we should be very suspcious.
    # TODO(csilvers): do this check more frequently.
    # TODO(csilvers): send to slack and/or 911 as well as emailing
    if any(row[2] > 750 for row in all_data[1:]):    # ignore the header line
        _send_email({heading: all_data[:75]}, None,
                    to=['infrastructure@khanacademy.org'],
                    subject=('WARNING: some very expensive RPC calls on %s!'
                             % _pretty_date(yyyymmdd)),
                    dry_run=dry_run)


def email_out_of_memory_errors(date, dry_run=False):
    # This sends two emails, for two different ways of seeing the data.
    # But we'll have them share the same subject so they thread together.
    yyyymmdd = date.strftime("%Y%m%d")
    subject = 'OOM errors - '

    # Out-of-memory errors look like:
    #   Exceeded soft private memory limit with 260.109 MB after servicing 2406 requests total  #@Nolint
    # with SDK 1.9.7, they changed. Note the double-space after "after":
    #   Exceeded soft private memory limit of 512 MB with 515 MB after  servicing 9964 requests total  #@Nolint
    numreqs = r"REGEXP_EXTRACT(app_logs.message, r'servicing (\d+) requests')"
    query = """\
SELECT COUNT(module_id) AS count_,
       module_id,
       NTH(10, QUANTILES(INTEGER(%s), 101)) as numserved_10th,
       NTH(50, QUANTILES(INTEGER(%s), 101)) as numserved_50th,
       NTH(90, QUANTILES(INTEGER(%s), 101)) as numserved_90th
FROM [logs.requestlogs_%s]
WHERE app_logs.message CONTAINS 'Exceeded soft private memory limit'
      AND module_id IS NOT NULL
GROUP BY module_id
ORDER BY count_ DESC
""" % (numreqs, numreqs, numreqs, yyyymmdd)
    data = bq_util.query_bigquery(query)
    bq_util.save_daily_data(data, "out_of_memory_errors_by_module", yyyymmdd)
    historical_data = bq_util.process_past_data(
        "out_of_memory_errors_by_module", date, 14,
        lambda row: row['module_id'])

    for row in data:
        sparkline_data = []
        for old_data in historical_data:
            old_row = old_data.get(row['module_id'])
            if old_row:
                sparkline_data.append(old_row['count_'])
            elif old_data:
                # If we have data, just not on this module, then it just didn't
                # OOM.
                sparkline_data.append(0)
            else:
                # On the other hand, if we don't have data at all, we should
                # show a gap.
                sparkline_data.append(None)
        row['last 2 weeks'] = sparkline_data

    _ORDER = ['count_', 'last 2 weeks', 'module_id',
              'numserved_10th', 'numserved_50th', 'numserved_90th']
    data = _convert_table_rows_to_lists(data, _ORDER)

    heading = 'OOM errors by module for %s' % _pretty_date(yyyymmdd)
    email_content = {heading: data}

    query = """\
SELECT COUNT(1) AS count_,
       module_id,
       elog_url_route AS url_route
FROM (
    SELECT FIRST(module_id) AS module_id,
           FIRST(elog_url_route) AS elog_url_route,
           SUM(IF(
               app_logs.message CONTAINS 'Exceeded soft private memory limit',
               1, 0)) AS oom_message_count
    FROM [logs.requestlogs_%s]
    GROUP BY request_id
    HAVING oom_message_count > 0
)
GROUP BY module_id, url_route
ORDER BY count_ DESC
""" % yyyymmdd
    data = bq_util.query_bigquery(query)
    bq_util.save_daily_data(data, "out_of_memory_errors_by_route", yyyymmdd)
    historical_data = bq_util.process_past_data(
        "out_of_memory_errors_by_route", date, 14,
        lambda row: (row['module_id'], row['url_route']))

    for row in data:
        sparkline_data = []
        for old_data in historical_data:
            old_row = old_data.get((row['module_id'], row['url_route']))
            if old_row:
                sparkline_data.append(old_row['count_'])
            elif old_data:
                # If we have data, just not on this route/module, then it just
                # didn't OOM.
                sparkline_data.append(0)
            else:
                # On the other hand, if we don't have data at all, we should
                # show a gap.
                sparkline_data.append(None)
        row['last 2 weeks'] = sparkline_data

    _ORDER = ['count_', 'last 2 weeks', 'module_id', 'url_route']
    heading = 'OOM errors by route for %s' % _pretty_date(yyyymmdd)

    email_content[heading] = _convert_table_rows_to_lists(data, _ORDER)
    _send_email(email_content, None,
                to=[initiatives.email('infra')],
                subject=subject + 'All',
                dry_run=dry_run)

    # Per initiative-reports
    for initiative_id, initiative_data in _by_initiative(data):
        table = _convert_table_rows_to_lists(initiative_data, _ORDER)
        email_content = {heading: table}
        _send_email(email_content, None,
                    to=[initiatives.email(initiative_id)],
                    subject=subject + initiatives.title(initiative_id),
                    dry_run=dry_run)


def email_memory_increases(date, window_length=20, min_increase_in_mb=1,
                           dry_run=False):
    """Emails the increases in memory caused by particular routes.

    It attempts to compute the amount of memory ignoring memory which is
    reclaimed in the next few requests.  (The number of requests which are
    checked is specified by the window_length parameter.  Routes with a total
    increase less than the min_increase_in_mb parameter are ignored.)
    """
    yyyymmdd = date.strftime("%Y%m%d")
    lead_lengths = range(1, window_length + 1)
    lead_selects = '\n'.join(
        "LEAD(total, %s) OVER (PARTITION BY instance_key ORDER BY start_time) "
        "AS lead_total_%s," % (i, i) for i in lead_lengths)

    fields = ['total'] + ['lead_total_%s' % i for i in lead_lengths]
    # We want to compute the minimal value of added + field - total, where
    # field is one of "total" or one of the "lead_total_i".  BigQuery
    # unfortunately doesn't give us a nice way to do this (at least that I know
    # of, without doing a CROSS JOIN of the table to itself).  One way to do
    # this would be a gigantic nested IF, but this could be exponentially
    # large, and queries have a fixed maximum size.  Instead we use a CASE
    # expression which could be O(n^2); since we don't pay for BigQuery
    # execution time, and n shouldn't be too huge, this seems like a better
    # approach.  In theory it might be better to do O(n) nested queries (each
    # of which does a single pairwise min), but this seems like it could in
    # practice be even slower, depending on the implementation.
    # TODO(benkraft): If I get a useful answer to
    # http://stackoverflow.com/questions/24923101/computing-a-moving-maximum-in-bigquery
    # we should use that instead.  Or, once we have user-defined functions in
    # BigQuery, we can probably do something actually reasonable.
    case_expr = '\n'.join(
        "WHEN %s THEN added + %s - total" % (
            ' AND '.join("%s <= %s" % (field1, field2)
                         for field2 in fields if field2 != field1),
            field1)
        for field1 in fields)

    # This is a kind of large query, so here's what it's doing, from inside to
    # out:
    #   First, extract the memory data from the logs.
    #   Second, compute the appropriate LEAD() columns, which tell us what the
    #       total will be a few requests later on the same instance
    #   Third, compute "real_added", which is the amount we think the request
    #       actually added to the heap, not counting memory which was soon
    #       reclaimed.  This might come out negative, so
    #   Fourth, make sure the memory added is at least zero, since if memory
    #       usage went down, this request probably shouldn't get the credit.
    #   Fifth, group by route and do whatever aggregation we want.  Ignore the
    #       first 25 requests to each module, since those are probably all
    #       loading things that each module has to load, and the request that
    #       does the loading shouldn't be blamed.
    query = """\
SELECT
    COUNT(*) AS count_,
    elog_url_route AS url_route,
    module_id AS module,
    AVG(real_added) AS added_avg,
    NTH(99, QUANTILES(real_added, 101)) AS added_98th,
    SUM(real_added) AS added_total,
FROM (
    SELECT
        IF(real_added > 0, real_added, 0) AS real_added,
        elog_url_route, module_id, num,
    FROM (
        SELECT
            (CASE %s ELSE added END) AS real_added,
            elog_url_route, module_id, num,
        FROM (
            SELECT
                %s
                RANK() OVER (PARTITION BY instance_key
                             ORDER BY start_time) AS num,
                added, total, elog_url_route, module_id,
            FROM (
                SELECT FIRST(instance_key) AS instance_key,
                       FIRST(start_time) AS start_time,
                       FIRST(elog_url_route) AS elog_url_route,
                       FIRST(module_id) AS module_id,
                       SUM(IF(
                           app_logs.message CONTAINS 'This request added',
                           FLOAT(REGEXP_EXTRACT(
                               app_logs.message,
                               "This request added (.*) MB to the heap.")),
                           0.0)) AS added,
                       SUM(IF(
                           app_logs.message CONTAINS 'This request added',
                           FLOAT(REGEXP_EXTRACT(
                               app_logs.message,
                               "Total memory now used: (.*) MB")),
                           0.0)) AS total
                FROM [logs.requestlogs_%s]
                GROUP BY request_id
            )
        )
    )
)
WHERE num > 25
GROUP BY url_route, module
ORDER BY added_total DESC
""" % (case_expr, lead_selects, yyyymmdd)
    data = bq_util.query_bigquery(query)
    bq_util.save_daily_data(data, "memory_increases", yyyymmdd)
    historical_data = bq_util.process_past_data(
        "memory_increases", date, 14,
        lambda row: (row['module'], row['url_route']))

    by_module = collections.defaultdict(list)
    by_initiative = collections.defaultdict(dict)
    for row in data:
        if row['added_total'] > min_increase_in_mb:
            heading = "Memory increases by route for %s module on %s" % (
                row['module'], _pretty_date(yyyymmdd))
            by_module[heading].append(row)

    _ORDER = ['count_', 'added_avg', 'last 2 weeks (avg)', 'added_98th',
              'added_total', 'added %%', 'url_route']
    for heading in by_module:
        total = sum(row['added_total'] for row in by_module[heading])
        for row in by_module[heading]:
            row['added %%'] = row['added_total'] / total * 100
            sparkline_data = []
            for old_data in historical_data:
                old_row = old_data.get((row['module'], row['url_route']))
                if old_row:
                    sparkline_data.append(old_row['added_avg'])
                else:
                    sparkline_data.append(None)
            row['last 2 weeks (avg)'] = sparkline_data
            del row['module']

        for initiative_id, initiative_data in _by_initiative(
                by_module[heading]):
            heading_data = _convert_table_rows_to_lists(
                initiative_data[:50], _ORDER)
            by_initiative[initiative_id][heading] = heading_data

        by_module[heading] = _convert_table_rows_to_lists(
            by_module[heading][:50], _ORDER)

    subject = "Memory Increases by Route - "
    _send_email(by_module, None,
                to=[initiatives.email('infra')],
                subject=subject + 'All',
                dry_run=dry_run)

    # Per-initiative reports
    for initiative_id, initiative_data in by_initiative.items():
        _send_email(initiative_data, None,
                    to=[initiatives.email(initiative_id)],
                    subject=subject + initiatives.title(initiative_id),
                    dry_run=dry_run)


def email_client_api_usage(date, dry_run=False):
    """Emails a report of API usage, segmented by client and build version."""
    yyyymmdd = date.strftime("%Y%m%d")

    ios_user_agent_regex = '^Khan%20Academy\.(.*)/(.*) CFNetwork/([.0-9]*)' \
                           ' Darwin/([.0-9]*)$'

    # We group all non-ios user agents into a single bucket to keep this
    # report down to a reasonable size.
    query = """\
SELECT IF(REGEXP_MATCH(user_agent, r'%(ios_user_agent_regex)s'),
      REGEXP_REPLACE(user_agent, r'%(ios_user_agent_regex)s', r'iOS \1'),
      'Web Browsers/other') as client,
      IF(REGEXP_MATCH(user_agent, r'%(ios_user_agent_regex)s'),
      REGEXP_REPLACE(user_agent, r'%(ios_user_agent_regex)s', r'\2'),
      '') as build,
    elog_url_route as route,
    count(elog_url_route) as request_count
FROM logs.requestlogs_%(date_format)s
WHERE REGEXP_MATCH(elog_url_route, '^api.main:/api/internal')
    AND user_agent IS NOT NULL
    AND elog_url_route IS NOT NULL
GROUP BY client, build, route
ORDER BY client DESC, build DESC, request_count DESC;
""" % {'ios_user_agent_regex': ios_user_agent_regex, 'date_format': yyyymmdd}
    data = bq_util.query_bigquery(query)
    bq_util.save_daily_data(data, "client_api_usage", yyyymmdd)

    _ORDER = ('client', 'build', 'route', 'request_count')
    all_data = _convert_table_rows_to_lists(data, _ORDER)

    subject = 'API usage by client - '
    heading = 'API usage by client for %s' % _pretty_date(yyyymmdd)
    _send_email({heading: all_data}, None,
                to=[initiatives.email('infra')],
                subject=subject + 'All', 
                dry_run=dry_run)

    # Per-initiative reports
    for initiative_id, initiative_data in _by_initiative(data,
                                                         key='route'):
        table = _convert_table_rows_to_lists(initiative_data, _ORDER)
        _send_email({heading: table}, None,
                    to=[initiatives.email(initiative_id)],
                    subject=subject + initiatives.title(initiative_id),
                    dry_run=dry_run)


def email_rrs_stats(date, dry_run=False):
    """Emails stats about rrs requests that are too slow or have errors.

    Requests that take longer than one second are timed out and thus simply add
    a second to the reponse, rather than speeding it up.
    """
    yyyymmdd = date.strftime("%Y%m%d")

    latency_q = """
SELECT
  REPLACE(REGEXP_EXTRACT(
    httpRequest.requestUrl, r'/render\?path=\.(.*)'), '%2F', '/') AS url,
  AVG(FLOAT(jsonPayload.latencyseconds)) AS average_latency,
  COUNT(1) AS count,
  SUM(CASE
      WHEN FLOAT(jsonPayload.latencyseconds) >= 1.0 THEN 1
      ELSE 0 END) AS timeouts,
  SUM(CASE
      WHEN FLOAT(jsonPayload.latencyseconds) >= 1.0 THEN 100
      ELSE 0 END) / count(1) as timeout_percent
FROM
  [khan-academy:react_render_logs.appengine_googleapis_com_nginx_request_{}]
GROUP BY
  url
ORDER BY
  timeout_percent DESC
""".format(yyyymmdd)

    error_q = """
SELECT
  REPLACE(REGEXP_EXTRACT(
    httpRequest.requestUrl, r'/render\?path=\.(.*)'), '%2F', '/') AS url,
  SUM(httpRequest.status == 500) AS error_count,
  (SUM(httpRequest.status == 500) / COUNT(1)) * 100 AS error_percent
FROM
  [khan-academy:react_render_logs.appengine_googleapis_com_nginx_request_{}]
GROUP BY
  url
ORDER BY
  error_percent DESC
""".format(yyyymmdd)

    latency_data = bq_util.get_daily_data('rrs_latency', yyyymmdd)
    if latency_data is None:
        table_name = ("khan-academy:react_render_logs" +
                      ".appengine_googleapis_com_nginx_request_{}"
                      ).format(yyyymmdd)
        table_exists = bq_util.does_table_exist(table_name)
        if not table_exists:
            print "The RRS logs were not generated. No email will be sent."
            print "Returning..."
            return
        latency_data = bq_util.query_bigquery(latency_q)
        bq_util.save_daily_data(latency_data, 'rrs_latency', yyyymmdd)

    error_data = bq_util.get_daily_data('rrs_errors', yyyymmdd)
    if error_data is None:
        error_data = bq_util.query_bigquery(error_q)
        bq_util.save_daily_data(error_data, 'rrs_errors', yyyymmdd)

    subject = 'React render server errors and timeouts - '
    error_heading = 'React component errors'
    latency_heading = 'React compontent render latency (> 1 sec = timeout)'
    error_order = ('url', 'error_count', 'error_percent')
    latency_order = ('url', 'average_latency', 'count', 'timeouts',
                     'timeout_percent')
    tables = collections.defaultdict(dict)

    # Put data in tables by initiative
    for initiative_id, initiative_data in _by_initiative(
            error_data, key='url', by_package=True):
        tables[initiative_id][error_heading] = _convert_table_rows_to_lists(
            initiative_data, error_order)
    for initiative_id, initiative_data in _by_initiative(
            latency_data, key='url', by_package=True):
        tables[initiative_id][latency_heading] = _convert_table_rows_to_lists(
            initiative_data, latency_order)

    # Send email to initiatives
    for initiative_id, initiative_tables in tables.items():
        _send_email(initiative_tables, graph=None,
                    to=[initiatives.email(initiative_id)],
                    subject=subject + initiatives.title(initiative_id),
                    dry_run=dry_run)

    # Send all data to infra
    tables = {}
    tables[error_heading] = _convert_table_rows_to_lists(error_data,
                                                         error_order)
    tables[latency_heading] = _convert_table_rows_to_lists(latency_data,
                                                           latency_order)
    _send_email(tables, graph=None, to=[initiatives.email('infra')],
                subject=subject + 'All', dry_run=dry_run)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--date', metavar='YYYYMMDD',
                        default=_DEFAULT_DAY.strftime("%Y%m%d"),
                        help=('Date to get reports for, specified as YYYYMMDD '
                              '(default "%(default)s")'))
    reports = [k for k, v in globals().iteritems() if
               k.startswith('email') and hasattr(v, '__call__')]
    parser.add_argument('--report', metavar='NAME', required=False,
                        help='The function name of a specific report to run.  '
                             'Available reports: %s' % ', '.join(reports),
                        choices=reports)
    parser.add_argument('--dry-run', '-n', action='store_true',
                        help="Say what we would do but don't actually do it.")
    args = parser.parse_args()
    date = datetime.datetime.strptime(args.date, "%Y%m%d")

    if args.report:
        report_method = globals()[args.report]
        print 'Emailing %s info' % args.report
        report_method(date, dry_run=args.dry_run)
    else:
        print 'Emailing instance hour info'
        email_instance_hours(date, dry_run=args.dry_run)

        print 'Emailing rpc stats info'
        email_rpcs(date, dry_run=args.dry_run)

        print 'Emailing out-of-memory info'
        email_out_of_memory_errors(date, dry_run=args.dry_run)

        print 'Emailing memory profiling info'
        email_memory_increases(date, dry_run=args.dry_run)

        print 'Emailing client API usage info'
        email_client_api_usage(date, dry_run=args.dry_run)

        print 'Emailing react render server info'
        email_rrs_stats(date, dry_run=args.dry_run)


if __name__ == '__main__':
    main()
