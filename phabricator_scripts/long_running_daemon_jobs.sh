#!/bin/sh -e

# Find phd daemon sub-jobs that have been running for more than an
# hour, and alert about them.  This probably indicates they're 'stuck'.

# $1: the pid of a process that has been running for a long time.
warn() {
    cat <<EOF | env PYTHONPATH="$HOME/alertlib_secret" \
                      "$HOME/alertlib/alert.py" \
                      --slack "#infrastructure" \
                      --severity "error" \
                      --summary "A phabricator daemon job is stuck"
The following pids have been running for a long time on the
phabricator ec2 machine, and are probably stuck.
   $1

To diagnose what's going on, log into the phabricator machine
(ask @csilvers or @anthony for help) and look at
    logs/phd_daemons/daemons.log
Hopefully that file will give insight as to what's going wrong.
EOF
}

bad_pids=""

# First, find the pid of the task that spawns the phabricator daemons.
# The '[]' trick avoids having the grep match itself in the ps output.
daemon_runner_pid=`ps -o pid,command x | grep "phd[-]daemon$" | awk '{print $1}'`
[ -n "$daemon_runner_pid" ]

# Now, find its children, which are the actual daemons.
daemon_pids=`ps --no-header --ppid="$daemon_runner_pid" -o pid`
[ -n "$daemon_pids" ]

# Now, find *their* children, which are the tasks the daemons are running.
for daemon_pid in $daemon_pids; do
    # The `| xargs` is a simple way to strip whitespace off the front and back
    task_pids=`ps --no-header --ppid="$daemon_pid" -o pid | xargs`
    # For each child, complain if the elapsed time since process-start is
    # more than 3600 seconds.
    for task_pid in $task_pids; do
        elapsed_time=`ps --no-header --pid="$task_pid" -o etimes | xargs`
        if [ -n "$elapsed_time" ] && [ "$elapsed_time" -gt 3600 ]; then
            bad_pids="$bad_pids $task_pid"
        fi
    done
done

if [ -n "$bad_pids" ]; then
    warn "$bad_pids"
fi
