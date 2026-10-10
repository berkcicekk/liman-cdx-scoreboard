#!/usr/bin/env python3
"""Kopru CEVIRI testi — psycopg/PostgreSQL GEREKTIRMEZ.

event_to_inject saf fonksiyonunu ve idempotency anahtarini dogrular:

    1) foothold_down  -> task  (+gecici)
    2) remediated     -> task  (+kalici)
    3) regression     -> penalty (-kalici)
    4) foothold_established / probe_error -> None (puanlanmaz)
    5) ayni event_id iki kez -> ayni request_id (DB ON CONFLICT ile dedupe)
    6) haritada olmayan makine -> UnmappedMachine
    7) bozuk event_id -> BadEventId
    8) Cenk CHECK kurallari: task>0, penalty<0, reason bos degil & <=500

Calistir:  python test_bridge.py
"""
from __future__ import annotations

import sys

from bridge_cenk import (
    BadEventId,
    BridgeMap,
    Inject,
    UnmappedMachine,
    event_to_inject,
    process_rows,
)

ok = 0
fail = 0


def check(label: str, got, expected) -> None:
    global ok, fail
    if got == expected:
        ok += 1
        print(f"  [OK]   {label}")
    else:
        fail += 1
        print(f"  [FAIL] {label}: beklenen={expected!r} gelen={got!r}")


def expect_raises(label: str, exc, fn) -> None:
    global ok, fail
    try:
        fn()
    except exc:
        ok += 1
        print(f"  [OK]   {label}")
    except Exception as e:  # noqa: BLE001
        fail += 1
        print(f"  [FAIL] {label}: {exc.__name__} beklendi, {type(e).__name__} geldi")
    else:
        fail += 1
        print(f"  [FAIL] {label}: {exc.__name__} beklendi, istisna olmadi")


MAP = BridgeMap(
    server_id="11111111-1111-1111-1111-111111111111",
    actor_uuid="22222222-2222-2222-2222-222222222222",
    teams={"BT-1": 10, "BT-2": 20},
    points={"foothold_down": 5, "remediated": 25, "regression": 25},
    db={},
)


def ev(event_type: str, machine_id: str = "BT-1",
       event_id: str = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", **extra) -> dict:
    base = {
        "event_id": event_id,
        "machine_id": machine_id,
        "user_id": "ogrenci-1",
        "vector_type": "port",
        "event_type": event_type,
        "detail": "dinleyen port 10.0.0.5:4444 | tespit=nmap -p4444",
    }
    base.update(extra)
    return base


def main() -> int:
    print("Kopru ceviri testi")

    # 1) foothold_down -> task, +gecici
    i = event_to_inject(ev("foothold_down"), MAP)
    check("foothold_down.kind", i.kind, "task")
    check("foothold_down.points", i.points, 5.0)
    check("foothold_down.team", i.team_id, 10)
    check("foothold_down.points>0", i.points > 0, True)

    # 2) remediated -> task, +kalici
    i = event_to_inject(ev("remediated"), MAP)
    check("remediated.kind", i.kind, "task")
    check("remediated.points", i.points, 25.0)

    # 3) regression -> penalty, -kalici
    i = event_to_inject(ev("regression"), MAP)
    check("regression.kind", i.kind, "penalty")
    check("regression.points", i.points, -25.0)
    check("regression.points<0", i.points < 0, True)

    # 4) puanlanmayan olaylar
    check("foothold_established -> None", event_to_inject(ev("foothold_established"), MAP), None)
    check("probe_error -> None", event_to_inject(ev("probe_error"), MAP), None)
    check("bilinmeyen -> None", event_to_inject(ev("baska_sey"), MAP), None)

    # 5) idempotency: ayni event_id -> ayni request_id (kanonik UUID)
    a = event_to_inject(ev("remediated", event_id="deadbeefdeadbeefdeadbeefdeadbeef"), MAP)
    b = event_to_inject(ev("remediated", event_id="deadbeefdeadbeefdeadbeefdeadbeef"), MAP)
    check("ayni event -> ayni request_id", a.request_id, b.request_id)
    check("request_id kanonik UUID", a.request_id, "deadbeef-dead-beef-dead-beefdeadbeef")
    farkli = event_to_inject(ev("remediated", event_id="0000beefdeadbeefdeadbeefdeadbeef"), MAP)
    check("farkli event -> farkli request_id", a.request_id != farkli.request_id, True)

    # 6) haritada olmayan makine
    expect_raises("haritada yok -> UnmappedMachine", UnmappedMachine,
                  lambda: event_to_inject(ev("remediated", machine_id="BT-9"), MAP))

    # 7) bozuk event_id
    expect_raises("bozuk event_id -> BadEventId", BadEventId,
                  lambda: event_to_inject(ev("remediated", event_id="not-a-uuid"), MAP))

    # 8) reason: bos degil, <=500, etiket tasir
    i = event_to_inject(ev("foothold_down", detail="x" * 900), MAP)
    check("reason <=500", len(i.reason) <= 500, True)
    check("reason bos degil", len(i.reason.strip()) > 0, True)
    i2 = event_to_inject(ev("remediated", detail=""), MAP)
    check("detailsiz reason yine bos degil", len(i2.reason.strip()) > 0, True)

    # beacon vektoru de ayni sekilde
    i = event_to_inject(ev("foothold_down", vector_type="beacon"), MAP)
    check("beacon vektoru de islenir", i.kind, "task")

    # --- process_rows: hata izolasyonu (sahte writer, DB gerektirmez) ---

    class FakeWriter:
        """insert(inj)->bool. Belirli request_id'de patlar; dedupe'u taklit eder."""
        def __init__(self, raise_on=None, dup=None):
            self.raise_on = raise_on or set()
            self.dup = dup or set()
            self.written = []

        def insert(self, inj):
            if inj.request_id in self.raise_on:
                raise RuntimeError("sahte DB hatasi")
            if inj.request_id in self.dup:
                return False  # zaten vardi
            self.written.append(inj)
            return True

    rid = lambda hexid: str(__import__("uuid").UUID(hex=hexid))
    e_ok1 = ev("remediated", event_id="11111111111111111111111111111111")
    e_bad = ev("remediated", event_id="22222222222222222222222222222222")  # writer patlayacak
    e_ok2 = ev("remediated", event_id="33333333333333333333333333333333")
    e_unmapped = ev("remediated", machine_id="BT-9")  # haritada yok
    e_badid = ev("remediated", event_id="not-a-uuid")  # bozuk
    e_info = ev("foothold_established")  # puanlanmaz
    e_dup = ev("foothold_down", event_id="44444444444444444444444444444444")

    fw = FakeWriter(raise_on={rid("22222222222222222222222222222222")},
                    dup={rid("44444444444444444444444444444444")})
    written = process_rows([e_ok1, e_bad, e_ok2, e_unmapped, e_badid, e_info, e_dup], MAP, fw)
    check("hata izolasyonu: 2 yeni yazildi", written, 2)
    check("patlayan kayit atlandi, sonraki islendi",
          [i.request_id for i in fw.written],
          [rid("11111111111111111111111111111111"), rid("33333333333333333333333333333333")])
    check("dup/unmapped/badid/info yazilmadi", len(fw.written), 2)

    print(f"\nSonuc: {ok} OK, {fail} FAIL")
    return 1 if fail else 0


if __name__ == "__main__":
    sys.exit(main())
