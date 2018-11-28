#!/usr/bin/env python

"""Get info about github repositories and emit it in csv form.

This is useful when deciding whether to archive or delete various
repos.  We download info that is helpful for making that decision.
The expectation is the output will be loaded into google spreadsheets
for comments and approval, and then the archiving/deletion decision
will be made based on the output of that process.
"""

import base64
import collections
import datetime
import csv
import json
import optparse
import os
import re
import sys
import urllib2


_GITHUB_REPO = re.compile(r'github.com[/:](Khan/[^.]*)', re.I)


def _parse_time(datetime_string):
    """Return a datetime for a YYYY-MM-DDTHH:MM:SSZ string."""
    # We ignore UTC, we don't care about every last hour.
    return datetime.datetime.strptime(datetime_string, "%Y-%m-%dT%H:%M:%SZ")


def _get_with_retries(url, basic_auth=None, max_tries=3):
    """If specified, basic_auth is a (username, password) pair."""
    request = urllib2.Request(url)
    if basic_auth:
        # This is the best way to set the user, according to
        # http://stackoverflow.com/questions/2407126/python-urllib2-basic-auth-problem
        encoded_password = base64.standard_b64encode('%s:%s' % basic_auth)
        request.add_unredirected_header('Authorization',
                                        'Basic %s' % encoded_password)

    for i in xrange(max_tries):
        try:
            return urllib2.urlopen(request)
        except urllib2.URLError as why:
            # For HTTP rc of 4xx, retrying won't help
            if i == max_tries - 1 or getattr(why, 'code', 500) < 500:
                if getattr(why, 'code', 500) != 404:
                    # 404's are expected sometimes, so don't print.
                    print 'FATAL ERROR: Fetching %s failed: %s' % (url, why)
                raise


def _get_repos(github_token, verbose):
    """A dict holding summary info about each repo that we have."""
    # The per_page param helps us avoid github rate-limiting.  cf.
    #    http://developer.github.com/v3/#rate-limiting
    # We use the token of a privileged user to be able to see private repos.
    github_api_url = 'https://api.github.com/orgs/Khan/repos?per_page=100'
    github_repo_info = []
    # The results may span several pages, requiring several fetches.
    while github_api_url:
        if verbose:
            print 'Fetching url %s' % github_api_url
        # Use the token-based basic-oauth scheme described at
        #   https://developer.github.com/v3/auth/#via-oauth-tokens
        response = _get_with_retries(github_api_url,
                                     (github_token, 'x-oauth-basic'))
        github_repo_info.extend(json.load(response))
        # 'Link:' header tells us if there's another page of results to read.
        m = re.search('<([^>]*)>; rel="next"', response.info().get('Link', ''))
        github_api_url = m and m.group(1)

    return github_repo_info


def _get_commit_info(repo, github_token, verbose):
    """Summary info on recent commits to the given repo, e.g. 'webapp'."""
    github_api_url = (
        'https://api.github.com/repos/%s/commits?per_page=100' % repo)
    if verbose:
        print 'Fetching url %s' % github_api_url
    try:
        r = _get_with_retries(github_api_url, (github_token, 'x-oauth-basic'))
    except urllib2.HTTPError as why:
        if why.code == 409:            # empty repo
            return []
        raise
    return json.load(r)


def _get_file_contents(repo, path, github_token, verbose):
    """Return the contents of `path` in repo, or '' if not found."""
    github_api_url = (
        'https://api.github.com/repos/%s/contents/%s' % (repo, path))
    if verbose:
        print 'Fetching url %s' % github_api_url
    try:
        r = _get_with_retries(github_api_url, (github_token, 'x-oauth-basic'))
    except urllib2.HTTPError as why:
        if why.code == 404:            # no .gitmodules
            return ''
        raise
    data = json.load(r)
    assert data['encoding'] == 'base64', data['encoding']
    return base64.b64decode(data['content'])


def _repos_this_repo_depends_on(repo, github_token, verbose):
    """A list of github repos this repo uses for submodules or package deps."""
    contents = _get_file_contents(repo, '.gitmodules', github_token, verbose)
    # TODO(csilvers): find package.json in any dir, not just the rootdir.
    contents += _get_file_contents(repo, 'package.json', github_token, verbose)
    contents += _get_file_contents(repo, 'requirements.txt',
                                   github_token, verbose)
    return _GITHUB_REPO.findall(contents)


def _most_frequent_author(commit_info):
    """The most-seen author-email in all the commits in commit_info."""
    if not commit_info:
        return '<empty repo>'
    author_emails = [c['commit']['author']['email'] for c in commit_info]
    return collections.Counter(author_emails).most_common(1)[0][0]


def _why_archive(repo_info, commit_info):
    """A first-pass recommendation of whether we should archive a repo.

    We say yes if there has been no commit in the last year, or there's
    been no commit in the last two months and there are <10 commits
    to the repo total.  (Indicating it was an abortive experiment.)

    If we say yes, we return the reason why we think so.  Otherwise we
    return None.
    """
    last_push = _parse_time(repo_info['pushed_at'])
    now = datetime.datetime.now()
    if last_push < now - datetime.timedelta(days=365):
        return "Last-push is over a year ago"
    if last_push < now - datetime.timedelta(days=60) and len(commit_info) < 10:
        return ("Has only %d commits, the last one over 3 months ago."
                % len(commit_info))
    return None


def summarize_repo_info(github_token, verbose):
    """Return a pair: (ordered list of csv column headers, list of dicts)."""
    header = ['repo name', 'is a fork', 'created', 'last push',
              'last push author', 'frequent author',
              'archive?', 'delete?', 'your username', 'comments']
    summaries = {}

    print "Getting list of repos...",
    repo_info = _get_repos(github_token, verbose)
    print "done"

    # a list of all repos some other repo depends on, e.g. as a submodule
    dependent_repos = {}
    for (i, repo) in enumerate(repo_info):
        print ("Getting info for %s (%d of %d)..."
               % (repo['full_name'], i + 1, len(repo_info))),
        repo_name = repo['full_name']
        commit_info = _get_commit_info(repo_name, github_token, verbose)
        why_archive = _why_archive(repo, commit_info)
        summaries[repo_name] = {
            'repo name': repo_name,
            'is a fork': 'yes' if repo['fork'] else 'no',
            'created': _parse_time(repo['created_at']).date(),
            'last push': _parse_time(repo['pushed_at']).date(),
            'last push author': (commit_info[0]['commit']['author']['email']
                                 if commit_info else '<empty repo>'),
            'frequent author': _most_frequent_author(commit_info),
            'archive?': 'yes' if why_archive else 'no',
            'delete?': 'no',
            'comments': why_archive or '',
        }
        for dependent_repo in _repos_this_repo_depends_on(
                repo_name, github_token, verbose):
            dependent_repos.setdefault(dependent_repo, set()).add(repo_name)
        print "done"

    # If a repo is used as a submodule for another repo, or is listed in
    # another repo's package.json, don't archive it; it's still active.
    # TODO(csilvers): maybe make sure the submodule-includer is active first?
    for (repo_name, depending_repos) in dependent_repos.iteritems():
        if repo_name in summaries:
            summaries[repo_name]['archive?'] = 'no'
            summaries[repo_name]['delete?'] = 'no'
            if len(depending_repos) == 1:
                summaries[repo_name]['comments'] = (
                    '%s uses it' % depending_repos[0])
            else:
                summaries[repo_name]['comments'] = (
                    '%s use it' % ' and '.join(sorted(depending_repos)))

    # We'll sort the repos by last_push_author for ease of reference.
    rows = summaries.values()
    rows.sort(key=lambda d: (d['last push author'], d['last push']))

    return (header, rows)


def main(outfile, verbose=False):
    with open(os.path.expanduser('~/github.repo_token')) as f:
        github_token = f.read().strip()

    (header, rows) = summarize_repo_info(github_token, verbose)

    writer = csv.DictWriter(outfile, header)
    writer.writeheader()
    writer.writerows(rows)


if __name__ == '__main__':
    usage = '%prog [options] <the root-directory of all repositories>'
    parser = optparse.OptionParser(usage=usage)
    parser.add_option('-v', '--verbose', action='store_true',
                      help='More verbose output')
    parser.add_option('-f', '--filename', default='github_info.csv',
                      help="Where to write the resulting CSV; '-' for stdout")
    (options, args) = parser.parse_args(sys.argv[1:])

    if options.filename == '-':
        main(sys.stdout, options.verbose)
    else:
        with open(options.filename, 'w') as f:
            main(f, options.verbose)
