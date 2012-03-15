# Copyright (C) 2009-2011 Fog Creek Software.  All rights reserved.
#
# To enable the "kilnauth" extension put these lines in your ~/.hgrc:
#  [extensions]
#  kilnauth = /path/to/kilnauth.py
#
# For help on the usage of kilnauth use:
#  hg help kilnauth
#
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.

'''stores authentication cookies for HTTP repositories

This extension knows how to capture Kiln authentication tokens when pushing
over HTTP.  This means you only need to enter your login and password once;
after that, the FogBugz token will be stored in your home directory, allowing
pushing without a password.

If you ever need to logout of Kiln, simply run ``hg logout''
'''

from cookielib import MozillaCookieJar, Cookie
from urllib2 import Request
import os
import re
import shutil
import stat
import sys
import tempfile

try:
    from hashlib import md5
except:
    # Python 2.4
    import md5

try:
    WindowsError
except NameError:
    WindowsError = None

from mercurial.i18n import _
import mercurial.url
from mercurial import commands

current_user = None

class CookieJar(MozillaCookieJar, object):
    def __init__(self, filename, *args, **kwargs):
        self.__original_path = filename
        tf = tempfile.NamedTemporaryFile(delete=False)
        self.__temporary_path = tf.name
        tf.close()
        if os.path.exists(filename):
            shutil.copyfile(filename, self.__temporary_path)
        return super(CookieJar, self).__init__(self.__temporary_path, *args, **kwargs)

    def __enter__(self):
        pass

    def __exit__(self, exc_type, exc_value, traceback):
        os.unlink(self.__temporary_path)
        self.__temporary_path = None

    def __del__(self):
        try:
            if self.__temporary_path:
                os.unlink(self.__temporary_path)
        except (OSError, IOError):
            pass

    def save(self, *args, **kwargs):
        with open(self.__temporary_path, 'rb') as f:
            before = md5(f.read()).digest()
        super(CookieJar, self).save(*args, **kwargs)
        with open(self.__temporary_path, 'rb') as f:
            after = md5(f.read()).digest()
        if before != after:
            try:
                os.rename(self.__temporary_path, self.__original_path)
            except WindowsError:
                shutil.copyfile(self.__temporary_path, self.__original_path)
            except (IOError, OSError):
                pass

def get_cookiejar(ui):
    global current_user
    if os.name == 'nt':
        cookie_path = os.path.expanduser('~\\_hgcookies')
    else:
        cookie_path = os.path.expanduser('~/.hgcookies')

    if not os.path.isdir(cookie_path):
        if os.path.exists(cookie_path):
            os.remove(cookie_path)
        os.mkdir(cookie_path)
        if os.name == 'posix':
            os.chmod(cookie_path, stat.S_IREAD | stat.S_IWRITE | stat.S_IEXEC)

    cookie_path = os.path.join(cookie_path, md5(current_user).hexdigest())
    # Cygwin's Python does not always expanduser() properly...
    if re.match(r'^[A-Za-z]:', cookie_path) is not None and re.match(r'[A-Za-z]:\\', cookie_path) is None:
        cookie_path = re.sub(r'([A-Za-z]):', r'\1:\\', cookie_path)

    try:
        cj = CookieJar(cookie_path)
        if not os.path.exists(cookie_path):
            cj.save()
            if os.name == 'posix':
                os.chmod(cookie_path, stat.S_IREAD | stat.S_IWRITE)
        cj.load(ignore_discard=True, ignore_expires=True)
        return cj
    except IOError:
        ui.warn(_('Cookie file %s exists, but could not be opened.\nContinuing without cookie authentication.\n') % cookie_path)
        return MozillaCookieJar(tempfile.NamedTemporaryFile().name)

def make_cookie(request, name, value):
    domain = request.get_host()
    port = None
    if ':' in domain:
        domain, port = domain.split(':', 1)
    if '.' not in domain:
        domain += ".local"
    return Cookie(version=0,
                  name=name, value=value,
                  port=port, port_specified=False,
                  domain=domain, domain_specified=False, domain_initial_dot=False,
                  path='/', path_specified=False, secure=False,
                  expires=None, discard=False,
                  comment=None, comment_url=None,
                  rest={})

def get_username(url):
    url = re.sub(r'https?://', '', url)
    url = re.sub(r'/.*', '', url)
    if '@' in url:
        # There should be some login info
        # rfind in case it's an email address
        username = url[:url.rfind('@')]
        if ':' in username:
            username = url[:url.find(':')]
        return username
    # Didn't find anything...
    return ''

def get_dest(ui):
    from mercurial.dispatch import _parse
    try:
        cmd_info = _parse(ui, sys.argv[1:])
        cmd = cmd_info[0]
        dest = cmd_info[2]
        if dest:
            dest = dest[0]
        elif cmd in ['outgoing', 'push']:
            dest = 'default-push'
        else:
            dest = 'default'
    except:
        dest = 'default'
    return ui.expandpath(dest)

def reposetup(ui, repo):
    global current_user
    if repo.local():
        try:
            current_user = get_username(get_dest(ui))
        except:
            current_user = ''

def extsetup():
    global current_user
    ui = mercurial.ui.ui()
    current_user = get_username(get_dest(ui))

    def open_wrapper(func):
        def open(*args, **kwargs):
            if isinstance(args[0], Request):
                request = args[0]
                cj = get_cookiejar(ui)
                cj.set_cookie(make_cookie(args[0], 'fSetNewFogBugzAuthCookie', '1'))
                cj.add_cookie_header(request)
                response = func(*args, **kwargs)
                cj.extract_cookies(response, args[0])
                cj.save(ignore_discard=True, ignore_expires=True)
            else:
                response = func(*args, **kwargs)
            return response
        return open

    old_opener = mercurial.url.opener
    def opener(*args, **kwargs):
        urlopener = old_opener(*args, **kwargs)
        urlopener.open = open_wrapper(urlopener.open)
        return urlopener
    mercurial.url.opener = opener

def logout(ui, domain=None):
    """log out of http repositories

    Clears the cookies stored for HTTP repositories. If [domain] is
    specified, only that domain will be logged out. Otherwise,
    all domains will be logged out.
    """

    cj = get_cookiejar(ui)
    try:
        cj.clear(domain=domain)
        cj.save()
    except KeyError:
        ui.write("Not logged in to '%s'\n" % (domain,))

commands.norepo += ' logout'

cmdtable = {
    'logout': (logout, [], '[domain]')
}

