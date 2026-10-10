"""Session Manager — oturum kayit defteri + baglanti kokeni (provenance).

TEK bir dinleyici, N makineden gelen geri-baglantilari kabul eder ve her
makineyi bir "session" olarak kayit defterinde tutar. Ayrica baglantinin
NEREDEN geldigini (peer IP:port), ne zamandir ayakta oldugunu ve kac kalp
atisi gonderdigini saklar — GUI bunlari gosterir.

Protokol (satir tabanli JSON):
    1) Ajan baglanir:  {"machine_id": "BT-1"}\\n
    2) Kalp atisi:     {"hb": 1}\\n

Sunucu gelen satirlari SADECE canlilik isareti olarak okur; hicbir komut
gondermez/calistirmaz. Bu bir komut kanali degil, tespit mekanizmasidir.

NOT: Kimligini bildirmeyen ham baglantilar da (or. duz `nc <ip> 9000`)
kaydedilir — "unknown" olarak isaretlenir ve GUI'de gorunur. Boylece
beklenmeyen her baglanti goze carpar.
"""
from __future__ import annotations

import hmac
import json
import socket
import threading
import time
from dataclasses import dataclass, field


class PortInUseError(RuntimeError):
    """Dinleyici portu baska bir surec tarafindan tutuluyor."""


@dataclass
class Session:
    machine_id: str
    peer_ip: str
    peer_port: int
    connected_at: float = field(default_factory=time.time)
    last_seen: float = field(default_factory=time.time)
    beats: int = 0
    identified: bool = True       # kimligini JSON ile bildirdi mi?

    @property
    def peer(self) -> str:
        return f"{self.peer_ip}:{self.peer_port}"


class SessionManager:
    def __init__(self, host: str = "0.0.0.0", port: int = 9000,
                 timeout: float = 20.0, verbose: bool = True,
                 tokens: dict[str, str] | None = None) -> None:
        self.host = host
        self.port = port
        self.timeout = timeout
        self.verbose = verbose
        # machine_id -> gizli token. Bos/None ise kimlik dogrulamasi KAPALI
        # (yerel demo/selftest icin). Doluysa beacon, claim ettigi machine_id
        # icin eslesen token'i gondermek ZORUNDA; aksi halde baglanti reddedilir.
        # Boylece bir katilimci baska bir takimin machine_id'sini taklit edemez.
        self.tokens = {str(k): str(v) for k, v in (tokens or {}).items()}
        self._sessions: dict[str, Session] = {}
        self._lock = threading.Lock()
        self._srv: socket.socket | None = None
        self._running = False
        self.total_connections = 0

    # ---------- yasam dongusu ----------

    def start(self) -> None:
        if self._running:
            return
        self._srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            self._srv.bind((self.host, self.port))
        except OSError as exc:
            self._srv.close()
            self._srv = None
            if exc.errno == 98:          # EADDRINUSE
                raise PortInUseError(self.port) from None
            raise
        self._srv.listen(64)
        self._srv.settimeout(1.0)
        self._running = True
        threading.Thread(target=self._accept_loop, name="session-accept",
                         daemon=True).start()
        self._log(f"dinleyici acik {self.host}:{self.port}")

    def stop(self) -> None:
        self._running = False
        if self._srv is not None:
            try:
                self._srv.close()
            except OSError:
                pass
            self._srv = None

    def _log(self, msg: str) -> None:
        if self.verbose:
            print(f"[sessions] {msg}", flush=True)

    # ---------- dinleyici ----------

    def _accept_loop(self) -> None:
        while self._running and self._srv is not None:
            try:
                conn, addr = self._srv.accept()
            except socket.timeout:
                continue
            except OSError:
                break
            with self._lock:
                self.total_connections += 1
            threading.Thread(target=self._handle, args=(conn, addr),
                             name=f"session-{addr[0]}", daemon=True).start()

    def _handle(self, conn: socket.socket, addr) -> None:
        peer_ip, peer_port = addr[0], addr[1]
        key: str | None = None
        conn.settimeout(self.timeout)
        buf = b""
        try:
            while self._running:
                data = conn.recv(4096)
                if not data:
                    break
                buf += data
                while b"\n" in buf:
                    raw, buf = buf.split(b"\n", 1)
                    line = raw.decode("utf-8", errors="replace").strip()
                    if not line:
                        continue
                    try:
                        msg = json.loads(line)
                        parsed = isinstance(msg, dict)
                    except json.JSONDecodeError:
                        msg, parsed = {}, False

                    if key is None:
                        mid = str(msg.get("machine_id", "")) if parsed else ""
                        if self.tokens:
                            # Kimlik dogrulamasi zorunlu: taklidi engelle.
                            tok = str(msg.get("token", "")) if parsed else ""
                            expected = self.tokens.get(mid)
                            if not mid or expected is None or not hmac.compare_digest(tok, expected):
                                self._log(
                                    f"RED       token/kimlik gecersiz <- {peer_ip}:{peer_port}"
                                    f"  (machine_id={mid!r})")
                                return  # kaydetme, baglantiyi kapat
                        identified = bool(mid)
                        key = mid or f"unknown@{peer_ip}:{peer_port}"
                        with self._lock:
                            self._sessions[key] = Session(
                                machine_id=key, peer_ip=peer_ip,
                                peer_port=peer_port, identified=identified)
                        self._log(
                            f"BAGLANDI  {key}  <- {peer_ip}:{peer_port}"
                            + ("" if identified else "   [KIMLIKSIZ]"))
                    else:
                        with self._lock:
                            s = self._sessions.get(key)
                            if s is not None:
                                s.last_seen = time.time()
                                s.beats += 1
        except (socket.timeout, OSError):
            pass
        finally:
            try:
                conn.close()
            except OSError:
                pass
            if key is not None:
                with self._lock:
                    self._sessions.pop(key, None)
                self._log(f"KOPTU     {key}  <- {peer_ip}:{peer_port}")

    # ---------- sorgular ----------

    def is_alive(self, machine_id: str) -> bool:
        with self._lock:
            s = self._sessions.get(machine_id)
            if s is None:
                return False
            return (time.time() - s.last_seen) <= self.timeout

    def snapshot(self) -> list[dict]:
        now = time.time()
        with self._lock:
            return [
                {
                    "machine_id": s.machine_id,
                    "peer": s.peer,
                    "peer_ip": s.peer_ip,
                    "beats": s.beats,
                    "age": round(now - s.connected_at, 1),
                    "silent_for": round(now - s.last_seen, 1),
                    "identified": s.identified,
                }
                for s in sorted(self._sessions.values(),
                                key=lambda x: x.connected_at)
            ]

    def live_ids(self) -> set[str]:
        with self._lock:
            return set(self._sessions.keys())
