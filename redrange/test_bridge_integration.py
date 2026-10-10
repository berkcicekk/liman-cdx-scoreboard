#!/usr/bin/env python3
"""Kopru ENTEGRASYON testi — gercek PostgreSQL gerektirir.

Red Range EventSink ile events.jsonl'a olay yazar, kopruyu (process_rows +
gercek CenkWriter) ona karsi calistirir ve ls_injects'te dogru kayitlarin
olustugunu + IDEMPOTENCY'yi (ikinci calistirma 0 yeni) kanitlar. Boylece
event_id->request_id esleme ve ON CONFLICT gercekten DB'de dogrulanir.

DB yoksa veya psycopg kurulu degilse TEMIZ SKIP eder (exit 0).

Baglanti env (libpq standardi), varsayilanlar parantezde:
    PGHOST (127.0.0.1)  PGPORT (5432)  PGDATABASE (postgres)
    PGUSER (postgres)   PGPASSWORD ()

Cenk'in tests/compose.yaml Postgres'ine karsi ornek:
    PGHOST=127.0.0.1 PGPORT=5432 PGDATABASE=liman PGUSER=liman \\
    PGPASSWORD=... python test_bridge_integration.py
"""
from __future__ import annotations

import os
import sys
import tempfile
import uuid
from pathlib import Path

from core.events import EventSink, read_events
from core.models import Event, EventType, VectorType

import bridge_cenk
from bridge_cenk import BridgeMap, process_rows


def skip(msg: str) -> int:
    print(f"[SKIP] {msg}")
    return 0


# Cenk ls_injects/ls_teams'in TEST icin yeterli alt kumesi — gercek kisitlarla
# (UNIQUE(server_id,request_id), kind/points/reason CHECK, FK) ki esleme ciktisi
# gercekten dogrulanmis olsun.
SCHEMA = """
CREATE TABLE IF NOT EXISTS ls_teams (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    server_id UUID NOT NULL,
    name VARCHAR(80) NOT NULL,
    UNIQUE (server_id, name),
    UNIQUE (id, server_id)
);
CREATE TABLE IF NOT EXISTS ls_injects (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    server_id UUID NOT NULL,
    team_id BIGINT NOT NULL,
    kind VARCHAR(7) NOT NULL CHECK (kind IN ('task','penalty')),
    points NUMERIC(12,2) NOT NULL CHECK (points <> 0),
    reason VARCHAR(500) NOT NULL CHECK (length(btrim(reason)) > 0),
    created_by UUID NOT NULL,
    request_id UUID NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    CHECK ((kind='task' AND points>0) OR (kind='penalty' AND points<0)),
    FOREIGN KEY (team_id, server_id) REFERENCES ls_teams(id, server_id),
    UNIQUE (server_id, request_id)
);
"""


def main() -> int:
    try:
        import psycopg
    except ImportError:
        return skip("psycopg kurulu degil (pip install -r requirements.txt)")

    conn_kwargs = dict(
        host=os.environ.get("PGHOST", "127.0.0.1"),
        port=int(os.environ.get("PGPORT", "5432")),
        dbname=os.environ.get("PGDATABASE", "postgres"),
        user=os.environ.get("PGUSER", "postgres"),
        password=os.environ.get("PGPASSWORD", ""),
    )
    try:
        admin = psycopg.connect(autocommit=True, connect_timeout=4, **conn_kwargs)
    except psycopg.OperationalError as e:
        return skip(f"PostgreSQL'e baglanilamadi: {e}")

    server_id = str(uuid.uuid4())   # bu kosu icin izole kapsam
    actor = str(uuid.uuid4())
    ok = True
    try:
        with admin.cursor() as cur:
            cur.execute(SCHEMA)
            # izole kapsam: bu server_id'ye ait onceki test kayitlarini temizle
            cur.execute("DELETE FROM ls_injects WHERE server_id=%s", (server_id,))
            cur.execute("DELETE FROM ls_teams WHERE server_id=%s", (server_id,))
            teams = {}
            for mid in ("BT-1", "BT-2"):
                cur.execute(
                    "INSERT INTO ls_teams(server_id,name) VALUES (%s,%s) RETURNING id",
                    (server_id, mid))
                teams[mid] = cur.fetchone()[0]

        m = BridgeMap(
            server_id=server_id,
            actor_uuid=actor,
            teams=teams,
            points={"foothold_down": 5, "remediated": 25, "regression": 25},
            db={**conn_kwargs, "password": conn_kwargs["password"]},
        )

        # olaylari EventSink ile gercek JSONL'a yaz
        tmp = Path(tempfile.mkdtemp()) / "events_it.jsonl"
        sink = EventSink(str(tmp))
        dup_id = uuid.uuid4().hex
        seq = [
            Event("BT-1", "o1", "port-BT-1", VectorType.PORT, EventType.FOOTHOLD_ESTABLISHED),  # bilgi
            Event("BT-1", "o1", "port-BT-1", VectorType.PORT, EventType.FOOTHOLD_DOWN),         # +5 task
            Event("BT-1", "o1", "port-BT-1", VectorType.PORT, EventType.REMEDIATED),            # +25 task
            Event("BT-2", "o2", "beacon-BT-2", VectorType.BEACON, EventType.REGRESSION),        # -25 penalty
            Event("BT-1", "o1", "port-BT-1", VectorType.PORT, EventType.PROBE_ERROR),           # yoksay
            Event("BT-1", "o1", "port-BT-1", VectorType.PORT, EventType.FOOTHOLD_DOWN, event_id=dup_id),
            Event("BT-1", "o1", "port-BT-1", VectorType.PORT, EventType.FOOTHOLD_DOWN, event_id=dup_id),  # AYNI id
            Event("BT-9", "o9", "port-BT-9", VectorType.PORT, EventType.REMEDIATED),            # haritada yok
        ]
        for e in seq:
            sink.emit(e)

        rows = read_events(str(tmp))
        writer = bridge_cenk.CenkWriter(m)

        written1 = process_rows(rows, m, writer)
        written2 = process_rows(rows, m, writer)  # ikinci kez: ON CONFLICT -> 0 yeni

        with admin.cursor() as cur:
            cur.execute("SELECT count(*) FROM ls_injects WHERE server_id=%s", (server_id,))
            total = cur.fetchone()[0]
            cur.execute("SELECT kind,sum(points) FROM ls_injects WHERE server_id=%s GROUP BY kind ORDER BY kind", (server_id,))
            by_kind = dict(cur.fetchall())

        def check(label, got, exp):
            nonlocal ok
            flag = "OK  " if got == exp else "FAIL"
            if got != exp:
                ok = False
            print(f"  [{flag}] {label}: beklenen={exp} gelen={got}")

        print("Kopru entegrasyon testi")
        # foothold_down(+5) + remediated(+25) + regression(-25) + dup foothold_down(+5, tek)
        check("ilk kosu 4 yeni inject", written1, 4)
        check("ikinci kosu 0 yeni (idempotent)", written2, 0)
        check("toplam satir 4", total, 4)
        check("task toplami +35", float(by_kind.get("task", 0)), 35.0)
        check("penalty toplami -25", float(by_kind.get("penalty", 0)), -25.0)

    finally:
        with admin.cursor() as cur:
            cur.execute("DELETE FROM ls_injects WHERE server_id=%s", (server_id,))
            cur.execute("DELETE FROM ls_teams WHERE server_id=%s", (server_id,))
        admin.close()

    print(f"\nSonuc: {'GECTI' if ok else 'BASARISIZ'}")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
