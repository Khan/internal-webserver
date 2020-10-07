#!/bin/sh

# Download the latest performance data from Jenkins of all our deploys.
# This also deletes old perf data files, so we don't use up all the disk.

# Delete all deploy-files that are over this many days old.
KEEP_DAYS=30

../jenkins-perf-visualizer/download_jenkins_perf_data.py \
    --config="$HOME/internal-webserver/jenkins-perf-config.json" \
    deploy/merge-branches \
    deploy/2ndsmoketest-priming \
    deploy/build-webapp \
    deploy/deploy-webapp \
    deploy/webapp-test \
    deploy/e2e-test

# Get rid of files that are old, to bound the disk space we use.
find ../jenkins-perf-data -type f -a -mtime +"$KEEP_DAYS" -print0 | xargs -0 rm
# Now get rid of an empty symlink-farm dirs.
find ../jenkins-perf-data -type d -print0 | xargs -0 rmdir 2>/dev/null
