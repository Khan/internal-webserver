#!/bin/sh

# Gets a list of all the repositories that we have under hg and
# github, and emits urls that can be pasted into a browser to add
# these repositories to reviewboard (must be logged into reviewboard
# as admin).
# TODO(csilvers): use the API to register repositories?
# http://www.reviewboard.org/docs/manual/dev/webapi/2.0/resources/repository-list/

if [ "$1" = -h -o "$1" = "--help" ]; then
  echo "USAGE: $0 [-a]"
  echo "       -a: List all repos, even ones reviewboard already has"
  exit 0
fi

# Figure out which repositories reviewboard alread knows about.
# (With "-a", do them all.)
existing_repos=""
if [ "$1" != "-a" ]; then
  existing_repos=`curl -s -H'Accept: application/xml' http://reviewboard.khanacademy.org/api/repositories/?max-results=100000 | grep '<name>'`
fi

for repo in `curl -s http://github.com/api/v2/xml/repos/show/Khan | grep -o 'https://github.com/Khan/[^<]*' | cut -f5 -d/`; do
   name="$repo"
   echo "$existing_repos" | grep -q ">$name<" && continue
   echo "http://reviewboard.khanacademy.org/admin/db/scmtools/repository/add/?visible=on&hosting_type=github&hosting_owner=Khan&bug_tracker_use_hosting=true&hosting_project_name=$repo&name=$name"
done

for url in `curl -s 'https://khanacademy.kilnhg.com/Auth/LogOn?nr=' | grep -o '/Code/[^/"]*/[^/"]*/[^/"]*'`; do
   project=`echo "$url" | cut -d/ -f3`
   repo_group=`echo "$url" | cut -d/ -f4`
   repo=`echo "$url" | cut -d/ -f5`
   name="$project/$repo_group/$repo"
   echo "$existing_repos" | grep -q ">$name<" && continue
   echo "http://reviewboard.khanacademy.org/admin/db/scmtools/repository/add/?visible=on&hosting_type=kiln&kiln_domain=khanacademy&kiln_project=$project&kiln_repository_group=$repo_group&kiln_repository=$repo&name=$name"
done
