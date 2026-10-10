#!/usr/bin/env bash
# =====================================================================
#  RED RANGE — BT-2 hedef makinesi kurulumu (Pardus / Debian tabanli)
#
#  KULLANIM:   sudo ./setup_BT-2.sh <KONTROLCU_IP>
#  ORNEK:      sudo ./setup_BT-2.sh 192.168.1.10
#
#  Bu script makineyi KASTEN ZAYIFLATIR. Sadece izole bir egitim aginda
#  calistir. Once snapshot al — geri donus yolu budur.
#
#  Kaldirmak icin:  sudo ./setup_BT-2.sh --uninstall
# =====================================================================
set -euo pipefail

MACHINE_ID="BT-2"
DECOY_PORT=4555
VULN_PORT=8081
BEACON_PORT=9000
RANGE_DIR="/opt/range"

RED=$'\033[91m'; GRN=$'\033[92m'; YLW=$'\033[93m'; BLU=$'\033[94m'; OFF=$'\033[0m'
say()  { echo "${BLU}[*]${OFF} $*"; }
good() { echo "${GRN}[+]${OFF} $*"; }
warn() { echo "${YLW}[!]${OFF} $*"; }
die()  { echo "${RED}[x]${OFF} $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "root olarak calistir:  sudo $0 <KONTROLCU_IP>"

# ---------------------------------------------------------------- kaldirma
if [[ "${1:-}" == "--uninstall" ]]; then
  say "Kaldiriliyor..."
  systemctl disable --now range-beacon range-decoy range-weak 2>/dev/null || true
  rm -f /etc/systemd/system/range-{beacon,decoy,weak}.service
  rm -rf "$RANGE_DIR" /opt/appdata
  rm -f /etc/cron.d/range-maint /etc/sudoers.d/range-loose
  systemctl daemon-reload
  good "Artefaktlar kaldirildi."
  warn "SSH ayarlarini ve firewall'u ELLE geri al (ya da snapshot'a don)."
  exit 0
fi

CONTROLLER_IP="${1:-}"
[[ -n "$CONTROLLER_IP" ]] || die "Kontrolcu IP'si gerekli:  sudo $0 192.168.1.10 [BEACON_TOKEN]"
# Opsiyonel beacon token: bos ise kimlik dogrulamasi KAPALI (eski davranis).
# Doluysa kontrolcu config'indeki beacon_token ile BIREBIR eslesmeli; aksi halde
# beacon reddedilir. Bir takimin baska bir takimi taklit etmesini engeller.
BEACON_TOKEN="${2:-${BEACON_TOKEN:-}}"

echo
echo "${BLU}=====================================================${OFF}"
echo "  RED RANGE kurulum:  ${GRN}$MACHINE_ID${OFF}"
echo "  Kontrolcu        :  $CONTROLLER_IP:$BEACON_PORT"
echo "  Decoy port       :  $DECOY_PORT   (nmap ile bulunacak)"
echo "  Zafiyet servisi  :  $VULN_PORT   (kalici puan burada)"
echo "${BLU}=====================================================${OFF}"
echo

command -v python3 >/dev/null || die "python3 bulunamadi"
mkdir -p "$RANGE_DIR"

# ------------------------------------------------- 1) ajan dosyalari
say "Ajan dosyalari yaziliyor -> $RANGE_DIR"

cat > "$RANGE_DIR/beacon.py" <<'PYEOF'
#!/usr/bin/env python3
"""BEACON â€” poligon hedef makinesine (Pardus) kurulan kalp-atisi ajani.

NE YAPAR: Kontrolcuye baglanir, kimligini bildirir ve duzenli araliklarla
"canliyim" mesaji gonderir. Hepsi bu.

NE YAPMAZ: Komut almaz, komut calistirmaz, dosya okumaz/yazmaz, kalicilik
numarasi yapmaz, kendini gizlemez. Bir izleme ajanindan (monitoring agent)
farki yoktur. Egitim senaryosundaki rolu: savunan kisinin "burada olmamasi
gereken bir giden baglanti var" diye FARK EDIP kapatmasi gereken artefakt.

Savunan bunu soyle bulur:
    ss -tnp | grep <kontrolcu_ip>
    systemctl list-units --type=service | grep beacon

Kullanim:
    python3 beacon.py --server 192.168.1.10 --port 9000 --id pardus-01
"""
from __future__ import annotations

import argparse
import json
import os
import socket
import time

HEARTBEAT_SECONDS = 5
RECONNECT_SECONDS = 5


def run(server: str, port: int, machine_id: str, token: str = "") -> None:
    hello = {"machine_id": machine_id}
    if token:
        hello["token"] = token   # kontrolcu bunu config'teki token ile dogrular
    while True:
        try:
            with socket.create_connection((server, port), timeout=10) as s:
                s.sendall((json.dumps(hello) + "\n").encode())
                print(f"[beacon] baglandi -> {server}:{port} ({machine_id})", flush=True)
                while True:
                    time.sleep(HEARTBEAT_SECONDS)
                    s.sendall((json.dumps({"hb": 1}) + "\n").encode())
        except OSError as exc:
            print(f"[beacon] baglanti yok ({exc}); {RECONNECT_SECONDS}s sonra tekrar",
                  flush=True)
            time.sleep(RECONNECT_SECONDS)


def main() -> None:
    p = argparse.ArgumentParser(description="Poligon kalp-atisi ajani")
    p.add_argument("--server", required=True, help="Kontrolcu IP")
    p.add_argument("--port", type=int, default=9000)
    p.add_argument("--id", dest="machine_id", required=True, help="machine_id")
    p.add_argument("--token", default=os.environ.get("BEACON_TOKEN", ""),
                   help="kontrolcu config'indeki beacon_token (env: BEACON_TOKEN)")
    a = p.parse_args()
    run(a.server, a.port, a.machine_id, a.token)


if __name__ == "__main__":
    main()
PYEOF

cat > "$RANGE_DIR/decoy_listener.py" <<'PYEOF'
#!/usr/bin/env python3
"""DECOY LISTENER â€” hedef makinede dinleyen "bulunmasi gereken" port.

NE YAPAR: Bir TCP portunu dinler, baglanana bir banner yollar ve kapatir.

NE YAPMAZ: Komut kabul etmez, shell acmaz, hicbir sey calistirmaz. Kasten
"aptal"dir. Egitim senaryosundaki rolu: savunan kisinin PORT TARAMASIYLA
bulup kapatmasi gereken artefakt. Beacon'in aksine bu, nmap ile gorunur â€”
iki farkli tespit becerisini ogretmek icin ikisi birlikte kullanilir.

Savunan bunu soyle bulur:
    nmap -p- <hedef_ip>
    ss -tlnp | grep <port>

Kullanim:
    python3 decoy_listener.py --port 4444
"""
from __future__ import annotations

import argparse
import socket

BANNER = b"RANGE-ARTIFACT: bu port kasten aciktir. Gorevin: bul ve kapat.\n"


def run(host: str, port: int) -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as srv:
        srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        srv.bind((host, port))
        srv.listen(16)
        print(f"[decoy] dinleniyor {host}:{port}", flush=True)
        while True:
            try:
                conn, addr = srv.accept()
            except OSError:
                break
            with conn:
                try:
                    conn.sendall(BANNER)
                except OSError:
                    pass


def main() -> None:
    p = argparse.ArgumentParser(description="Poligon decoy dinleyici")
    p.add_argument("--host", default="0.0.0.0")
    p.add_argument("--port", type=int, default=4444)
    a = p.parse_args()
    run(a.host, a.port)


if __name__ == "__main__":
    main()
PYEOF

cat > "$RANGE_DIR/weak_service.py" <<'PYEOF'
#!/usr/bin/env python3
"""WEAK SERVICE â€” kasten ag'a acilmis, kimlik dogrulamasiz ic servis.

Bu, senaryodaki ASIL ZAFIYETTIR (kok neden). Icerik olarak zararsiz bir
durum sayfasi doner; "zafiyet" olan sey, ic kullanima ait bir servisin
kimlik dogrulamasiz sekilde tum aga acik olmasidir â€” gercek hayatta en sik
gorulen yanlis configlerden biri.

Savunanin gorevi: bu servisi ya sadece localhost'a baglamak, ya durdurmak,
ya da guvenlik duvariyla kapatmak. Bunu yaptiginda motor "remediated" der
ve KALICI puan yazilir.

Savunan bunu soyle bulur:
    nmap -p- <hedef_ip>
    ss -tlnp | grep <port>
    curl http://<hedef_ip>:8081/

Kullanim:
    python3 weak_service.py --port 8081
"""
from __future__ import annotations

import argparse
from http.server import BaseHTTPRequestHandler, HTTPServer

PAGE = b"""<!doctype html>
<meta charset="utf-8">
<title>Internal Status</title>
<h1>Internal Status Service</h1>
<p>RANGE-VULN: Bu servis kimlik dogrulamasiz ve tum aga acik.
Gorevin: erisimi kapat (localhost'a bagla / servisi durdur / firewall).</p>
"""


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):                      # noqa: N802
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(PAGE)))
        self.end_headers()
        self.wfile.write(PAGE)

    def log_message(self, fmt, *args):     # sessiz log
        print(f"[weak-service] {self.address_string()} {fmt % args}", flush=True)


def main() -> None:
    p = argparse.ArgumentParser(description="Kasten acik birakilmis ic servis")
    p.add_argument("--host", default="0.0.0.0")
    p.add_argument("--port", type=int, default=8081)
    a = p.parse_args()
    srv = HTTPServer((a.host, a.port), Handler)
    print(f"[weak-service] dinleniyor {a.host}:{a.port}", flush=True)
    srv.serve_forever()


if __name__ == "__main__":
    main()
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
ExecStart=/usr/bin/python3 $RANGE_DIR/beacon.py --server $CONTROLLER_IP --port $BEACON_PORT --id $MACHINE_ID --token "$BEACON_TOKEN"
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
echo "${GRN}=====================================================${OFF}"
echo "${GRN}  $MACHINE_ID hazir${OFF}"
echo "${GRN}=====================================================${OFF}"
echo
echo "  Kontrol:"
echo "    systemctl status range-weak range-decoy range-beacon"
echo "    ss -tlnp | grep -E '$DECOY_PORT|$VULN_PORT'"
echo "    ss -tnp  | grep $CONTROLLER_IP"
echo
echo "  Kontrolcude config.json'a ekle:"
echo "    { \"machine_id\": \"$MACHINE_ID\", \"user_id\": \"<ogrenci>\","
echo "      \"host\": \"$(hostname -I | awk '{print $1}')\","
echo "      \"vuln_port\": $VULN_PORT, \"decoy_port\": $DECOY_PORT }"
echo
echo "  ${YLW}Kendi dinleyicini kullanmak istersen:${OFF}"
echo "    systemctl stop range-decoy"
echo "    nc -lvnp $DECOY_PORT          # motor bunu da FOOTHOLD CANLI gorur"
echo
echo "  Kaldirmak:  sudo $0 --uninstall"
echo
