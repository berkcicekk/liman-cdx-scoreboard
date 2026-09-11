#!/usr/bin/env bash
# Container-only service-manager double. It starts the real PHP daemon as liman.
set -eu
command=${1:-}; shift || true
unit=${*: -1}
name=${unit%.service}
pidfile=/tmp/systemctl-${name}.pid
enabled=/tmp/systemctl-${name}.enabled
active() { [[ -f $pidfile ]] && kill -0 "$(cat "$pidfile")" 2>/dev/null && [[ $(ps -o stat= -p "$(cat "$pidfile")") != Z* ]]; }
start() {
    [[ $name == lockedshields-probe ]] || return 0
    if active; then return 0; fi
    if [[ -f /tmp/ls-test-break-start ]]; then rm -f /tmp/ls-test-break-start; return 1; fi
    /usr/sbin/runuser -u liman -- env LS_CONFIG_PATH=/etc/liman/locked-shields.php LS_WORKER_LOCK=/var/lib/lockedshields-probe/worker.lock \
        /usr/bin/php /liman/server/storage/extensions/lockedshields/scripts/probe_worker.php >/tmp/ls-daemon.log 2>&1 </dev/null 9>&- &
    echo $! > "$pidfile"
}
stop() { if active; then kill "$(cat "$pidfile")"; sleep 1; fi; rm -f "$pidfile"; }
case "$command" in
    is-active) active ;;
    is-enabled) [[ -f $enabled ]] ;;
    enable) touch "$enabled"; [[ " $* " != *' --now '* ]] || start ;;
    disable) rm -f "$enabled"; [[ " $* " != *' --now '* ]] || stop ;;
    start) start ;;
    stop) stop ;;
    show) cat "$pidfile" ;;
    daemon-reload) : ;;
    *) echo "Unsupported fixture systemctl command: $command" >&2; exit 1 ;;
esac
