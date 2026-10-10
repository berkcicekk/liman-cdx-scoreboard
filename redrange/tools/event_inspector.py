#!/usr/bin/env python3
"""EVENT INSPECTOR — dogrulama araci (RESMI SCORER DEGILDIR).

  !! BU URETIM PUANLAMA SISTEMI DEGILDIR !!

  Resmi scoring board ayri bir kisi tarafindan yaziliyor. Bu arac sadece
  motorun DOGRU event urettigini gozle dogrulamak icin var — bir referans
  tuketici ornegi. Puan degerleri temsilidir, resmi politika degildir.

  Scorer'i yazan kisi icin gercek teslimat: README'deki events.jsonl
  SOZLESMESI (event_type'lar, event_id ile dedupe, ts formati). Bu dosya
  o sozlesmenin nasil tuketilebilecegini gosteren bir ornekten ibarettir;
  silinebilir, uretimde kullanilmamalidir.

Calistir:
    python tools/event_inspector.py
    python tools/event_inspector.py --json rapor.json
    python tools/event_inspector.py --watch
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import time
from collections import defaultdict
from pathlib import Path

# tools/ altindan calistirildiginda sys.path[0] = tools/ olur ve "core"
# bulunamaz. Proje kokunu yola ekliyoruz ki her iki sekilde de calissin.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from core.events import read_events  # noqa: E402

# ---- TEMSILI puan degerleri (resmi politika DEGIL) ----
POINTS = {
    "foothold_down": 10,     # GECICI  — foothold dusuruldu
    "remediated": 50,        # KALICI  — kok neden kapatildi
    "regression": -50,       # duzeltme geri alindi
    "foothold_established": 0,
    "probe_error": 0,
}

YORUM = {
    "foothold_established": "saldirgan foothold kurdu / geri geldi",
    "foothold_down": "savunan foothold'u dusurdu",
    "remediated": "savunan zafiyeti KAPATTI (kok neden)",
    "regression": "duzeltme geri alindi — zafiyet tekrar acik",
    "probe_error": "olcum yapilamadi (ag hatasi)",
}

G, R, Y, B, C, X = ("\033[92m", "\033[91m", "\033[93m",
                    "\033[94m", "\033[96m", "\033[0m")

EVENT_COLOR = {
    "foothold_established": R,
    "foothold_down": Y,
    "remediated": G,
    "regression": R,
    "probe_error": "",
}


def analyze(path: str) -> dict:
    rows = read_events(path)

    # at-least-once varsay: event_id ile tekrari ayikla
    seen: set[str] = set()
    events = []
    for r in rows:
        eid = r.get("event_id")
        if eid and eid in seen:
            continue
        if eid:
            seen.add(eid)
        events.append(r)
    events.sort(key=lambda r: r.get("ts", 0))

    machines: dict[str, dict] = defaultdict(lambda: {
        "user_id": "",
        "score": 0,
        "temporary": 0,
        "permanent": 0,
        "regressions": 0,
        "vectors": {},          # vector_type -> son durum
        "timeline": [],
        "last_peer": "",
    })

    for e in events:
        mid = e.get("machine_id", "?")
        m = machines[mid]
        et = e.get("event_type", "")
        vt = e.get("vector_type", "?")
        m["user_id"] = e.get("user_id", m["user_id"])
        m["score"] += POINTS.get(et, 0)

        if et == "foothold_down":
            m["temporary"] += 1
            m["vectors"][vt] = "dusuruldu"
        elif et == "remediated":
            m["permanent"] += 1
            m["vectors"][vt] = "KAPATILDI"
        elif et == "foothold_established":
            m["vectors"][vt] = "foothold canli"
        elif et == "regression":
            m["regressions"] += 1
            m["vectors"][vt] = "geri acildi"

        detail = e.get("detail", "")
        if "baglanti" in detail or "port" in detail:
            m["last_peer"] = detail

        m["timeline"].append({
            "ts": e.get("ts", 0),
            "vector_type": vt,
            "event_type": et,
            "yorum": YORUM.get(et, et),
            "detail": detail,
        })

    return {"machines": dict(machines), "total_events": len(events)}


def render(report: dict, show_timeline: bool = True) -> None:
    machines = report["machines"]
    if not machines:
        print(f"{Y}Henuz hic olay yok. Motor calisiyor mu? events.jsonl bos.{X}")
        return

    order = sorted(machines.items(), key=lambda kv: -kv[1]["score"])

    print(f"\n{B}{'='*78}{X}")
    print(f"{B}  OLAY OZETI{X}  (dogrulama amacli — resmi puan degil)"
          f"   ({report['total_events']} olay islendi)")
    print(f"{B}{'='*78}{X}")
    print(f"  {'#':<3}{'MAKINE':<10}{'KULLANICI':<12}{'PUAN':>6}"
          f"{'GECICI':>8}{'KALICI':>8}{'GERI':>6}   DURUM")
    print(f"  {'-'*74}")

    for i, (mid, m) in enumerate(order, 1):
        vec = ", ".join(f"{k}:{v}" for k, v in m["vectors"].items()) or "-"
        color = G if m["permanent"] else (Y if m["temporary"] else R)
        print(f"  {i:<3}{mid:<10}{m['user_id']:<12}{color}{m['score']:>6}{X}"
              f"{m['temporary']:>8}{m['permanent']:>8}{m['regressions']:>6}   {vec}")

    print(f"\n{C}  Puan politikasi:{X} foothold_down=+{POINTS['foothold_down']}  "
          f"remediated=+{POINTS['remediated']}  regression={POINTS['regression']}")

    print(f"\n{B}  BAGLANTI KOKENI (son gorulen){X}")
    for mid, m in order:
        if m["last_peer"]:
            print(f"    {mid:<10} {m['last_peer']}")

    if not show_timeline:
        return

    print(f"\n{B}{'='*78}{X}")
    print(f"{B}  ZAMAN CIZELGESI — savunan ne yapti?{X}")
    print(f"{B}{'='*78}{X}")
    for mid, m in order:
        print(f"\n  {C}### {mid} ({m['user_id']}) — {m['score']} puan{X}")
        for t in m["timeline"]:
            stamp = time.strftime("%H:%M:%S", time.localtime(t["ts"]))
            col = EVENT_COLOR.get(t["event_type"], "")
            pts = POINTS.get(t["event_type"], 0)
            pstr = f"  ({pts:+d})" if pts else ""
            print(f"    {stamp}  {t['vector_type']:<7} {col}{t['yorum']}{X}{pstr}")
            if t["detail"]:
                print(f"              {t['detail']}")


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Olay akisi dogrulama araci (resmi scorer degil)")
    ap.add_argument("--events", default="events.jsonl")
    ap.add_argument("--json", dest="json_out", help="JSON rapor dosyasi")
    ap.add_argument("--watch", action="store_true", help="5 sn'de bir yenile")
    ap.add_argument("--no-timeline", action="store_true")
    a = ap.parse_args()

    if not os.path.exists(a.events):
        print(f"{R}{a.events} bulunamadi. Once motoru calistir.{X}")
        return 1

    def once():
        rep = analyze(a.events)
        if a.watch:
            os.system("clear")
        render(rep, show_timeline=not a.no_timeline)
        if a.json_out:
            with open(a.json_out, "w", encoding="utf-8") as f:
                json.dump(rep, f, ensure_ascii=False, indent=2)
            print(f"\n{G}JSON rapor yazildi: {a.json_out}{X}")

    if a.watch:
        try:
            while True:
                once()
                time.sleep(5)
        except KeyboardInterrupt:
            print("\nkapatildi")
    else:
        once()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
