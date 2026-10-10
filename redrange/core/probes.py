"""Ag olcumleri — hepsi ZARARSIZ, salt-okunur kontrollerdir.

Burada komut calistirma, kimlik denemesi veya payload gonderme YOKTUR.
Tek yaptigimiz "su portta biri dinliyor mu" sorusunu sormak; tipki `nmap -p`
ya da bir saglik kontrolu (health check) gibi.
"""
from __future__ import annotations

import socket


def tcp_probe(host: str, port: int, timeout: float = 2.0) -> bool:
    """TCP baglanti kurulabiliyor mu?

    True  -> port acik (biri dinliyor)
    False -> reddedildi / zaman asimi / ulasilamadi
    """
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.settimeout(timeout)
        try:
            s.connect((host, port))
            return True
        except OSError:
            return False


def host_reachable(host: str, port: int, timeout: float = 2.0) -> bool:
    """Makineye hic ulasilabiliyor mu (ag seviyesinde)?

    ICMP/ping yerine TCP kullaniyoruz: ping cogu zaman guvenlik duvarinda
    kapalidir ve zaten 'foothold canli mi' sorusunu cevaplamaz.
    Burada sadece 'makine ayakta mi' ayrimini yapmak icin kullanilir.
    """
    return tcp_probe(host, port, timeout)
