#!/usr/bin/env python

"""Set assignees for Sentry issues

We would like Sentry issues to be associated with initiatives. This is done
with assignees. There is also a Sentry "ownership" feature that does something
similar to this script, but doesn't actually set the assignee unfortunatley.

This script should be run periodically. It will look for unassigned issues
and assign them to initiatives based on the URLs of issue events.

"""
import collections
import os.path
import re
import urlparse

import requests


# Map from Sentry team to URL path regexes. These were derived from routes in
# webapp/main.py The goal is to do make a reasonable guess as to initiative
# ownership; we don't seek perfection, since URL isn't 100% reliabile indicator
# of who owns the error anyway.
URLS = (
    ('infrastructure', (r'/devadmin/.*', r'/admin/.*')),
    ('test-prep', (r'/prep/.*', r'/collegeboard/.*', r'/sat.*')),
    ('classroom', (
        r'/coach.*', r'/parent.*', r'/teachers', r'/acceptcoach',
        r'/joinclass')),
    ('mpp', (
        r'/donate/.*', r'/careers/.*', r'/contribute/.*',
        r'/youcanlearnanything.*', r'/bncc', r'/kids.*', r'/homeschool')),
    # LP is assigned anything not already matched.
    ('learning-platform', (r'.*', )))


SENTRY_URL = 'https://sentry.io/api/0'


here = os.path.abspath(os.path.dirname(__file__))
with open(os.path.join(here, 'sentry_api_key')) as f:
    api_key = f.read().strip()


def parse_links(header):
    "Parse Link http header."
    links = [part.strip() for part in header.split(',')]
    for link in links:
        parts = [part.strip() for part in link.split(';')]
        url = parts[0][1:-1]
        meta_data = {}
        for part in parts[1:]:
            m = re.match(r'(\w+)="([^"]+)"', part)
            meta_data[m.group(1)] = m.group(2)
        yield url, meta_data


def sentry_request(path, params=None, put=False, return_links=False):
    "Make a request to Sentry."
    if put:
        resp = requests.put(SENTRY_URL + path, auth=(api_key, ''), json=params)
    else:
        # Assume get.
        resp = requests.get(SENTRY_URL + path, auth=(api_key, ''),
                            params=params)
    resp.raise_for_status()
    if return_links:
        header = resp.headers['Link']
        return resp.json(), tuple(parse_links(header))
    return resp.json()


def get_unassigned_issue_ids():
    "Get a list of unassigned issue ids."
    issue_ids = []
    cursor = None
    while True:
        params = {'query': 'is:unresolved is:unassigned'}
        if cursor is not None:
            params['cursor'] = cursor
        issues, links = sentry_request(
            '/projects/khanacademyorg/prod-js/issues/',
            params=params, return_links=True)
        issue_ids += [issue['id'] for issue in issues]

        # Deal with pagination: https://docs.sentry.io/api/pagination/
        cursor = None
        for link, meta_data in links:
            if meta_data['rel'] == 'next' and meta_data['results'] == 'true':
                cursor = meta_data['cursor']
        if cursor is None:
            break  # No more batches.

    return issue_ids


def get_issue_urls(issue_id):
    "Return a sequence of (url path, count) tuples for the issue."
    urls = sentry_request('/issues/{}/tags/url/'.format(issue_id))
    return [(urlparse.urlparse(url['value']).path, url['count'])
            for url in urls['topValues']]


def initiative_for_url(url):
    "Return the initiative name that's responsible for a URL path."
    for assignee, patterns in URLS:
        for pattern in patterns:
            if re.match(pattern, url):
                return assignee
    raise ValueError('Should never happen')


def best_initiative(urls):
    "Return the best initiative for an issues URLs."
    totals = collections.Counter()
    for url, count in urls:
        initiative = initiative_for_url(url)
        totals.update({initiative: count})
    return totals.most_common(1)[0][0]


def set_issue_assignee(issue_id, team, team_ids):
    "Set the issue's assignee to a team."
    assignee = 'team:{}'.format(team_ids[team])
    sentry_request('/issues/{}/'.format(issue_id), {'assignedTo': assignee},
                   put=True)


def get_initiative_ids():
    "Fetch the initiative team ids."
    ids = {}
    for initiative, _ in URLS:
        data = sentry_request('/teams/khanacademyorg/{}/'.format(initiative))
        ids[initiative] = data['id']
    return ids


def main():
    ids = get_initiative_ids()
    unassigned_issue_ids = get_unassigned_issue_ids()
    for issue_id in unassigned_issue_ids:
        try:
            issue_urls = get_issue_urls(issue_id)
        except requests.exceptions.HTTPError:
            # Not sure why this sometimes happens.
            print 'Could not fetch issue {}'.format(issue_id)
            continue
        initiative = best_initiative(issue_urls)
        set_issue_assignee(issue_id, initiative, ids)

    print 'Set assignees for {} issues.'.format(len(unassigned_issue_ids))


if __name__ == '__main__':
    main()
