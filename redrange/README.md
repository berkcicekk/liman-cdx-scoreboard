# Red Range — Savunma Poligonu Kontrolcüsü

Proxmox/VMware üzerinde çalışan hedef makinelerde **foothold artefaktlarını** ve
**yanlış konfigürasyonları** izleyen, savunanların bunları temizlemesini
puanlanabilir olaylara dönüştüren kontrol paneli.

## Ne yapar, ne yapmaz

**Yapar:** Her makineyi periyodik olarak yoklar, foothold'un canlı olup
olmadığını ve altındaki yanlış config'in kapatılıp kapatılmadığını ölçer,
sonucu append-only bir olay akışına yazar, Flet arayüzünde canlı gösterir.

**Yapmaz:** Hedefe payload göndermez, komut çalıştırmaz, sömürü denemez.
Bu bir saldırı aracı değil, bir **eğitim poligonu kontrolcüsüdür.** Hedefteki
artefaktlar (beacon + decoy) poligon imajına kurulum sırasında elle yerleştirilir
ve systemd tarafından ayakta tutulur; kontrolcünün tek işi **ölçmektir.**

## Durum makinesi ve puanlama

Her `(makine, vektör)` çifti için aynı küçük durum makinesi döner:

```
PENDING ──foothold görüldü──────► UP
   UP   ──foothold düştü────────► DOWN          [GEÇİCİ puan]
  DOWN  ──zafiyet hâlâ açık─────► UP            (sadece yüzey temizliği)
  DOWN  ──zafiyet kapandı───────► REMEDIATED    [KALICI puan]
REMEDIATED ─zafiyet geri açıldı─► UP/DOWN       [REGRESSION]
```

**Kritik tasarım kararı:** KALICI puan, foothold düştüğü *anda* verilmez —
çünkü o anda "sadece portu mu kapattı, yoksa açığı mı kapattı" ayırt edilemez.
Ancak bir sonraki tikte zafiyet probu **başarısız** olunca verilir. Bu gecikme
bilerek böyledir ve savunana doğru dersi verir: *portu kapatmak yeterli değil,
kök nedeni kapatmalısın.*

## İki vektör = iki tespit becerisi

| Vektör | Hedefte ne var | Savunan nasıl bulur |
|---|---|---|
| `port` | Dinleyen bir port (decoy) | `nmap -p- <ip>` |
| `beacon` | Dışarı giden bağlantı | `ss -tnp`, `systemctl`, log |

Beacon **port taramasıyla görünmez** — bilerek. Savunanın iki farklı beceri
geliştirmesi için ikisi birlikte kullanılır.

## Mimari

```
redrange/
├── core/              ← motor. ui/'yi HİÇ bilmez, tek başına çalışır.
│   ├── models.py        Target, VectorState, EventType, Event
│   ├── probes.py        zararsız TCP yoklamaları
│   ├── sessions.py      SessionManager: thread'li dinleyici + registry
│   ├── vectors.py       AttackVector (ABC) + Beacon/Port/Scripted
│   ├── engine.py        VectorRuntime, tick_vector, Engine
│   ├── events.py        EventSink (JSONL) + okuyucular
│   └── config.py        config.json → AppContext
├── ui/app.py          ← Flet 1.0 paneli. Sadece okur ve gösterir.
├── agent/             ← HEDEF makineye kurulanlar (bkz. PARDUS_SETUP.md)
│   ├── beacon.py        kalp-atışı ajanı
│   ├── decoy_listener.py dinleyen port artefaktı
│   └── weak_service.py  kasten açık bırakılmış servis (asıl zafiyet)
├── run_gui.py         ← arayüzle çalıştır
├── run_headless.py    ← arayüzsüz çalıştır (servis olarak)
├── scripts/           ← BT-1..BT-4 icin self-contained kurulum scriptleri
├── diagnose.py        ← "neden BEKLIYOR?" teshis araci
├── tools/             ← yardimci araclar
│   └── event_inspector.py  olay akisi DOGRULAMA araci (resmi scorer DEGIL)
├── demo.py            ← 4 sahte makine (Pardus'suz deneme)
├── selftest.py        ← cekirdek dogrulama
├── integration_test.py← 4 makineli tam akis dogrulamasi
└── config.json
```

**Altın kural:** `core/` asla `ui/`'dan import etmez. Bağımlılık tek yönlü.
`run_headless.py` bunun kanıtı — aynı çekirdek, arayüz yok.

## Kurulum

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

Python 3.10+ gerekir (Flet 1.0 şartı).

## Çalıştırma

```bash
python selftest.py          # cekirdek dogrulama (tek makine, localhost)
python integration_test.py  # 4 makineli tam akis dogrulamasi
python diagnose.py          # "neden BEKLIYOR?" teshisi
python run_gui.py           # Flet paneli
python run_headless.py      # arayuzsuz
python tools/event_inspector.py   # olay akisini gozle dogrula
```

### Pardus olmadan denemek

```bash
# Terminal 1 — 4 sahte makineyi localhost'ta canlandir
python demo.py

# Terminal 2 — paneli ac
python run_gui.py --config config_demo.json

# Terminal 3 — savunani taklit et
python demo.py --kill BT-2 --what decoy   # gecici puan
python demo.py --kill BT-2 --what vuln    # kalici puan
python tools/event_inspector.py --events events_demo.jsonl
```

Komutları **proje kökünden** çalıştır (`core` paketi `sys.path`'te bulunsun).

## config.json

```json
{
  "listen_host": "0.0.0.0",      // beacon'ların bağlanacağı arayüz
  "listen_port": 9000,           // beacon dinleyici portu
  "tick_seconds": 3,             // ölçüm aralığı
  "session_timeout": 20,         // bu kadar ses gelmezse oturum ölü
  "events_path": "events.jsonl",
  "targets": [
    {
      "machine_id": "pardus-01",
      "user_id": "baris",
      "host": "192.168.1.50",
      "vuln_port": 8081,         // KALICI puan: bu kapanınca
      "decoy_port": 4444         // GEÇİCİ puan: bu kapanınca
    }
  ]
}
```

10 makine için `targets` listesine 10 satır ekle — motor otomatik olarak her
makine için iki vektör (port + beacon) oluşturur.

## Scorer entegrasyonu (puanı yazan kişi için)

Motor **puanı bilmez.** Sadece `events.jsonl` dosyasına append-only olay yazar.
Scorer bu dosyayı tail'ler ve puana çevirir. Sözleşme:

```json
{"event_id":"a1b2...","ts":1758478759.12,"machine_id":"pardus-01",
 "user_id":"baris","vector_id":"port-pardus-01","vector_type":"port",
 "event_type":"foothold_down","detail":""}
```

| `event_type` | Anlamı | Puan |
|---|---|---|
| `foothold_established` | Saldırgan foothold kurdu/geri geldi | — (bilgi) |
| `foothold_down` | Savunan foothold'u düşürdü | **GEÇİCİ** |
| `remediated` | Savunan zafiyeti kapattı | **KALICI** |
| `regression` | Düzeltme geri alındı | negatif/reset |
| `probe_error` | Ölçüm yapılamadı (ağ hatası) | — (yok say) |

- `event_id` benzersizdir → scorer **at-least-once** varsayıp bununla dedupe etsin.
- `ts` epoch saniyedir (UTC, sıralanabilir). Biçimlendirmeyi gösterim anında yap.
- Motor ile scorer arasındaki **tek** bağ bu dosyadır; birbirlerinin kodunu bilmezler.

> `tools/event_inspector.py` **resmi scorer değildir** — motorun doğru event ürettiğini
> doğrulamak için bir referans tüketici örneğidir. Üretim puanlaması ayrı bir
> sorumluluktur ve yalnızca yukarıdaki sözleşmeye dayanır.

### Cenk Scoreboard köprüsü

`bridge_cenk.py` bu event akışını **Cenk Scoreboard (Liman MYS)** inject defterine
çevirir — sözleşmeye dayalı, idempotent bir scorer örneği. Ayrıntı için
[BRIDGE.md](BRIDGE.md).

## Güvenlik notu

Hedef makineler kasten zayıflatılmıştır. Bu makineleri **izole bir ağda**
(host-only / internal, internete ve kurum ağına kapalı) çalıştır. Kurulumdan
önce snapshot al. Poligon bittiğinde snapshot'a dön.
