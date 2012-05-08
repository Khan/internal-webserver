#!/usr/bin/python

"""Update the phabricator webserver with the current list of repositories.

We query github and kilnhg, to get a list of all the repositories that
they know about that are owned by Khan academy.  (We could add other
sources, like bitbucket, at need.)

Then we query phabricator to get a list of all the repositories it has
registered.

If there are any repositories in the first group but not the second,
we use the phabricator API to add these repositories.

We log what we've done (usually nothing) to stdout, and errors to
stderr, so this is appropriate to be put in a cronjob.
"""

import json
import optparse
import os
import re
import subprocess
import sys
import time
import urllib

# We need to load python-phabricator.  On the internal-webserver
# install, it lives in a particular place we know about.  We take
# advantage of the knowledge that this file lives in the root-dir
# of internal-webserver.
_INTERNAL_WEBSERVER_ROOT = os.path.abspath(os.path.dirname(__file__))
sys.path.append(os.path.join(_INTERNAL_WEBSERVER_ROOT, 'python-phabricator'))
import phabricator

# We also load kiln_local_backup to make it easier to id kiln repos.
sys.path.append(os.path.join(_INTERNAL_WEBSERVER_ROOT, 'hg_mirrors'))
import kiln_local_backup


def _find_kilnauth_token(domain):
    """domain should be something like 'khanacademy.kilnhg.com'."""
    prefix = '%s\tFALSE\t/\tFALSE\t\tfbToken\t' % domain
    for (dirpath, _, filenames) in os.walk(os.path.expanduser('~/.hgcookies')):
        for filename in filenames:
            f = open(os.path.join(dirpath, filename))
            for line in f:
                if line.startswith(prefix):
                    return line[len(prefix):].strip()
            f.close()   # might as well be nice
    raise KeyError('FATAL: Cannot find a %s fbToken in ~/.hgcookies' % domain)


def _create_new_phabricator_callsign(repo_name, existing_callsigns):
    """Create a small, unique, legal callsign out of the repo-name."""
    # Callsigns must be capital letters.
    repo_name = repo_name.upper()

    # Get rid of numbers: callsigns can't have them.
    repo_name = re.sub(r'[0-9]', '', repo_name)

    # Get the 'words' by breaking on non-letters.
    name_parts = re.split(r'[^A-Z]+', repo_name)

    # Get rid of some uninteresting parts of the name that kiln puts in.
    if 'GROUP' in name_parts:
        name_parts.remove('GROUP')
    if 'WEBSITE' in name_parts:
        name_parts.remove('WEBSITE')

    # We'll use a call-sign of the prefixes of each word.
    # Ideally, just the first letter of each word, than first 2, etc.
    for prefix_len in xrange(max(len(w) for w in name_parts)):
        candidate_callsign = ''.join(w[:prefix_len + 1] for w in name_parts)
        if candidate_callsign not in existing_callsigns:
            return candidate_callsign

    # Dunno what to do if the name *still* isn't unique...
    raise NameError('Cannot find a unique callsign.  Will need to modify '
                    'update_phabricator_repositories:'
                    '_create_new_phabricator_callsign.')


def _get_repos_to_add(phabctl, verbose):
    """Query github, kiln, phabricator, etc; return a set of clone-urls."""
    hg_domain = 'khanacademy.kilnhg.com'
    hg_token = _find_kilnauth_token(hg_domain)
    kiln_repo_info = kiln_local_backup.get_repos('https', hg_domain, hg_token,
                                                 verbose, verbose)
    kiln_repos = set(r['cloneUrl'] for r in kiln_repo_info)

    git_project = 'Khan'
    git_api_url = 'https://github.com/api/v2/json/repos/show/%s' % git_project
    if verbose:
        print 'Fetching url %s' % git_api_url
    git_repo_info = json.loads(urllib.urlopen(git_api_url).read())
    git_repos = set(r['url'] for r in git_repo_info['repositories'])

    phabricator_domain = 'http://phabricator.khanacademy.org'
    if phabctl.certificate is None:
        raise KeyError('You must set up your .arcrc via '
                       '"arc install-certificate %s"' % phabricator_domain)
    if verbose:
        print 'Fetching list of repositories from %s' % phabricator_domain
    phabricator_repo_info = phabctl.repository.query()
    phabricator_repos = set(r['remoteURI'] for r in phabricator_repo_info)
    # phabricator requires each repo to have a unique "callsign".  We
    # store the existing callsigns to ensure uniqueness for new ones.
    existing_callsigns = set(r['callsign'] for r in phabricator_repo_info)

    new_repos = (kiln_repos | git_repos) - phabricator_repos
    return (new_repos, existing_callsigns)


def add_repository(phabctl, repo_rootdir, repo_clone_url, existing_callsigns,
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
      existing_callsigns: a set of all callsigns of all repos in the
          phabricator database.  If we successfully add a new repository
          here, we also update existing_callsigns with the new callsign.
      options: a struct holding the commandline flags values, used for
          --verbose and --dry_run.

    Raises:
      phabricator.APIError: if something goes wrong with the insert.
    """
    # For git, the name is just the repo-name.  For khan, it's the
    # repo-triple (everything after 'Code').
    prefix_map = {'https://github.com/Khan/': 'git',
                  'https://khanacademy.kilnhg.com/Code/': 'hg'}
    for (prefix, vcs_type) in prefix_map.iteritems():
        if repo_clone_url.startswith(prefix):
            name = repo_clone_url[len(prefix):]
            if name.endswith('.git'):        # shouldn't happen, but...
                name = name[:-len('.git')]
            break
    else:    # for/else: if we get here, no prefix matched
        raise NameError('Unknown repo type: Must update prefix_map in '
                        'update_phabricator_repositories.py')

    callsign = _create_new_phabricator_callsign(name, existing_callsigns)
    destdir = os.path.join(repo_rootdir, vcs_type, name)
    print ('Adding new repository %s: url=%s, callsign=%s, vcs=%s, destdir=%s'
           % (name, repo_clone_url, callsign, vcs_type, destdir))
    if not options.dry_run:
        phabctl.repository.create(name=name, vcs=vcs_type, callsign=callsign,
                                  uri=repo_clone_url,
                                  tracking=True, pullFrequency=60,
                                  localPath=destdir)
    existing_callsigns.add(callsign)


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


def _check_daemons(need_to_restart, verbose):
    """Restart the phabricator daemons, as needed if we add new repos."""
                           # c.f. http://www.phabricator.com/docs/phabricator/article/Diffusion_User_Guide.html#running-diffusion-daemon
    phd = os.path.join(_INTERNAL_WEBSERVER_ROOT, 'phabricator/bin/phd')
    env = os.environ.copy()
    env['PHABRICATOR_ENV'] = 'khan'   # our config file for phabricator

    if need_to_restart:
        _run_command_with_logging([phd, 'stop'], env, verbose)

    existing_daemons = _run_command_with_logging([phd, 'status'], env, verbose)
    if not 'Fetch' in existing_daemons:
        _run_command_with_logging([phd, 'repository-launch-master'],
                                  env, verbose)
    if not 'Taskmaster' in existing_daemons:
        _run_command_with_logging([phd, 'launch', 'taskmaster'],
                                  env, verbose)


def main(repo_rootdir, options):
    """New repositories will be placed under repo_rootdir/vcs_type/name."""
    if options.verbose:
        print
        print 'START: %s' % time.ctime()

    phabctl = phabricator.Phabricator()
    (new_repos, existing_callsigns) = _get_repos_to_add(phabctl,
                                                        options.verbose)

    if options.verbose:
        print 'Adding %d new repositories to phabricator' % len(new_repos)
    num_failures = 0
    # Since the order we go through the repos can affect the callsigns we
    # emit, sort the list of repos so this is always reproducible.
    for repo in sorted(new_repos):
        try:
            add_repository(phabctl, repo_rootdir,
                           repo, existing_callsigns, options)
        except (phabricator.APIError, NameError), why:
            print >>sys.stderr, ('ERROR: Unable to add repository %s: %s'
                                 % (repo, why))
            num_failures += 1

    # Even if there are no new repositories, this is useful to run
    # to make sure the daemons are always up and running.
    need_to_restart = len(new_repos) > num_failures   # we added some repos
    _check_daemons(need_to_restart, options.verbose)

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
