#!/bin/sh
#
# Normal output goes to stdout, indications of error go to both stdout
# and stderr.  The intent is that a cronjob can redirect stdout to a
# logfile, and leave stderr to prompt mail when something goes wrong.

: ${PYTHON:=python}

# $@: command to run
try_several_times() {
  for i in 0 1 2; do          # try up to 3 times
    [ "$i" -gt 0 ] && echo "Retrying (retry #$i)"

    output=`"$@" 2>&1`
    [ $? = 0 ] && return 0

    echo "FAILED: $@:"
    echo "$output"
  done
  echo "FAILED running $@ in `pwd`:" 1>&2  # let stderr know of the failure
  echo "$output" 1>&2
  return 1   # we never succeeded in running the command
}


# Create any new repositories that are needed, and update the ones
# that aren't new.
echo
date

github_repos=`timeout 1m $PYTHON -c 'import json, urllib; print "\n".join(x["git_url"] for x in json.loads(urllib.urlopen("https://api.github.com/orgs/Khan/repos").read()))'`

# This requires you to have your .ssh key registered with kiln.
# TODO(csilvers): get the list of all repos via the kiln API.
kiln_repos='ssh://khanacademy@khanacademy.kilnhg.com/Website/Group/webapp'

for repo in $github_repos $kiln_repos; do
   dirname=`basename $repo`    # the rest is 'https://github.com/Khan'
   dirname=`echo "$dirname" | sed 's/\.git$//'`.git     # force a .git ending
   if [ -d "$dirname" ]; then
     echo "Running git fetch -q in $dirname"
     ( cd $dirname;
       try_several_times timeout 1m git fetch -q
       # We'll gc too, to keep performance up over time
       timeout 10m git gc --auto --prune --aggressive --quiet >/dev/null
     )
   else
     echo "Running git clone --mirror $repo"
     try_several_times timeout 5m git clone --mirror "$repo"
     touch $dirname/git-daemon-export-ok
   fi
done

echo "DONE: "`date`
