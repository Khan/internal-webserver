#!/usr/bin/env python3

"""A script to notify, on slack, when fastly config changes happen.

We have the #whats-happening slack channel that reports on config changes that
affect our running site.  It's useful for debugging when we have site failures.
Every deploy and publish posts to that channel, for instance.

We'd like changes to the active fastly config to notify there as well -- at
least for the public-facing fastly services.  The trick is that while deploys
and publishes go through scripts that we write, that can post to slack, there
are changes to fastly that -- as of Sept 2020 -- can only be made via the
fastly UI.

Rather than forcing people to remember to manually post to #whats-happening
every time they use the UI, we write a simple script that asks fastly if the
version of the service has changed recently, and posts to slack if so.  It is
meant to be run every minute or so, via cron.
"""
import collections
import json
import http.client
import logging
import os
import time

from gae_dashboard import alertlib


_FASTLY_HOST = 'api.fastly.com'
_API_SECRET_LOCATION = "~/internal-webserver/fastly-notifier.secret"
_HISTORY_LOCATION = "~/internal-webserver/fastly-notifier.json"

_SERVICES_TO_IGNORE = set((
    '*.khanacademy.org **TEST**',
))

ServiceInfo = collections.namedtuple("ServiceInfo",
                                     ("version", "updated_at", "description"))


def get_service_info(api_key):
    """Return a dict from service-name to ServiceInfo."""
    conn = http.client.HTTPSConnection(_FASTLY_HOST)
    conn.request("GET", "/service", headers={'Fastly-Key': api_key})
    resp = conn.getresponse()
    body = resp.read()
    if resp.status != 200:
        raise http.client.HTTPException("Error talking to %s: response %s (%s)"
                                        % (_FASTLY_HOST, resp.status, body))
    data = json.loads(body)

    retval = {}
    for service in data:
        name = service['name']
        if name in _SERVICES_TO_IGNORE:
            continue
        active_version = next(
            (v for v in service['versions'] if v['active']),
            {'number': '<none>', 'updated_at': '<unknown>', 'comment': None},
        )
        retval[name] = ServiceInfo(version=active_version['number'],
                                   updated_at=active_version['updated_at'],
                                   description=active_version['comment'])
    return retval


def get_modification_messages(service_info, last_service_info, history_file):
    messages = []

    for service in set(last_service_info) - set(service_info):
        messages.append(
            '*Fastly service `%s` has been deleted*.\nLast seen at %s.'
            % (service, time.ctime(os.path.getmtime(history_file))))

    for (service, info) in service_info.items():
        # json stores data as a list, not a tuple, so we have to convert.
        if list(info) != last_service_info.get(service):
            messages.append(
                '*Fastly service `%s` was modified at %s*.\n'
                'New version: %s.\nDescription: %s'
                % (service, info.updated_at, info.version,
                   info.description or '<none>'))

    return messages


def send_to_slack(slack_channel, messages):
    for message in messages:
        alertlib.Alert(message, severity=logging.INFO) \
          .send_to_slack(slack_channel, sender='fastly',
                         icon_emoji=':fastly:')


if __name__ == '__main__':
    import argparse
    dflt = ' (default: %(default)s)'
    parser = argparse.ArgumentParser()
    parser.add_argument('--secret-file', default=_API_SECRET_LOCATION,
                        help='Path of the fastly API secret' + dflt)
    parser.add_argument('--history-file', default=_HISTORY_LOCATION,
                        help='Path of the notification-history file' + dflt)
    parser.add_argument('--slack-channel', default='#whats-happening',
                        help='Slack channel to notify at' + dflt)
    args = parser.parse_args()

    secret_file = os.path.expanduser(args.secret_file)
    history_file = os.path.expanduser(args.history_file)

    with open(secret_file) as f:
        api_key = f.read().strip()

    if os.path.exists(history_file):
        with open(history_file) as f:
            last_service_info = json.load(f)
    else:
        logging.warn("No history file found, assuming this is the first run")
        last_service_info = {}

    service_info = get_service_info(api_key)
    messages = get_modification_messages(service_info, last_service_info,
                                         history_file)
    send_to_slack(args.slack_channel, messages)

    with open(history_file, 'w') as f:
        json.dump(service_info, f, indent=4, sort_keys=True)
