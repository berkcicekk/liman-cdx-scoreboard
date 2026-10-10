# Red Range → Cenk Köprüsü

Red Range motoru **puanı bilmez**; sadece `events.jsonl`'a append-only olgu yazar.
`bridge_cenk.py` o akışı tail'ler ve her geçişi Cenk Scoreboard'un (Liman MYS)
`ls_injects` defterine çevirir. Bu, README'de bahsedilen "ayrı scorer"ın somut
hâlidir — yalnızca event sözleşmesine dayanır, motorun kodunu bilmez.

```
Red Range motoru ──► events.jsonl ──► bridge_cenk.py ──► Cenk ls_injects ──► Cenk dashboard
   (olgu uretir)      (sozlesme)        (ceviri)          (PostgreSQL)        (SLA+inject+security)
```

## Neden inject defteri?

İki sistem de aslında aynı şeydir: **append-only, idempotent, puanı-bilmeyen
defter.** Red Range `event_id`'si (`uuid4().hex`) ile Cenk `request_id`'si (UUID)
birebir eşleşir. Köprü `event_id`'yi kanonik UUID'ye çevirir
(`deadbeef… → deadbeef-dead-beef-…`) ve `ON CONFLICT (server_id,request_id)
DO NOTHING` ile yazar. Aynı event iki kez gelse Cenk ikinciyi sessizce yutar —
Red Range'in *at-least-once* sözleşmesi Cenk'in dedupe sözleşmesiyle örtüşür.

Cenk'in diğer iki ekseni (SLA = kendi servis probe'ları, security = kendi
zafiyet worker'ı) köprüden **bağımsızdır**; köprü yalnızca inject eksenini besler.

## Olay eşlemesi

| Red Range olayı | Cenk inject | Puan | Not |
|---|---|---|---|
| `foothold_down` | `task` | **+geçici** | Yüzey temizlendi (port kapandı) |
| `remediated` | `task` | **+kalıcı** | Kök neden kapandı |
| `regression` | `penalty` | **−kalıcı** | Düzeltme geri alındı (telafi kaydı) |
| `foothold_established` | — | — | Bilgi; yazılmaz |
| `probe_error` | — | — | Ağ hatası; yazılmaz |

Puan değerleri `bridge_map.json` → `points` içinden gelir. `regression` değeri
pozitif yazılır; köprü `penalty` için otomatik negatife çevirir.

### Bilinçli davranışlar
- **Geçici + kalıcı toplanır.** Tam temizlik yapan takım hem `foothold_down`
  hem `remediated` puanını alır — Cenk inject defterinin "her olgu bir kayıt,
  her geri alma bir telafi kaydı" mantığıyla birebir.
- **Regression yalnızca kalıcıyı geri alır**; geçici puan tarihsel gerçek olarak kalır.
- **Tekrarlayan UP→DOWN→UP döngüleri** her seferinde yeni `event_id` = yeni inject
  üretir. İstismara açık görünürse `points` değerlerini düşürün; ileride "yalnızca
  ilk düşüşü puanla" kuralı eklenebilir (şimdilik tüm geçişler puanlanır).

## Kurulum

### 1. Cenk tarafı (bir kez)
`database/migrations.sql` + `database/grants.sql` uygulanmış, takımlar oluşturulmuş
olmalı. Köprü, `ls_scoreboard` rolünün mevcut `INSERT ON ls_injects` yetkisini
kullanır — ek grant gerekmez.

```sql
-- her Red Range makinesi icin bir Cenk takimi
INSERT INTO ls_teams (server_id, name) VALUES ('<liman-sunucu-uuid>', 'BT-1') RETURNING id;
```

### 2. `bridge_map.json`
Red Range kimliklerini Cenk kimliklerine bağlayın:

```json
{
  "server_id": "<liman-sunucu-uuid>",
  "actor_uuid": "<köprüye ayrılmış sabit UUID>",
  "teams": { "BT-1": 42, "BT-2": 43 },
  "points": { "foothold_down": 5, "remediated": 25, "regression": 25 },
  "database": {
    "host": "127.0.0.1", "port": 5432, "dbname": "liman",
    "user": "ls_scoreboard", "password_env": "BRIDGE_DB_PASSWORD"
  }
}
```

> `machine_id` → `team_id` eşlemesi **birebir** olmalı. `teams`'te olmayan bir
> makineden gelen olay atlanır (köprü çökmez). DB parolasını dosyaya yazmayın;
> `password_env` ile ortam değişkeninden okunur.

### 3. Bağımlılık ve çalıştırma

**Hızlı yol (önerilen):** `setup_bridge.sh` venv + psycopg kurar, haritayı ve DB
erişimini önkontrolden geçirir (takımlar `ls_teams`'de var mı dahil), sorun yoksa başlatır:
```bash
export BRIDGE_DB_PASSWORD='...'     # ls_scoreboard parolasi
./setup_bridge.sh                   # kurulum + onkontrol + baslat
./setup_bridge.sh --check           # sadece dogrula, baslatma
```

**Elle:**
```bash
pip install -r requirements.txt          # psycopg eklendi
export BRIDGE_DB_PASSWORD='...'           # ls_scoreboard parolasi
python bridge_cenk.py --map bridge_map.json
```

Köprü `events.jsonl`'ı kaldığı yerden tail'ler; offset `events.jsonl.bridge_offset`
dosyasında tutulur, yeniden başlatmada baştan okumaz. Zaten `ON CONFLICT` sayesinde
baştan okusa da çift puan oluşmaz.

| Bayrak | Anlamı |
|---|---|
| `--config` | Red Range `config.json` (varsayılan: `config.json`) |
| `--map` | eşleme sözleşmesi (varsayılan: `bridge_map.json`) |
| `--once` | bir tur işle ve çık (CI / tek seferlik) |
| `--poll` | tail bekleme saniyesi (varsayılan: `tick_seconds`) |
| `--offset-file` | offset dosyası yolu |

## Doğrulama

Ceviri mantığı — PostgreSQL gerektirmez:
```bash
python test_bridge.py
```

Uçtan uca (demo + test Cenk veritabanı):
```bash
# Terminal 1 — 4 sahte makine
python demo.py
# Cenk test Postgres'i hazir, 4 takim seed'li, BRIDGE_DB_PASSWORD set
python bridge_cenk.py --config config_demo.json --map bridge_map.demo.json
# Terminal 3 — savunani taklit et
python demo.py --kill BT-2 --what decoy   # -> foothold_down -> +gecici inject
python demo.py --kill BT-2 --what vuln     # -> remediated   -> +kalici inject
```
Cenk dashboard'unda BT-2 takımında iki inject satırı ve artan skor görünür.

## Ölçüm sorumluluğu ayrımı (önemli)

Tatbikat makinelerindeki her zafiyet bu iki sistemden **yalnızca birine** aittir:

| Zafiyet türü | Ölçen sistem | Nasıl |
|---|---|---|
| Ağ-yüzeyli foothold (açık port, dışa bağlantı) | **Red Range** | `port`/`beacon` vektörü, TCP probe |
| Yerel yetki yükseltme (capability, sudo, systemd timer, LD_PRELOAD, SUID, IaC) | **Cenk** | `SSH_AUDIT`/`REMOTE_EXPLOIT`, allowlist `argv`, `exit 0 = Patched` |

> **Değişmez:** Red Range motoru hedefe **komut çalıştırmaz, payload göndermez**
> (`core/vectors.py`). Yerel privesc denetimi bir komut çalıştırmayı gerektirir; bu
> yüzden Red Range'e audit-runner **eklenmez** — o iş Cenk'in zafiyet worker'ının.
> `ScriptedVector` yalnızca bir test sahtesidir, audit vektörü değildir.

## Güvenlik notu

Köprü güvenilen bir arka-plan sürecidir; `ls_scoreboard` rolüyle yalnızca
`ls_injects`'e yazar, Core tablolarına erişmez. Parola ortam değişkeninde tutulur,
repoya ve komut satırına yazılmaz.
