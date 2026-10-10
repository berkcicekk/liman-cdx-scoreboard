#!/usr/bin/env python3
"""DEMO — 4 sahte makineyi (BT-1..BT-4) localhost'ta canlandirir.

Pardus'a hic dokunmadan GUI'yi calisir halde gormek icin. Her sahte makine
icin decoy listener + weak service + beacon baslatir ve config_demo.json
uretir.

    Terminal 1:  python demo.py
    Terminal 2:  python run_gui.py --config config_demo.json

Savunani taklit etmek icin (Terminal 3) bir makineyi "temizle":
    python demo.py --kill BT-2 --what decoy    # gecici puan
    python demo.py --kill BT-2 --what vuln     # kalici puan
"""
from __future__ import annotations

import argparse
import json
import signal
import subprocess
import sys
import time
from pathlib import Path

HERE = Path(__file__).parent
AGENT = HERE / "agent"
CONFIG = HERE / "config_demo.json"
PIDFILE = HERE / ".demo_pids.json"
LISTEN_PORT = 9000

MACHINES = [
    # id,     decoy, vuln
    ("BT-1", 14441, 18081),
    ("BT-2", 14442, 18082),
    ("BT-3", 14443, 18083),
    ("BT-4", 14444, 18084),
]


def write_config() -> None:
    cfg = {
        "listen_host": "127.0.0.1",
        "listen_port": LISTEN_PORT,
        "tick_seconds": 2,
        "session_timeout": 15,
        "events_path": "events_demo.jsonl",
        "targets": [
            {"machine_id": mid, "user_id": f"ogrenci-{i}", "host": "127.0.0.1",
             "vuln_port": v, "decoy_port": d}
            for i, (mid, d, v) in enumerate(MACHINES, 1)
        ],
    }
    CONFIG.write_text(json.dumps(cfg, indent=2, ensure_ascii=False))
    print(f"[demo] {CONFIG.name} yazildi")


def start_all() -> None:
    write_config()
    pids: dict[str, dict[str, int]] = {}
    for mid, decoy, vuln in MACHINES:
        p_decoy = subprocess.Popen(
            [sys.executable, str(AGENT / "decoy_listener.py"),
             "--host", "127.0.0.1", "--port", str(decoy)],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        p_vuln = subprocess.Popen(
            [sys.executable, str(AGENT / "weak_service.py"),
             "--host", "127.0.0.1", "--port", str(vuln)],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        p_beacon = subprocess.Popen(
            [sys.executable, str(AGENT / "beacon.py"),
             "--server", "127.0.0.1", "--port", str(LISTEN_PORT), "--id", mid],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        pids[mid] = {"decoy": p_decoy.pid, "vuln": p_vuln.pid,
                     "beacon": p_beacon.pid}
        print(f"[demo] {mid}: decoy={decoy} vuln={vuln} beacon-> :{LISTEN_PORT}")
    PIDFILE.write_text(json.dumps(pids))

    print("\n[demo] 4 sahte makine ayakta.")
    print("[demo] Simdi ikinci terminalde:")
    print("         python run_gui.py --config config_demo.json")
    print("[demo] Savunani taklit et:")
    print("         python demo.py --kill BT-2 --what decoy   (gecici puan)")
    print("         python demo.py --kill BT-2 --what vuln    (kalici puan)")
    print("[demo] Ctrl+C ile hepsini kapat\n")

    def cleanup(*_):
        stop_all()
        sys.exit(0)

    signal.signal(signal.SIGINT, cleanup)
    signal.signal(signal.SIGTERM, cleanup)
    while True:
        time.sleep(1)


def kill_one(mid: str, what: str) -> None:
    if not PIDFILE.exists():
        print("[demo] demo calismiyor"); return
    pids = json.loads(PIDFILE.read_text())
    if mid not in pids:
        print(f"[demo] {mid} yok. Gecerli: {list(pids)}"); return
    pid = pids[mid].get(what)
    if not pid:
        print(f"[demo] '{what}' bilinmiyor (decoy|vuln|beacon)"); return
    try:
        import os
        os.kill(pid, signal.SIGTERM)
        print(f"[demo] {mid} / {what} oldruldu (pid {pid})")
        if what == "decoy":
            print("       -> birkac saniye icinde GUI'de DUSURULDU gorunmeli [gecici]")
        elif what == "vuln":
            print("       -> zafiyet kapandi; foothold da dustuyse KAPATILDI [kalici]")
        elif what == "beacon":
            print("       -> beacon oturumu kopacak [gecici]")
    except ProcessLookupError:
        print(f"[demo] pid {pid} zaten yok")


def stop_all() -> None:
    if not PIDFILE.exists():
        return
    import os
    pids = json.loads(PIDFILE.read_text())
    for mid, procs in pids.items():
        for what, pid in procs.items():
            try:
                os.kill(pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
    PIDFILE.unlink(missing_ok=True)
    print("[demo] tum sahte makineler kapatildi")


def main() -> None:
    ap = argparse.ArgumentParser(description="4 sahte makine ile demo")
    ap.add_argument("--kill", metavar="MACHINE_ID")
    ap.add_argument("--what", default="decoy",
                    choices=["decoy", "vuln", "beacon"])
    ap.add_argument("--stop", action="store_true")
    ap.add_argument("--write-config-only", action="store_true")
    a = ap.parse_args()

    if a.write_config_only:
        write_config()
    elif a.stop:
        stop_all()
    elif a.kill:
        kill_one(a.kill, a.what)
    else:
        start_all()


if __name__ == "__main__":
    main()
