#!/usr/bin/env bash
set -Eeuo pipefail
export LS_CONFIG_PATH=/etc/liman/locked-shields.php
export LS_FIXTURE_JWT="$(openssl rand -hex 24)"
export LS_FIXTURE_ENGINE_KEY="$(openssl rand -hex 24)"
install -m 0755 /app/tests/systemctl-fixture.sh /usr/local/bin/systemctl
install -m 0600 /app/tests/deployment-config.php /tmp/ls-deploy.php
mkdir -p /run/systemd/system
printf %s "$LS_FIXTURE_JWT" >/tmp/ls-api.jwt
printf %s "$LS_FIXTURE_ENGINE_KEY" >/tmp/ls-engine.key
chmod 0644 /tmp/ls-engine.key
php -S 127.0.0.1:18889 /app/tests/deployment-api.php >/tmp/ls-api.log 2>&1 &
api_pid=$!
openssl req -x509 -newkey rsa:2048 -nodes -keyout /tmp/ls-fixture.key -out /tmp/ls-fixture.crt -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost,IP:127.0.0.1 >/dev/null 2>&1
cp /tmp/ls-fixture.crt /usr/local/share/ca-certificates/ls-fixture.crt
update-ca-certificates >/dev/null 2>&1
LS_FIXTURE_STANDARD_PORTS=1 python3 /app/tests/fixtures.py >/tmp/ls-fixtures.log 2>&1 &
fixtures_pid=$!
trap 'systemctl stop lockedshields-probe.service; kill "$api_pid" "$fixtures_pid" 2>/dev/null || true' EXIT
sleep 1
shellcheck /app/deploy.sh /app/scripts/smoke_test.sh
bash /app/deploy.sh /app/dist/LockedShields-1.0.0.zip /tmp/ls-deploy.php
[[ $(readlink /liman/extensions/lockedshields) == /liman/server/storage/extensions/lockedshields ]]
/usr/bin/systemd-analyze verify /etc/systemd/system/lockedshields-probe.service
set +e
runuser -u liman -- php /liman/server/storage/extensions/lockedshields/scripts/probe_worker.php --once >/tmp/ls-flock.log 2>&1
code=$?
set -e
[[ $code == 75 ]]
echo 'PASS flock rejects a concurrent worker'
bash /liman/server/storage/extensions/lockedshields/scripts/smoke_test.sh /tmp/ls-deploy.php >/tmp/ls-smoke.json
php -r '$j=json_decode(file_get_contents("/tmp/ls-smoke.json"),true,512,JSON_THROW_ON_ERROR); if($j["result"]!=="PASS")exit(1); echo json_encode(["result"=>$j["result"],"teams"=>$j["teams"],"matrix"=>$j["matrix"]],JSON_PRETTY_PRINT),"\n";'
# Rerunning the complete deployment and smoke must retain IDs, logs and only two injects.
bash /app/deploy.sh /app/dist/LockedShields-1.0.0.zip /tmp/ls-deploy.php
bash /liman/server/storage/extensions/lockedshields/scripts/smoke_test.sh /tmp/ls-deploy.php >/tmp/ls-smoke-repeat.json
php -r '$j=json_decode(file_get_contents("/tmp/ls-smoke-repeat.json"),true);if($j["result"]!=="PASS"||count($j["getScoreboardData"]["injects"])!==2)exit(1);'
echo 'PASS repeated deployment and smoke preserve data; injects are idempotent'
printf invalid-jwt >/tmp/ls-api.jwt
if bash /liman/server/storage/extensions/lockedshields/scripts/smoke_test.sh /tmp/ls-deploy.php >/tmp/ls-smoke-invalid.json 2>/tmp/ls-smoke-invalid.log; then
    echo 'FAIL invalid JWT was accepted' >&2; exit 1
fi
echo 'PASS invalid API credentials produce nonzero smoke exit'
printf %s "$LS_FIXTURE_JWT" >/tmp/ls-api.jwt
target=/liman/server/storage/extensions/lockedshields
printf before-failed-deploy > "$target/rollback-marker.txt"
touch /tmp/ls-test-break-start
if bash /app/deploy.sh /app/dist/LockedShields-1.0.0.zip /tmp/ls-deploy.php >/tmp/ls-rollback.log 2>&1; then
    echo 'FAIL injected service start failure was ignored' >&2; exit 1
fi
[[ $(cat "$target/rollback-marker.txt") == before-failed-deploy ]]
[[ $(readlink /liman/extensions/lockedshields) == "$target" ]]
systemctl is-active --quiet lockedshields-probe.service
echo 'PASS failed service start restores previous files, symlink and active daemon'
# Stopping fixture targets must fail smoke even when all score calculations are correct.
kill "$fixtures_pid"
wait "$fixtures_pid" || true
if bash "$target/scripts/smoke_test.sh" /tmp/ls-deploy.php >/tmp/ls-smoke-down.json 2>/tmp/ls-smoke-down.log; then
    echo 'FAIL down targets incorrectly passed strict smoke' >&2; exit 1
fi
php -r '$j=json_decode(file_get_contents("/tmp/ls-smoke-down.json"),true);if(($j["result"]??"")!=="FAIL"||$j["all_targets_up"]!==false||!$j["verified"]["total_scores"])exit(1);'
echo 'PASS down targets report valid calculations but nonzero strict smoke exit'
echo 'Deployment integration tests passed (systemd and registry transport fixtures; real PHP daemon/sandbox/PostgreSQL/network probes).'
