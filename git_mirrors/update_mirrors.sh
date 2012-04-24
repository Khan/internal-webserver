#!/bin/sh

: ${PYTHON:=python}
: ${LOGFILE:=cron.log}

# $@: command to run
try_several_times() {
  for i in 0 1 2; do          # try up to 3 times
    [ "$i" -gt 0 ] && echo "Retrying (retry #$i)" | tee -a "$LOGFILE"

    output=`"$@" 2>&1`
    [ $? = 0 ] && return 0

    echo "FAILED:" | tee -a "$LOGFILE"
    echo "$output" | tee -a "$LOGFILE"
  done
  return 1   # we never succeeded in running the command
}


# Create any new repositories that are needed, and update the ones
# that aren't new.
echo >>"$LOGFILE"
date >>"$LOGFILE"

for repo in `timeout 1m $PYTHON -c 'import json, urllib; print "\n".join(x["url"] for x in json.loads(urllib.urlopen("https://github.com/api/v2/json/repos/show/Khan").read())["repositories"])'`; do
   dirname=`basename $repo`.git    # the rest is 'https://github.com/Khan'
   if [ -d "$dirname" ]; then
     echo "Running git fetch -q in $dirname" >>"$LOGFILE"
     ( cd $dirname;
       try_several_times timeout 1m git fetch -q >>"$LOGFILE" \
         || echo "Failed to run git fetch -q in $dirname" | tee -a "$LOGFILE"
     )
   else
     echo "Running git clone --mirror $repo" >>"$LOGFILE"
     try_several_times timeout 5m git clone --mirror "$repo" >>"$LOGFILE" \
       || echo "Failed to run git clone --mirror $repo" | tee -a "$LOGFILE"
     touch $dirname/git-daemon-export-ok
   fi
done

echo "DONE: "`date` >>"$LOGFILE"
