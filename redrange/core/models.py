"""Domain model — motor ile arayuz/scorer arasindaki ortak tipler.

Bu dosyada PUAN mantigi ve ARAYUZ kodu YOKTUR. Sadece "ne oldu" bilgisini
tasiyan veri tipleri var. core/ katmani ui/ katmanindan hicbir sey import etmez.
"""
from __future__ import annotations

import time
import uuid
from dataclasses import dataclass, field
from enum import Enum


class VectorType(str, Enum):
    """Foothold'un ag yonu — tespit yontemini belirler."""
    BEACON = "beacon"  # hedef DISARI baglanir  -> netstat/log ile bulunur
    PORT = "port"      # hedef bir portu DINLER -> nmap ile bulunur


class VectorState(str, Enum):
    PENDING = "pending"        # henuz foothold gorulmedi
    UP = "up"                  # foothold canli
    DOWN = "down"              # foothold dustu, zafiyet durumu henuz belirsiz
    REMEDIATED = "remediated"  # zafiyet kapatildi, geri gelemiyor


class EventType(str, Enum):
    FOOTHOLD_ESTABLISHED = "foothold_established"  # BILGI  (puanlanmaz)
    FOOTHOLD_DOWN = "foothold_down"                # GECICI puan sinyali
    REMEDIATED = "remediated"                      # KALICI puan sinyali
    REGRESSION = "regression"                      # duzeltme geri alindi
    PROBE_ERROR = "probe_error"                    # olcum yapilamadi (ag hatasi)


@dataclass(frozen=True)
class Target:
    """Bir (kullanici, makine) cifti — yarismadaki tek bir savunan ve kutusu."""
    machine_id: str
    user_id: str
    host: str
    vuln_port: int    # kapatilmasi gereken "acik" servis  -> KALICI puan burada
    decoy_port: int   # hedefte dinleyen foothold portu     -> GECICI puan burada


@dataclass
class Event:
    """Motorun urettigi tek cikti birimi. Scorer ve GUI bunu tuketir."""
    machine_id: str
    user_id: str
    vector_id: str
    vector_type: VectorType
    event_type: EventType
    detail: str = ""
    # Dikkat: default_factory, fonksiyonu HER nesnede yeniden cagirir.
    # Duz "= time.time()" yazilsaydi tum event'ler ayni damgayi tasirdi.
    ts: float = field(default_factory=time.time)
    event_id: str = field(default_factory=lambda: uuid.uuid4().hex)

    def to_dict(self) -> dict:
        return {
            "event_id": self.event_id,
            "ts": self.ts,
            "machine_id": self.machine_id,
            "user_id": self.user_id,
            "vector_id": self.vector_id,
            "vector_type": self.vector_type.value,
            "event_type": self.event_type.value,
            "detail": self.detail,
        }
