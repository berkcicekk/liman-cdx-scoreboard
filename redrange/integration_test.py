#!/usr/bin/env python3
"""4 MAKINELI ENTEGRASYON TESTI — Pardus'a dokunmadan tum akisi dogrular.

demo.py ile BT-1..BT-4'u localhost'ta canlandirir, motoru uzerine baglar ve
savunan davranisini taklit eder:

    1) 4 makine x 2 vektor = 8 foothold  -> hepsi UP
    2) BT-2'nin decoy portu kapatilir    -> DOWN   [gecici]
    3) BT-2'nin zafiyeti kapatilir       -> REMEDIATED [kalici]
    4) Diger makineler etkilenmemis olmali

Calistir:  python integration_test.py
"""
from __future__ import annotations

import signal
import subprocess
import sys
import time

from core.config import build_context

ok = fail = 0


def check(label: str, got, expected) -> None:
    global ok, fail
    if got == expected:
        ok += 1
        print(f"  [OK]   {label}: {got}")
    else:
        fail += 1
        print(f"  [FAIL] {label}: beklenen={expected} gelen={got}")


def main() -> int:
    global fail
    subprocess.run([sys.executable, "demo.py", "--stop"],
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run([sys.executable, "demo.py", "--write-config-only"],
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    ctx = build_context("config_demo.json")
    ctx.sessions.verbose = False
    ctx.start()
    time.sleep(1)

    demo = subprocess.Popen([sys.executable, "demo.py"],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    print("== ajanlar baglaniyor (10s) ==")
    time.sleep(10)

    def st() -> dict:
        return {(r["machine_id"], r["vector_type"]): r["state"]
                for r in ctx.engine.snapshot()}

    try:
        ctx.engine.tick_once(); time.sleep(1); ctx.engine.tick_once()
        s = st()
        print("\n== 1) tum foothold'lar canli ==")
        check("UP sayisi", sum(1 for v in s.values() if v == "up"), 8)
        check("canli oturum", len(ctx.sessions.snapshot()), 4)
        check("oturum isimleri",
              sorted(x["machine_id"] for x in ctx.sessions.snapshot()),
              ["BT-1", "BT-2", "BT-3", "BT-4"])

        print("\n== 2) BT-2 decoy portu kapatiliyor -> GECICI ==")
        subprocess.run([sys.executable, "demo.py", "--kill", "BT-2",
                        "--what", "decoy"], stdout=subprocess.DEVNULL)
        time.sleep(2); ctx.engine.tick_once()
        check("BT-2 port durumu", st()[("BT-2", "port")], "down")

        print("\n== 3) BT-2 zafiyet servisi kapatiliyor -> KALICI ==")
        subprocess.run([sys.executable, "demo.py", "--kill", "BT-2",
                        "--what", "vuln"], stdout=subprocess.DEVNULL)
        time.sleep(2); ctx.engine.tick_once()
        check("BT-2 port durumu", st()[("BT-2", "port")], "remediated")

        print("\n== 4) diger makineler etkilenmedi ==")
        others = sorted({v for k, v in st().items() if k[0] != "BT-2"})
        check("BT-1/3/4 durumlari", others, ["up"])
    finally:
        ctx.stop()
        subprocess.run([sys.executable, "demo.py", "--stop"],
                       stdout=subprocess.DEVNULL)
        try:
            demo.send_signal(signal.SIGTERM)
        except ProcessLookupError:
            pass

    print(f"\n==== SONUC: {ok} basarili, {fail} basarisiz ====")
    return 1 if fail else 0


if __name__ == "__main__":
    sys.exit(main())
