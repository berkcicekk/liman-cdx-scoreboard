#!/usr/bin/env python3
"""WEAK SERVICE — kasten ag'a acilmis, kimlik dogrulamasiz ic servis.

Bu, senaryodaki ASIL ZAFIYETTIR (kok neden). Icerik olarak zararsiz bir
durum sayfasi doner; "zafiyet" olan sey, ic kullanima ait bir servisin
kimlik dogrulamasiz sekilde tum aga acik olmasidir — gercek hayatta en sik
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
