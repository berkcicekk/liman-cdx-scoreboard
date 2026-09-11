#!/usr/bin/env bash
set -euo pipefail
chmod 755 /liman/server /liman/server/storage /liman/server/storage/extensions
test -r /liman/extensions/lockedshields/db.json
test -r /liman/extensions/cenk/db.json
install -d -m 755 /etc/liman
install -d -m 750 -o liman -g liman /var/lib/lockedshields-probe

# On restart/recreation, refresh Docker DNS -> INET seed/config before starting worker.
if [[ -f /liman/.lockedshields-local-ready ]]; then
    php /opt/lockedshields-local/bootstrap.php
    bash /opt/lockedshields-local/worker.sh start
fi
