"""Giris noktalarinin paylastigi baslatma yardimcisi."""
from __future__ import annotations

import sys

from core.config import AppContext, build_context
from core.sessions import PortInUseError


def start_or_explain(config_path: str) -> AppContext:
    """Context kurar ve baslatir; port meskulse anlasilir mesajla cikar."""
    ctx = build_context(config_path)
    if not ctx.config["targets"]:
        print("UYARI: config icinde hic hedef yok.")
    try:
        ctx.start()
    except PortInUseError as exc:
        port = exc.args[0]
        print(f"""
HATA: {port} portu zaten kullanimda.

Bu bir kod hatasi degil — baska bir surec o portu tutuyor. Muhtemelen:
  * Acik kalmis bir run_gui.py / run_headless.py
  * diagnose.py'nin beacon yakalama penceresi (25 sn) hala acik

Kim tuttugunu bul:
    ss -tlnp | grep :{port}
    # ya da:  sudo lsof -i :{port}

Kapat:
    pkill -f run_gui.py
    pkill -f run_headless.py

Alternatif: config.json icinde "listen_port" degerini degistir
(hedeflerdeki beacon servislerinin --port degerini de guncellemeyi unutma).
""".rstrip(), file=sys.stderr)
        raise SystemExit(1)
    return ctx
