#!/usr/bin/env python

"""Check if Sentry is rate-limiting.

We want to make sure that Sentry doesn't rate-limit the prod-js project. If it
does that makes it hard to do good deploy-time monitoring since it may not be
collecting errors during the deploy process.

We've set up sentry to rate-limit other projects in hopes of keeping the total
volume of errors low enough that prod-js doesn't get rate-limited.

This script checks to see if the prod-js project has been rated limited in the
past day and sends an alert if it has been.
"""
import os.path

import alertlib
import requests

here = os.path.abspath(os.path.dirname(__file__))
with open(os.path.join(here, 'sentry_api_key')) as f:
    api_key = f.read().strip()


PROJECTS_TO_CHECK = [
    'prod-js',
    'mobile-app',
    'mobile-android',
    'mobile-ios',
    'mobile-webview',
    'react-native',
]


def get_num_rejected(project):
    "Return the number of events rate-limited in the last day"
    resp = requests.get(
        'https://sentry.io/api/0/projects/khanacademyorg/{}/stats/'.format(project),
        auth=(api_key, ''),
        params={'resolution': '1d', 'stat': 'rejected'})
    resp.raise_for_status()
    data = resp.json()
    return sum([n for (ts, n) in data])


def main():
    for project in PROJECTS_TO_CHECK:
        if get_num_rejected(project) == 0:
            continue
        alertlib.Alert(
            'Sentry {} project is being rate-limited'.format(project)
            ).send_to_slack('#infrastructure-sre')


if __name__ == '__main__':
    main()
