#!/usr/bin/env python3
"""BEACON — poligon hedef makinesine (Pardus) kurulan kalp-atisi ajani.

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
