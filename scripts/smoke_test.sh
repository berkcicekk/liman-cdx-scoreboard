#!/usr/bin/env bash
set -Eeuo pipefail
base=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
config=${1:-/etc/liman/lockedshields-deploy.php}
exec php "$base/scripts/smoke_test.php" "$config"
