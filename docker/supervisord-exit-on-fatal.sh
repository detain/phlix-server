#!/bin/sh
#
# S163 review F6 — make a FATAL program KILL THE CONTAINER.
#
# Without this, supervisord happily stays alive as PID 1 after the application
# has given up: `docker ps` shows `Up`, the restart policy never fires, and any
# orchestrator (compose `restart: unless-stopped`, a k8s liveness probe, an
# operator's dashboard) is told everything is fine. That is precisely the shape
# of the S163 outage — `Up 22 minutes` while every request 502'd — so leaving
# supervisord to soldier on past FATAL would preserve the original defect in a
# new place. Nothing in the image consumes the `unhealthy` state either.
#
# This is a supervisor eventlistener. It subscribes to PROCESS_STATE_FATAL ONLY
# (see `[eventlistener:exit-on-fatal]` in supervisord.conf), so the first header
# line it ever reads IS a fatal event — there is no other event to demultiplex
# and no payload to drain before acting. It signals PID 1 (supervisord), which
# shuts down and takes the container with it, surfacing the failure to whatever
# is supervising the container.
#
# Protocol: write "READY\n", then read one header line from stdin.
# http://supervisord.org/events.html#event-listeners
#
# NB invoked as `sh <path>`, not executed directly, so the file's exec bit is
# irrelevant to whether the container boots.
#
# ⚠ THE MARKER FILE IS LOAD-BEARING. (S163 review round 2, finding 3.)
# `kill -TERM 1` on its own is NOT enough: supervisord treats SIGTERM as a
# clean shutdown and exits 0, so `docker inspect` showed
# `"Status":"exited","ExitCode":0` and the failure was indistinguishable from a
# `docker stop` — `restart: on-failure`, k8s `OnFailure`, `docker wait` and
# every exit-code alert read it as SUCCESS. There is no way to make supervisord
# itself exit non-zero (it handles TERM/INT/QUIT identically, and PID 1 ignores
# any signal it has no handler for), so `docker/docker-entrypoint.sh` keeps PID
# 1 for itself, runs supervisord as a child, and exits 70 when this marker
# exists. Write it BEFORE signalling.

FATAL_MARKER="${PHLIX_SUPERVISOR_FATAL_MARKER:-/var/run/phlix-supervisor-fatal}"

printf 'READY\n'

while read -r header; do
    case "$header" in
        *eventname:PROCESS_STATE_FATAL*)
            echo "==========================================================" >&2
            echo "PHLIX-SUPERVISOR-FATAL: a supervised program entered FATAL." >&2
            echo "  ${header}" >&2
            echo "The application is not running and supervisord has stopped" >&2
            echo "retrying it. Stopping the container so the failure is not" >&2
            echo "reported as a healthy 'Up' — check 'docker logs' and" >&2
            echo "/var/phlix/logs/phlix-error.log for the cause." >&2
            echo "==========================================================" >&2
            # Order matters: the marker must exist before PID 1 wakes up.
            printf '%s\n' "$header" > "$FATAL_MARKER" 2>/dev/null \
                || echo "PHLIX-SUPERVISOR-FATAL: could not write ${FATAL_MARKER}; the container will exit 0 and look like a clean stop." >&2
            kill -TERM 1
            exit 0
            ;;
    esac
    # Not reachable while `events=PROCESS_STATE_FATAL`, but keeps the listener
    # protocol-correct if the subscription is ever widened.
    printf 'RESULT 2\nOK'
    printf 'READY\n'
done
