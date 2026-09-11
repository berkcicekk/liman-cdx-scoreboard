#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

# Usage: sudo bash deploy.sh ./Cenk-1.0.0.zip /etc/liman/lockedshields-deploy.php
base=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
zip=$(realpath -- "${1:-$base/dist/Cenk-1.0.0.zip}")
config=$(realpath -- "${2:-/etc/liman/lockedshields-deploy.php}")
target=/liman/server/storage/extensions/cenk
canonical=/liman/extensions/cenk
runtime=/etc/liman/locked-shields.php
unit=/etc/systemd/system/lockedshields-probe.service
helper=$base/scripts/deploy_support.php
stage='' backup='' activated=0 canonical_changed=0 unit_changed=0 config_changed=0
had_target=0 had_canonical=0 had_unit=0 had_runtime=0 old_active=0 old_enabled=0 legacy_active=0 legacy_enabled=0
[[ $EUID -eq 0 ]] || { echo 'Run deploy.sh as root.' >&2; exit 1; }
for cmd in php systemctl flock runuser realpath install find getfacl setfacl; do
    command -v "$cmd" >/dev/null || { echo "Missing command: $cmd" >&2; exit 1; }
done
id liman >/dev/null
[[ -f $zip && -f $helper && -f $config ]] || { echo 'Package, deployment helper or config is missing.' >&2; exit 1; }
[[ $(stat -c %u "$config") == 0 ]] || { echo 'Deployment config must be owned by root.' >&2; exit 1; }
[[ -d /run/systemd/system ]] || { echo 'This deploy script requires a host running systemd.' >&2; exit 1; }
exec 9>/run/lock/lockedshields-deploy.lock
flock -n 9 || { echo 'Another deployment is in progress.' >&2; exit 75; }
php "$helper" preflight "$config"

install -d -m 0755 /liman/server/storage/extensions /liman/extensions
[[ ! -L $target ]] || { echo 'Deployment target must be a directory, not a symlink.' >&2; exit 1; }
for existing in "$target" "$canonical"; do
    if [[ -e $existing || -L $existing ]]; then
        # PHP variables must not be expanded by Bash.
        # shellcheck disable=SC2016
        php -r '$m=json_decode(file_get_contents($argv[1]."/db.json"),true);exit(($m["name"]??"")==="Cenk"?0:1);' "$existing" || {
            echo "Refusing to replace unrecognized directory: $existing" >&2; exit 1;
        }
    fi
done
stage=$(mktemp -d /liman/server/storage/extensions/.lockedshields-stage.XXXXXXXX)
cleanup_stage() {
    case "$stage" in /liman/server/storage/extensions/.lockedshields-stage.*) [[ ! -d $stage ]] || rm -rf -- "$stage" ;; esac
}
trap cleanup_stage EXIT
php "$helper" extract "$zip" "$stage"
while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find "$stage" -type f -name '*.php' -print0)
find "$stage" -type d -exec chmod 0755 {} +
find "$stage" -type f -exec chmod 0644 {} +
chmod 0755 "$stage/deploy.sh" "$stage/scripts/probe_worker.php" "$stage/scripts/smoke_test.sh"

backup=$(mktemp -d /liman/server/storage/extensions/.lockedshields-backup.XXXXXXXX)
chmod 0700 "$backup"
[[ ! -e $target ]] || { cp -a -- "$target" "$backup/target"; had_target=1; }
[[ ! -e $canonical && ! -L $canonical ]] || { cp -a -- "$canonical" "$backup/canonical"; had_canonical=1; }
[[ ! -e $unit ]] || { cp -a -- "$unit" "$backup/unit"; had_unit=1; }
[[ ! -e $runtime ]] || { cp -a -- "$runtime" "$backup/runtime"; had_runtime=1; }
if systemctl is-active --quiet lockedshields-probe.service; then old_active=1; fi
if systemctl is-enabled --quiet lockedshields-probe.service; then old_enabled=1; fi
if systemctl is-active --quiet locked-shields-worker.service; then legacy_active=1; fi
if systemctl is-enabled --quiet locked-shields-worker.service; then legacy_enabled=1; fi

rollback() {
    status=$?
    trap - ERR INT TERM
    set +e
    systemctl stop lockedshields-probe.service
    if (( activated )); then
        [[ ! -e $target ]] || mv -- "$target" "$backup/failed-target"
        (( ! had_target )) || cp -a -- "$backup/target" "$target"
    fi
    if (( canonical_changed )); then
        [[ ! -e $canonical && ! -L $canonical ]] || mv -- "$canonical" "$backup/failed-canonical"
        (( ! had_canonical )) || cp -a -- "$backup/canonical" "$canonical"
    fi
    if (( unit_changed )); then
        if (( had_unit )); then cp -a -- "$backup/unit" "$unit"; else rm -f -- "$unit"; fi
    fi
    if (( config_changed )); then
        if (( had_runtime )); then cp -a -- "$backup/runtime" "$runtime"; else rm -f -- "$runtime"; fi
    fi
    systemctl daemon-reload
    if (( old_enabled )); then systemctl enable lockedshields-probe.service; else systemctl disable lockedshields-probe.service; fi
    (( ! old_active )) || systemctl start lockedshields-probe.service
    (( ! legacy_enabled )) || systemctl enable locked-shields-worker.service
    (( ! legacy_active )) || systemctl start locked-shields-worker.service
    echo "Deployment failed. Previous files/service restored where present. Backup: $backup" >&2
    echo 'Additive database migration/grants and native API registration are retained for a safe retry.' >&2
    exit "${status:-1}"
}
trap rollback ERR
trap 'false' INT TERM
(( ! old_active )) || systemctl stop lockedshields-probe.service
if (( legacy_active || legacy_enabled )); then systemctl disable --now locked-shields-worker.service; fi

# Register through Liman's own API so it creates its UUID, keys and sandbox account.
# Existing same-version registrations are reused; assignment is checked before inserting.
canonical_changed=1
if ! extension_id=$(php "$helper" register "$config" "$zip"); then false; fi
[[ $extension_id =~ ^[0-9a-f-]{36}$ ]]
php "$helper" migrate "$config" "$stage"

activated=1
[[ ! -e $target ]] || mv -- "$target" "$backup/replaced-target"
mv -- "$stage" "$target"
stage=
[[ ! -e $canonical && ! -L $canonical ]] || mv -- "$canonical" "$backup/registered-canonical"
canonical_changed=1
ln -s -- "$target" "$canonical"

# Worker uses liman; Liman sandbox may execute as the extension UUID account.
install -d -m 0750 -o liman -g liman /var/lib/lockedshields-probe
[[ -d /etc/liman ]] || install -d -m 0750 -o root -g liman /etc/liman
if id "$extension_id" >/dev/null 2>&1; then
    for directory in /liman /liman/server /liman/server/storage /liman/server/storage/extensions /etc/liman; do
        setfacl -m "u:$extension_id:--x" "$directory"
    done
fi
for directory in /liman /liman/server /liman/server/storage /liman/server/storage/extensions /etc/liman; do
    setfacl -m u:liman:--x "$directory"
done
config_changed=1
php "$helper" runtime "$config" "$runtime.new"
chown root:liman "$runtime.new"
if id "$extension_id" >/dev/null 2>&1; then setfacl -m "u:$extension_id:r--" "$runtime.new"; fi
mv -f -- "$runtime.new" "$runtime"
# This is literal PHP code, not shell interpolation.
# shellcheck disable=SC2016
runuser -u liman -- env LS_CONFIG_PATH="$runtime" php -r 'require $argv[1]."/app/bootstrap.php"; LockedShields\Database::connect(LockedShields\Config::load())->query("SELECT 1");' "$target"

unit_changed=1
install -m 0644 "$target/deploy/lockedshields-probe.service" "$unit"
systemctl daemon-reload
systemctl enable --now lockedshields-probe.service
# Give immediate startup failures a chance to surface; Restart=always is not health.
sleep 2
systemctl is-active --quiet lockedshields-probe.service
if ! worker_pid=$(systemctl show -p MainPID --value lockedshields-probe.service); then false; fi
[[ $worker_pid =~ ^[1-9][0-9]*$ ]]
trap - ERR INT TERM
printf 'Deployment complete. Extension UUID: %s\nPath: %s\nBackup: %s\n' "$extension_id" "$target" "$backup"
printf 'Run smoke test: sudo bash %s/scripts/smoke_test.sh %s\n' "$target" "$config"
