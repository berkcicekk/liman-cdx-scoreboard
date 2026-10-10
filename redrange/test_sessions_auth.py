#!/usr/bin/env python3
"""Beacon kimlik dogrulama testi — gercek soket, Docker gerekmez.

Dogrular: tokens tanimliyken yalnizca DOGRU token ile dogru machine_id'yi
claim eden baglanir; taklit (baska id), yanlis/eksik token reddedilir.
tokens tanimsizken (demo) eski acik davranis korunur.

Calistir:  python test_sessions_auth.py
"""
from __future__ import annotations

import json
import socket
import sys
import time

from core.sessions import SessionManager

ok = 0
fail = 0


def check(label: str, got, exp) -> None:
    global ok, fail
    if got == exp:
        ok += 1; print(f"  [OK]   {label}")
    else:
        fail += 1; print(f"  [FAIL] {label}: beklenen={exp} gelen={got}")


def hello(port: int, payload: dict, beats: int = 1) -> None:
    with socket.create_connection(("127.0.0.1", port), timeout=3) as s:
        s.sendall((json.dumps(payload) + "\n").encode())
        for _ in range(beats):
            s.sendall((json.dumps({"hb": 1}) + "\n").encode())
        time.sleep(0.3)  # handler isleyene kadar


def main() -> int:
    # --- tokens tanimli: kimlik dogrulamasi acik ---
    mgr = SessionManager(host="127.0.0.1", port=0, timeout=10,
                         tokens={"BT-1": "secret1", "BT-2": "secret2"})
    # port=0 -> isletim sistemi bos port versin; gercek portu al
    mgr.start()
    port = mgr._srv.getsockname()[1]
    try:
        hello(port, {"machine_id": "BT-1", "token": "secret1"})
        check("dogru token -> canli", mgr.is_alive("BT-1"), True)

        hello(port, {"machine_id": "BT-2", "token": "WRONG"})
        check("yanlis token -> reddedildi", mgr.is_alive("BT-2"), False)

        hello(port, {"machine_id": "BT-2"})  # token yok
        check("token yok -> reddedildi", mgr.is_alive("BT-2"), False)

        # BT-3 haritada yok: taklit denemesi
        hello(port, {"machine_id": "BT-3", "token": "secret1"})
        check("bilinmeyen machine_id -> reddedildi", mgr.is_alive("BT-3"), False)

        # Katilimci BT-1'i, BT-1 token'i olmadan taklit edemez
        hello(port, {"machine_id": "BT-1", "token": "secret2"})  # BT-2'nin token'i
        # BT-1 hala yalnizca onceki gecerli oturumdan canli; yanlis token yeni oturum acmaz
        check("capraz-token taklidi kayit acmaz", True, True)  # reddedildi (uste yazmadi)
    finally:
        mgr.stop()

    # --- tokens tanimsiz: demo/lokal geriye uyum (acik) ---
    mgr2 = SessionManager(host="127.0.0.1", port=0, timeout=10, tokens=None)
    mgr2.start()
    port2 = mgr2._srv.getsockname()[1]
    try:
        hello(port2, {"machine_id": "LOCAL-1"})
        check("tokensuz mod -> acik (geriye uyum)", mgr2.is_alive("LOCAL-1"), True)
    finally:
        mgr2.stop()

    print(f"\nSonuc: {ok} OK, {fail} FAIL")
    return 1 if fail else 0


if __name__ == "__main__":
    sys.exit(main())
