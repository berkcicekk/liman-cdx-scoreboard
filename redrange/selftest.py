#!/usr/bin/env python3
"""UCTAN UCA KENDI KENDINI TEST — gercek socket, gercek surecler.

Pardus'a hic dokunmadan, her seyi 127.0.0.1 uzerinde canlandirir ve durum
makinesinin gercekten dogru calistigini kanitlar:

    1) artefaktlar ayakta            -> PENDING  -> UP
    2) foothold'lar oldurulur        -> UP       -> DOWN        [GECICI]
    3) zafiyet hala acik             -> DOWN'da bekler
    4) zafiyet servisi kapatilir     -> DOWN     -> REMEDIATED  [KALICI]

Calistir:  python selftest.py
"""
from __future__ import annotations

import json
import socket
import subprocess
import sys
import time
from pathlib import Path

from core.engine import Engine, Job
from core.events import EventSink
from core.models import Target
from core.sessions import SessionManager
from core.vectors import BeaconVector, PortVector

HERE = Path(__file__).parent
SESS_PORT = 19000
DECOY_PORT = 14444
VULN_PORT = 18081
EVENTS = HERE / "selftest_events.jsonl"

ok_count = 0
fail_count = 0


def check(label: str, got, expected) -> None:
    global ok_count, fail_count
    if got == expected:
        ok_count += 1
        print(f"  [OK]   {label}: {got}")
    else:
        fail_count += 1
        print(f"  [FAIL] {label}: beklenen={expected} gelen={got}")


def wait_port(port: int, up: bool, timeout: float = 6.0) -> bool:
    end = time.time() + timeout
    while time.time() < end:
        with socket.socket() as s:
            s.settimeout(0.4)
            alive = s.connect_ex(("127.0.0.1", port)) == 0
        if alive == up:
            return True
        time.sleep(0.2)
    return False


def main() -> int:
    if EVENTS.exists():
        EVENTS.unlink()

    print("== artefaktlar baslatiliyor ==")
    decoy = subprocess.Popen(
        [sys.executable, str(HERE / "agent" / "decoy_listener.py"),
         "--host", "127.0.0.1", "--port", str(DECOY_PORT)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    vuln = subprocess.Popen(
        [sys.executable, str(HERE / "agent" / "weak_service.py"),
         "--host", "127.0.0.1", "--port", str(VULN_PORT)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    beacon = subprocess.Popen(
        [sys.executable, str(HERE / "agent" / "beacon.py"),
         "--server", "127.0.0.1", "--port", str(SESS_PORT), "--id", "test-01"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    sessions = SessionManager("127.0.0.1", SESS_PORT, timeout=8.0)
    sessions.start()
    sink = EventSink(str(EVENTS))

    target = Target(machine_id="test-01", user_id="baris", host="127.0.0.1",
                    vuln_port=VULN_PORT, decoy_port=DECOY_PORT)
    jobs = [
        Job(target, PortVector("port-test")),
        Job(target, BeaconVector(sessions, "beacon-test")),
    ]
    engine = Engine(jobs, sink, tick_seconds=1.0)

    try:
        assert wait_port(DECOY_PORT, True), "decoy acilmadi"
        assert wait_port(VULN_PORT, True), "weak service acilmadi"
        time.sleep(2.0)                     # beacon baglansin

        print("\n== 1) artefaktlar ayakta -> UP bekleniyor ==")
        engine.tick_once()
        check("port vektoru", jobs[0].runtime.state.value, "up")
        check("beacon vektoru", jobs[1].runtime.state.value, "up")
        check("canli oturum", len(sessions.snapshot()), 1)

        print("\n== 2) savunan foothold'lari oldurur -> DOWN + gecici puan ==")
        decoy.terminate(); decoy.wait(timeout=5)
        beacon.terminate(); beacon.wait(timeout=5)
        assert wait_port(DECOY_PORT, False), "decoy kapanmadi"
        time.sleep(9.0)                     # oturum zaman asimi (8s)
        engine.tick_once()
        check("port vektoru", jobs[0].runtime.state.value, "down")
        check("beacon vektoru", jobs[1].runtime.state.value, "down")
        check("gecici puan (port)", jobs[0].runtime.temporary_points, 1)
        check("gecici puan (beacon)", jobs[1].runtime.temporary_points, 1)

        print("\n== 3) zafiyet HALA acik -> DOWN'da kalmali (kalici puan YOK) ==")
        engine.tick_once()
        check("port vektoru", jobs[0].runtime.state.value, "down")
        check("kalici puan (port)", jobs[0].runtime.permanent_points, 0)

        print("\n== 4) savunan zafiyet servisini kapatir -> REMEDIATED + kalici ==")
        vuln.terminate(); vuln.wait(timeout=5)
        assert wait_port(VULN_PORT, False), "weak service kapanmadi"
        engine.tick_once()
        check("port vektoru", jobs[0].runtime.state.value, "remediated")
        check("beacon vektoru", jobs[1].runtime.state.value, "remediated")
        check("kalici puan (port)", jobs[0].runtime.permanent_points, 1)
        check("kalici puan (beacon)", jobs[1].runtime.permanent_points, 1)

        print("\n== 5) olay akisi (events.jsonl) ==")
        rows = [json.loads(l) for l in EVENTS.read_text().splitlines() if l.strip()]
        kinds = [r["event_type"] for r in rows]
        check("foothold_established sayisi", kinds.count("foothold_established"), 2)
        check("foothold_down sayisi", kinds.count("foothold_down"), 2)
        check("remediated sayisi", kinds.count("remediated"), 2)
        check("tum event'ler benzersiz", len({r["event_id"] for r in rows}), len(rows))
        check("zaman damgalari farkli", len({r["ts"] for r in rows}) > 1, True)

        print("\n--- ornek akis ---")
        for r in rows:
            print(f"  {time.strftime('%H:%M:%S', time.localtime(r['ts']))} "
                  f"{r['machine_id']} {r['vector_type']:<7} {r['event_type']}")
    finally:
        for p in (decoy, vuln, beacon):
            if p.poll() is None:
                p.terminate()
        sessions.stop()

    print(f"\n==== SONUC: {ok_count} basarili, {fail_count} basarisiz ====")
    return 1 if fail_count else 0


if __name__ == "__main__":
    sys.exit(main())
