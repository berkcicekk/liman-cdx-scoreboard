"""Motorun kalbi — her (hedef, vektor) icin ayni kucuk durum makinesi.

    PENDING ──foothold gorundu──> UP
       UP   ──foothold dustu────> DOWN            [GECICI puan]
      DOWN  ──zafiyet hala acik─> UP              (sadece yuzey temizligi)
      DOWN  ──zafiyet kapandi───> REMEDIATED      [KALICI puan]
  REMEDIATED ─zafiyet geri acildi> UP             [REGRESSION]

Kritik ayrinti: KALICI puan, foothold dustugu ANDA verilemez — cunku o anda
"portu mu kapatti, aciklari mi kapatti" ayirt edilemez. Ancak bir sonraki
tik'te zafiyet probu BASARISIZ olunca verilir. Bu gecikme bilerek boyledir.
"""
from __future__ import annotations

import threading
import time
from dataclasses import dataclass, field

from core.events import EventSink
from core.models import Event, EventType, Target, VectorState
from core.vectors import AttackVector


@dataclass
class VectorRuntime:
    state: VectorState = VectorState.PENDING
    last_change: float = field(default_factory=time.time)
    temporary_points: int = 0   # kac kez foothold dusuruldu
    permanent_points: int = 0   # kac kez zafiyet kapatildi
    last_error: str = ""


def tick_vector(vector: AttackVector, target: Target,
                rt: VectorRuntime) -> EventType | None:
    """Tek bir tik. Durumu ilerletir, olan biteni EventType olarak dondurur."""
    st = rt.state

    if st == VectorState.PENDING:
        if vector.is_foothold_alive(target):
            _to(rt, VectorState.UP)
            return EventType.FOOTHOLD_ESTABLISHED
        return None

    if st == VectorState.UP:
        if not vector.is_foothold_alive(target):
            _to(rt, VectorState.DOWN)
            rt.temporary_points += 1
            return EventType.FOOTHOLD_DOWN
        return None

    if st == VectorState.DOWN:
        # Kritik catal: zafiyet hala acik mi?
        if vector.attempt_exploit(target):
            if vector.is_foothold_alive(target):
                vector.establish_foothold(target)
                _to(rt, VectorState.UP)
                return EventType.FOOTHOLD_ESTABLISHED
            return None                      # acik ama foothold henuz donmedi
        _to(rt, VectorState.REMEDIATED)
        rt.permanent_points += 1
        return EventType.REMEDIATED

    if st == VectorState.REMEDIATED:
        # Duzeltme geri alindi mi?
        if vector.attempt_exploit(target):
            _to(rt, VectorState.UP if vector.is_foothold_alive(target)
                else VectorState.DOWN)
            rt.permanent_points = max(0, rt.permanent_points - 1)
            return EventType.REGRESSION
        return None

    return None


def _to(rt: VectorRuntime, new_state: VectorState) -> None:
    rt.state = new_state
    rt.last_change = time.time()


@dataclass
class Job:
    target: Target
    vector: AttackVector
    runtime: VectorRuntime = field(default_factory=VectorRuntime)


class Engine:
    """Arka planda donen servis. Arayuzden TAMAMEN bagimsizdir."""

    def __init__(self, jobs: list[Job], sink: EventSink,
                 tick_seconds: float = 3.0) -> None:
        self.jobs = jobs
        self.sink = sink
        self.tick_seconds = tick_seconds
        self._running = False
        self._thread: threading.Thread | None = None
        self._lock = threading.Lock()
        self.ticks = 0

    def start(self) -> None:
        if self._running:
            return
        self._running = True
        self._thread = threading.Thread(target=self._loop, name="engine", daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._running = False

    @property
    def running(self) -> bool:
        return self._running

    def _loop(self) -> None:
        while self._running:
            self.tick_once()
            # tick_seconds boyunca uyu ama stop()'a hizli cevap ver
            slept = 0.0
            while self._running and slept < self.tick_seconds:
                time.sleep(0.2)
                slept += 0.2

    def tick_once(self) -> None:
        with self._lock:
            self.ticks += 1
        for job in self.jobs:
            try:
                et = tick_vector(job.vector, job.target, job.runtime)
                job.runtime.last_error = ""
            except Exception as exc:                      # ag hatasi vb.
                job.runtime.last_error = f"{type(exc).__name__}: {exc}"
                self.sink.emit(Event(
                    machine_id=job.target.machine_id,
                    user_id=job.target.user_id,
                    vector_id=job.vector.id,
                    vector_type=job.vector.vector_type,
                    event_type=EventType.PROBE_ERROR,
                    detail=job.runtime.last_error,
                ))
                continue
            if et is not None:
                try:
                    detail = job.vector.detail_for(job.target)
                except Exception:
                    detail = ""
                self.sink.emit(Event(
                    machine_id=job.target.machine_id,
                    user_id=job.target.user_id,
                    vector_id=job.vector.id,
                    vector_type=job.vector.vector_type,
                    event_type=et,
                    detail=detail,
                ))

    def snapshot(self) -> list[dict]:
        """GUI'nin okudugu anlik durum tablosu."""
        rows = []
        for job in self.jobs:
            rt = job.runtime
            rows.append({
                "machine_id": job.target.machine_id,
                "user_id": job.target.user_id,
                "host": job.target.host,
                "vector_id": job.vector.id,
                "vector_type": job.vector.vector_type.value,
                "state": rt.state.value,
                "since": round(time.time() - rt.last_change),
                "temporary": rt.temporary_points,
                "permanent": rt.permanent_points,
                "error": rt.last_error,
            })
        return rows
