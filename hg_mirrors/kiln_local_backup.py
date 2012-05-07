#!/usr/bin/env python
# encoding: utf-8
"""
kiln_local_backup.py

Copyright (c) 2010 Nate Silva

Permission is hereby granted, free of charge, to any person
obtaining a copy of this software and associated documentation files
(the "Software"), to deal in the Software without restriction,
including without limitation the rights to use, copy, modify, merge,
publish, distribute, sublicense, and/or sell copies of the Software,
and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS
BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN
ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
"""
__version__ = "0.1.6"

import sys
import os
import urllib2
import time
import urllib
import urlparse
from subprocess import Popen, PIPE, STDOUT
from optparse import OptionParser, IndentedHelpFormatter

try:
    import json
except ImportError:
    try:
        import simplejson as json
    except ImportError:
        sys.exit('For versions of Python earlier than 2.6, you must ' +
            'install simplejson (http://pypi.python.org/pypi/simplejson/).')

CONFIG_FILE = 'backup.config'


def parse_command_line(args):
    """
    Parse the command line arguments.

    Returns a tuple of (options, destination_dir).

    Calls sys.exit() if the command line could not be parsed.
    """

    usage = 'usage: %prog [options] DESTINATION-DIR'
    description = 'Backs up all available Mercurial repositories on Kiln ' + \
        'by cloning them (if they have not been backed up before), or by ' + \
        'pulling changes. In order to run this without user interaction, ' + \
        'you must install the FogBugz "KilnAuth" Mercurial extension and ' + \
        'clone at least one repository so that your credentials are saved.'
    version = "%prog, v" + __version__

    parser = OptionParser(usage=usage, description=description,
                          version=version)
    parser.formatter = IndentedHelpFormatter(max_help_position=30)

    parser.add_option('-t', '--token', dest='token', help='FogBugz API token')
    parser.add_option('-s', '--server', dest='server', help='Kiln server name')
    parser.add_option('--scheme', dest='scheme', type='choice',
        choices=('https', 'http'), help='scheme used to connect to server')
    parser.add_option('-d', '--debug', dest='debug', action='store_true',
        default=False, help='extra-verbose output')
    parser.add_option('-q', '--quiet', dest='verbose', action='store_false',
        default=True, help='non-verbose output')
    parser.add_option('-l', '--limit', dest='limit', metavar='PATH',
        help='only backup repos in the specified project/group (ex.: ' + \
        'MyProject) (or: MyProject/MyGroup)')
    parser.add_option('-u', '--update', dest='update', action='store_true',
        default=False, help='update working copy when cloning or pulling')

    (options, args) = parser.parse_args(args)

    # Get the destination directory, which should be the one and
    # only non-option argument.
    if len(args) == 0:
        parser.error('Must specify the destination directory for the backups.')
    if len(args) > 1:
        parser.error('Unknown arguments passed after destination directory')
    destination_dir = args[0]

    # Now get any saved options from the configuration file and use
    # them to fill in any missing options.
    configfile_path = os.path.join(destination_dir, CONFIG_FILE)
    if os.path.exists(configfile_path):
        configfile = open(configfile_path, 'r')
        config_data = json.load(configfile)
        configfile.close()

        if not options.token and 'token' in config_data:
            options.token = config_data['token']

        if not options.server and 'server' in config_data:
            options.server = config_data['server']

        if not options.scheme and 'scheme' in config_data:
            options.scheme = config_data['scheme']

    # default to https if still no scheme specified
    if not options.scheme:
        options.scheme = 'https'

    return (options, destination_dir)


def get_repos(scheme, server, token, verbose, debug, max_tries=3):
    """
    Query Kiln to get the list of available repositories. Return the
    list, sorted by the Kiln “full name” of each repository.
    """

    if verbose:
        print console_encode('Getting the list of repositories from %s' %
            server)

    urlp = urlparse.urlparse('%s://%s/' % (scheme, server))

    if urlp.netloc[-10:] == 'kilnhg.com':
        url = '%s://%s/Api/Repos/?token=%s' % (scheme, server, token)
    else:
        url = '%s://%s/kiln/Api/Repos/?token=%s' % (scheme, server, token)

    if debug:
        print console_encode('Fetching url: %s' % url)

    for i in xrange(max_tries):
        try:
            data = json.load(urllib2.urlopen(url))
            break
        except urllib2.URLError, why:
            if i == max_tries - 1:   # are not going to retry again
                print console_encode('FATAL ERROR: Fetch failed: %s' % why)
                raise
            else:
                print console_encode('Fetch failed (%s).  Retrying...' % why)

    if 'error' in data:
        sys.exit(data['error'])

    if verbose:
        print console_encode('Found %d repositories' % len(data))

    return sorted(data, lambda x, y: cmp(x['fullName'], y['fullName']))


def backup_repo(clone_url, target_dir, verbose, debug, update):
    """
    Backup the specified repository. Returns True if successful. If
    the backup fails, prints an error message and returns False.
    """
    backup_method = 'clone'

    # If the filesystem does not use Unicode (from Python’s
    # perspective), convert target_dir to plain ASCII.
    if not sys.getfilesystemencoding().upper().startswith('UTF'):
        target_dir = target_dir.encode('ascii', 'xmlcharrefreplace')

    # Does the target directory already exist?
    if os.path.isdir(target_dir):
        # Yes, it exists. Does it refer to the same repository?
        args = ['hg', 'paths', '-R', target_dir, 'default']
        if debug:
            print console_encode('Running command: %s' % args)
        default = Popen(args, stdout=PIPE, stderr=STDOUT).communicate()[0]
        default = urllib2.unquote(default.strip())

        if default == clone_url:
            # It exists and is identical. We will pull.
            backup_method = 'pull'
        else:
            # It exists but refers to a different repo or is not a
            # repo. Move it to an archive directory.
            (parent_dir, repo_name) = os.path.split(target_dir)
            if verbose:
                print console_encode('exists but is not the same repo'),
                sys.stdout.flush()
            new_name = 'archive/%f-%s' % (time.time(), repo_name)
            new_dir = os.path.join(parent_dir, new_name)
            os.renames(target_dir, new_dir)
            if verbose:
                print console_encode('(archived); continuing backup...'),
                sys.stdout.flush()
    else:
        # Path doesn’t exist: create it
        os.makedirs(target_dir)

    # Back it up
    if backup_method == 'clone':
        if update:
            args = ['hg', 'clone', clone_url, target_dir]
        else:
            args = ['hg', 'clone', '--noupdate', clone_url, target_dir]
    else:
        if update:
            args = ['hg', 'pull', '-u', '-R', target_dir]
        else:
            args = ['hg', 'pull', '-R', target_dir]

    # KilnAuth uses os.path.expanduser which should use
    # %USERPROFILE%. When run from Scheduled Tasks, it does not seem
    # to work unless you also set %HOMEDRIVE% and %HOMEPATH%.
    child_env = os.environ
    if os.name == 'nt':
        (drive, path) = os.path.splitdrive(os.environ['USERPROFILE'])
        child_env['HOMEDRIVE'] = drive
        child_env['HOMEPATH'] = path

    if debug:
        print console_encode('Running command: %s' % args)
    proc = Popen(args, stdout=PIPE, stderr=PIPE, env=child_env)
    (stdoutdata, stderrdata) = proc.communicate()
    no_changes = (proc.returncode == 1 and 'no changes found' in stdoutdata)
    if proc.returncode and not no_changes:
        print console_encode('**** FAILED ****')
        print console_encode('*' * 60)
        print console_encode(u'Error %d backing up repository %s\n'
                             u'Error was: %s'
                             % (proc.returncode, clone_url, stderrdata))
        print console_encode('*' * 60)
        return False

    if verbose:
        if no_changes:
            print console_encode('-- no changes since last backup')
        else:
            print console_encode('backed up using method %s' %
                                 backup_method.upper())

    return True


def console_encode(message):
    """
    Encodes the message as appropriate for output to sys.stdout.
    This is needed especially for Windows, where stdout is often a
    non-Unicode encoding.
    """
    if sys.stdout.encoding == None:
        # Encoding not available. Force ASCII.
        return unicode(message).encode('ASCII', 'xmlcharrefreplace')
    if sys.stdout.encoding.upper().startswith('UTF'):
        # Unicode console. No need to convert.
        return message
    else:
        return unicode(message).encode(sys.stdout.encoding,
            'xmlcharrefreplace')


def encode_url(url):
    """
    URLs returned by Kiln may have unencoded Unicode characters in
    them. Encode them.
    """
    url_parts = list(urlparse.urlsplit(url.encode('utf-8')))
    url_parts[2] = urllib.quote(url_parts[2])
    return urlparse.urlunsplit(url_parts)


def main():
    """
    Main entry point for Kiln backup utility.
    """

    # Parse the command line
    (options, destination_dir) = parse_command_line(sys.argv[1:])

    # If token or server were not specified, prompt the user.
    if not options.token:
        options.token = raw_input('Your FogBugz API token: ')
        if not options.token:
            sys.exit('FogBugz API token is required.')

    if not options.server:
        options.server = \
            raw_input('Kiln server name (e.g. company.kilnhg.com): ')
        if not options.server:
            sys.exit('Kiln server name is required.')

    # If the destination directory doesn’t exist, try to create it.
    if not os.path.isdir(destination_dir):
        os.makedirs(destination_dir)
    if not os.path.isdir(destination_dir):
        sys.exit('destination directory', destination_dir, "doesn't exist",
            "and couldn't be created")

    # Save configuration
    configfile = open(os.path.join(destination_dir, CONFIG_FILE), 'w+')
    config = {'server': options.server, 'token': options.token,
        'scheme': options.scheme}
    json.dump(config, configfile, indent=4)
    configfile.write('\n')
    configfile.close()

    # Keep track of state for printing status messages
    if options.verbose:
        count = 0
        last_subdirectory = ''
        current_group = None

    # Keep track of overall success status. We continue backing up
    # even if there’s an error.
    overall_success = True

    # Time to begin!
    if options.verbose:
        print
        print console_encode('START: %s' % time.ctime())

    # Back up the repositories
    repos = get_repos(options.scheme, options.server, options.token,
                      options.verbose, options.debug)

    # If using --limit, filter repos we don’t want to backup.
    if options.limit:
        # Normalize the limit. Convert backslashes. Remove any
        # leading or trailing slash.
        limit = '/Kiln/Repo/%s' % options.limit.replace('\\', '/').strip('/')

        # Replace spaces with dashes, as Kiln does (in case the
        # user typed a human-readable repo name that has spaces)
        limit = limit.replace(' ', '-')

        # Filter. Case-insensitive. (Kiln won’t let you create two
        # groups or projects with the same name but different case.)
        repos = [_ for _ in repos if
            _['url'].lower().endswith(limit.lower())]

        if options.verbose:
            if len(repos) == 0:
                message = 'No repositories match the specified limit. '
                message += 'Nothing to back up!'
            else:
                if len(repos) == 1:
                    message = '1 repository matches '
                else:
                    message = '%d repositories match ' % len(repos)
                message += 'the specified limit and will be backed up'
            print console_encode(message)

    # Return an error code if there are no repos to back up. This
    # probably indicates a typo or similar mistake.
    if len(repos) == 0:
        overall_success = False

    for repo in repos:
        # The "full name" from Kiln is the project name, plus any
        # group name and the repo name. Components are separated by
        # a right angle quote (»). Turn this into a path by
        # converting angle quotes into path separators.
        parts = repo['fullName'].split(u'»')
        parts = [_.strip() for _ in parts]      # trim whitespace
        subdirectory = os.path.join(*parts)

        if options.verbose:
            # For the progress message, show the project and group
            # name as a header, and under that, list the repo names
            # being backed up. Also show a counter.

            group = os.path.commonprefix([last_subdirectory,
                os.path.dirname(subdirectory)])

            if group != current_group:
                current_group = os.path.dirname(subdirectory)
                print console_encode('\n%s' % current_group)

            last_subdirectory = subdirectory
            count += 1
            print console_encode('    >> [%d/%d] %s ' % (count, len(repos),
                os.path.basename(subdirectory))),

            # The following line fixes Issue 1. Python conveniently
            # inserts a space when a print statement ends with a
            # comma. Unfortunately if Mercurial is going to prompt
            # for a password, it does not know about the space that
            # is “supposed” to be there and the result looks like:
            # "reponamepassword:". The following line prevents
            # Python from inserting the space. Instead we manually
            # forced a space to the end of the print statement above.
            sys.stdout.write('')

            sys.stdout.flush()

        clone_url = encode_url(repo['cloneUrl'].strip('"'))
        target_dir = unicode(os.path.join(destination_dir, subdirectory))

        # TODO(csilvers): combine verbose and debug into a single variable.
        for i in xrange(3):   # we'll retry twice on error
            if i > 0:
                print console_encode('Retrying (retry #%s)...' % i)
            if backup_repo(clone_url, target_dir, options.verbose,
                           options.debug, options.update):
                break
        else:       # for/else: we get here if the backup never succeeded
            # Print to stderr so it gets turned into email when run via cron
            print >>sys.stderr, console_encode('Failed backup of repository %s'
                                               % clone_url)
            overall_success = False

    if options.verbose:
        print console_encode('DONE: %s' % time.ctime())

    if overall_success:
        print 'All repositories backed up successfully.'
        return 0
    else:
        print 'Completed with errors.'
        return 1


if __name__ == '__main__':
    sys.exit(main())
