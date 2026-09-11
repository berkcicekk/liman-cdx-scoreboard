#!/usr/bin/env bash
set -euo pipefail
[[ ${LS_LOCAL_HARNESS:-} == 1 ]] || exit 1

# Adapt the official entrypoint in the container, leaving the image and source intact.
# In particular its recursive chmod/chown must NEVER descend into the host mount.
php /opt/lockedshields-local/prepare.php
exec bash /tmp/lockedshields-init.sh
