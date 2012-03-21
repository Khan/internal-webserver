import os
import re
import tempfile
import urlparse

from djblets.util.filesystem import is_exe_in_path

from reviewboard.scmtools import sshutils
from reviewboard.scmtools.core import SCMTool, HEAD, PRE_CREATION
from reviewboard.scmtools.errors import SCMError, FileNotFoundError, \
                                        RepositoryNotFoundError
from reviewboard.diffviewer.parser import DiffParser, DiffParserError


sshutils.register_rbssh('CVS_RSH')


class CVSTool(SCMTool):
    name = "CVS"
    supports_authentication = True
    dependencies = {
        'executables': ['cvs'],
    }

    rev_re = re.compile(r'^.*?(\d+(\.\d+)+)\r?$')
    repopath_re = re.compile(r'^(?P<hostname>.*):(?P<port>\d+)?(?P<path>.*)')
    ext_cvsroot_re = re.compile(r':ext:([^@]+@)?(?P<hostname>[^:/]+)')

    def __init__(self, repository):
        super(CVSTool, self).__init__(repository)

        self.cvsroot, self.repopath = \
            self.build_cvsroot(self.repository.path,
                               self.repository.username,
                               self.repository.password)

        local_site_name = None

        if repository.local_site:
            local_site_name = repository.local_site.name

        self.client = CVSClient(self.cvsroot, self.repopath, local_site_name)

    def get_file(self, path, revision=HEAD):
        if not path:
            raise FileNotFoundError(path, revision)

        return self.client.cat_file(path, revision)

    def parse_diff_revision(self, file_str, revision_str, *args, **kwargs):
        if revision_str == "PRE-CREATION":
            return file_str, PRE_CREATION

        m = self.rev_re.match(revision_str)
        if not m:
            raise SCMError("Unable to parse diff revision header '%s'" %
                           revision_str)
        return file_str, m.group(1)

    def get_diffs_use_absolute_paths(self):
        return True

    def get_fields(self):
        return ['diff_path']

    def get_parser(self, data):
        return CVSDiffParser(data, self.repopath)

    @classmethod
    def build_cvsroot(cls, path, username, password):
        # NOTE: According to cvs, the following formats are valid.
        #
        #  :(gserver|kserver|pserver):[[user][:password]@]host[:[port]]/path
        #  [:(ext|server):][[user]@]host[:]/path
        #  :local:e:\path
        #  :fork:/path

        if not path.startswith(":"):
            # The user has a path or something. We'll want to parse out the
            # server name, port (if specified) and path and build a :pserver:
            # CVSROOT.
            m = cls.repopath_re.match(path)

            if m:
                path = m.group("path")
                cvsroot = ":pserver:"

                if username:
                    if password:
                        cvsroot += '%s:%s@' % (username,
                                               password)
                    else:
                        cvsroot += '%s@' % (username)

                cvsroot += "%s:%s%s" % (m.group("hostname"),
                                        m.group("port") or "",
                                        path)
                return cvsroot, path

        # We couldn't parse this as a hostname:port/path. Assume it's a local
        # path or a full CVSROOT and let CVS handle it.
        return path, path

    @classmethod
    def check_repository(cls, path, username=None, password=None,
                         local_site_name=None):
        """
        Performs checks on a repository to test its validity.

        This should check if a repository exists and can be connected to.
        This will also check if the repository requires an HTTPS certificate.

        The result is returned as an exception. The exception may contain
        extra information, such as a human-readable description of the problem.
        If the repository is valid and can be connected to, no exception
        will be thrown.
        """
        # CVS paths are a bit strange, so we can't actually use the
        # SSH checking in SCMTool.check_repository. Do our own.
        m = cls.ext_cvsroot_re.match(path)

        if m:
            sshutils.check_host(m.group('hostname'), username, password,
                                local_site_name)

        cvsroot, repopath = cls.build_cvsroot(path, username, password)
        client = CVSClient(cvsroot, repopath, local_site_name)

        try:
            client.cat_file('CVSROOT/modules', HEAD)
        except (SCMError, FileNotFoundError):
            raise RepositoryNotFoundError()

    @classmethod
    def parse_hostname(cls, path):
        """Parses a hostname from a repository path."""
        return urlparse.urlparse(path)[1] # netloc


class CVSDiffParser(DiffParser):
    """This class is able to parse diffs created with CVS. """

    regex_small = re.compile('^RCS file: (.+)$')

    def __init__(self, data, repo):
        DiffParser.__init__(self, data)
        self.regex_full = re.compile('^RCS file: %s/(.*),v$' % re.escape(repo))

    def parse_special_header(self, linenum, info):
        linenum = super(CVSDiffParser, self).parse_special_header(linenum, info)

        if 'index' not in info:
            # We didn't find an index, so the rest is probably bogus too.
            return linenum

        m = self.regex_full.match(self.lines[linenum])
        if not m:
            m = self.regex_small.match(self.lines[linenum])

        if m:
            info['filename'] = m.group(1)
            linenum += 1
        else:
            raise DiffParserError('Unable to find RCS line', linenum)

        while self.lines[linenum].startswith('retrieving '):
            linenum += 1

        if self.lines[linenum].startswith('diff '):
            linenum += 1

        return linenum

    def parse_diff_header(self, linenum, info):
        linenum = super(CVSDiffParser, self).parse_diff_header(linenum, info)

        if info.get('origFile') == '/dev/null':
            info['origFile'] = info['newFile']
            info['origInfo'] = 'PRE-CREATION'
        elif 'filename' in info:
            info['origFile'] = info['filename']

        if info.get('newFile') == '/dev/null':
            info['deleted'] = True

        return linenum


class CVSClient(object):
    def __init__(self, cvsroot, path, local_site_name):
        self.tempdir = ""
        self.currentdir = os.getcwd()
        self.cvsroot = cvsroot
        self.path = path
        self.local_site_name = local_site_name

        if not is_exe_in_path('cvs'):
            # This is technically not the right kind of error, but it's the
            # pattern we use with all the other tools.
            raise ImportError

    def cleanup(self):
        if self.currentdir != os.getcwd():
            # Restore current working directory
            os.chdir(self.currentdir)
            # Remove temporary directory
            if self.tempdir != "":
                os.rmdir(self.tempdir)

    def cat_file(self, filename, revision):
        # We strip the repo off of the fully qualified path as CVS does
        # not like to be given absolute paths.
        repos_path = self.path.split(":")[-1]

        if '@' in repos_path:
            repos_path = '/' + repos_path.split('@')[-1].split('/', 1)[-1]

        if filename.startswith(repos_path + "/"):
            filename = filename[len(repos_path) + 1:]

        # Strip off the ",v" we sometimes get for CVS paths.
        if filename.endswith(",v"):
            filename = filename.rstrip(",v")

        # We want to try to fetch the files with different permutations of
        # "Attic" and no "Attic". This means there are 4 various permutations
        # that we have to check, based on whether we're using windows- or
        # unix-type paths

        filenameAttic = filename

        if '/Attic/' in filename:
            filename = '/'.join(filename.rsplit('/Attic/', 1))
        elif '\\Attic\\' in filename:
            filename = '\\'.join(filename.rsplit('\\Attic\\', 1))
        elif '\\' in filename:
            pos = filename.rfind('\\')
            filenameAttic = filename[0:pos] + "\\Attic" + filename[pos:]
        elif '/' in filename:
            pos = filename.rfind('/')
            filenameAttic = filename[0:pos] + "/Attic" + filename[pos:]
        else:
            # There isn't any path information, so we can't provide an
            # Attic path that makes any kind of sense.
            filenameAttic = None

        try:
            return self._cat_specific_file(filename, revision)
        except FileNotFoundError:
            if filenameAttic:
                return self._cat_specific_file(filenameAttic, revision)
            else:
                raise

    def _cat_specific_file(self, filename, revision):
        # Somehow CVS sometimes seems to write .cvsignore files to current
        # working directory even though we force stdout with -p.
        self.tempdir = tempfile.mkdtemp()
        os.chdir(self.tempdir)

        p = SCMTool.popen(['cvs', '-f', '-d', self.cvsroot, 'checkout',
                           '-r', str(revision), '-p', filename],
                          self.local_site_name)
        contents = p.stdout.read()
        errmsg = p.stderr.read()
        failure = p.wait()

        # Unfortunately, CVS is not consistent about exiting non-zero on
        # errors.  If the file is not found at all, then CVS will print an
        # error message on stderr, but it doesn't set an exit code with
        # pservers.  If the file is found but an invalid revision is requested,
        # then cvs exits zero and nothing is printed at all. (!)
        #
        # But, when it is successful, cvs will print a header on stderr like
        # so:
        #
        # ===================================================================
        # Checking out foobar
        # RCS: /path/to/repo/foobar,v
        # VERS: 1.1
        # ***************

        # So, if nothing is in errmsg, or errmsg has a specific recognized
        # message, call it FileNotFound.
        if not errmsg or \
           errmsg.startswith('cvs checkout: cannot find module') or \
           errmsg.startswith('cvs checkout: could not read RCS file'):
            self.cleanup()
            raise FileNotFoundError(filename, revision)

        # Otherwise, if there's an exit code, or errmsg doesn't look like
        # successful header, then call it a generic SCMError.
        #
        # If the .cvspass file doesn't exist, CVS will return an error message
        # stating this. This is safe to ignore.
        if (failure and not errmsg.startswith('==========')) and \
           not ".cvspass does not exist - creating new file" in errmsg:
            self.cleanup()
            raise SCMError(errmsg)

        self.cleanup()
        return contents
