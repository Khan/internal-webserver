#!/usr/bin/env python

"""Fetch stats about appengine and send them to graphite.

We get the stats via the cloud-monitoring API.  Records are sent to
graphite under the keys
   webapp.gae.dashboard.summary.<module>_module.*
"""

import functools
import os
import time

import cloudmonitoring_util
import graphite_util


_NOW = int(time.time())
_LAST_RECORD_DB = os.path.expanduser('~/dashboard_report_time.db')


def _time_t_of_latest_record():
    """time_t of the most recently stored dashboard record.

    This data is stored in a file.  We could consider this a small database.

    Returns:
        The time_t (# of seconds since the UNIX epoch in UTC) or None if
        there is no previous record.
    """
    if os.path.exists(_LAST_RECORD_DB):
        with open(_LAST_RECORD_DB) as f:
            return int(f.read().strip())
    return None


def _write_time_t_of_latest_record(time_t):
    """Given the record with the latest time-t, write it to the db."""
    with open(_LAST_RECORD_DB, 'w') as f:
        print >>f, time_t


_TIMESERIES = None
_TIMESERIES_CACHE = {}      # map from fn args to returned dict.


def _get_timeseries(metric, project_id, start_time_t, end_time_t):
    """service.timeseries().list(), but with caching and auto-paging."""
    global _TIMESERIES

    cache_key = (project_id, metric, start_time_t, end_time_t)
    if cache_key in _TIMESERIES_CACHE:
        return _TIMESERIES_CACHE[cache_key]

    if _TIMESERIES is None:
        service = cloudmonitoring_util.get_cloudmonitoring_service()
        _TIMESERIES = service.timeseries()

    retval = {'timeseries': []}
    page_token = None
    while True:
        # TODO(csilvers): do I want to set 'window'?
        r = cloudmonitoring_util.execute_with_retries(
            _TIMESERIES.list(
                project=project_id,
                metric=metric,
                oldest=cloudmonitoring_util.to_rfc3339(start_time_t),
                youngest=cloudmonitoring_util.to_rfc3339(end_time_t),
                pageToken=page_token,
                count=10000))

        # Merge these fields into retval.
        retval['kind'] = r['kind']
        # Luckily, RFC3339 dates can be lexicographically compared.
        if 'youngest' not in retval or r['youngest'] > retval['youngest']:
            retval['youngest'] = r['youngest']
        if 'oldest' not in retval or r['oldest'] < retval['oldest']:
            retval['oldest'] = r['oldest']

        retval['timeseries'].extend(r.get('timeseries', []))

        # Go to the next page of results, if necessary.
        if 'nextPageToken' in r:
            page_token = r['nextPageToken']
        else:
            break

    _TIMESERIES_CACHE[cache_key] = retval
    return _TIMESERIES_CACHE[cache_key]


def _get_module(timeseries):
    """Extracts the module from an entry in the 'timeseries' array.

    See
        https://developers.google.com/resources/api-libraries/documentation/cloudmonitoring/v2beta2/python/latest/cloudmonitoring_v2beta2.timeseries.html
    for a description of the 'timeseries' array.
    """
    return timeseries['timeseriesDesc']['labels'][
        'appengine.googleapis.com/module']


def _parse_points(points, make_per_second=False):
    """Given a list of 'points', return a list of (time_t, value).

    If make_per_second is True, then we divide the given value by the
    time-range, to get a per-second value.  This is necessary because
    we tend to use per-second counts in graphite, while
    cloudmonitoring likes to give absolute counts over a time-range.

    See
        https://developers.google.com/resources/api-libraries/documentation/cloudmonitoring/v2beta2/python/latest/cloudmonitoring_v2beta2.timeseries.html
    for a description of the 'points' array.

    """
    retval = set()
    for point in points:
        start_time = cloudmonitoring_util.from_rfc3339(point['start'])
        end_time = cloudmonitoring_util.from_rfc3339(point['end'])

        # For some reason -- maybe 32-bit compatibility? -- the
        # cloudmonitoring API converts doubles and bools to native
        # type, but not int64.
        if 'doubleValue' in point:
            value = point['doubleValue']
        elif 'boolValue' in point:
            value = point['boolValue']
        elif 'stringValue' in point:
            value = point['stringValue']
        elif 'int64Value' in point:
            value = int(point['int64Value'])
        elif 'distributionValue' in point:
            raise NotImplementedError('Support distributionValue')
        else:
            raise ValueError('Point %s lacks an expected value key' % point)

        if make_per_second:
            assert end_time > start_time, point   # not a 'sum' type
            value = value * 1.0 / (end_time - start_time)

        # We associate the number with the minute it *ends* on.
        retval.add((end_time, value))

    return retval


def _add_points(existing_points, new_points, combinator):
    """Appends or combined all the points in new_points with existing_points.

    This worked on parsed points (so (time_t,value) pairs).  If an
    existing point already exists with the same time_t as a new point,
    we replace the existing point with the combined value of the
    existing and new point.  If no such existing point exists, we just
    add the new point to existing_points.

    The combinator should be 'sum'.  If there's a need for 'avg', we'll
    need to re-implement this to do proper weighting.

    This is used when combining points from the same module but
    different versions.  In such cases we'll have two different
    timeseries-maps over the same time-period, but with different
    module and version fields.
    """
    assert combinator in ('sum',), combinator

    new_points = dict(new_points)     # convert to map from time_t -> value
    for i in xrange(len(existing_points)):
        (time_t, value) = existing_points[i]
        if time_t in new_points:
            if combinator == 'sum':
                existing_points[i] = (time_t, value + new_points.pop(time_t))
            else:
                raise NotImplementedError("We don't support %s" % combinator)
    existing_points.extend(sorted(new_points.iteritems()))


def _collect_per_module_per_second(data, filter=None):
    """Create a per-module dict of (time_t, value) pairs.

    For every timeseries in 'data', which is a timeseries object
    returned by the cloudmonitoring API, we apply the filter (if
    given), ignoring the timeseries if the filter returns False.
    Then, we take every datapoint in the timeseries and add it
    to our return value, which is a dict of module->data.  (Each
    timeseries says what module it goes with.)

    The return value also has a key of 'None', and its values
    are the totals across all module.

    This function is only appropriate for stats where cloudmonitoring
    returns count-in-this-timerange, and we want to convert that to
    counts-per-second.  In particular, it's not appropriate when
    cloudmonitoring returns an average.

    Arguments:
       data: A cloudmonitoring data structure as returned by
           service.timeseries().list(...).execute()
       filter: a function that takes a timeseries dict and
           returns True if we should consider that dict and
           False else.  The timeseries dict is described at
               https://developers.google.com/resources/api-libraries/documentation/cloudmonitoring/v2beta2/python/latest/cloudmonitoring_v2beta2.timeseries.html
    """
    retval = {}
    for timeseries in data['timeseries']:
        if filter and not filter(timeseries):
            continue

        module = _get_module(timeseries)
        points = _parse_points(timeseries['points'], make_per_second=True)
        _add_points(retval.setdefault(module, []), points, 'sum')
        _add_points(retval.setdefault(None, []), points, 'sum')  # grand total
    return retval


# This decorator marks a function that returns data suitable for
# sending to graphite.  All functions marked with this decorator will
# automatically be called via fetch_stats, and send data to graphite
# with the name webapp.gae.dashboard.<string>.  <string> can include
# '%(module_name)s'.
_FUNC_MAP = {}     # map from graphite-name to function to call for it


def _send_to_graphite_given_values(graphite_host, graphite_name, values,
                                   verbose=False):

    """Actually do the sending to graphite once values are computed.

    values is either a list of (time_t, value) pairs, or a dict from
    module-name to a time_t/value list.  Returns the latest time_t seen.
    """
    if not values:
        return

    # 'values' is either a list of (time_t, value) pairs, or a
    # map from module_name to list of (time_t, value) pairs.
    if isinstance(values, dict):
        assert '%(module_name)s' in graphite_name, graphite_name
        retval = 0
        for (module_name, module_values) in values.iteritems():
            if module_name is None:   # Holds sum over all modules
                key_suffix = graphite_name.replace('%(module_name)s.', '')
            else:
                module = module_name.replace('-', '_') + '_module'
                key_suffix = graphite_name % {'module_name': module}
            retval = max(retval,
                         _send_to_graphite_given_values(
                             graphite_host, key_suffix, module_values,
                             verbose))
        return retval
    else:
        assert '%(module_name)s' not in graphite_name, graphite_name
        key = 'webapp.gae.dashboard.%s' % graphite_name
        graphite_data = [(key, v) for v in sorted(values)]
        if verbose:
            if graphite_host:
                print "--> Sending to graphite:"
            else:
                print "--> Would send to graphite:"
            # Replace the common prefix with an abbreviation to make it
            # more likely each line will fit within 80 chars.
            print '\n'.join(
                str(v).replace('webapp.gae.dashboard.summary.', 'w.g.d.s.')
                for v in graphite_data)
        graphite_util.send_to_graphite(graphite_host, graphite_data)
        return graphite_data[-1][1][0] if graphite_data else 0


def _send_to_graphite(graphite_name):
    def graphite_wrapper(func):
        @functools.wraps(func)
        def wrapper(project_id, graphite_host, start_time_t, end_time_t,
                    verbose=False, dry_run=False):
            """Returns the number of datapoints we sent to graphite."""
            timeseries_getter = functools.partial(   # all but the metric
                _get_timeseries,
                project_id=project_id,
                start_time_t=start_time_t,
                end_time_t=end_time_t)

            retval = func(timeseries_getter)

            if dry_run:
                graphite_host = None    # disable the actual sending
                verbose = True          # print what we would have done

            return _send_to_graphite_given_values(
                graphite_host, graphite_name, retval, verbose=verbose)

        assert graphite_name not in _FUNC_MAP, 'Dup: %s' % graphite_name
        _FUNC_MAP[graphite_name] = wrapper
        return wrapper
    return graphite_wrapper


# -------------------------------------------------------------------------
#                                  THE STATS
# -------------------------------------------------------------------------


@_send_to_graphite('summary.%(module_name)s.client_errors_per_second')
def client_errors_per_second(timeseries_getter):
    data = timeseries_getter(
        'appengine.googleapis.com/http/server/response_count')

    filter = lambda ts: (ts['timeseriesDesc']['labels'][
        'appengine.googleapis.com/response_code'].startswith('4'))  # 4xx

    return _collect_per_module_per_second(data, filter)


@_send_to_graphite('summary.%(module_name)s.server_errors_per_second')
def server_errors_per_second(timeseries_getter):
    data = timeseries_getter(
        'appengine.googleapis.com/http/server/response_count')

    filter = lambda ts: (ts['timeseriesDesc']['labels'][
        'appengine.googleapis.com/response_code'].startswith('5'))  # 5xx

    return _collect_per_module_per_second(data, filter)


@_send_to_graphite('summary.%(module_name)s.requests_per_second')
def requests_per_second(timeseries_getter):
    # TODO(csilvers): is there a 1-to-1 correspondence between responses
    # and requests?
    data = timeseries_getter(
        'appengine.googleapis.com/http/server/response_style_count')
    return _collect_per_module_per_second(data)


@_send_to_graphite('summary.%(module_name)s.static_requests_per_second')
def static_requests_per_second(timeseries_getter):
    data = timeseries_getter(
        'appengine.googleapis.com/http/server/response_style_count')

    filter = lambda ts: (ts['timeseriesDesc']['labels'][
        'appengine.googleapis.com/dynamic'] == 'false')

    return _collect_per_module_per_second(data, filter)


@_send_to_graphite('summary.%(module_name)s.dynamic_requests_per_second')
def dynamic_requests_per_second(timeseries_getter):
    data = timeseries_getter(
        'appengine.googleapis.com/http/server/response_style_count')

    filter = lambda ts: (ts['timeseriesDesc']['labels'][
        'appengine.googleapis.com/dynamic'] == 'true')

    return _collect_per_module_per_second(data, filter)


@_send_to_graphite('summary.%(module_name)s.cached_requests_per_second')
def cached_requests_per_second(timeseries_getter):
    data = timeseries_getter(
        'appengine.googleapis.com/http/server/response_style_count')

    filter = lambda ts: (ts['timeseriesDesc']['labels'][
        'appengine.googleapis.com/cached'] == 'true')

    return _collect_per_module_per_second(data, filter)


@_send_to_graphite('summary.%(module_name)s.milliseconds_per_dynamic_request')
def milliseconds_per_dynamic_request(timeseries_getter):
    return None


@_send_to_graphite('summary.%(module_name)s.milliseconds_per_loading_request')
def milliseconds_per_loading_request(timeseries_getter):
    return None


@_send_to_graphite('summary.%(module_name)s.quota_denials_per_second')
def quota_denials_per_second(timeseries_getter):
    data = timeseries_getter(
        'appengine.googleapis.com/http/server/quota_denial_count')
    return _collect_per_module_per_second(data)


@_send_to_graphite('summary.%(module_name)s.dos_api_denials_per_second')
def dos_api_denials_per_second(timeseries_getter):
    data = timeseries_getter(
        'appengine.googleapis.com/http/server/dos_intercept_count')
    return _collect_per_module_per_second(data)


@_send_to_graphite('summary.%(module_name)s.bytes_sent_per_second')
def bytes_sent_per_second(timeseries_getter):
    # TODO(csilvers): should I filter out cached responses?
    data = timeseries_getter(
        'appengine.googleapis.com/system/network/sent_bytes_count')
    return _collect_per_module_per_second(data)


@_send_to_graphite('summary.%(module_name)s.bytes_received_per_second')
def bytes_received_per_second(timeseries_getter):
    # TODO(csilvers): figure out what 'cached' means here.
    data = timeseries_getter(
        'appengine.googleapis.com/system/network/received_bytes_count')
    return _collect_per_module_per_second(data)


@_send_to_graphite('summary.%(module_name)s.total_instance_count')
def total_instance_count(timeseries_getter):
    data = timeseries_getter(
        'appengine.googleapis.com/system/instance_count')

    # Can't use _collect_per_module_per_second because we're not per-second.
    retval = {}
    for timeseries in data['timeseries']:
        module = _get_module(timeseries)
        points = _parse_points(timeseries['points'])
        _add_points(retval.setdefault(module, []), points, 'sum')
        _add_points(retval.setdefault(None, []), points, 'sum')  # grand total
    return retval


@_send_to_graphite('summary.%(module_name)s.active_instance_count')
def active_instance_count(timeseries_getter):
    data = timeseries_getter(
        'appengine.googleapis.com/system/instance_count')

    retval = {}
    for timeseries in data['timeseries']:
        if timeseries['timeseriesDesc']['labels'][
                'appengine.googleapis.com/state'] != 'active':
            continue
        module = _get_module(timeseries)
        points = _parse_points(timeseries['points'])
        _add_points(retval.setdefault(module, []), points, 'sum')
        _add_points(retval.setdefault(None, []), points, 'sum')  # grand total
    return retval


@_send_to_graphite('summary.%(module_name)s.billed_instance_count')
def billed_instance_count(timeseries_getter):
    return None      # not implemented yet :-(


@_send_to_graphite('memcache.hit_count')
def memcache_hit_count(timeseries_getter):
    return None


@_send_to_graphite('memcache.hit_ratio')
def memcache_hit_ratio(timeseries_getter):
    return None


@_send_to_graphite('memcache.item_count')
def memcache_item_count(timeseries_getter):
    return None


@_send_to_graphite('memcache.miss_count')
def memcache_miss_count(timeseries_getter):
    return None


@_send_to_graphite('memcache.oldest_item_age_seconds')
def memcache_oldest_item_age_seconds(timeseries_getter):
    return None


@_send_to_graphite('memcache.total_cache_size_bytes')
def memcache_total_cache_size_bytes(timeseries_getter):
    return None


def main(project_id, graphite_host, interval_in_seconds=300,
         verbose=False, dry_run=False):
    start_time_t = _time_t_of_latest_record() or _NOW - 86400 * 30
    end_time_t = _NOW

    last_time_t_seen = start_time_t
    for range_start in xrange(start_time_t, end_time_t, interval_in_seconds):
        range_end = range_start + interval_in_seconds
        for (name, stat_func) in _FUNC_MAP.iteritems():
            if verbose:
                print ("Calculating stats for %s from %s - %s"
                       % (name, range_start, range_end))
            this_last_time_t = stat_func(project_id, graphite_host,
                                         range_start, range_end,
                                         verbose=verbose, dry_run=dry_run)
            if this_last_time_t:     # can be None for 'not implemented yet'
                last_time_t_seen = max(last_time_t_seen, this_last_time_t)

        if dry_run:
            print ("Would update last-processed-time from %s to %s"
                   % (_time_t_of_latest_record(), last_time_t_seen))
        else:
            print ("Updating last-processed-time from %s to %s"
                   % (_time_t_of_latest_record(), last_time_t_seen))
            _write_time_t_of_latest_record(last_time_t_seen)

    print "Done!"


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--project-id', '-p', default='khan-academy',
                        help=('The cloud-monitoring project-id to fetch '
                              'stats for (Default: %(default)s)'))
    parser.add_argument('--graphite_host',
                        default='carbon.hostedgraphite.com:2004',
                        help=('host:port to send stats to graphite '
                              '(using the pickle protocol). '
                              '(Default: %(default)s)'))
    parser.add_argument('--interval', type=int,
                        default=300,
                        help=('Process this many minutes of logs at a time '
                              '(Default: %(default)s)'))
    parser.add_argument('--verbose', '-v', action='store_true',
                        help="Show more information about what we're doing.")
    parser.add_argument('--dry-run', '-n', action='store_true',
                        help="Show what we would do but don't do it.")
    args = parser.parse_args()

    main(args.project_id, args.graphite_host, args.interval,
         args.verbose, args.dry_run)
