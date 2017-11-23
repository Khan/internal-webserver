#!/usr/bin/python
# TODO(colin): fix these lint errors (http://pep8.readthedocs.io/en/release-1.7.x/intro.html#error-codes)
# pep8-disable:E124

"""Update the phabricator webserver with the current list of repositories.

We query github to get a list of all the repositories that they know
about that are owned by Khan academy.

Then we query phabricator to get a list of all the repositories it has
registered.

If there are any repositories in the first group but not the second,
we use the phabricator API to add these repositories.

We log what we've done (usually nothing) to stdout, and errors to
stderr, so this is appropriate to be put in a cronjob.
"""

import base64
import json
import optparse
import os
import re
import socket
import string
import subprocess
import sys
import time
import urllib2

# We need to load python-phabricator.  On the internal-webserver
# install, it lives in a particular place we know about.  We take
# advantage of the knowledge that this file lives in the root-dir
# of internal-webserver.
_INTERNAL_WEBSERVER_ROOT = os.path.abspath(os.path.dirname(__file__))
sys.path.append(os.path.join(_INTERNAL_WEBSERVER_ROOT, 'python-phabricator'))
import phabricator

_DEBUG = False


def _retry(cmd, times, verbose=False, exceptions=(Exception,)):
    """Retry cmd up to times times, if it gives an exception in exceptions."""
    for i in xrange(times):
        try:
            return cmd()
        except exceptions, why:
            if i + 1 == times:    # ran out of tries
                raise
            if verbose:
                print 'Error running "%s": %s.  Retrying' % (cmd, why)


def _create_new_phabricator_callsign(repo_name, vcs_type, existing_callsigns):
    """Create a small, unique, legal callsign out of the repo-name."""
    # Callsigns must be capital letters.
    repo_name = repo_name.upper()

    # Get rid of numbers: callsigns can't have them.
    repo_name = re.sub(r'[0-9]', '', repo_name)

    # Get the 'words' by breaking on non-letters.
    name_parts = re.split(r'[^A-Z]+', repo_name)

    # We'll use a call-sign of the prefixes of each word, starting with
    # 'G' for git.
    callsign_start = vcs_type[0].upper()
    # Ideally, just the first letter of each word, than first 2, etc.
    for prefix_len in xrange(max(len(w) for w in name_parts)):
        candidate_callsign = (callsign_start +
                              ''.join(w[:prefix_len + 1] for w in name_parts))
        if candidate_callsign not in existing_callsigns:
            return candidate_callsign

    # Yikes!  OK, our last chance: just add a unique letter at the end.
    for letter in string.uppercase:
        final_chance = candidate_callsign + letter
        if final_chance not in existing_callsigns:
            return final_chance

    # Dunno what to do if the name *still* isn't unique...
    raise NameError('Cannot find a unique callsign.  Will need to modify '
                    'update_phabricator_repositories:'
                    '_create_new_phabricator_callsign.')


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
        except urllib2.URLError, why:
            if i == max_tries - 1:   # are not going to retry again
                print 'FATAL ERROR: Fetching %s failed: %s' % (url, why)
                raise


def _get_repos_to_add_and_delete(phabctl, verbose):
    """Query github, phabricator, etc; return sets of clone-urls."""
    # The per_page param helps us avoid github rate-limiting.  cf.
    #    http://developer.github.com/v3/#rate-limiting
    # We use the token of a privileged user to be able to see private repos.
    github_api_url = 'https://api.github.com/orgs/Khan/repos?per_page=100'
    with open(os.path.expanduser('~/github.repo_token')) as f:
        github_token = f.read().strip()
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
        if m:
            github_api_url = m.group(1)
        else:
            github_api_url = None

    # When adding a new github repo to phabricator, we want to use the
    # ssh url for a private repo and the https url for a public repo.
    # (TODO(csilvers): any reason not to just use the ssh url everywhere?)
    # But if a repo changes from public to private, we don't want to
    # (or need to) change the phabricator state.  So for every repo
    # we store a pair of urls.  The first item of the pair is the url
    # to use when adding.  But we don't need to add if *either* url is
    # in phabricator.
    github_repos = set()
    for repo in github_repo_info:
        try:
            ssh_url = repo['ssh_url']
            if ssh_url.endswith('.git'):
                ssh_url = ssh_url[:-len('.git')]

            clone_url = repo['clone_url']
            if clone_url.endswith('.git'):
                clone_url = clone_url[:-len('.git')]

            if repo['private']:
                github_repos.add((ssh_url, clone_url))
            else:
                github_repos.add((clone_url, ssh_url))
        except (TypeError, IndexError):
            raise RuntimeError('Unexpected response from github: %s'
                               % github_repo_info)

    if phabctl.certificate is None:
        raise KeyError('You must set up your .arcrc via '
                       '"arc install-certificate %s"' % phabctl.host)
    if verbose:
        print 'Fetching list of repositories from %s' % phabctl.host

    # Ugh, we have to use the low-level API because python-phabricator
    # doesn't understand requests with 3 components (`diff.repo.search`).
    urlparams = {}
    if _DEBUG:
        # Turn on profiling so when phabricator is slow and this command
        # times out, we have a chance of figuring out why.
        urlparams = {'__profile__': 1}

    phabricator_repo_info = []
    cursor = None
    # We have to do paging ourselves. :-(
    while True:
        query_cmd = lambda: phabctl.make_request('diffusion.repository.search',
                                                 {'after': cursor,
                                                  'attachments': {'uris': True}
                                                 },
                                                 urlparams)
        new_info = _retry(query_cmd, times=3, verbose=verbose,
                          exceptions=(socket.timeout,))
        phabricator_repo_info.extend(new_info.response['data'])
        cursor = new_info.response['cursor']['after']
        if not cursor:
            break

    # phabricator requires each repo to have a unique "callsign".  We
    # store the existing callsigns to ensure uniqueness for new ones.
    # We also store the associated url to help with delete commands.
    # We also want to distinguish 'active' from 'inactive' phabricator
    # repos.  We treat inactive repos -- for historical reasons, we
    # call them 'untracked' -- much like deleted repos: they just sit
    # around so old urls pointing to the repo still work.
    repo_to_callsign_map = {}
    tracked_phabricator_repos = set()
    for repo_info in phabricator_repo_info:
        for uri_info in repo_info['attachments']['uris']['uris']:
            repo_url = uri_info['fields']['uri']['raw']
            repo_to_callsign_map[repo_url] = repo_info['fields']['callsign']
            # We only care about uri's that are tracking a github url.
            if (repo_info['fields']['status'] == 'active' and
                    uri_info['fields']['io']['effective'] == 'observe'):
                tracked_phabricator_repos.add(repo_url)

    phabricator_repos = frozenset(repo_to_callsign_map)

    if verbose:
        def _print(name, lst):
            print '* Existing %s repos:\n%s\n' % (name, '\n'.join(sorted(lst)))
        _print('github', {u1 for (u1, _) in github_repos})
        _print('(tracked) phabricator', tracked_phabricator_repos)
        _print('UNTRACKED phabricator',
               phabricator_repos - tracked_phabricator_repos)

    new_repos = set()
    # Either url refers to the same repo, so we have to check both.
    for (url1, url2) in github_repos:
        if url1 not in phabricator_repos and url2 not in phabricator_repos:
            new_repos.add(url1)

    deleted_repos = (tracked_phabricator_repos -
                     {u1 for (u1, _) in github_repos} -
                     {u2 for (_, u2) in github_repos})
    return (new_repos, deleted_repos, repo_to_callsign_map)


def add_repository(phabctl, repo_rootdir, repo_clone_url, url_to_callsign_map,
                   options):
    """Use the phabricator API to add a new repo with reasonable defaults.

    Phabricator has a concept of a 'callsign', which is a (preferably)
    short, unique identifier for a project.  We need to know the
    callsigns of all existing repos in phabricator to pick a new one
    for this project.

    Arguments:
      phabctl: a Phabricator.phabricator() instance.
      repo_rootdir: the root dir where this repo will be cloned.  The
          actual clone will take place in root_dir/vcs_type/name.
      repo_clone_url: the clone-url of the repository to add.
      url_to_callsign_map: a map from repo-clone-url to the phabricator
          callsign, for each repo in the phabricator database.  If we
          successfully add a new repository here, we also update
          this map with the new callsign.  This argument can be
          modified by the function (in place).
      options: a struct holding the commandline flags values, used for
          --verbose and --dry_run.

    Raises:
      phabricator.APIError: if something goes wrong with the insert.
    """
    # For git the name is just the repo-name.  We use git@ for private
    # github repos and https: for public github repos.
    # Map of prefix: (vcs_type, ssh-passphrase phab-id or None for public repo)
    prefix_map = {
            'https://github.com/Khan/': ('git', None),
            'git@github.com:Khan/': ('git', 'K2'),  # phabricator.ka.org/K2
        }
    for (prefix, (vcs_type, passphrase_id)) in prefix_map.iteritems():
        if repo_clone_url.startswith(prefix):
            name = repo_clone_url[len(prefix):]
            if name.endswith('.git'):        # shouldn't happen, but...
                name = name[:-len('.git')]
            break
    else:    # for/else: if we get here, no prefix matched
        raise NameError('Unknown repo type: Must update prefix_map in '
                        'update_phabricator_repositories.py')

    callsign = _create_new_phabricator_callsign(
        name, vcs_type, set(url_to_callsign_map.values()))

    # We need to convert the ssh-passphrase phabricator id to a PHID.
    if passphrase_id is None:
        passphrase_phid = None
    else:
        q = phabctl.phid.lookup(names=[passphrase_id])
        passphrase_phid = q[passphrase_id]['phid']

    if options.dry_run:
        print ('Would add new repository %s: '
               'url=%s, callsign=%s, vcs=%s %s'
               % (name, repo_clone_url, callsign, vcs_type,
                  'PRIVATE' if passphrase_id else ''))
    else:
        print ('Adding new repository %s: '
               'url=%s, callsign=%s, vcs=%s %s'
               % (name, repo_clone_url, callsign, vcs_type,
                  'PRIVATE' if passphrase_id else ''))
        sys.stdout.flush()

        # Ugh, we have to use the low-level API because python-phabricator
        # doesn't understand requests with 3 components (`diff.repo.edit`).

        def _make_txn(m):
            """Turn all {key: value}s into {'type': key, 'value': value}."""
            return [{'type': k, 'value': v} for (k, v) in m.iteritems()]

        txns = {'name': name, 'vcs': vcs_type, 'callsign': callsign}
        r = phabctl.make_request('diffusion.repository.edit',
                                 {'transactions': _make_txn(txns)})
        phid = r.response['object']['phid']

        txns = {'repository': phid, 'io': 'observe', 'uri': repo_clone_url}
        if passphrase_id:
            txns['credential'] = passphrase_phid
        phabctl.make_request('diffusion.uri.edit',
                             {'transactions': _make_txn(txns)})

        txns = {'status': 'active'}
        phabctl.make_request('diffusion.repository.edit',
                             {'transactions': _make_txn(txns),
                              'objectIdentifier': phid})

    url_to_callsign_map[repo_clone_url] = callsign


def delete_repository(phabctl, repo_rootdir, repo_clone_url,
                      url_to_callsign_map, options):
    """Because it's scary to delete automatically, for now I just warn."""
    print ('Repository %s has been deleted: '
           'run (on the phabricator machine):\n'
           '   env PHABRICATOR_ENV=khan'
           ' ~/internal-webserver/phabricator/bin/remove destroy r%s'
           % (repo_clone_url, url_to_callsign_map[repo_clone_url]))


def _run_command_with_logging(cmd_as_list, env, verbose):
    """Run cmd, raise an exception if it fails and log the output."""
    if verbose:
        print 'Running %s' % cmd_as_list

    p = subprocess.Popen(cmd_as_list, env=env,
                         stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    output = p.communicate()[0]

    if p.returncode != 0:         # set by p.communicate()
        raise RuntimeError('%s failed.  Output:\n---\n%s---\n'
                           % (cmd_as_list, output))
    elif verbose:
        print 'Output:\n---\n%s---\n' % output
    return output


def main(repo_rootdir, options):
    """New repositories will be placed under repo_rootdir/vcs_type/name."""
    if options.verbose:
        print
        print 'START: %s' % time.ctime()

    phabricator_domain = 'https://phabricator.khanacademy.org'
    phabctl = phabricator.Phabricator(host=phabricator_domain + '/api/')

    (new_repos, deleted_repos, url_to_callsign_map) = (
        _get_repos_to_add_and_delete(phabctl, options.verbose))

    if options.verbose:
        print 'Adding %d new repositories to phabricator' % len(new_repos)
    num_failures = 0
    # Since the order we go through the repos can affect the callsigns we
    # emit, sort the list of repos so this is always reproducible.
    for repo in sorted(new_repos):
        try:
            add_repository(phabctl, repo_rootdir,
                           repo, url_to_callsign_map, options)
        except (phabricator.APIError, NameError), why:
            print >>sys.stderr, ('ERROR: Unable to add repository %s: %s'
                                 % (repo, why))
            num_failures += 1

    if options.verbose:
        print ('Removing %d deleted repositories from phabricator'
               % len(deleted_repos))
    for repo in sorted(deleted_repos):
        delete_repository(phabctl, repo_rootdir, repo, url_to_callsign_map,
                          options)

    if options.verbose:
        print 'DONE: %s' % time.ctime()

    return num_failures


if __name__ == '__main__':
    usage = '%prog [options] <the root-directory of all repositories>'
    parser = optparse.OptionParser(usage=usage)
    parser.add_option('-v', '--verbose', action='store_true',
                      help='More verbose output')
    parser.add_option('-n', '--dry_run', action='store_true',
                      help="Just say what we would do, but don't do it")
    (options, args) = parser.parse_args(sys.argv[1:])
    if len(args) != 1:
        parser.error('Must specify the root-directory.')
    if options.dry_run:
        options.verbose = True

    sys.exit(main(args[0], options))
