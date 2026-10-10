# Red Range — Algoritma

Sistemin çalışma mantığı, sözde kod (pseudocode) düzeyinde. Mimari için
`README.md`, kurulum için `PARDUS_SETUP.md`.

---

## 0. Tek bakışta

```
┌──────────────┐   kalp atışı    ┌───────────────────┐
│ HEDEF (BT-n) │ ───────────────►│ SessionManager    │  (thread)
│  • beacon    │                 │  registry{id→sess}│
│  • decoy port│◄── TCP prob ────┤                   │
│  • weak svc  │◄── TCP prob ────┤ Engine (thread)   │
└──────────────┘                 │  her tik'te:      │
                                 │   tick_vector()   │
                                 └─────────┬─────────┘
                                           │ geçiş oldu mu?
                                           ▼
                                   events.jsonl (append-only)
                                           │
                              ┌────────────┴────────────┐
                              ▼                         ▼
                         Flet GUI                  Scorer (ayrı kişi)
```

**Temel fikir:** Motor hedefe hiçbir şey *göndermez*. Sadece iki soruyu
periyodik olarak sorar ve cevapların *değişimini* olaya çevirir:

1. `is_foothold_alive()` → foothold şu an ayakta mı?
2. `attempt_exploit()` → alttaki yanlış config hâlâ açık mı?

---

## 1. Başlangıç (bootstrap)

```
config.json oku
  → listen_host, listen_port, tick_seconds, session_timeout, events_path
  → targets[]  (machine_id, user_id, host, vuln_port, decoy_port)

SessionManager(listen_host, listen_port, session_timeout) oluştur
EventSink(events_path) oluştur

jobs ← []
her target için:
    jobs.ekle( Job(target, PortVector(),   VectorRuntime()) )
    jobs.ekle( Job(target, BeaconVector(), VectorRuntime()) )
    # her makine 2 iş üretir → 4 makine = 8 iş

Engine(jobs, sink, tick_seconds) oluştur

sessions.start()   # dinleyici thread'i
engine.start()     # tik thread'i
```

`VectorRuntime` başlangıç durumu: `state = PENDING`, sayaçlar `0`.

> **Not:** `listen_port` meşgulse `PortInUseError` fırlatılır ve kullanıcıya
> hangi süreci öldüreceği söylenir — sessizce çökmez.

---

## 2. SessionManager — iki eşzamanlı döngü

### 2.1 Kabul döngüsü (tek thread)

```
döngü (çalışıyorken):
    conn, addr ← accept()          # 1 sn timeout, stop()'a cevap versin diye
    zaman aşımı ise → devam
    yeni thread başlat: handle(conn, addr)
```

### 2.2 Bağlantı işleyici (bağlantı başına bir thread)

```
handle(conn, addr):
    key ← yok
    döngü:
        data ← conn.recv()
        data boş ise → çık            # karşı taraf kapattı
        satırlara böl (\n)
        her satır için:
            msg ← JSON çöz (başarısızsa boş kabul et)

            EĞER key yok (İLK satır):
                machine_id ← msg["machine_id"]  (yoksa "unknown@ip:port")
                registry[key] ← Session(id, peer_ip, peer_port, now, now)
                logla "BAGLANDI"
            DEĞİLSE (sonraki satırlar = kalp atışı):
                registry[key].last_seen ← now
                registry[key].beats += 1
    finally:
        registry'den key'i sil
        logla "KOPTU"
```

### 2.3 Canlılık sorgusu (motorun kullandığı)

```
is_alive(machine_id):
    s ← registry[machine_id]
    s yoksa → False
    döndür (now - s.last_seen) <= session_timeout
```

İki ölüm yolu var: **bağlantı kopar** (registry'den silinir) veya
**sessizleşir** (timeout aşılır). İkisi de `False` döndürür.

---

## 3. Motor tik döngüsü

```
_loop():
    döngü (çalışıyorken):
        tick_once()
        tick_seconds kadar uyu     # 0.2 sn'lik dilimlerle, stop() hızlı cevap versin

tick_once():
    ticks += 1
    her job için:
        DENE:
            et ← tick_vector(job.vector, job.target, job.runtime)
        HATA (ağ vb.):
            runtime.last_error ← hata
            PROBE_ERROR olayı yaz
            sonraki job'a geç        # ← bir makinenin hatası diğerlerini etkilemez
        EĞER et boş değilse:
            detail ← vector.detail_for(target)    # bağlantı kökeni
            Event yaz (machine_id, user_id, vector_id, vector_type, et, detail)
```

**Kritik:** Her job bağımsız `try/except` içinde. Bir hedef çökse bile döngü
dönmeye devam eder.

---

## 4. Durum makinesi — `tick_vector()` (ASIL ALGORİTMA)

```
tick_vector(vector, target, rt):

    EĞER rt.state == PENDING:
        EĞER vector.is_foothold_alive(target):
            rt.state ← UP
            döndür FOOTHOLD_ESTABLISHED          # bilgi, puanlanmaz
        döndür boş

    EĞER rt.state == UP:
        EĞER DEĞİL vector.is_foothold_alive(target):
            rt.state ← DOWN
            rt.temporary_points += 1
            döndür FOOTHOLD_DOWN                 # >>> GEÇİCİ puan
        döndür boş

    EĞER rt.state == DOWN:
        # KRİTİK ÇATAL
        EĞER vector.attempt_exploit(target):     # zafiyet hâlâ açık mı?
            EĞER vector.is_foothold_alive(target):
                vector.establish_foothold(target)
                rt.state ← UP
                döndür FOOTHOLD_ESTABLISHED      # sadece yüzey temizliğiydi
            döndür boş                           # açık ama foothold henüz dönmedi
        rt.state ← REMEDIATED
        rt.permanent_points += 1
        döndür REMEDIATED                        # >>> KALICI puan

    EĞER rt.state == REMEDIATED:
        EĞER vector.attempt_exploit(target):     # düzeltme geri mi alındı?
            rt.state ← (foothold canlıysa UP, değilse DOWN)
            rt.permanent_points -= 1  (0'ın altına düşmez)
            döndür REGRESSION
        döndür boş
```

### Durum geçiş tablosu

| Durum | Sorulan | Sonuç | Yeni durum | Olay |
|---|---|---|---|---|
| `PENDING` | foothold canlı? | evet | `UP` | `foothold_established` |
| `PENDING` | foothold canlı? | hayır | `PENDING` | — |
| `UP` | foothold canlı? | hayır | `DOWN` | `foothold_down` **[geçici]** |
| `DOWN` | zafiyet açık? | evet + foothold döndü | `UP` | `foothold_established` |
| `DOWN` | zafiyet açık? | evet + foothold yok | `DOWN` | — |
| `DOWN` | zafiyet açık? | **hayır** | `REMEDIATED` | `remediated` **[kalıcı]** |
| `REMEDIATED` | zafiyet açık? | evet | `UP`/`DOWN` | `regression` |

### Neden KALICI puan gecikmeli?

Foothold düştüğü **anda** iki durum birbirinden ayırt edilemez:

```
savunan portu kapattı  ──┐
                          ├──► gözlem: is_foothold_alive() = False
savunan açığı kapattı  ──┘
```

Ayrımı yapan tek şey **bir sonraki tikte** `attempt_exploit()`'in sonucudur.
Bu yüzden `DOWN` bir *bekleme* durumudur, hata değil — algoritma kasten bir tik
bekleyip kök nedeni sorar.

---

## 5. Vektör probları — ölçüm nasıl yapılıyor

İki vektör tipi de aynı arayüzü uygular, farklı ölçerler:

| | `PortVector` (bind) | `BeaconVector` (reverse) |
|---|---|---|
| `is_foothold_alive` | `tcp_probe(host, decoy_port)` | `sessions.is_alive(machine_id)` |
| `attempt_exploit` | `tcp_probe(host, vuln_port)` | `tcp_probe(host, vuln_port)` |
| `establish_foothold` | no-op | no-op |
| Savunan nasıl bulur | `nmap -p-` | `ss -tnp` / log |

```
tcp_probe(host, port, timeout=2sn):
    soket aç (IPv4/TCP)
    timeout ayarla
    DENE: connect((host, port)) → döndür True     # biri dinliyor
    HATA OSError → döndür False                   # reddedildi/timeout/ulaşılamadı
```

> `establish_foothold` **bilerek boştur.** Motor hedefe payload göndermez;
> artefaktlar poligon imajına kurulumda yerleştirilir ve `systemd
> Restart=always` ile geri gelir. Motorun işi ölçmek, kurmak değil.

---

## 6. Event üretimi ve sözleşme

Olay **yalnızca durum değiştiğinde** yazılır (her tikte değil).

```
emit(event):
    satır ← JSON(event)
    kilit al:
        events.jsonl dosyasına "a" modunda aç
        satır + "\n" yaz
        flush
```

Her event: `event_id` (benzersiz, dedupe için), `ts` (epoch saniye, sıralanabilir),
`machine_id`, `user_id`, `vector_id`, `vector_type`, `event_type`, `detail`.

`detail` bağlantı kökenini taşır:
- beacon → `giden baglanti 10.0.0.5:45180 | kalp=12 | sure=60s | tespit=ss -tnp`
- port → `dinleyen port 10.0.0.5:4444 | tespit=nmap -p4444`

---

## 7. Zamanlama parametreleri

| Parametre | Varsayılan | Etkisi |
|---|---|---|
| `tick_seconds` | 3 sn | Ölçüm sıklığı; tepki gecikmesinin alt sınırı |
| `session_timeout` | 20 sn | Beacon susunca ölü sayılma süresi |
| `HEARTBEAT_SECONDS` | 5 sn | Beacon'ın kalp atışı aralığı |
| `RECONNECT_SECONDS` | 5 sn | Beacon kopunca yeniden deneme |
| `tcp_probe timeout` | 2 sn | Prob'un asılı kalma sınırı |

**Kural:** `session_timeout` > `HEARTBEAT_SECONDS` × 2 olmalı, yoksa sağlıklı
beacon'lar yanlışlıkla ölü sayılır.

En kötü tespit gecikmesi:
- port vektörü ≈ `tick_seconds` (3 sn)
- beacon vektörü ≈ `session_timeout + tick_seconds` (23 sn)

---

## 8. Örnek senaryo — adım adım

Savunan BT-2'yi temizliyor (`tick_seconds=3`):

```
t=0s    decoy açık, weak service açık, beacon bağlı
        → port:UP, beacon:UP

t=12s   savunan: systemctl disable --now range-decoy
t=15s   tik → is_foothold_alive(4555)=False
        → port:UP → DOWN     [foothold_down, +geçici]

t=18s   tik → DOWN: attempt_exploit(8081)=True  (zafiyet hâlâ açık!)
                is_foothold_alive=False
        → DOWN'da kalır, olay yok
        (savunan sadece yüzeyi temizledi — kalıcı puan YOK)

t=25s   savunan: systemctl disable --now range-weak
t=27s   tik → DOWN: attempt_exploit(8081)=False
        → port:DOWN → REMEDIATED   [remediated, +KALICI]

t=40s   savunan servisi yanlışlıkla geri açarsa
        → REMEDIATED → UP/DOWN     [regression, -kalıcı]
```

---

## 9. Sınır durumlar ve nasıl ele alınıyor

| Durum | Davranış |
|---|---|
| Hedef makine tamamen kapalı | Her iki prob `False` → `PENDING`'de kalır (çökmez) |
| Ağ hatası / DNS hatası | `try/except` → `PROBE_ERROR` olayı, döngü devam eder |
| `machine_id` uyuşmazlığı | Beacon registry'ye *farklı* anahtarla girer → `is_alive()` False → beacon `PENDING`'de kalır. GUI'de "CONFIG'DE YOK" olarak kırmızı görünür |
| Kimliksiz ham bağlantı (`nc <ip> 9000`) | `unknown@ip:port` olarak kaydedilir, GUI'de `[KIMLIKSIZ]` |
| Dinleyici portu meşgul | `PortInUseError` → açıklayıcı mesaj + çözüm komutları |
| `events.jsonl` yarım satır | Okuyucu `JSONDecodeError`'ı yutar, satırı atlar |
| Aynı event iki kez okunursa | `event_id` ile dedupe (scorer'ın sorumluluğu) |
| Kullanıcı decoy yerine kendi `nc`'sini koyarsa | Motor farkı bilmez — port açık ⇒ foothold canlı |

---

## 10. Değişmezler (invariants)

1. `core/` asla `ui/`'dan import etmez — motor arayüzsüz çalışır.
2. Motor **puan hesaplamaz**, sadece olay üretir.
3. Olay yalnızca **geçişte** yazılır; aynı durumda tekrar yazılmaz.
4. Her `(makine, vektör)` çiftinin **kendi bağımsız** `VectorRuntime`'ı vardır.
5. Registry'ye erişim her zaman kilit altındadır (thread güvenliği).
6. Bir job'ın hatası diğer job'ları durdurmaz.
