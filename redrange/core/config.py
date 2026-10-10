"""config.json okuma ve motoru kurma."""
from __future__ import annotations

import json
import os
from dataclasses import dataclass

from core.engine import Engine, Job
from core.events import EventSink
from core.models import Target
from core.sessions import PortInUseError, SessionManager
from core.vectors import BeaconVector, PortVector

DEFAULT_CONFIG = {
    "listen_host": "0.0.0.0",
    "listen_port": 9000,
    "tick_seconds": 3,
    "session_timeout": 20,
    "events_path": "events.jsonl",
    "targets": [],
}


@dataclass
class AppContext:
    """Uygulamanin tum canli parcalari tek yerde."""
    config: dict
    sessions: SessionManager
    sink: EventSink
    engine: Engine

    def start(self) -> None:
        self.sessions.start()
        self.engine.start()

    def stop(self) -> None:
        self.engine.stop()
        self.sessions.stop()


def load_config(path: str = "config.json") -> dict:
    cfg = dict(DEFAULT_CONFIG)
    if os.path.exists(path):
        with open(path, encoding="utf-8") as f:
            cfg.update(json.load(f))
    return cfg


def build_context(path: str = "config.json") -> AppContext:
    cfg = load_config(path)

    sessions = SessionManager(
        host=cfg["listen_host"],
        port=int(cfg["listen_port"]),
        timeout=float(cfg["session_timeout"]),
    )
    sink = EventSink(cfg["events_path"])

    jobs: list[Job] = []
    for t in cfg["targets"]:
        target = Target(
            machine_id=t["machine_id"],
            user_id=t["user_id"],
            host=t["host"],
            vuln_port=int(t["vuln_port"]),
            decoy_port=int(t["decoy_port"]),
        )
        # Her makine icin IKI vektor: biri nmap'le bulunur, biri log/netstat ile.
        jobs.append(Job(target, PortVector(f"port-{target.machine_id}")))
        jobs.append(Job(target, BeaconVector(sessions, f"beacon-{target.machine_id}")))

    engine = Engine(jobs, sink, tick_seconds=float(cfg["tick_seconds"]))
    return AppContext(cfg, sessions, sink, engine)
