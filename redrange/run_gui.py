#!/usr/bin/env python3
"""Giris noktasi — GUI ile. Proje KOKUNDEN calistir.

    python run_gui.py
    python run_gui.py --config config_demo.json
"""
from __future__ import annotations

import argparse

import flet as ft

from core.startup import start_or_explain
from ui.app import build_app


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", default="config.json")
    args = ap.parse_args()

    ctx = start_or_explain(args.config)   # dinleyici + motor arka planda
    try:
        ft.run(build_app(ctx))       # Flet 1.0: ft.app degil, ft.run
    finally:
        ctx.stop()


if __name__ == "__main__":
    main()
