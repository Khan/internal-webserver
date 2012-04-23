#!/bin/sh

: ${PYTHON:=python}
: ${LOGFILE:=cron.log}

# Create any new repositories that are needed, and update the ones
# that aren't new.
echo >>"$LOGFILE"
date >>"$LOGFILE"

for repo in `timeout 1m $PYTHON -c 'import json, urllib; print "\n".join(x["url"] for x in json.loads(urllib.urlopen("https://github.com/api/v2/json/repos/show/Khan").read())["repositories"])'`; do
   dirname=`basename $repo`.git    # the rest is 'https://github.com/Khan'
   if [ -d "$dirname" ]; then
     echo "Running git fetch -q in $dirname" >>"$LOGFILE"
     ( cd $dirname && timeout 1m git fetch -q )
   else
     echo "Running git clone --mirror $repo" >>"$LOGFILE"
     timeout 5m git clone --mirror "$repo"
     touch $dirname/git-daemon-export-ok
   fi
done

echo "DONE: "`date` >>"$LOGFILE"
