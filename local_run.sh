#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
# Git Bash otherwise rewrites Linux paths passed to docker.exe.
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
secrets_file=.env.local
if [[ ! -f "$secrets_file" ]]; then
    umask 077
    random_secret() { openssl rand -hex 24; }
    {
        printf 'LIMAN_DB_PASSWORD=%s\n' "$(random_secret)"
        printf 'LIMAN_REDIS_PASSWORD=%s\n' "$(random_secret)"
        printf 'LS_SCOREBOARD_DB_PASSWORD=%s\n' "$(random_secret)"
    } > "$secrets_file"
    echo "Created $secrets_file with local-only random credentials."
fi
set -a
# shellcheck disable=SC1091
source "$secrets_file"
set +a
for value in "$LIMAN_DB_PASSWORD" "$LIMAN_REDIS_PASSWORD" "$LS_SCOREBOARD_DB_PASSWORD"; do
    [[ $value =~ ^[a-f0-9]{48}$ ]] || { echo "Invalid local secret format in $secrets_file." >&2; exit 1; }
done
if command -v docker-compose >/dev/null 2>&1; then
    compose=(docker-compose -f docker-compose.local.yml)
elif docker compose version >/dev/null 2>&1; then
    compose=(docker compose -f docker-compose.local.yml)
else
    echo 'Docker Compose is required (Docker Desktop / Compose v2).' >&2
    exit 1
fi
docker info >/dev/null
trap 'echo "Local setup failed. Inspect: docker compose -f docker-compose.local.yml logs --tail=100 liman" >&2' ERR
"${compose[@]}" up -d
# PostgreSQL accepts local socket connections from its container user. Refresh the
# Liman role after each run so an existing named volume also adopts new local secrets.
"${compose[@]}" exec -T postgres psql -U liman -d liman -c "ALTER ROLE liman PASSWORD '$LIMAN_DB_PASSWORD';" >/dev/null
# Compose bind mounts do not trigger a container recreation. Restart so edits to
# local harness scripts are applied while keeping the named Liman/Postgres data.
"${compose[@]}" restart liman
echo 'Waiting for Liman (up to 5 minutes)...'
ready=0
for ((attempt=0; attempt<150; attempt++)); do
    if "${compose[@]}" exec -T liman curl -fsS --max-time 3 http://127.0.0.1/auth/login >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 2
done
if [[ $ready != 1 ]]; then
    "${compose[@]}" logs --tail=80 liman
    exit 1
fi
# Stop before refreshing the target IP/config. Seed operations preserve score history.
"${compose[@]}" exec -T liman bash /opt/lockedshields-local/worker.sh stop
"${compose[@]}" exec -T liman php /opt/lockedshields-local/bootstrap.php
"${compose[@]}" exec -T liman bash /opt/lockedshields-local/worker.sh start
"${compose[@]}" exec -T liman php /opt/lockedshields-local/check.php
"${compose[@]}" exec -T liman cat /liman/.lockedshields-local-access.txt
