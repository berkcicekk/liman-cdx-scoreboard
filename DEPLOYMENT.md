# Otomatik kurulum ve smoke testi

Bu belge istenen **`liman` kullanıcısı + `/liman/server/storage/extensions/lockedshields`** kurulum profilidir. Önceki README'deki kısıtlı `ls_scoreboard` rolü ve UUID kullanıcılı worker profilinin yerine bunu uygulayın; iki worker servisini birlikte başlatmayın. `deploy.sh` eski `locked-shields-worker.service` etkinse durdurur/devre dışı bırakır.

## 1. Hedef Linux sunucusunu hazırla

Hedef, PHP Sandbox kullanan Liman ve çalışan systemd bulunan Linux sunucusudur. Üretim veritabanı olarak **Liman'ın mevcut PostgreSQL veritabanı** kullanılır. `liman` Linux hesabı önceden bulunmalıdır.

```bash
sudo apt-get update
sudo apt-get install -y php-cli php-pgsql php-curl php-ldap php-xml php-mbstring php-zip iputils-ping acl unzip
id liman
php -m
```

PHP CLI'da `pcntl`, `posix`, `proc_open` kullanılabilir olmalı. Paket yüklemesi ve sunucuya atama için geçerli bir **Liman yönetici JWT'si** gerekir. Bu JWT tarayıcı parolası veya Personal Access Token değildir. Liman'ın mevcut oturum/API kimlik doğrulama sürecinden alın; betik oturum açma/MFA'yı atlamaz.

## 2. Paketi ve yapılandırmayı yerleştir

Güncellenmiş `LockedShields-1.0.0.zip` dosyasını hedef sunucuya kopyaladıktan sonra:

```bash
mkdir -p "$HOME/lockedshields-installer"
unzip LockedShields-1.0.0.zip -d "$HOME/lockedshields-installer"
cd "$HOME/lockedshields-installer"
sudo install -d -m 0750 /etc/liman
sudo install -m 0600 -o root -g root config/deploy.example.php /etc/liman/lockedshields-deploy.php
sudo install -d -m 0700 /root/.config/lockedshields
sudo install -m 0600 /dev/null /root/.config/lockedshields/liman.jwt
sudoedit /root/.config/lockedshields/liman.jwt
sudoedit /etc/liman/lockedshields-deploy.php
```

JWT dosyası yalnızca token metnini içermeli. Gizli bilgileri komut satırına koymayın. Yapılandırmada:

| Alan | İçerik |
|---|---|
| `database` | Liman ana PostgreSQL DSN'si; kullanıcı `liman`, çalışma parolası |
| `admin_database` | **Aynı DSN**, migration/GRANT uygulayabilen yönetici hesabı |
| `scopes` | Gerçek Liman sunucu UUID'si → izin verilen tatbikat CIDR ağları |
| `api.base_url` | Güvenilen sertifikalı Liman HTTPS adresi |
| `api.token_file` | Yönetici JWT dosyası |
| `api.ca_file` | Gerekiyorsa özel CA dosyası; TLS doğrulaması kapatılmaz |
| `api.extension_id` | İsteğe bağlı, varsa kayıtlı eklenti UUID'siyle doğrulanır |
| `smoke.server_id` | Smoke için ayrılan gerçek Liman sunucu UUID'si |
| `smoke.alpha_ip`, `smoke.bravo_ip` | Gerçek erişilebilir test hedefleri; scope CIDR listesinde olmalı |
| `smoke.*_https_hostname` | HTTPS sertifikası/sanal sunucu adı; TCP bağlantısı yine belirtilen IP'ye gider |
| `smoke.expect_all_up` | Varsayılan `true`: herhangi bir down/stale durumunda test başarısız |

`Team Alpha` ve `Team Bravo` sanal **takım kayıtlarıdır**; ağ servisleri kendiliğinden oluşturulmaz. Her IP'de HTTP:80, HTTPS:443 ve SSH:22 servisleri ile ICMP yanıtı hazır olmalı. TLS sertifikaları worker'ın sistem CA deposunda güvenilir olmalıdır. İzole test kapsamı kullanın; smoke kayıtları silinmez ve bu kapsamın puanlarına etki eder.

## 3. Kurulumu çalıştır

```bash
sudo bash deploy.sh /tam/yol/LockedShields-1.0.0.zip /etc/liman/lockedshields-deploy.php
```

Betik sırasıyla:

1. Root, PHP, ACL, Linux `liman` hesabı, systemd, PostgreSQL ve yönetici API erişimini doğrular; ayrı deployment `flock` kilidi alır.
2. ZIP içeriğini yol geçişi/symlink/tekrarlanan dosya açısından denetler; staging dizinine açar, PHP dosyalarını lint eder.
3. Liman'ın yerleşik `GET /api/settings/extensions`, `POST /api/settings/extensions/upload` ve `POST /api/extensions/assign` uçlarını kullanır. Aynı sürüm kaydı/ataması varsa yeniden oluşturmaz.
4. `database/migrations.sql` dosyasını ana PostgreSQL'de çalıştırır. PostgreSQL `liman` rolü yoksa verilen parola ile oluşturur; mevcut rolün parolasını değiştirmez.
5. Dört tablo ve dört identity sequence için `database/grants_liman.sql` üzerinden **ALL PRIVILEGES** verir ve her yetkiyi doğrular. Başka Core tablolarına GRANT eklenmez.
6. Dosyaları `/liman/server/storage/extensions/lockedshields` dizinine yerleştirir. İncelenen Liman sürümünün gerçek eklenti yolu `/liman/extensions/lockedshields` olduğu için buradan yeni dizine symlink kurar.
7. Yalnızca runtime ayarlarını `/etc/liman/locked-shields.php` dosyasına yazar. Yönetici DB parolası ve JWT bu dosyaya kopyalanmaz. Liman sandbox UUID hesabının okuma/geçiş ACL'lerini ayarlar.
8. `/etc/systemd/system/lockedshields-probe.service` dosyasını kurar, daemon-reload ve enable/start uygular, servisin aktif kaldığını kontrol eder.

`liman` PostgreSQL rolüne istenen tam yetkiler verildiğinden bu profil SQL üzerinden log/inject değiştirme ve silmeye de izin verir. Uygulama API'si yine yalnızca ekleme sunar. Önceki kısıtlı rol profilinin append-only veritabanı garantisi bu profilde geçerli değildir.

## 4. Worker ve kilit

Kurulan servis:

```ini
[Service]
User=liman
Group=liman
WorkingDirectory=/liman/server/storage/extensions/lockedshields
ExecStart=/usr/bin/php /liman/server/storage/extensions/lockedshields/scripts/probe_worker.php
Restart=always
RestartSec=10
```

Tam dosya `deploy/lockedshields-probe.service` içindedir. `StateDirectory` kalıcı kilit dizinini hazırlar. `scripts/probe_worker.php`, `/var/lib/lockedshields-probe/worker.lock` üzerinde `LOCK_EX | LOCK_NB` alır ve dosya tanıtıcısını süreç boyunca açık tutar. İkinci süreç **75** koduyla çıkar. Kilit dosyası silinmez; böylece farklı inode üzerinde eşzamanlı iki kilit oluşmaz. PostgreSQL lease ve `SKIP LOCKED` kontrolleri de korunur.

```bash
sudo systemctl status lockedshields-probe.service
sudo systemctl show lockedshields-probe.service -p User -p MainPID -p Restart -p RestartUSec
sudo journalctl -u lockedshields-probe.service -n 80 --no-pager
```

Manuel tek tur (daemon çalışıyorsa 75 beklenir):

```bash
sudo -u liman php /liman/server/storage/extensions/lockedshields/scripts/probe_worker.php --once
```

`bin/worker.php` çekirdek döngü olarak tutulur. Operasyonel çalıştırmalarda kilitli `scripts/probe_worker.php` girişini kullanın.

## 5. Uçtan uca smoke testi

```bash
sudo bash /liman/server/storage/extensions/lockedshields/scripts/smoke_test.sh \
  /etc/liman/lockedshields-deploy.php > smoke-result.json
echo "Smoke çıkış kodu: $?"
```

İlerleme mesajları stderr'e, JSON raporu stdout'a yazılır. Script şunları doğrular:

- Scope içinde `Team Alpha` ve `Team Bravo`; her takımda `[smoke] HTTP:80`, `[smoke] HTTPS:443`, `[smoke] SSH:22`, `[smoke] PING` — toplam sekiz servis.
- Her servis için test başlangıcındaki son log ID'sinden daha yeni bir `ls_service_logs` satırı. Eski loglar başarı sayılmaz.
- Daemon çalışıyorsa işleri due işaretleyerek onu tetikler; daemon yoksa kilitli worker'ı `liman` hesabıyla bir tur çalıştırır. Başka lease'leri silmez.
- **Gerçek HTTP panel API'si** `ls_add_inject` ile Alpha'ya `task/200`, Bravo'ya `penalty/100` yazar; ikinci kayıt DB'de **-100** olarak saklanır. Doğrudan inject SQL INSERT'i kullanılmaz.
- Aynı deterministik `request_id` ile tekrar gönderip iki kayıt oluşmadığını kontrol eder. Aynı testi tekrar çalıştırmak +200/-100 puanını tekrar eklemez; aynı Liman kullanıcı kimliğiyle çalıştırın.
- `getScoreboardData` fonksiyonunu engine üzerinden çağırır. Bu, mevcut `ls_dashboard` iznini kontrol eden alias'tır; ayrıca yeni bir rol izni gerekmez.
- JSON'un `generated_at` zamanında ham logları bağımsız hesaplayarak SLA yüzdelerini, uptime/downtime/unknown sürelerini, matris durumlarını ve `ağırlıklı SLA + inject` toplamlarını karşılaştırır.

Çıktı şekli (sayılar canlı ölçüm zamanına göre değişir):

```json
{
  "result": "PASS",
  "all_targets_up": true,
  "verified": {
    "fresh_probe_logs": 8,
    "sla_and_matrix": true,
    "panel_injects": true,
    "total_scores": true
  },
  "teams": [
    {"name": "Team Alpha", "sla_score": 350, "inject_score": 200, "total_score": 550},
    {"name": "Team Bravo", "sla_score": 340, "inject_score": -100, "total_score": 240}
  ],
  "matrix": [],
  "getScoreboardData": {}
}
```

İlk ölçüme kadar geçen zaman uptime sayılmadığından sağlıklı hedeflerde başlangıç SLA'sı hemen %100 olmak zorunda değildir. Rapor sabit bir puanı beklemek yerine ham ölçüm sürelerini doğrular. `expect_all_up=false` yalnızca hesap/log akışını test etmek içindir; raporda `all_targets_up=false` açıkça gösterilir.

Token hatası, eksik log, puan farkı veya varsayılan modda down/stale hedef **sıfırdan farklı çıkış kodu** üretir. Aynı adlı mevcut gerçek takımlara/uyuşmayan servislere yazılmaz. Smoke verileri otomatik silinmez; test kapsamı içinde denetim için korunur.

## Yeniden kurulum ve hata durumu

Kurulum ve smoke tekrar çalıştırılabilir. Önceki dosya/servis/runtime ayarları `.lockedshields-backup.*` dizininde root'a özel saklanır. Kurulum servis başlatma dahil hata verirse önceki dosyalar ve önceki aktif servis geri yüklenir. Eklemeli migration/GRANT ve yerleşik API kayıt/atamaları geri silinmez; sonraki çalıştırma bunları tekrar kullanır. Yedekler otomatik silinmez.

## Geliştirme doğrulaması

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build.ps1
$env:TEST_POSTGRES_PASSWORD = -join ((48..57) + (97..102) | Get-Random -Count 48 | % {[char]$_})
$env:TEST_RUNTIME_DB_PASSWORD = -join ((48..57) + (97..102) | Get-Random -Count 48 | % {[char]$_})
docker compose -f tests/compose.yaml build php
docker compose -f tests/compose.yaml up -d postgres
docker compose -f tests/compose.yaml run --rm php bash tests/deployment.sh
docker compose -f tests/compose.yaml down
```

Bu testler gerçek PostgreSQL, `liman` hesabındaki PHP daemon, gerçek şifreli Liman PHP Sandbox ve gerçek HTTP/HTTPS/SSH/ICMP probe bağlantılarını kullanır. Container içindeki systemd yönetimi ve Liman kayıt/yükleme uçları test doubles ile temsil edilir; üretilen unit ayrıca `systemd-analyze verify` ile doğrulanır. Canlı hedef sunucuda systemd ve yerleşik Liman yüklemesi ayrıca `deploy.sh`/smoke ile doğrulanmalıdır; hedef erişim bilgileri sağlanmadan bunların üretimde çalıştırıldığı iddia edilmez.

10 Eylül 2026 doğrulaması: kurulum ve yeniden kurulum, sekiz taze servis ölçümü, API inject idempotency, flock çakışma koruması, servis başlatma hatasında geri alma, hatalı JWT ve down hedef senaryoları geçti. Mevcut 57 backend kontrolü yeniden geçti. Yerel `dist/smoke-result.example.json` ve `dist/smoke-down.example.json` test ortamından alınmış gerçek rapor örnekleridir; üretim ölçümü değildir.
