#!/bin/bash

# Updates the internal-webserver repository, which has the phabricator
# source as 'fake sub-repos', and then pushes it to the phabricator
# machine (toby), where it safely restarts the webserver there.

# Die if something goes wrong.
set -e

# Make git 1.8 revert to git 1.7 behavior, and not prompt for a merge
# message when doing a 'git pull' from upstream.
export GIT_MERGE_AUTOEDIT=no

# We always run from the same directory as where this script lives.
# This is the only bash-ism in the script.
cd "$(dirname "${BASH_SOURCE[0]}")"

# We need the internal-webserver repository to be set up properly.
if [ ! -d "phabricator/.git" ]; then
  echo "You need to set up the phabricator subdirectories as 'fake' sub-repos:"
  echo "https://sites.google.com/a/khanacademy.org/forge/for-developers/code-review-policy/using-phabricator/for-phabricator-admins"
  exit 1
fi

# We'll need the right permissions file to push to production.
# c.f. https://sites.google.com/a/khanacademy.org/forge/for-khan-employees/accessing-amazon-ec2-instances#TOC-Accessing-EC2-Instances
if [ ! -s "$HOME/.ssh/internal_webserver.pem" ]; then
  echo "You need to install internal_webserver.pem to push to production."
  echo "At https://www.dropbox.com/home/Khan%20Academy%20All%20Staff/Secrets"
  echo "download internal_webserver.pem and save it in your ~/.ssh directory"
  exit 1
fi

git checkout master
trap 'git checkout -' 0  # reset to old branch when the script exits

# $1: the directory to cd to (root of some git tree).
push_upstream() {
(  cd "$@"
   git checkout master
   git pull --no-rebase
   git pull --no-rebase upstream master
   git push
   git checkout -
)
}

push_upstream phabricator
push_upstream libphutil
push_upstream arcanist
#push_upstream python-phabricator

git add .
git status

echo -n "Does everything look ok? (y/N) "
read prompt
if [ "$prompt" != "y" -a "$prompt" != "Y" -a "$prompt" != "yes" ]; then
   echo "Aborting; user said no"
   echo "[Note the fake-subrepos (e.g. phabricator/) have already been pushed]"
   exit 1
fi

# Turn off linting (it's all third-party code).
env FORCE_COMMIT=1 git commit -am "merge from upstream phabricator" && git push

# Now push to production
ssh ubuntu@phabricator.khanacademy.org -i "$HOME/.ssh/internal_webserver.pem" \
   "cd internal-webserver; \
    git checkout master; \
    git pull; \
    PHABRICATOR_ENV=khan phabricator/bin/phd stop; \
    sudo /etc/init.d/lighttpd stop; \
    PHABRICATOR_ENV=khan phabricator/bin/storage upgrade --force; \
    sudo /etc/init.d/lighttpd start; \
    PHABRICATOR_ENV=khan phabricator/bin/phd start; \
   "

echo "DONE!"
