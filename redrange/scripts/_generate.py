#!/usr/bin/env python3
"""4 makine icin self-contained kurulum scripti uretir (BT-1..BT-4)."""
from pathlib import Path

HERE = Path(__file__).parent
AGENT = HERE.parent / "agent"

MACHINES = [
    ("BT-1", 4444, 8081),
    ("BT-2", 4555, 8081),
    ("BT-3", 5666, 8081),
    ("BT-4", 7777, 8081),
]

TEMPLATE = r'''#!/usr/bin/env bash
# =====================================================================
#  RED RANGE — {MID} hedef makinesi kurulumu (Pardus / Debian tabanli)
#
#  KULLANIM:   sudo ./setup_{MID}.sh <KONTROLCU_IP>
#  ORNEK:      sudo ./setup_{MID}.sh 192.168.1.10
#
#  Bu script makineyi KASTEN ZAYIFLATIR. Sadece izole bir egitim aginda
#  calistir. Once snapshot al — geri donus yolu budur.
#
#  Kaldirmak icin:  sudo ./setup_{MID}.sh --uninstall
# =====================================================================
set -euo pipefail

MACHINE_ID="{MID}"
DECOY_PORT={DECOY}
VULN_PORT={VULN}
BEACON_PORT=9000
RANGE_DIR="/opt/range"

RED=$'\033[91m'; GRN=$'\033[92m'; YLW=$'\033[93m'; BLU=$'\033[94m'; OFF=$'\033[0m'
say()  {{ echo "${{BLU}}[*]${{OFF}} $*"; }}
good() {{ echo "${{GRN}}[+]${{OFF}} $*"; }}
warn() {{ echo "${{YLW}}[!]${{OFF}} $*"; }}
die()  {{ echo "${{RED}}[x]${{OFF}} $*" >&2; exit 1; }}

[[ $EUID -eq 0 ]] || die "root olarak calistir:  sudo $0 <KONTROLCU_IP>"

# ---------------------------------------------------------------- kaldirma
if [[ "${{1:-}}" == "--uninstall" ]]; then
  say "Kaldiriliyor..."
  systemctl disable --now range-beacon range-decoy range-weak 2>/dev/null || true
  rm -f /etc/systemd/system/range-{{beacon,decoy,weak}}.service
  rm -rf "$RANGE_DIR" /opt/appdata
  rm -f /etc/cron.d/range-maint /etc/sudoers.d/range-loose
  systemctl daemon-reload
  good "Artefaktlar kaldirildi."
  warn "SSH ayarlarini ve firewall'u ELLE geri al (ya da snapshot'a don)."
  exit 0
fi

CONTROLLER_IP="${{1:-}}"
[[ -n "$CONTROLLER_IP" ]] || die "Kontrolcu IP'si gerekli:  sudo $0 192.168.1.10"

echo
echo "${{BLU}}=====================================================${{OFF}}"
echo "  RED RANGE kurulum:  ${{GRN}}$MACHINE_ID${{OFF}}"
echo "  Kontrolcu        :  $CONTROLLER_IP:$BEACON_PORT"
echo "  Decoy port       :  $DECOY_PORT   (nmap ile bulunacak)"
echo "  Zafiyet servisi  :  $VULN_PORT   (kalici puan burada)"
echo "${{BLU}}=====================================================${{OFF}}"
echo

command -v python3 >/dev/null || die "python3 bulunamadi"
mkdir -p "$RANGE_DIR"

# ------------------------------------------------- 1) ajan dosyalari
say "Ajan dosyalari yaziliyor -> $RANGE_DIR"

cat > "$RANGE_DIR/beacon.py" <<'PYEOF'
{BEACON}
PYEOF

cat > "$RANGE_DIR/decoy_listener.py" <<'PYEOF'
{DECOY_SRC}
PYEOF

cat > "$RANGE_DIR/weak_service.py" <<'PYEOF'
{WEAK}
PYEOF

chmod 755 "$RANGE_DIR"/*.py
good "3 ajan dosyasi yazildi"

# ------------------------------------------------- 2) systemd servisleri
say "systemd servisleri olusturuluyor"

cat > /etc/systemd/system/range-weak.service <<EOF
[Unit]
Description=Internal Status Service (range vuln)
After=network.target
[Service]
Type=simple
ExecStart=/usr/bin/python3 $RANGE_DIR/weak_service.py --host 0.0.0.0 --port $VULN_PORT
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/range-decoy.service <<EOF
[Unit]
Description=Range Decoy Listener
After=network.target
[Service]
Type=simple
ExecStart=/usr/bin/python3 $RANGE_DIR/decoy_listener.py --host 0.0.0.0 --port $DECOY_PORT
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/range-beacon.service <<EOF
[Unit]
Description=Range Beacon Agent
After=network.target
[Service]
Type=simple
ExecStart=/usr/bin/python3 $RANGE_DIR/beacon.py --server $CONTROLLER_IP --port $BEACON_PORT --id $MACHINE_ID
Restart=always
RestartSec=10
[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now range-weak range-decoy range-beacon
good "3 servis aktif (Restart=always — sadece process oldurmek yetmez)"

# ------------------------------------------------- 3) ek yanlis configler
say "Sertlestirme gorevleri (yanlis configler) uygulaniyor"

# 3.1 SSH gevsekligi
if [[ -f /etc/ssh/sshd_config ]]; then
  cp -n /etc/ssh/sshd_config /etc/ssh/sshd_config.range-backup || true
  sed -i 's/^#*PermitRootLogin.*/PermitRootLogin yes/'            /etc/ssh/sshd_config
  sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication yes/' /etc/ssh/sshd_config
  sed -i 's/^#*X11Forwarding.*/X11Forwarding yes/'                /etc/ssh/sshd_config
  systemctl restart ssh 2>/dev/null || systemctl restart sshd 2>/dev/null || true
  good "SSH gevsetildi (yedek: /etc/ssh/sshd_config.range-backup)"
fi

# 3.2 dunyaya yazilabilir dizin + dosya
mkdir -p /opt/appdata
chmod 777 /opt/appdata
touch /opt/appdata/config.ini
chmod 666 /opt/appdata/config.ini
good "/opt/appdata 777 yapildi"

# 3.3 herkesin yazabildigi, root olarak calisan cron betigi
cat > /opt/appdata/maintenance.sh <<'EOF'
#!/bin/bash
# poligon: zararsiz bakim betigi
logger "range maintenance ran"
EOF
chmod 777 /opt/appdata/maintenance.sh
echo '*/5 * * * * root /opt/appdata/maintenance.sh' > /etc/cron.d/range-maint
chmod 644 /etc/cron.d/range-maint
good "Yazilabilir cron betigi kuruldu"

# 3.4 gevsek sudo
echo 'ALL ALL=(ALL) NOPASSWD: /bin/systemctl' > /etc/sudoers.d/range-loose
chmod 440 /etc/sudoers.d/range-loose
good "Gevsek sudo kurali eklendi"

# 3.5 firewall kapali
ufw disable >/dev/null 2>&1 || true
good "Guvenlik duvari devre disi"

# ------------------------------------------------- 4) ozet
echo
echo "${{GRN}}=====================================================${{OFF}}"
echo "${{GRN}}  $MACHINE_ID hazir${{OFF}}"
echo "${{GRN}}=====================================================${{OFF}}"
echo
echo "  Kontrol:"
echo "    systemctl status range-weak range-decoy range-beacon"
echo "    ss -tlnp | grep -E '$DECOY_PORT|$VULN_PORT'"
echo "    ss -tnp  | grep $CONTROLLER_IP"
echo
echo "  Kontrolcude config.json'a ekle:"
echo "    {{ \"machine_id\": \"$MACHINE_ID\", \"user_id\": \"<ogrenci>\","
echo "      \"host\": \"$(hostname -I | awk '{{print $1}}')\","
echo "      \"vuln_port\": $VULN_PORT, \"decoy_port\": $DECOY_PORT }}"
echo
echo "  ${{YLW}}Kendi dinleyicini kullanmak istersen:${{OFF}}"
echo "    systemctl stop range-decoy"
echo "    nc -lvnp $DECOY_PORT          # motor bunu da FOOTHOLD CANLI gorur"
echo
echo "  Kaldirmak:  sudo $0 --uninstall"
echo
'''


def main() -> None:
    beacon = (AGENT / "beacon.py").read_text().rstrip()
    decoy = (AGENT / "decoy_listener.py").read_text().rstrip()
    weak = (AGENT / "weak_service.py").read_text().rstrip()

    for mid, dport, vport in MACHINES:
        body = TEMPLATE.format(MID=mid, DECOY=dport, VULN=vport,
                               BEACON=beacon, DECOY_SRC=decoy, WEAK=weak)
        out = HERE / f"setup_{mid}.sh"
        out.write_text(body)
        out.chmod(0o755)
        print(f"yazildi: {out.name}  (decoy={dport} vuln={vport})")


if __name__ == "__main__":
    main()
