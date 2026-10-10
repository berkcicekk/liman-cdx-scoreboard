# AI.md — Yapay Zekâ Asistanları İçin Proje Bağlamı

Bu dosya, bir AI asistanının (Claude Code vb.) bu depoda çalışmaya başlamadan önce
okuması gereken özet bağlamdır. Kullanıcıya dönük dokümanlar için [README.md](README.md),
[DEPLOYMENT.md](DEPLOYMENT.md) ve [MAKINE_HAZIRLAYAN_EKIP_TEKNIK_REHBERI.md](MAKINE_HAZIRLAYAN_EKIP_TEKNIK_REHBERI.md).

## Proje nedir

**Cenk Scoreboard**, Liman MYS (sürüm kodu **1093+**) üzerinde çalışan bir PHP
eklentisidir. Siber tatbikatlarda servis sürekliliğini, White Team puanlarını ve
zafiyet uyumluluğunu tek skor tahtasında toplar. Probe'lar: HTTP, HTTPS, SSH, DNS,
LDAP, WINRM, PING.

## Mimari (tek bakışta)

- **Dashboard (okuma):** `views/functions.php` → `app/Scoreboard.php`. Tarayıcı 10 sn
  polling ile `ls_dashboard` çağırır. Sunucu kimliği **tarayıcıdan alınmaz**, her zaman
  güvenilir `server()->id` bağlamından gelir. Tek tutarlı snapshot `REPEATABLE READ`
  içinde okunur.
- **Worker (yazma):** `bin/worker.php` / `scripts/probe_worker.php` → `app/Worker.php`,
  `app/Probe.php`. İzinli CIDR (`scopes`) içindeki servisleri ölçer; sonucu append-only
  `ls_service_logs`'a yazar. Zafiyet denetimlerini allowlist'ten `argv` olarak **shell
  açmadan** çalıştırır; durum değişiminde `app/Notifier.php` ile HTTPS webhook.
- **Eşzamanlılık:** `FOR UPDATE SKIP LOCKED` + lease (servis ve zafiyet satırlarında
  `lease_token`/`lease_until`) aynı işin iki kez çalışmasını engeller.

## Skor modeli (üç bağımsız eksen)

```
Toplam = SLA (servis ağırlığı × uptime) + inject (task/penalty) + security (Patched zafiyet)
```

- **SLA:** `app/Scoreboard.php::services()` içindeki pencere/LATERAL SQL. Ölçüm sonucu bir
  sonraki sonuca veya en fazla kendi aralığının sonuna kadar geçerli. Son 60 dk için 61 nokta.
- **inject:** `ls_injects` append-only defter; `request_id` (UUID) ile idempotent
  (`ON CONFLICT (server_id,request_id) DO NOTHING`). Düzeltme = telafi kaydı.
- **security:** Durumu `Patched` olan `ls_vulnerabilities` puanları.

## Önemli dosyalar

| Yol | Rol |
|---|---|
| `views/functions.php` | Liman giriş noktaları; `ls_dispatch` ile yönlendirme |
| `app/Scoreboard.php` | Dashboard okuma, createTeam/createService/addInject |
| `app/Worker.php`, `app/Probe.php` | Ölçüm motoru ve probe'lar |
| `app/Policy.php` | `authorize()`, `uuid()`, scope denetimi |
| `database/migrations.sql` | Şema (`ls_teams/services/service_logs/injects/vulnerabilities/vulnerability_logs`) |
| `database/grants.sql` | Kısıtlı `ls_scoreboard` rolü izinleri |
| `config/locked-shields.example.php` | Üretim yapılandırması (DSN, scopes, allowlist, webhook) |
| `db.json` | Liman manifesti (eklenti adı `Cenk`, fonksiyon izinleri) |

## Değişmezler / konvansiyonlar (AI bunları bozmamalı)

1. **Güven sınırı:** `server_id`, `user_id`, rol, timestamp **asla** tarayıcı girdisinden
   alınmaz. Scope daima `Policy::authorize()` → `server()->id`.
2. **Zafiyet komutu çalıştırılmaz:** `ls_vulnerabilities.command` bir **allowlist
   anahtarıdır**, kabuk metni değil. Gerçek komut config'te `argv`; shell yok.
3. **Append-only defterler:** `ls_service_logs`, `ls_injects`, `ls_vulnerability_logs`
   güncellenmez; düzeltme telafi kaydıyla yapılır.
4. **En küçük yetki:** `ls_scoreboard` rolü Core tablolarına erişmez; sadece belirli
   kolonları günceller (`grants.sql`).
5. **Gizli bilgi yok:** Parola/token/anahtar repoya, komut satırına, webhook URL'sine
   yazılmaz; kanıt çıktısı 1000 karakterle sınırlı ve maskelenir.
6. Kod ve doküman dili **Türkçe**; mevcut üslup korunur.

## Doğrulama

```powershell
docker compose -f tests/compose.yaml up --build --abort-on-container-exit --exit-code-from php --attach php
npm ci; npm run check
```
Gerçek PostgreSQL + PHP Sandbox/Blade + worker + probe + UI (Playwright). CI:
`.github/workflows/verify.yml` her push/PR'da aynı kontrolleri koşar.

## Red Range köprüsü (ilişkili proje)

Ayrı bir savunma poligonu kontrolcüsü olan **Red Range**, append-only bir `events.jsonl`
olay akışı üretir (puanı bilmez). Red Range'in `bridge_cenk.py` köprüsü bu akışı bu
eklentinin **inject eksenine** çevirir: olay `event_id`'si (uuid4 hex) kanonik UUID'ye
dönüştürülüp `ls_injects.request_id` olarak idempotent yazılır. SLA ve security eksenleri
köprüden bağımsızdır. Ayrıntı Red Range deposundaki `BRIDGE.md` dosyasındadır.

### Ölçüm sorumluluğu ayrımı (önemli)

Tatbikat makinelerindeki zafiyetler iki gruba ayrılır; her grubu **farklı** sistem ölçer:

- **Ağ-yüzeyli foothold** (açık port, dışa bağlantı; örn. anonim FTP, Tomcat paneli) →
  **Red Range** `port`/`beacon` vektörü. Red Range hedefe **komut çalıştırmaz, payload
  göndermez** — bu, motorun değişmezidir ve **bozulmamalıdır**.
- **Yerel yetki yükseltme** (capability, sudo/sudoers, systemd timer, LD_PRELOAD, SUID,
  IaC/otomasyon zehirlenmesi; ağdan görünmez) → **Cenk'in `SSH_AUDIT`/`REMOTE_EXPLOIT`
  zafiyet kontrolü.** Allowlist'ten `argv` çalıştırılır (shell yok), `exit 0 = Patched`.

> **Yapma:** Red Range'e audit/komut-çalıştırma eklemeyin — bu iş Cenk'in zafiyet
> worker'ının. Örnek allowlist: `config/locked-shields.exercise.example.php`.

## İsimlendirme (kanonik karar)

**Ürün adı: Cenk.** Kaynak: manifest (`db.json` → `Cenk`), `scripts/build.ps1` çıktısı
`Cenk-$version.zip`, README başlığı ve son commit'ler. Kullanıcıya dönük her yerde paket
adı **`Cenk-1.0.0.zip`**'tir (`DEPLOYMENT.md` bu sürümde hizalandı).

**İç implementasyon adları `LockedShields`/`lockedshields`/`ls_` olarak KALIR** — bunlar
şemaya ve kurulum yoluna gömülü olduğu için rename edilmez:
- PHP ad alanı `LockedShields\` (örn. `app/Scoreboard.php`),
- tablo öneki `ls_*`, yapılandırma dosyası `locked-shields.php`,
- kurulum yolu `/liman/.../extensions/lockedshields` (incelenen Liman sürümünün gerçek
  eklenti yoluyla uyumlu).

Yani **ürün = Cenk, implementasyon kimlikleri = LockedShields**; bu ikisi bilinçli olarak
ayrı. Tam bir iç rename ayrı ve şema-kırıcı bir refactor'dur; istenmeden yapılmaz.

## Dikkat edilecek diğer noktalar

- **Migration:** `database/migrations.sql` ile `database/migrations/001_locked_shields.sql`
  **birebir aynı** (içerik mirror). Biri silinirse ikisine de bakan dokümanları/scriptleri güncelle.
- **İki kurulum profili:** README kısıtlı `ls_scoreboard` rolü; `DEPLOYMENT.md` `liman`
  kullanıcılı profil. İkisi birlikte çalıştırılmaz — `deploy.sh` eski worker unit'ini durdurur.
- **İki systemd unit:** `deploy/locked-shields-worker.service` (eski) ve
  `deploy/lockedshields-probe.service` (yeni profil). Aynı anda ikisi etkin olmamalı.

## Destek

[cckberk@gmail.com](mailto:cckberk@gmail.com)
