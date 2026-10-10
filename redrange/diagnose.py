#!/usr/bin/env python3
"""TESHIS — "neden her sey BEKLIYOR?" sorusunun cevabini bulur.

Her katmani ayri ayri olcer ve nerede koptugunu soyler:

  1. config.json mantikli mi (sablon degerleri kalmis mi?)
  2. Hedef makineye ag seviyesinde ulasiliyor mu?
  3. decoy_port acik mi?   -> `port` vektorunun UP olmasi buna bagli
  4. vuln_port acik mi?    -> KALICI puan mantigi buna bagli
  5. Beacon'lar baglaniyor mu ve hangi machine_id ile?
     -> config'deki isimle ESLESIYOR mu (BT-1 vs pardus-01 tuzagi)

Calistir:  python diagnose.py
"""
from __future__ import annotations

import json
import socket
import sys
import time
from pathlib import Path

from core.config import load_config
from core.probes import tcp_probe

CAPTURE_SECONDS = 25
PLACEHOLDER_HOSTS = {"SET_ME", "192.168.1.50", "ip_address", "changeme",
                     "CHANGE_ME", "x.x.x.x", ""}

G, R, Y, B, X = "\033[92m", "\033[91m", "\033[93m", "\033[94m", "\033[0m"


def ok(m):   print(f"  {G}[OK]{X}    {m}")
def bad(m):  print(f"  {R}[HATA]{X}  {m}")
def warn(m): print(f"  {Y}[DIKKAT]{X} {m}")
def info(m): print(f"  {B}[BILGI]{X} {m}")


def port_free(host: str, port: int) -> bool:
    with socket.socket() as s:
        s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            s.bind((host, port))
            return True
        except OSError:
            return False


def capture_beacons(host: str, port: int, seconds: int) -> dict[str, str]:
    """Gecici dinleyici acar, baglanan machine_id'leri toplar."""
    seen: dict[str, str] = {}
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind((host, port))
    srv.listen(16)
    srv.settimeout(1.0)
    end = time.time() + seconds
    while time.time() < end:
        try:
            conn, addr = srv.accept()
        except socket.timeout:
            continue
        except OSError:
            break
        conn.settimeout(3.0)
        try:
            data = conn.recv(4096).decode("utf-8", errors="replace")
            line = data.split("\n", 1)[0].strip()
            try:
                mid = str(json.loads(line).get("machine_id", "")) or "(kimliksiz)"
            except Exception:
                mid = "(kimliksiz/ham baglanti)"
        except OSError:
            mid = "(veri gelmedi)"
        finally:
            try:
                conn.close()
            except OSError:
                pass
        seen[mid] = f"{addr[0]}:{addr[1]}"
        print(f"      -> baglandi: {mid}  ({addr[0]})")
    srv.close()
    return seen


def main() -> int:
    cfg_path = Path("config.json")
    if not cfg_path.exists():
        bad("config.json bulunamadi. Proje kokunden calistir.")
        return 1
    cfg = load_config(str(cfg_path))
    problems = 0

    print(f"\n{B}=== 1. config.json ==={X}")
    targets = cfg.get("targets", [])
    if not targets:
        bad("Hic hedef tanimli degil."); problems += 1
    else:
        ok(f"{len(targets)} hedef tanimli: " +
           ", ".join(t["machine_id"] for t in targets))
    for t in targets:
        if t["host"] in PLACEHOLDER_HOSTS:
            bad(f"{t['machine_id']}: host hala SABLON degeri -> '{t['host']}'  "
                f"(gercek Pardus IP'sini yaz!)")
            problems += 1
    info(f"dinleyici portu: {cfg['listen_port']}, tik: {cfg['tick_seconds']}s, "
         f"oturum zaman asimi: {cfg['session_timeout']}s")

    print(f"\n{B}=== 2-4. Hedeflere ag testleri ==={X}")
    for t in targets:
        host, mid = t["host"], t["machine_id"]
        print(f"\n  --- {mid} ({host}) ---")
        decoy = tcp_probe(host, int(t["decoy_port"]), timeout=3.0)
        vuln = tcp_probe(host, int(t["vuln_port"]), timeout=3.0)
        if decoy:
            ok(f"decoy_port {t['decoy_port']} ACIK  -> 'port' vektoru UP olmali")
        else:
            bad(f"decoy_port {t['decoy_port']} KAPALI -> 'port' vektoru BEKLIYOR kalir")
            problems += 1
        if vuln:
            ok(f"vuln_port {t['vuln_port']} ACIK  -> kalici puan mantigi calisir")
        else:
            warn(f"vuln_port {t['vuln_port']} KAPALI -> zafiyet servisi calismiyor "
                 f"(ya da zaten kapatilmis)")
        if not decoy and not vuln:
            bad(f"{host} uzerinde iki port da kapali — makine ulasilamiyor olabilir. "
                f"Kali'den dene:  ping {host}   /   nmap -Pn -p "
                f"{t['decoy_port']},{t['vuln_port']} {host}")

    print(f"\n{B}=== 5. Beacon kimlik eslesmesi ==={X}")
    lp = int(cfg["listen_port"])
    if not port_free(cfg["listen_host"], lp):
        warn(f"Port {lp} MESGUL — run_gui.py / run_headless.py acik olmali.")
        info("Beacon testi icin onlari KAPAT ve bu araci tekrar calistir.")
        info("Alternatif: GUI'deki 'CANLI OTURUM' sayacina bak. "
             "0'dan buyukse beacon geliyor ama isim eslesmiyor demektir.")
    else:
        info(f"{CAPTURE_SECONDS} saniye boyunca beacon baglantilari dinleniyor...")
        seen = capture_beacons(cfg["listen_host"], lp, CAPTURE_SECONDS)
        configured = {t["machine_id"] for t in targets}
        if not seen:
            bad("Hic beacon baglanmadi.")
            bad("Pardus'ta kontrol et:  systemctl status range-beacon")
            bad("Beacon'in --server degeri bu makinenin IP'si mi? Guvenlik duvari?")
            problems += 1
        else:
            for mid, peer in seen.items():
                if mid in configured:
                    ok(f"'{mid}' baglandi ({peer}) ve config ile ESLESIYOR")
                else:
                    bad(f"'{mid}' baglandi ({peer}) ama config'de YOK!")
                    bad(f"     config'deki isimler: {sorted(configured)}")
                    bad(f"     -> config.json'da machine_id'yi '{mid}' yap, "
                        f"ya da beacon servisinde --id degerini degistir")
                    problems += 1
            for mid in configured - set(seen):
                warn(f"'{mid}' config'de var ama hic baglanmadi")

    print(f"\n{B}=== SONUC ==={X}")
    if problems == 0:
        ok("Belirgin bir sorun bulunamadi.")
    else:
        bad(f"{problems} sorun bulundu — yukaridaki [HATA] satirlarini duzelt.")
    return 0 if problems == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
