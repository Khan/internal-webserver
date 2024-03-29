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
    deploy/firstinqueue-priming \
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
    if [ -z "$git_revision" ]; then
        continue
    fi
    mkdir -p "$symlink_farm"

    # Find all jobs with the same GIT_REVISION.
    grep -l "$git_revision_text" *.data | while read f; do
        ln -snf "../$f" "$symlink_farm/$f"
        # Make the symlink have the same timestamp as the pointed-to file.
        touch -h -r "$f" "$symlink_farm/$f"
    done
done

# I'm a bit worried about using up too much disk, so I do a hacky
# thing to save some space: we can, conceptually, delete any .data
# file that's not in one of the symlink-farm dirs from above; those
# are builds that are not associated with a deploy.  But we can't
# actually delete them, or they'll just be re-downloaded the next run
# of this script.  Instead we just replace their content with
# something small.
# To make sure we don't zero out files just because the corresponding
# deploy-webapp job hasn't started yet, we wait 4 hours before clearing.
find . -maxdepth 1 -name '*.data' -a -mmin +240 -a -size +1k | while read f; do
    if [ -z "$(find . -type l -name "$(basename "$f")")" ]; then
        echo "Zeroing out $f: not a build used in a deploy"
        echo "Not a deploy build" > "$f"
    fi
done

# Get rid of files that are old, to bound the disk space we use.
find . -type f -a -mtime +"$KEEP_DAYS" -print0 | xargs -r -0 rm || true
# Get rid of any dangling symlinks that resulted from the above.
find . -xtype l -print0 | xargs -r -0 rm || true
# Now get rid of an empty symlink-farm dirs.
find . -depth -type d -print0 | xargs -r -0 rmdir 2>/dev/null || true

# Now create the html visualization files for each deploy.
mkdir -p html

# We only build html files if the latest file in a deploy-dir is
# at least 4 hours old.  That avoids us building "partial" html files
# for deploys that are still going on.
for deploy_dir in 20*/; do   # this will last us for another 80 years :-)
    if [ -n "$(find "$deploy_dir" -type l -mmin -240)" ]; then
        continue
    fi
    outfile="html/$(basename "$deploy_dir").html"
    # Let's figure out a good title for this graph.
    # The REVISION_DESCRIPTION is good but let's get rid of
    # the suffixes some jobs add:
    #   . "and the prior deploy"
    #   . "(currently deploying)
    #   . "(now live)"
    title=$(grep -ho '"REVISION_DESCRIPTION": "[^"]*"' "$deploy_dir"/*.data \
            | head -n1 \
            | cut -d'"' -f4 \
            | sed 's/and the prior deploy\|(currently deploying)\|(now live)//')
    if [ ! -s "$outfile" ]; then
        echo "Creating an html file for $deploy_dir"
        # We compress the html to take less space on gcs.
        # (We'll set the right header so gcs knows it's compressed.)
        ../jenkins-perf-visualizer/visualize_jenkins_perf_data.py \
                --config=../internal-webserver/jenkins-perf-config.json \
                --title="$title" \
                -o /dev/stdout \
                "$deploy_dir"/*.data \
            | gzip -9 \
            > "$outfile" \
            || rm "$outfile"
    fi
done

# Let's just always update the index file.  We sort so the newest
# profiles are at the top.
for html in html/20*.html; do
    base=$(basename "$html")
    title=$(zgrep '^ *"title": ' "$html" | tail -n1 | cut -f4 -d'"')
    date=$(zgrep '^ *"subtitle": ' "$html" | tail -n1 | cut -f4 -d'"')
    echo "<a href='https://storage.cloud.google.com/ka-jenkins-perf/$base'>$date</a>: $title<br>"
done | sort -t'>' -k2r | gzip -9 > html/index.html

echo "Uploading html to gcs"
# We let gzip know that all the files we're uploading are compressed.
gsutil -m -h Content-encoding:gzip rsync -j html html/ gs://ka-jenkins-perf/

echo "View perf data at https://storage.googleapis.com/ka-jenkins-perf/index.html"
