#!/usr/bin/env python3
"""Red Range -> Cenk koprusu (scorer).

Motor puani bilmez; sadece `events.jsonl`'a olgu yazar (README/ALGORITHM).
Bu kopru o akisi tail'ler ve her gecisi Cenk Scoreboard'un `ls_injects`
defterine cevirir. Iki sistem de append-only, idempotent, puani-bilmeyen
birer defterdir; aralarindaki TEK bag event sozlesmesidir.

Tasarim:
  * Saf ceviri (`event_to_inject`) ile DB yazimi (`CenkWriter`) AYRIKTIR;
    ceviri psycopg olmadan calisir ve test edilebilir.
  * Idempotency: event_id (uuid4 hex) -> request_id (kanonik UUID). Yazim
    `ON CONFLICT (server_id,request_id) DO NOTHING` ile; ayni event iki kez
    gelse Cenk ikinciyi sessizce yutar. Red Range'in at-least-once sozlesmesi
    Cenk'in dedupe sozlesmesiyle birebir ortusur.
  * Bir makine haritada yoksa veya event_id bozuksa: uyar, atla, DEVAM ET.
    Bir kaydin hatasi akisi durdurmaz (motorun "job izolasyonu" ilkesi).

Calistir:
    python bridge_cenk.py --map bridge_map.json
    python bridge_cenk.py --map bridge_map.demo.json --config config_demo.json --once
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import time
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

from core.config import load_config
from core.events import tail_events

# --- esleme sozlesmesi -------------------------------------------------------

# Yalnizca bu olaylar puanlanir. foothold_established (bilgi) ve probe_error
# (ag hatasi) Cenk'e yansitilmaz.
KIND = {
    "foothold_down": "task",      # GECICI: yuzey temizligi
    "remediated": "task",         # KALICI: kok neden kapatildi
    "regression": "penalty",      # duzeltme geri alindi -> telafi (negatif)
}
LABEL = {
    "foothold_down": "Foothold dustu (gecici)",
    "remediated": "Zafiyet kapatildi - kok neden (kalici)",
    "regression": "Duzeltme geri alindi",
}


class UnmappedMachine(Exception):
    """Event'teki machine_id bridge_map.teams icinde yok."""


class BadEventId(Exception):
    """event_id gecerli bir uuid4 hex degil; request_id uretilemez."""


@dataclass(frozen=True)
class Inject:
    """Cenk ls_injects'e yazilacak tek kayit."""
    server_id: str
    team_id: int
    kind: str
    points: float
    reason: str
    created_by: str
    request_id: str


@dataclass
class BridgeMap:
    """Red Range kimliklerini Cenk kimliklerine baglayan sozlesme."""
    server_id: str
    actor_uuid: str
    teams: dict[str, int]
    points: dict[str, float]
    db: dict

    @classmethod
    def load(cls, path: str) -> "BridgeMap":
        with open(path, encoding="utf-8") as f:
            raw = json.load(f)
        for key in ("server_id", "actor_uuid", "teams", "points"):
            if key not in raw:
                raise ValueError(f"bridge_map eksik alan: {key}")
        return cls(
            server_id=str(raw["server_id"]),
            actor_uuid=str(raw["actor_uuid"]),
            teams={str(k): int(v) for k, v in raw["teams"].items()},
            points={str(k): float(v) for k, v in raw["points"].items()},
            db=raw.get("database", {}),
        )


def _reason(event_type: str, ev: dict) -> str:
    """Inject gerekcesi. Cenk CHECK: bos olamaz, <=500 karakter."""
    vector_type = ev.get("vector_type", "?")
    detail = str(ev.get("detail", "")).strip()
    reason = f"{LABEL[event_type]} | {vector_type}"
    if detail:
        reason += f" | {detail}"
    return reason[:500]


def event_to_inject(ev: dict, m: BridgeMap) -> Optional[Inject]:
    """Tek event -> tek Inject (ya da puanlanmiyorsa None). SAF fonksiyon.

    Yukseltir:
        UnmappedMachine - machine_id haritada yok
        BadEventId      - event_id'den UUID uretilemiyor
    """
    event_type = ev.get("event_type")
    if event_type not in KIND:
        return None  # foothold_established / probe_error / bilinmeyen -> yoksay

    machine_id = str(ev.get("machine_id", ""))
    team_id = m.teams.get(machine_id)
    if team_id is None:
        raise UnmappedMachine(machine_id)

    kind = KIND[event_type]
    base = abs(m.points.get(event_type, 0.0))
    # Cenk CHECK: task -> points>0, penalty -> points<0, ayrica points<>0.
    points = round(-base if kind == "penalty" else base, 2)
    if points == 0:
        return None  # 0 puanli inject'i Cenk reddeder; sessizce atla

    event_id = ev.get("event_id", "")
    try:
        request_id = str(uuid.UUID(hex=str(event_id)))
    except (ValueError, AttributeError, TypeError):
        raise BadEventId(event_id)

    return Inject(
        server_id=m.server_id,
        team_id=team_id,
        kind=kind,
        points=points,
        reason=_reason(event_type, ev),
        created_by=m.actor_uuid,
        request_id=request_id,
    )


# --- DB yazimi (psycopg yalnizca burada import edilir) -----------------------

INSERT_SQL = (
    "INSERT INTO ls_injects(server_id,team_id,kind,points,reason,created_by,request_id) "
    "VALUES (%s,%s,%s,%s,%s,%s,%s) "
    "ON CONFLICT (server_id,request_id) DO NOTHING"
)


class CenkWriter:
    """ls_injects'e idempotent INSERT. ls_scoreboard rolu yeterli.

    autocommit=True: her INSERT bagimsiz commit'lenir; bir kaydin veri hatasi
    (orn. FK ihlali) islemi zehirlemez, sonraki kayitlar etkilenmez. Baglanti
    koparsa (OperationalError) insert() bir kez yeniden baglanip tekrar dener.
    """

    def __init__(self, m: BridgeMap) -> None:
        import psycopg  # lazy: ceviri ve testler psycopg olmadan calisir

        self._psycopg = psycopg
        db = m.db
        password = ""
        if db.get("password_env"):
            password = os.environ.get(db["password_env"], "")
            if not password:
                raise RuntimeError(
                    f"Ortam degiskeni bos: {db['password_env']} (DB parolasi)"
                )
        elif db.get("password"):
            password = str(db["password"])  # tercih edilmez; env kullanin

        self._kwargs = dict(
            host=db.get("host", "127.0.0.1"),
            port=int(db.get("port", 5432)),
            dbname=db["dbname"],
            user=db["user"],
            password=password,
            autocommit=True,
        )
        self.conn = self._psycopg.connect(**self._kwargs)

    def _reconnect(self) -> None:
        self.close()
        self.conn = self._psycopg.connect(**self._kwargs)

    def _do_insert(self, inj: Inject) -> bool:
        with self.conn.cursor() as cur:
            cur.execute(
                INSERT_SQL,
                (inj.server_id, inj.team_id, inj.kind, inj.points,
                 inj.reason, inj.created_by, inj.request_id),
            )
            return cur.rowcount == 1

    def insert(self, inj: Inject) -> bool:
        """Doner: True = yeni yazildi, False = zaten vardi (dedupe).

        Baglanti koptuysa bir kez yeniden baglanip tekrar dener. Veri hatasi
        (FK, kisit vb.) oldugu gibi yukari firlar; cagiran yeri izole eder.
        """
        try:
            return self._do_insert(inj)
        except self._psycopg.OperationalError:
            self._reconnect()
            return self._do_insert(inj)

    def close(self) -> None:
        try:
            self.conn.close()
        except Exception:
            pass


# --- offset kaliciligi -------------------------------------------------------

def _read_offset(path: str) -> int:
    try:
        return int(Path(path).read_text().strip())
    except (FileNotFoundError, ValueError):
        return 0


def _write_offset(path: str, offset: int) -> None:
    Path(path).write_text(str(offset))


# --- ceviri + yazma dongusu --------------------------------------------------

def process_rows(rows: list[dict], m: BridgeMap, writer) -> int:
    """Bir grup event'i isle; yazilan YENI inject sayisini dondur.

    Her event kendi izolasyonundadir: haritada olmayan makine, bozuk event_id
    veya tek bir DB yazim hatasi akisi DURDURMAZ (motorun job-izolasyonu ilkesi).
    `writer` yalnizca .insert(inj)->bool uygular; testte sahte writer verilebilir.
    """
    written = 0
    for ev in rows:
        try:
            inj = event_to_inject(ev, m)
        except UnmappedMachine as e:
            print(f"[atla] haritada yok: machine_id={e} event={ev.get('event_type')}")
            continue
        except BadEventId as e:
            print(f"[atla] bozuk event_id={e!r}")
            continue
        if inj is None:
            continue
        try:
            inserted = writer.insert(inj)
        except Exception as e:  # noqa: BLE001 - tek kaydin hatasi akisi durdurmaz
            print(f"[hata] yazilamadi team={inj.team_id} req={inj.request_id}: "
                  f"{type(e).__name__}: {e}")
            continue
        if inserted:
            written += 1
            print(f"[yaz]  team={inj.team_id} {inj.kind} {inj.points:+g} "
                  f"req={inj.request_id} | {inj.reason}")
        else:
            print(f"[dup]  req={inj.request_id} zaten vardi")
    return written


# --- ana dongu ---------------------------------------------------------------

def run(config_path: str, map_path: str, once: bool = False,
        poll_seconds: Optional[float] = None, offset_path: Optional[str] = None) -> int:
    cfg = load_config(config_path)
    events_path = cfg["events_path"]
    if poll_seconds is None:
        poll_seconds = max(1.0, float(cfg.get("tick_seconds", 3)))

    m = BridgeMap.load(map_path)
    offset_path = offset_path or (events_path + ".bridge_offset")
    offset = _read_offset(offset_path)

    writer = CenkWriter(m)
    print(f"[kopru] events={events_path} offset={offset} takim_sayisi={len(m.teams)}")
    written = 0
    try:
        while True:
            rows, offset = tail_events(events_path, offset)
            written += process_rows(rows, m, writer)
            _write_offset(offset_path, offset)
            if once:
                break
            time.sleep(poll_seconds)
    except KeyboardInterrupt:
        print("\n[kopru] durduruldu")
    finally:
        writer.close()
    return written


def main(argv: Optional[list[str]] = None) -> int:
    ap = argparse.ArgumentParser(description="Red Range -> Cenk kopru (scorer)")
    ap.add_argument("--config", default="config.json", help="Red Range config.json")
    ap.add_argument("--map", default="bridge_map.json", help="esleme sozlesmesi")
    ap.add_argument("--once", action="store_true", help="bir tur isle ve cik")
    ap.add_argument("--poll", type=float, default=None, help="tail bekleme saniyesi")
    ap.add_argument("--offset-file", default=None, help="offset dosyasi yolu")
    args = ap.parse_args(argv)
    run(args.config, args.map, once=args.once,
        poll_seconds=args.poll, offset_path=args.offset_file)
    return 0


if __name__ == "__main__":
    sys.exit(main())
