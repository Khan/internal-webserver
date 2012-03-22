#!/bin/sh

# Gets a list of all the repositories that we have under hg and
# github, and emits urls that can be pasted into a browser to add
# these repositories to reviewboard (must be logged into reviewboard
# as admin).
# TODO(csilvers): figure out what reviewboard already has, and filter them out.

for repo in `curl -s http://github.com/api/v2/xml/repos/show/Khan | grep -o 'https://github.com/Khan/[^<]*' | cut -f5 -d/`; do
   echo "http://reviewboard.khanacademy.org/admin/db/scmtools/repository/add/?visible=on&hosting_type=github&hosting_owner=Khan&bug_tracker_use_hosting=true&name=$repo&hosting_project_name=$repo"
done

# TODO(csilvers): get repositories from kilnhg
