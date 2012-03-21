#!/usr/bin/env python

import os
import pkg_resources
import platform
import sys
from optparse import OptionParser
from random import choice


options = None


class SiteOptions(object):
    copy_media = platform.system() == "Windows"


def create_settings():
    if not os.path.exists("settings_local.py"):
        print "Creating a settings_local.py in the current directory."
        print "This can be modified with custom settings."

        src_path = os.path.join("contrib", "conf", "settings_local.py.tmpl")
        in_fp = open(src_path, "r")
        out_fp = open("settings_local.py", "w")

        for line in in_fp.xreadlines():
            if line.startswith("SECRET_KEY = "):
                secret_key = ''.join([
                    choice('abcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*(-_=+)')
                    for i in range(50)
                ])

                out_fp.write('SECRET_KEY = "%s"\n' % secret_key)
            elif line.strip().startswith("'ENGINE': "):
                out_fp.write("        'ENGINE': 'django.db.backends.%s',\n" %
                             options.db_type)
            elif line.strip().startswith("'NAME': "):
                if options.db_type == 'sqlite':
                    name = os.path.abspath(options.db_name)
                else:
                    name = options.db_name

                out_fp.write("        'NAME': '%s',\n" % name)
            elif line.strip().startswith("'USER': "):
                out_fp.write("        'USER': '%s',\n" % options.db_user)
            elif line.strip().startswith("'PASSWORD': "):
                out_fp.write("        'PASSWORD': '%s',\n" % options.db_password)
            else:
                out_fp.write(line)

        in_fp.close()
        out_fp.close()


def install_media(site):
    print "Rebuilding media paths..."

    media_path = os.path.join("htdocs", "media")
    uploaded_path = os.path.join(site.install_dir, media_path, "uploaded")
    ext_media_path = os.path.join(site.install_dir, media_path, 'ext')

    site.mkdir(uploaded_path)
    site.mkdir(os.path.join(uploaded_path, "images"))
    site.mkdir(ext_media_path)

    if not pkg_resources.resource_exists("djblets", "media"):
        sys.stderr.write("Unable to find a valid Djblets installation.\n")
        sys.stderr.write("Make sure you've ran `python setup.py develop` "
                         "in the Djblets source tree.\n")
        sys.exit(1)

    print "Using Djblets media from %s" % \
        pkg_resources.resource_filename("djblets", "media")

    site.link_pkg_dir("djblets", "media",
                      os.path.join(site.install_dir, media_path, "djblets"))


def build_egg_info():
    os.system("%s setup.py egg_info" % sys.executable)


def parse_options(args):
    global options

    parser = OptionParser(usage='%prog [options]')
    parser.add_option('--no-media', action='store_false', dest='install_media',
                      default=True,
                      help="Don't install media files")
    parser.add_option('--no-db', action='store_false', dest='sync_db',
                      default=True,
                      help="Don't synchronize the database")
    parser.add_option('--database-type', dest='db_type',
                      default='sqlite3',
                      help="Database type (postgresql, mysql, sqlite3)")
    parser.add_option('--database-name', dest='db_name',
                      default='reviewboard.db',
                      help="Database name (or path, for sqlite3)")
    parser.add_option('--database-user', dest='db_user',
                      default='',
                      help="Database user")
    parser.add_option('--database-password', dest='db_password',
                      default='',
                      help="Database password")

    options, args = parser.parse_args(args)

    return args


def main():
    if not os.path.exists(os.path.join("reviewboard", "manage.py")):
        sys.stderr.write("This must be run from the top-level Review Board "
                         "directory\n")
        sys.exit(1)


    # Insert the current directory first in the module path so we find the
    # correct reviewboard package.
    sys.path.insert(0, os.getcwd())
    from reviewboard.cmdline.rbsite import Site

    parse_options(sys.argv[1:])

    # Re-use the Site class, since it has some useful functions.
    site = Site("reviewboard", SiteOptions)

    create_settings()
    build_egg_info()

    if options.install_media:
        install_media(site)

    if options.sync_db:
        print "Synchronizing database..."
        site.abs_install_dir = os.getcwd()
        site.sync_database(allow_input=True)

    print
    print "Your Review Board tree is ready for development."
    print


if __name__ == "__main__":
    main()
