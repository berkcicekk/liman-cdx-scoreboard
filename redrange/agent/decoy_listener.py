#!/usr/bin/env python3
"""DECOY LISTENER — hedef makinede dinleyen "bulunmasi gereken" port.

NE YAPAR: Bir TCP portunu dinler, baglanana bir banner yollar ve kapatir.

NE YAPMAZ: Komut kabul etmez, shell acmaz, hicbir sey calistirmaz. Kasten
"aptal"dir. Egitim senaryosundaki rolu: savunan kisinin PORT TARAMASIYLA
bulup kapatmasi gereken artefakt. Beacon'in aksine bu, nmap ile gorunur —
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
