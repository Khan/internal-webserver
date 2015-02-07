#!/usr/bin/env python

"""Fetch and scrape stats from the GAE dashboard, and send them to graphite.

This is a wrapper around ka_report.py, which stores data for every
page except for the dashboard homepage (eg /instance_summary), and
dashboard_report.py, which stores data for one chart on the main
homepage, for a single module.
"""

import calendar
import datetime
import json
import os
import threading

import dashboard_report
import gae_dashboard_curl
import gae_util
import ka_report


# window=0 gives us 30 minutes of data
# (cf. dashboard_report.py:_time_windows)
_WINDOW = 0


def _fetch_one_chart(email, password, application, module, chartnum,
                     chartmap, chartmap_lock, verbose):
    # TODO(csilvers): can all the threads share a single DashboardClient?
    dashclient = gae_dashboard_curl.DashboardClient(email, password)
    url = ('/dashboard/stats?app_id=%s&version_id=%s:&type=%s&window=%s'
           % (application, module, chartnum, _WINDOW))
    data = dashclient.fetch(url)
    with chartmap_lock:
        chartmap[(module, chartnum)] = json.loads(data)
    if verbose:
        print '>>> Got data from chart #%s in module %s' % (chartnum, module)


def main(email, password, application, graphite_host,
         verbose=False, dry_run=False):
    # First, run ka_report.py.
    # TODO(csilvers): do this in a thread (or separate process?) so
    # it's data-fetching can intersperse with dashboard_report.
    # I don't do that because I'm not sure about thread-safety.
    if verbose:
        print '>>> fetching ka_report data'
    version = None     # we want the default version
    ka_report.main(email, password, application, version, graphite_host,
                   verbose=verbose, dry_run=dry_run)

    # Sadly, the code was written to take a time_t rather than a
    # datetime originally.  It should probably be rewritten, but for
    # now we just convert.
    now_dt = datetime.datetime.utcnow()
    now = calendar.timegm(now_dt.timetuple())

    num_charts = dashboard_report.num_charts()
    modules = gae_util.get_modules(email, password, application)

    # Now use curl to collect the stats for each chart.
    chartmap_lock = threading.Lock()
    chartmap = {}
    threads = []
    for module in modules:
        for chartnum in xrange(num_charts):
            chartmap[(module, chartnum)] = None    # initialize
    for module in modules:
        for chartnum in xrange(num_charts):
            thread = threading.Thread(target=_fetch_one_chart,
                                      args=(email, password, application,
                                            module, chartnum,
                                            chartmap, chartmap_lock, verbose))
            threads.append(thread)
            thread.start()
    # Wait for all the urls to be fetched
    if verbose:
        print ('>>> Waiting for data from %s charts in %s modules'
               % (num_charts, len(modules)))
    for thread in threads:
        thread.join()

    # Exceptions in threads are swallowed :-(, so we have to manually
    # check that they all succeeded.
    bad_fetches = [k for k in chartmap if chartmap[k] is None]
    if bad_fetches:
        raise ValueError("Failed to fetch the following module/chart-nums: %s"
                         % bad_fetches)

    dashboard_report_input = []
    for module in modules:
        for chartnum in xrange(num_charts):
            dashboard_report_input.append({
                'chart_num': chartnum,
                'module': module,
                'time_window': _WINDOW,
                'chart_url_data': chartmap[(module, chartnum)],
            })

    # Now we can finally call the dashboard report!
    dashboard_report.main(dashboard_report_input, now, graphite_host,
                          verbose, dry_run)


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    _HOME = os.getenv('HOME')
    parser.add_argument('--email', '-e', default='khanbackups@gmail.com',
                        help=('The username used to access the GAE dashboard. '
                              '(Default: %(default)s)'))
    parser.add_argument('--private_pw', default='%s/private_pw' % _HOME,
                        help=('File holding the password for user --username. '
                              '(Default: %(default)s)'))
    parser.add_argument('--application', '-A', default='s~khan-academy',
                        help=('GAE app-id that we are scraping stats for '
                              '(Default: %(default)s)'))
    parser.add_argument('--graphite_host',
                        default='carbon.hostedgraphite.com:2004',
                        help=('host:port to send stats to graphite '
                              '(using the pickle protocol). '
                              '(Default: %(default)s)'))
    parser.add_argument('--verbose', '-v', action='store_true',
                        help="Show more information about what we're doing.")
    parser.add_argument('--dry-run', '-n', action='store_true',
                        help="Show what we would do but don't do it.")
    args = parser.parse_args()

    with open(args.private_pw) as f:
        password = f.read().strip()

    main(args.email, password, args.application, args.graphite_host,
         args.verbose, args.dry_run)
