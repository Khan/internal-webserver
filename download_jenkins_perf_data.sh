#!/bin/sh

# Download the latest performance data from Jenkins of all our deploys.
# This also deletes old perf data files, so we don't use up all the disk.
#
# This should be run from ubuntu@toby:$HOME.

# Delete all deploy-files that are over this many days old.
KEEP_DAYS=30

jenkins-perf-visualizer/download_jenkins_perf_data.py \
    --config=internal-webserver/jenkins-perf-config.json \
    deploy/merge-branches \
    deploy/2ndsmoketest-priming \
    deploy/build-webapp \
    deploy/deploy-webapp \
    deploy/webapp-test \
    deploy/e2e-test

cd jenkins-perf-data

# Now we want to collect build jobs that are part of the same deploy.
# We join these builds based on GIT_REVISION; each deploy deploys a
# unique git revision.  We only want to analyze "finished" deploys
# so we start with the deploy-webapp job, and then find all associated
# jobs.  We put them the related jobs into a s single symlink-farm
# directory.
#
# TODO(csilvers): also include merge-branches jobs.  We'd need to fetch
# the console logs for the merge-branches job, and look for the line:
#    Resolved .* --> <GIT_REVISION>
ls deploy--deploy-webapp*.data | while read dw; do
    git_revision_text=$(grep -o '"GIT_REVISION": "[^"]*"' "$dw")
    git_revision=$(echo "$git_revision_text" | cut -d'"' -f4)
    symlink_farm=$(date -r "$dw" +"%Y-%m-%d.$git_revision")
    if [ -z "$git_revision" -o -d "$symlink_farm" ]; then
        continue   # already processed this deploy-webapp file.
    fi
    mkdir "$symlink_farm"

    # Find all jobs with the same GIT_REVISION.
    grep -l "$git_revision_text" *.data | while read f; do
        ln -s "../$f" "$symlink_farm/$f"
    done
done

# I'm a bit worried about using up too much disk, so I do a hacky
# thing to save some space: we can, conceptually, delete any .data
# file that's not in one of the symlink-farm dirs from above; those
# are builds that are not associated with a deploy.  But we can't
# actually delete them, or they'll just be re-downloaded the next run
# of this script.  Instead we just replace their content with
# something small.
find . -maxdepth 1 -name '*.data' -a -size +1k | while read f; do
    if [ -z "$(find . -type l -name "$(basename "$f")")" ]; then
        echo "Zeroing out $f: not a build used in a deploy"
        echo "Not a deploy build" > "$f"
    fi
done

# Get rid of files that are old, to bound the disk space we use.
find . -type f -a -mtime +"$KEEP_DAYS" -print0 | xargs -r -0 rm || true
# Now get rid of an empty symlink-farm dirs.
find . -depth -type d -print0 | xargs -r -0 rmdir 2>/dev/null || true

# Now create the html visualization files for each deploy.
mkdir -p html

to_upload=
for deploy_dir in 20*/; do   # this will last us for another 80 years :-)
    outfile="html/$(basename "$deploy_dir").html"
    if ! [ -s "$outfile" ]; then
        echo "Creating an html file for $deploy_dir"
        ../jenkins-perf-visualizer/visualize_jenkins_perf_data.py \
            --config=../internal-webserver/jenkins-perf-config.json \
            -o "$outfile" \
            "$deploy_dir"/*.data \
        && to_upload="$to_upload $outfile"
    fi
done

if [ -n "$to_upload" ]; then
    echo "Uploading $to_upload to gcs"
    gsutil cp -z html $to_upload gs://ka-jenkins-perf/
fi

