"""Event sink — motor ile scorer/GUI arasindaki TEK temas noktasi.

Motor puani bilmez; sadece olgu yazar. Akis append-only JSONL:
her satir bir event. Scorer'i yazan kisi bu dosyayi tail'leyip tuketir,
event_id ile tekrari ayiklar (dedupe).
"""
from __future__ import annotations

import json
import os
import threading
from typing import Iterator

from core.models import Event


class EventSink:
    """Thread-safe, append-only JSONL yazici."""

    def __init__(self, path: str = "events.jsonl") -> None:
        self.path = path
        self._lock = threading.Lock()

    def emit(self, event: Event) -> None:
        line = json.dumps(event.to_dict(), ensure_ascii=False)
        with self._lock:
            with open(self.path, "a", encoding="utf-8") as f:
                f.write(line + "\n")
                f.flush()


def read_events(path: str = "events.jsonl", limit: int | None = None) -> list[dict]:
    """Akisi bastan okur. GUI acilisinda gecmisi doldurmak icin."""
    if not os.path.exists(path):
        return []
    rows: list[dict] = []
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                rows.append(json.loads(line))
            except json.JSONDecodeError:
                continue  # yarim yazilmis satiri atla
    if limit is not None:
        rows = rows[-limit:]
    return rows


def tail_events(path: str, offset: int) -> tuple[list[dict], int]:
    """`offset` baytindan sonrasini okur. GUI'nin canli takibi icin.

    Doner: (yeni event listesi, yeni offset)
    """
    if not os.path.exists(path):
        return [], offset
    size = os.path.getsize(path)
    if size < offset:      # dosya silinmis/donmus -> bastan
        offset = 0
    if size == offset:
        return [], offset
    rows: list[dict] = []
    with open(path, encoding="utf-8") as f:
        f.seek(offset)
        chunk = f.read()
        new_offset = offset + len(chunk.encode("utf-8"))
        for line in chunk.splitlines():
            line = line.strip()
            if not line:
                continue
            try:
                rows.append(json.loads(line))
            except json.JSONDecodeError:
                continue
    return rows, new_offset
