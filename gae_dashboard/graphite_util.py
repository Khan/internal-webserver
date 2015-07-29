"""Utility functions for reading and sending data to graphite.

Graphite is our timeseries-graphing tool.  We send statistics from
the GAE admin dashboard to graphite in order to graph them.
"""

import cPickle
import datetime
import json
import logging
import os
import socket
import struct
import urllib
import urllib2


def fetch(graphite_host, targets, from_str=None):
    """Fetch data using graphite's Render URL API.
    
    This requires that $HOME/hostedgraphite_access_secret exists and
    holds the hostedgraphite access key for read-only access. See
    aws-config/toby/setup.sh.
    
    See the Render URL API docs, particularly for from_str, at
    http://graphite.readthedocs.org/en/latest/render_api.html#from-until
    
    Arguments:
        graphite_host: host of graphite Render URL API, the segment
            between https:// and the first / for your graphite
            installation.
        targets: a list of graphite targets to fetch.
        from_str: a value for the "from" parameter to the Render URL
            API, e.g., -5min for the last 5 minutes.
    
    Returns the JSON-formatted response as a Python object, which
    looks like this:
    
        [{"target": "a", "datapoints": [[0.123, 1428603130], ...]},
         {"target": "b", "datapoints": [[0.456, 1428603000], ...]},
         ...
         ]
    
    There is one item in the list for each target in the "targets"
    argument. The second argument in each datapoints is a timestamp,
    the number of seconds since the UNIX epoch.

    """
    assert len(set(targets)) == len(targets), ('Duplicate target in %s'
                                               % targets)
    
    # Load the access key that we need to read data from graphite.
    # This will (properly) raise an exception if this file isn't installed
    # (based on the contents of webapp secrets.py).
    with open(os.path.expanduser('~/hostedgraphite_access_secret')) as f:
        access_key = f.read().strip()
    
    targets = ''.join('&target=%s' % urllib.quote(m, ')(') for m in targets)
    url = ('https://%s/%s/graphite/render/?format=json%s'
           % (graphite_host, access_key, targets))
    if from_str:
        url += '&from=%s' % urllib.quote(from_str.encode('utf-8'), ")(")
    
    logging.debug('Loading %s' % url.replace(access_key, '<access key>'))
    return json.load(urllib2.urlopen(url))


def send_to_graphite(graphite_host, records):
    """Sends the given records to the graphite host.

    The format of the pickle-protocol data is described at:
    http://graphite.readthedocs.org/en/latest/feeding-carbon.html#the-pickle-protocol

    Arguments:
        graphite_host: hostname:port (port should be the port for the
            pickle protocol, probably 2004), or '' or None to avoid
            sending data to graphite.
        records: A list of (key, (time_t, value)) pairs.  The key is
            the graphite metric name (e.g. webapp.gae.summary.errors).
            The value must be an int.
    """
    if not graphite_host or not records:
        return

    # Load the api key that we need to send data to graphite.
    # This will (properly) raise an exception if this file isn't installed
    # (based on the contents of webapp secrets.py).
    with open(os.path.expanduser('~/hostedgraphite_secret')) as f:
        api_key = f.read().strip()

    # We need to prepend the api-key to each record we're sending.
    records = [('%s.%s' % (api_key, k), v) for (k, v) in records]

    (hostname, port_string) = graphite_host.split(':')
    host_ip = socket.gethostbyname(hostname)
    port = int(port_string)

    pickled_data = cPickle.dumps(records, cPickle.HIGHEST_PROTOCOL)
    payload = struct.pack("!L", len(pickled_data)) + pickled_data

    graphite_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    graphite_socket.connect((host_ip, port))
    graphite_socket.send(payload)
    graphite_socket.close()


def maybe_send_to_graphite(graphite_host, category, records, module=None):
    """Send dashboard statistics to the graphite timeseries-graphing tool.

    This requires $HOME/hostedgraphite_secret exists and holds the
    hostedgraphite API key. See aws-config/toby/setup.sh.

    Arguments:
        graphite_host: hostname:port (port should be the port for the
            pickle protocol, probably 2004), or '' or None to avoid
            sending data to graphite.
        category: a string to identify the source of this data.
            The key we send to graphite will be
                 webapp.gae.dashboard.<category>.<statistic>
            or, if module is also specified,
                 webapp.gae.dashboard.<category>.<module>_module.<statistic>
        records: a list of dicts, where the key is a string and the
            value a number.  We send each record to graphite.  Each
            record *must* have a 'utc_datetime' field with a
            datetime.datetime() object that says when this record's
            data is from.
        module: the GAE module that we collected this data for.
            e.g. 'default', 'frontend-highmem', etc.  If None, we
            assume this is global (not per-module) data and do not
            include it in the key.
    """
    epoch = datetime.datetime.utcfromtimestamp(0)

    graphite_data = []
    for record in records:
        record = record.copy()    # since we're munging it in place

        timestamp = record.pop('utc_datetime')
        # Convert the timestamp to a time_t.
        timestamp = int((timestamp - epoch).total_seconds())

        for (field, value) in record.iteritems():
            if module:
                module_component = '.%s' % module.replace('-', '_') + '_module'
            else:
                module_component = ''
            key = ('webapp.gae.dashboard.%s%s.%s'
                   % (category, module_component, field))

            graphite_data.append((key, (timestamp, value)))

    send_to_graphite(graphite_host, graphite_data)
