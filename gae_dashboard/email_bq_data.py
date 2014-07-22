#!/usr/bin/env python

"""Email various analyses -- hard-coded into this file -- from bigquery.

This script will make a bigquery query, possibly massage the data a
bit, and put it into an html table that it emails out.  It can also
graph the data and append the graph to the email as well.

Here's an example of an analysis we could do here:
"Which url-routes took up the most instance hours yesterday?"
"""

import argparse
import datetime
import email
import email.mime.text
import email.utils
import json
import smtplib
import subprocess


# Report on the previous day by default
_DEFAULT_DAY = datetime.datetime.now() - datetime.timedelta(1)


def _is_number(s):
    """Return True if s is a numeric type or 'looks like' a number."""
    if isinstance(s, (int, long, float)):
        return True
    if isinstance(s, basestring):
        return s.strip('01234567890.-') == ''
    return False


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


def _query_bigquery(sql_query):
    """Use the 'bq' tool to run a query, and return the results as
    a json list (each row is a dict).

    We do naive type conversion to int and float, when possible.

    This requires 'pip install bigquery' be run on this machine.
    """
    # We could probably do 'import bq' and call out directly, but
    # I couldn't figure out an easy way to do this.  Ah well.
    data = subprocess.check_output(['bq', '-q', '--format=json', '--headless',
                                    'query', sql_query])
    table = json.loads(data)

    for row in table:
        for key in row:
            if row[key] is None:
                row[key] = '(None)'
            else:
                try:
                    row[key] = int(row[key])
                except ValueError:
                    try:
                        row[key] = float(row[key])
                    except ValueError:
                        pass

    return table


def _send_email(table, graph, to, cc=None, subject='bq data', preamble=None):
    """Send an email with the given table and graph.

    Arguments:
       table: a list of lists: [[1A, 1B], [2A, 2B], ...].  Can be None.
       graph: TODO(csilvers).  Can be None.
       to: a list of email addresses
       cc: an optional list of email addresses
       subject: subject of the email
       preamble: text to put before the table and graph.
    """
    body = []
    if preamble:
        body.append('<p>%s</p>' % preamble)

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
                if isinstance(col, (int, long)):
                    style += 'text-align: right;'
                elif isinstance(col, float):
                    style += 'text-align: right;'
                    col = '%.2f' % col     # make the output reasonable
                body.append('<td style="%s">%s</td>' % (style, col))
            body.append('</tr>')
        body.append('</tbody>')
        body.append('</table>')

    if graph:
        pass

    msg = email.mime.text.MIMEText('\n'.join(body), 'html')
    msg['Subject'] = subject
    msg['From'] = '"bq-cron-reporter" <toby-admin+bq-cron@khanacademy.org>'
    msg['To'] = ', '.join(to)
    if cc:
        msg['Cc'] = ', '.join(cc)
    s = smtplib.SMTP('localhost')
    s.sendmail('toby-admin+bq-cron@khanacademy.org', to, msg.as_string())
    s.quit()


# This is a map from module-name to how many processors it has -- so
# F4 would be 4, F8 would be 8.  If a module is run with
# multithreading, we divide the num-processes by 8 to emulate having 8
# threads run concurrently.  This is a total approximation!
# TODO(csilvers): use webapp's module_util to get the F-class and
# multithreading status instead of hard-coding.
_MODULE_CPU_COUNT = {
    'default': 4,
    'i18n': 4,
    'frontend-highmem': 4,
    'batch-lowlatency': 2,
    'batch': 2 / 8.0,
    'highmem': 8,
    }


def email_instance_hours(yyyymmdd):
    """Email instance hours report for the given datetime.date object."""
    cost_fn = '\n'.join("WHEN module_id == '%s' THEN latency * %s" % kv
                        for kv in _MODULE_CPU_COUNT.iteritems())
    query = """\
SELECT COUNT(*) as count_,
elog_url_route as url_route,
SUM(CASE %s ELSE 0 END) / 3600 as instance_hours
FROM [logs.requestlogs_%s]
WHERE url_map_entry != "" # omit static files
GROUP BY url_route
ORDER BY instance_hours DESC
""" % (cost_fn, yyyymmdd)
    data = _query_bigquery(query)

    # Munge the table by adding a few columns.
    total_instance_hours = 0.0
    for row in data:
        total_instance_hours += row['instance_hours']

    for row in data:
        row['% of total'] = row['instance_hours'] / total_instance_hours * 100
        row['per 1k requests'] = row['instance_hours'] / row['count_'] * 1000

    _ORDER = ('% of total', 'instance_hours', 'count_', 'per 1k requests',
              'url_route')
    data = _convert_table_rows_to_lists(data, _ORDER)

    subject = 'Instance Hours by Route'
    preamble = 'Instance hours by route for %s' % date.strftime("%Y/%m/%d")
    # Let's just send the top most expensive routes, not all of them.
    _send_email(data[:50], None,
                to=['infrastructure-blackhole@khanacademy.org'],
                subject=subject, preamble=preamble)


def email_rpcs(yyyymmdd):
    """Email RPCs-per-route report for the given datetime.date object."""
    rpc_fields = ('Get', 'Put', 'Next', 'RunQuery', 'Delete')

    inits = ["IFNULL(INTEGER(t%s.rpc_%s), 0) AS rpc_%s" % (name, name, name)
             for name in rpc_fields]
    joins = ["LEFT OUTER JOIN ( "
             "SELECT elog_url_route AS url_route, COUNT(*) AS rpc_%s "
             "FROM FLATTEN([logs.requestlogs_%s], elog_stats_rpc) "
             "WHERE elog_stats_rpc.key = 'stats.rpc.datastore_v3.%s' "
             "GROUP BY url_route) AS t%s ON t1.url_route = t%s.url_route"
             % (name, yyyymmdd, name, name, name)
             for name in rpc_fields]
    query = """\
SELECT t1.url_route AS url_route,
t1.url_requests AS requests,
%s
FROM (
SELECT elog_url_route AS url_route, COUNT(*) AS url_requests
FROM [logs.requestlogs_%s]
GROUP BY url_route) AS t1
%s
ORDER BY t1.url_requests DESC;
""" % (',\n'.join(inits), yyyymmdd, '\n'.join(joins))
    data = _query_bigquery(query)

    # Munge the table by getting per-request counts for every RPC stat.
    for row in data:
        for stat in rpc_fields:
            row['%s/req' % stat] = row['rpc_%s' % stat] * 1.0 / row['requests']

    # Convert each row from a dict to a list, in a specific order.
    _ORDER = (['url_route', 'requests'] +
              ['rpc_%s' % f for f in rpc_fields] +
              ['%s/req' % f for f in rpc_fields])
    data = _convert_table_rows_to_lists(data, _ORDER)

    subject = 'RPC calls by route'
    preamble = 'RPC calls by route for %s' % date.strftime("%Y/%m/%d")
    # Let's just send the top most expensive routes, not all of them.
    _send_email(data[:75], None,
                to=['infrastructure-blackhole+bq-data@khanacademy.org'],
                subject=subject, preamble=preamble)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--date', metavar='YYYYMMDD',
                        default=_DEFAULT_DAY.strftime("%Y%m%d"),
                        help=('Date to get reports for, specified as YYYYMMDD '
                              '(default "%(default)s")'))
    args = parser.parse_args()

    email_instance_hours(args.date)
    email_rpcs(args.date)


if __name__ == '__main__':
    main()
