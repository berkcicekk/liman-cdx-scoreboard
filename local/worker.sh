#!/usr/bin/env bash
set -euo pipefail
runtime=/var/lib/lockedshields-probe
script=/liman/server/storage/extensions/lockedshields/scripts/probe_worker.php
pidfile=$runtime/worker.pid
mkdir -p "$runtime"
exec 9>"$runtime/control.lock"
flock -x 9

alive() {
    [[ -r "$pidfile" ]] || return 1
    pid=$(cat "$pidfile")
    [[ "$pid" =~ ^[1-9][0-9]*$ ]] || return 1
    kill -0 "$pid" 2>/dev/null || return 1
    [[ -r /proc/$pid/cmdline ]] || return 1
    tr '\0' '\n' < "/proc/$pid/cmdline" | grep -Fxq "$script"
}
stop() {
    if alive; then
        kill -TERM "$pid"
        for ((i=0; i<100; i++)); do
            if ! alive; then break; fi
            sleep 0.1
        done
        if alive; then echo 'Worker did not stop; refusing duplicate worker.' >&2; exit 1; fi
    fi
    rm -f "$pidfile"
}
case "${1:-start}" in
    stop) stop; exit 0 ;;
    restart) stop ;;
    status) alive && echo "Worker running: PID $pid"; exit $? ;;
    start) ;;
    *) echo 'Usage: worker.sh start|stop|restart|status' >&2; exit 2 ;;
esac
if alive; then echo "Worker already running: PID $pid"; exit 0; fi
chown liman:liman "$runtime"
# Let the shell running as liman write the PID of the actual PHP process.
# Close the control-lock descriptor in the child; the worker has its own flock.
# shellcheck disable=SC2016
# Positional parameters intentionally expand in the child shell.
runuser -u liman -- bash -c '
    nohup php "$1" >> "$2/worker.log" 2>&1 < /dev/null &
    echo $! > "$2/worker.pid"
' bash "$script" "$runtime" 9>&-
sleep 1
if ! alive; then tail -n 30 "$runtime/worker.log" >&2; exit 1; fi
echo "Worker started: PID $pid"
