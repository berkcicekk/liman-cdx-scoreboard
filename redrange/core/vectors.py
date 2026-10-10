"""Saldiri vektorleri — motorun sordugu uc soruyu cevaplayan eklentiler.

Motor bu arayuzu cagirir, icini bilmez:

    attempt_exploit(target)    -> alttaki YANLIS CONFIG hala duruyor mu?
    establish_foothold(target) -> foothold'un geri gelmesi icin gereken sey
    is_foothold_alive(target)  -> foothold su an canli mi?

TASARIM NOTU (onemli):
Bu bir saldiri araci degil, bir EGITIM POLIGONU kontrolcusudur. Motor hedefe
payload GONDERMEZ ve komut CALISTIRMAZ. Foothold artefaktlari (beacon ajani ve
decoy dinleyici) poligon imajina kurulum sirasinda elle yerlestirilir; systemd
onlari ayakta tutar. Motorun isi sadece OLCMEK: foothold duruyor mu, zafiyet
kapandi mi. Bu yuzden establish_foothold() bir sey "kurmaz", sadece gozlemi
kaydeder.
"""
from __future__ import annotations

from abc import ABC, abstractmethod

from core.models import Target, VectorType
from core.probes import tcp_probe
from core.sessions import SessionManager


class AttackVector(ABC):
    id: str
    vector_type: VectorType

    @abstractmethod
    def attempt_exploit(self, target: Target) -> bool:
        """Zafiyet (yanlis config) hala acik mi? Acik ise foothold geri gelebilir."""

    @abstractmethod
    def establish_foothold(self, target: Target) -> None:
        """Foothold'un yeniden kuruldugunu isaretle (motor payload gondermez)."""

    @abstractmethod
    def is_foothold_alive(self, target: Target) -> bool:
        """Foothold su an canli mi?"""

    def detail_for(self, target: Target) -> str:
        """Olaya eklenecek koken bilgisi (nereden/nasil). Zorunlu degil."""
        return ""


class BeaconVector(AttackVector):
    """REVERSE tarz foothold: hedef bize geri baglanir.

    Savunanin tespit yolu: hedefte `ss -tnp` / `netstat` ile GIDEN baglanti,
    ve calisan servis. Port taramasiyla GORUNMEZ.
    """
    vector_type = VectorType.BEACON

    def __init__(self, sessions: SessionManager, vector_id: str = "beacon") -> None:
        self.id = vector_id
        self.sessions = sessions

    def attempt_exploit(self, target: Target) -> bool:
        # Zafiyet = acik birakilmis servis portu. Acik oldugu surece
        # foothold yeniden kurulabilir sayilir.
        return tcp_probe(target.host, target.vuln_port)

    def establish_foothold(self, target: Target) -> None:
        return None  # beacon systemd tarafindan geri getirilir

    def is_foothold_alive(self, target: Target) -> bool:
        return self.sessions.is_alive(target.machine_id)

    def detail_for(self, target: Target) -> str:
        for s in self.sessions.snapshot():
            if s["machine_id"] == target.machine_id:
                return (f"giden baglanti {s['peer']} | kalp={s['beats']} "
                        f"| sure={s['age']}s | tespit=ss -tnp")
        return "giden baglanti yok | tespit=ss -tnp"


class PortVector(AttackVector):
    """BIND tarz foothold: hedef bir portu dinler.

    Savunanin tespit yolu: `nmap -p- <ip>` ile beklenmeyen acik port.
    """
    vector_type = VectorType.PORT

    def __init__(self, vector_id: str = "port") -> None:
        self.id = vector_id

    def attempt_exploit(self, target: Target) -> bool:
        return tcp_probe(target.host, target.vuln_port)

    def establish_foothold(self, target: Target) -> None:
        return None

    def is_foothold_alive(self, target: Target) -> bool:
        return tcp_probe(target.host, target.decoy_port)

    def detail_for(self, target: Target) -> str:
        return (f"dinleyen port {target.host}:{target.decoy_port} "
                f"| tespit=nmap -p{target.decoy_port}")


class ScriptedVector(AttackVector):
    """Gercek makine olmadan motoru denemek icin kontrol edilebilir sahte vektor."""
    vector_type = VectorType.PORT

    def __init__(self, vector_id: str = "scripted") -> None:
        self.id = vector_id
        self.can_exploit = True
        self.foothold_alive = True

    def attempt_exploit(self, target: Target) -> bool:
        return self.can_exploit

    def establish_foothold(self, target: Target) -> None:
        self.foothold_alive = True

    def is_foothold_alive(self, target: Target) -> bool:
        return self.foothold_alive
