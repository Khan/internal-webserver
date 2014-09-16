#!/usr/bin/python

"""Make sure all Khan github repos include 'interns' and 'full-time' teams.

This way, everyone at Khan will have read and write access to all our
github repos.
"""

import base64
import json
import optparse
import os
import re
import sys
import urllib2


def _get_with_retries(url, github_token=None, max_tries=3):
    """If specified, basic_auth is a (username, password) pair."""
    request = urllib2.Request(url)
    if github_token:
        # Use the token-based basic-oauth scheme described at
        #   https://developer.github.com/v3/auth/#via-oauth-tokens
        # This is the best way to set the user, according to
        #   http://stackoverflow.com/questions/2407126/python-urllib2-basic-auth-problem
        encoded_password = base64.standard_b64encode('%s:x-oauth-basic'
                                                     % github_token)
        request.add_unredirected_header('Authorization',
                                        'Basic %s' % encoded_password)

    for i in xrange(max_tries):
        try:
            return urllib2.urlopen(request)
        except urllib2.URLError, why:
            if i == max_tries - 1:   # are not going to retry again
                print 'FATAL ERROR: Fetching %s failed: %s' % (url, why)
                raise


def _get_full(github_path, github_token=None, max_tries=3, verbose=False):
    """Follows Link: headers if needed to get *all* the data."""
    retval = None

    # The per_page param helps us avoid github rate-limiting.  cf.
    #    http://developer.github.com/v3/#rate-limiting
    # We use the token of a privileged user to be able to see private repos.
    url = 'https://api.github.com%s?per_page=100' % github_path

    while url:
        if verbose:
            print 'Fetching url %s' % url
        response = _get_with_retries(url, github_token)
        if retval is None:
            retval = json.load(response)
        else:
            retval.extend(json.load(response))   # better be a list!

        # 'Link:' header tells us if there's another page of results to read.
        m = re.search('<([^>]*)>; rel="next"', response.info().get('Link', ''))
        if m:
            url = m.group(1)
        else:
            url = None

    return retval


def _put(github_path, github_token=None, max_tries=3, verbose=False):
    """Does a PUT with empty data."""
    # Without using requests library, this is the way to go; see
    # http://stackoverflow.com/questions/111945/is-there-any-way-to-do-http-put-in-python
    url = 'https://api.github.com%s' % github_path
    if verbose:
        print 'PUT-ing url %s' % url
    opener = urllib2.build_opener(urllib2.HTTPSHandler)
    request = urllib2.Request(url)
    request.get_method = lambda: 'PUT'

    request.add_header('Content-Length', '0')
    if github_token:
        encoded_password = base64.standard_b64encode('%s:x-oauth-basic'
                                                     % github_token)
        request.add_unredirected_header('Authorization',
                                        'Basic %s' % encoded_password)

    opener.open(request)


def _get_team_repos(teams_info, team_name, github_token, verbose):
    """Given output of /orgs/Khan/teams, return (team_name, team_id, repos)."""
    team_id = next(r for r in teams_info if r['name'] == team_name)['id']
    team_repo_info = _get_full('/teams/%s/repos' % team_id,
                               github_token=github_token, verbose=verbose)
    team_repos = set(r['full_name'] for r in team_repo_info)
    return (team_name, team_id, team_repos)


def main(dry_run, verbose):
    # Use the token-based basic-oauth scheme described at
    #   https://developer.github.com/v3/auth/#via-oauth-tokens
    with open(os.path.expanduser('~/github.team_token')) as f:
        github_token = f.read().strip()

    # Get a list of all the repos we have.
    repo_info = _get_full('/orgs/Khan/repos',
                          github_token=github_token, verbose=verbose)
    all_repos = set(r['full_name'] for r in repo_info)

    # Get a list of all our teams.
    teams = _get_full('/orgs/Khan/teams',
                      github_token=github_token, verbose=verbose)
    team_info = [
        _get_team_repos(teams, 'dev-fulltime', github_token, verbose),
        _get_team_repos(teams, 'interns', github_token, verbose),
        ]

    for repo in all_repos:
        for (team_name, team_id, team_repos) in team_info:
            if repo not in team_repos:
                if dry_run:
                    print 'Would add the %s team to %s' % (team_name, repo)
                else:
                    print 'Adding the %s team to %s' % (team_name, repo)
                    _put('/teams/%s/repos/%s' % (team_id, repo),
                         github_token=github_token, verbose=verbose)


if __name__ == '__main__':
    usage = '%prog [options] <the root-directory of all repositories>'
    parser = optparse.OptionParser(usage=usage)
    parser.add_option('-v', '--verbose', action='store_true',
                      help='More verbose output')
    parser.add_option('-n', '--dry_run', action='store_true',
                      help="Just say what we would do, but don't do it")
    (options, args) = parser.parse_args(sys.argv[1:])
    if options.dry_run:
        options.verbose = True

    sys.exit(main(dry_run=options.dry_run, verbose=options.verbose))
