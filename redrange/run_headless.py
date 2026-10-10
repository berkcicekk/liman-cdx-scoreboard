#!/usr/bin/env python3
"""Giris noktasi — arayuzsuz. Sunucuda/servis olarak calistirmak icin.

Motorun GUI'den tamamen bagimsiz oldugunun kaniti: ayni cekirdek, arayuz yok.
    python run_headless.py
"""
from __future__ import annotations

import time

import argparse

from core.startup import start_or_explain


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", default="config.json")
    args = ap.parse_args()
    ctx = start_or_explain(args.config)
    print(f"[range] dinleyici :{ctx.config['listen_port']}  "
          f"tik={ctx.config['tick_seconds']}s  "
          f"hedef={len(ctx.config['targets'])}")
    print("[range] Ctrl+C ile durdurulur")
    try:
        while True:
            time.sleep(5)
            for r in ctx.engine.snapshot():
                print(f"  {r['machine_id']:<12} {r['vector_type']:<7} "
                      f"{r['state']:<11} gecici={r['temporary']} kalici={r['permanent']}")
            print("-" * 60)
    except KeyboardInterrupt:
        print("\n[range] durduruluyor...")
    finally:
        ctx.stop()


if __name__ == "__main__":
    main()
