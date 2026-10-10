# Pardus Hedef Makineleri — BT-1 … BT-4 Kurulumu

Artık elle uğraşmıyorsun: her makine için **tek bir self-contained bash script** var.
Ajanlar script'in içine gömülü, ayrıca dosya kopyalamana gerek yok.

> **Önce:** Makineleri **izole bir ağa** al (VMware host-only / internal, Proxmox'ta ayrı
> VLAN). İnternete ve kurum ağına kapalı olsun. Kuruluma başlamadan **snapshot al.**
> Script makineyi kasten zayıflatır; geri dönüş yolun snapshot'tır.

---

## 1. Kurulum (makine başına tek komut)

Kontrolcüden (Kali) her hedefe scriptini gönder:

```bash
scp scripts/setup_BT-1.sh kullanici@<BT1_IP>:/tmp/
scp scripts/setup_BT-2.sh kullanici@<BT2_IP>:/tmp/
scp scripts/setup_BT-3.sh kullanici@<BT3_IP>:/tmp/
scp scripts/setup_BT-4.sh kullanici@<BT4_IP>:/tmp/
```

Her Pardus makinesinde — **argüman: Kali'nin IP'si**:

```bash
chmod +x /tmp/setup_BT-1.sh
sudo /tmp/setup_BT-1.sh 192.168.1.10      # <-- kendi Kali IP'ni yaz
```

Script şunları yapar:

| Adım | Ne kurulur | Port |
|---|---|---|
| 1 | `weak_service` — kimlik doğrulamasız iç servis (**asıl zafiyet**) | 8081 |
| 2 | `decoy_listener` — dinleyen port artefaktı | makineye göre |
| 3 | `beacon` — dışarı bağlanan kalp-atışı ajanı | Kali:9000 |
| 4 | SSH gevşetme, 777 dizin, yazılabilir cron, gevşek sudo, firewall kapalı | — |

Üç servis de `Restart=always` ile kurulur — bu **kasıtlı**: savunan sadece process'i
öldürürse artefakt geri gelir, kalıcı susturmak için `systemctl disable --now` gerekir.

**Port dağılımı:**

| Makine | decoy_port | vuln_port |
|---|---|---|
| BT-1 | 4444 | 8081 |
| BT-2 | 4555 | 8081 |
| BT-3 | 5666 | 8081 |
| BT-4 | 7777 | 8081 |

Decoy portları kasten farklı — savunanın gerçekten `nmap -p-` çekmesi gerekiyor.

---

## 2. Kontrolcü tarafı: config.json

Script bitince ekrana o makine için hazır JSON satırını basar. `config.json`'daki
`SET_ME` değerlerini gerçek IP'lerle değiştir:

```json
{ "machine_id": "BT-1", "user_id": "ogrenci-1", "host": "192.168.1.51",
  "vuln_port": 8081, "decoy_port": 4444 }
```

> ⚠️ **En sık hata bu.** `host` alanı `SET_ME` kalırsa her şey **BEKLIYOR** görünür.
> `machine_id` ile beacon'ın `--id` değeri **birebir aynı** olmalı (`BT-1` ≠ `pardus-01`).

Doğrula:

```bash
python diagnose.py
```

---

## 3. Kendi dinleyicini kullanmak

Motor, decoy portunda **kim dinliyorsa** onu foothold sayar. Kendi netcat'ini
koymak istersen:

```bash
sudo systemctl stop range-decoy
nc -lvnp 4444
```

GUI'de yine `FOOTHOLD CANLI` görünür ve aynı şekilde puanlanır — motor artefaktın
ne olduğunu değil, portun açık olup olmadığını ölçer.

---

## 4. Savunanın görev listesi (brief olarak verilecek)

| # | Hedef | Nasıl bulunur | Nasıl kapatılır | Puan |
|---|---|---|---|---|
| 1 | Beklenmeyen açık port | `nmap -p- <ip>` · `ss -tlnp` | `systemctl disable --now range-decoy` | GEÇİCİ |
| 2 | Dışarı giden şüpheli bağlantı | `ss -tnp` · `systemctl list-units` | `systemctl disable --now range-beacon` | GEÇİCİ |
| 3 | Kimlik doğrulamasız servis (8081) | `curl http://<ip>:8081/` | durdur / localhost'a bağla / firewall | **KALICI** |
| 4 | SSH sertleştirme | `sudo sshd -T \| grep -Ei 'permitroot\|passwordauth'` | `sshd_config` düzelt | manuel |
| 5 | Dosya izinleri | `find / -xdev -perm -0002 2>/dev/null` | `chmod` / `chown` | manuel |
| 6 | Gevşek sudo | `sudo -l` · `ls /etc/sudoers.d/` | kuralı kaldır | manuel |
| 7 | Firewall kapalı | `ufw status` | `ufw enable` + default deny | manuel |

**Savunana söylenmez, keşfetmeleri gerekir:** 1 ve 2'yi kapatmak *geçici* puandır.
3 hâlâ açıksa sistem "kök neden duruyor" der. Kalıcı puan ancak 3 kapanınca gelir.

---

## 5. Doğrulama

Hedefte:
```bash
systemctl status range-weak range-decoy range-beacon
ss -tlnp | grep -E '4444|8081'
ss -tnp  | grep <KALI_IP>
```

Kontrolcüde:
```bash
python diagnose.py        # her katmanı ayrı ayrı test eder
python run_gui.py         # BT-1..BT-4 kırmızı FOOTHOLD CANLI görünmeli
python scoreboard.py      # puan tablosu + zaman çizelgesi
```

---

## 6. Kaldırma

```bash
sudo /tmp/setup_BT-1.sh --uninstall
```

Artefaktları ve misconfig dosyalarını siler. SSH ayarlarının yedeği
`/etc/ssh/sshd_config.range-backup` olarak durur. En temizi **snapshot'a dönmek.**

---

## 7. Daha fazla makineye ölçekleme

`scripts/_generate.py` içindeki `MACHINES` listesine satır ekle ve çalıştır:

```python
MACHINES = [
    ("BT-1", 4444, 8081),
    ("BT-5", 6001, 8081),   # yeni
]
```

```bash
python scripts/_generate.py     # setup_BT-5.sh üretilir
```

Sonra `config.json`'a karşılık gelen hedefi ekle.
