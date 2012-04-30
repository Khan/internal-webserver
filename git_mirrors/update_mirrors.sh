#!/bin/sh
#
# Normal output goes to stdout, indications of error go to both stdout
# and stderr.  The intent is that a cronjob can redirect stdout to a
# logfile, and leave stderr to prompt mail when something goes wrong.

: ${PYTHON:=python}

# $@: command to run
try_several_times() {
  for i in 0 1 2; do          # try up to 3 times
    [ "$i" -gt 0 ] && echo "Retrying (retry #$i)" | tee /dev/stderr

    output=`"$@" 2>&1`
    [ $? = 0 ] && return 0

    echo "FAILED: $@:" | tee /dev/stderr
    echo "$output" | tee /dev/stderr
  done
  return 1   # we never succeeded in running the command
}


# Create any new repositories that are needed, and update the ones
# that aren't new.
echo
date

for repo in `timeout 1m $PYTHON -c 'import json, urllib; print "\n".join(x["url"] for x in json.loads(urllib.urlopen("https://github.com/api/v2/json/repos/show/Khan").read())["repositories"])'`; do
   dirname=`basename $repo`.git    # the rest is 'https://github.com/Khan'
   if [ -d "$dirname" ]; then
     echo "Running git fetch -q in $dirname"
     ( cd $dirname;
       try_several_times timeout 1m git fetch -q \
         || echo "Failed to run git fetch -q in $dirname" | tee /dev/stderr
     )
   else
     echo "Running git clone --mirror $repo"
     try_several_times timeout 5m git clone --mirror "$repo" \
       || echo "Failed to run git clone --mirror $repo" | tee /dev/stderr
     touch $dirname/git-daemon-export-ok
   fi
done

echo "DONE: "`date`
