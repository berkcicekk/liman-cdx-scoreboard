# Makine Hazırlayan Ekip İçin Teknik Rehber

Bu rehber, Cenk Scoreboard eklentisinin yerel Docker test ortamını veya bir Liman makinesini hazırlayan ekip içindir. Eklenti, Liman Core, PostgreSQL ve probe worker üzerinden çalışır; HTTP/SSH servis sürekliliği ile zafiyet uyumluluğunu aynı skor tablosunda gösterir.

## Sorumluluk sınırı

Makine hazırlayan ekip aşağıdakilerden sorumludur:

- Docker test ortamında Docker Desktop/Engine ve Compose'un çalışması.
- Hedef ağlara erişimin yalnızca yetkili tatbikat ağlarıyla sınırlanması.
- PostgreSQL migration ve sınırlı çalışma zamanı rolünün kurulması.
- Worker sürecinin tek örnek olarak çalışması.
- Zafiyet denetim komutlarının administrator yapılandırmasındaki allowlist üzerinden tanımlanması.

White Team ise takım, servis, görev/ceza ve zafiyet kayıtlarının içerik sorumluluğunu taşır.

## Yerel Docker ortamı

Ön koşullar:

- Docker Desktop veya Docker Engine 29+.
- Docker Compose v2. Git Bash veya WSL kullanılıyorsa `bash`.
- Yerel `8000` portunun boş olması.

Proje kökünde aşağıdaki komutu çalıştırın:

```bash
./local_run.sh
```

Betik şunları yapar:

1. `liman`, `postgres`, `redis`, `webssh` ve `mock-target` konteynerlerini başlatır.
2. Eklenti kaynaklarını hot-reload için `/liman/server/storage/extensions/lockedshields` altına mount eder.
3. Liman manifest adı `Cenk` olduğundan renderer için aynı kaynakları `/liman/extensions/cenk` altında da sunar.
4. `database/migrations.sql` ve `database/grants.sql` dosyalarını Liman PostgreSQL veritabanında çalıştırır.
5. Local Blue Team, HTTP:80, SSH:22 ve örnek `DirtyPipe Kernel Patch` zafiyet denetimini oluşturur.
6. `probe_worker.php` dosyasını systemd yerine `nohup`, PID dosyası ve `flock` ile başlatır.

Betik sonunda giriş bilgilerini ve doğrudan Scoreboard bağlantısını yazdırır. Panel adresi `http://localhost:8000`'dir.

İlk çalıştırmada betik, Docker servisleri için rastgele parolaları `.env.local` içinde üretir. Bu dosya `.gitignore` kapsamındadır; paylaşmayın, paketlemeyin ve kaynak kontrolüne eklemeyin. Yeniden oluşturmak için ortamı kapattıktan sonra yalnızca bu yerel dosyayı silip `./local_run.sh` komutunu çalıştırın.

Durumu incelemek için:

```bash
docker compose -f docker-compose.local.yml ps
docker compose -f docker-compose.local.yml logs --tail=100 liman
docker compose -f docker-compose.local.yml exec liman bash /opt/lockedshields-local/worker.sh status
```

Ortamı durdurmak için:

```bash
docker compose -f docker-compose.local.yml down
```

`down -v` PostgreSQL ve Liman kalıcı verilerini siler; yalnızca sıfırdan test gerektiğinde kullanın.

## Ağ ve portlar

| Bileşen | Erişim | Amaç |
|---|---:|---|
| Liman Web UI | `127.0.0.1:8000` | Yerel yönetim paneli |
| PostgreSQL | Sadece Docker ağı | Liman ve eklenti verileri |
| Redis | Sadece Docker ağı | Liman kuyruk/oturum altyapısı |
| mock-target HTTP | Docker ağı `80` | HTTP probe ve örnek zafiyet denetimi |
| mock-target SSH | Docker ağı `22` | SSH probe |

Üretimde eklentinin erişebileceği hedefler `/etc/liman/locked-shields.php` içindeki `scopes` CIDR listesiyle sınırlandırılır. Public, loopback veya link-local adresler açıkça bu listeye alınmadıkça kullanılamaz.

## PostgreSQL hazırlığı

Migration, Liman'ın mevcut PostgreSQL veritabanında tablo sahibi tarafından çalıştırılır:

```bash
psql -v ON_ERROR_STOP=1 -d LIMAN_DB_ADI -f database/migrations.sql
```

Ardından sadece eklentinin tablolarına erişebilen çalışma zamanı rolünü oluşturun ve grant dosyasını uygulayın:

```sql
CREATE ROLE ls_scoreboard LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD 'rastgele-uzun-parola';
\i database/grants.sql
```

`ls_scoreboard` rolü Core tablolarına erişmez. `ls_vulnerabilities` tablosunda yalnızca durum, kontrol zamanı ve worker lease sütunlarını güncelleyebilir; `ls_vulnerability_logs` tablosuna yalnızca eklemeli denetim kanıtı yazabilir.

## Worker işletimi

Worker servis probe'larını ve zafiyet denetimlerini çalıştırır. Her zafiyet kontrolü `last_checked_at` değerine göre varsayılan 10 saniye aralıkla planlanır. Aynı kaydı iki worker'ın yürütmesini önlemek için PostgreSQL `FOR UPDATE SKIP LOCKED` ve 20 saniyelik lease kullanılır.

Yerel ortamda:

```bash
docker compose -f docker-compose.local.yml exec liman bash /opt/lockedshields-local/worker.sh status
docker compose -f docker-compose.local.yml exec liman tail -n 100 /var/lib/lockedshields-probe/worker.log
```

Üretimde sistemd tanımı [deploy/lockedshields-probe.service](deploy/lockedshields-probe.service) içindedir. Eklenti adı Cenk olduğundan servis çalışma dizini `/liman/server/storage/extensions/cenk` olmalıdır.

## Otomatik zafiyet denetimleri

`ls_vulnerabilities` tablosu aşağıdaki temel alanları içerir:

| Alan | Açıklama |
|---|---|
| `team_id` | Denetimin ait olduğu Blue Team |
| `name` | Görünen denetim adı |
| `check_type` | `REMOTE_EXPLOIT` veya `SSH_AUDIT` |
| `command` | Yapılandırma allowlist anahtarı |
| `points` | Denetim `Patched` olduğunda geçerli güvenlik puanı |
| `status` | `Patched` veya `Vulnerable` |
| `last_checked_at` | Son çalışma zamanı |

`command` alanına kabuk komutu, `ssh user@host ...` veya kullanıcıdan gelen metin yazmayın. Bu alan, yalnızca yapılandırmadaki bir anahtardır. Gerçek komut `argv` dizisi olarak tanımlanır; worker bunu shell açmadan çalıştırır.

Örnek `/etc/liman/locked-shields.php` yapılandırması:

```php
'vulnerability_interval_seconds' => 10,
'vulnerability_checks' => [
    'SUNUCU_UUID' => [
        'dirtypipe-kernel-patch' => [
            'check_type' => 'SSH_AUDIT',
            'argv' => ['/usr/local/libexec/check-dirtypipe-patch'],
            'timeout_ms' => 3000,
        ],
        'remote-remediation-check' => [
            'check_type' => 'REMOTE_EXPLOIT',
            'argv' => ['/usr/local/libexec/check-remote-remediation'],
            'timeout_ms' => 3000,
        ],
    ],
],
```

Denetim komutu `0` ile biterse kayıt `Patched` olur ve `points` takımın güncel güvenlik skoruna eklenir. Başka bir çıkış kodu, timeout veya allowlist eşleşmemesi kaydı `Vulnerable` yapar ve puan eklenmez. Puanlar anlık uyumluluk puanıdır; aynı denetim her çalıştığında tekrar tekrar puan yazılmaz.

Yeni bir kayıt eklemek için örnek SQL:

```sql
INSERT INTO ls_vulnerabilities (team_id, name, check_type, command, points)
VALUES (42, 'DirtyPipe Kernel Patch', 'SSH_AUDIT', 'dirtypipe-kernel-patch', 25);
```

Bu SQL'deki `command` değeri, yapılandırmadaki `vulnerability_checks[SUNUCU_UUID]['dirtypipe-kernel-patch']` anahtarıyla aynı olmalıdır.

Her çalıştırma `ls_vulnerability_logs` tablosuna eklemeli olarak kaydedilir. Kayıt; denetim zamanı, patched sonucu, exit code, süre ve en fazla 1000 karakterlik maskelenmiş çıktıyı içerir. `password`, `token`, `secret`, `authorization` ve API anahtarı biçimindeki değerler saklanmadan önce maskelenir. Ham denetim çıktısına parola veya erişim anahtarı yazmayın.

İsteğe bağlı bildirim için yapılandırmada sunucu UUID'si altında `notifications => ['webhooks'=>['https://...']]` tanımlayın. Cenk yalnızca zafiyet durumu değiştiğinde HTTPS POST gönderir; tekrarlayan polling sonuçları bildirim üretmez. Webhook URL'sine kullanıcı adı, parola veya token koymayın.

## Kabul kontrolleri

Kurulumdan sonra aşağıdaki kontrollerin tamamı geçmelidir:

```bash
# Worker ve zafiyet sonucu
docker compose -f docker-compose.local.yml exec liman bash /opt/lockedshields-local/worker.sh status
docker compose -f docker-compose.local.yml exec postgres psql -U liman -d liman \
  -c "SELECT name,status,points,last_checked_at FROM ls_vulnerabilities; SELECT vulnerability_id,is_patched,exit_code,duration_ms,output,checked_at FROM ls_vulnerability_logs ORDER BY checked_at DESC LIMIT 20;"

# Servis ölçümleri
docker compose -f docker-compose.local.yml exec postgres psql -U liman -d liman \
  -c "SELECT s.name,l.is_up FROM ls_services s JOIN LATERAL (SELECT is_up FROM ls_service_logs WHERE service_id=s.id ORDER BY checked_at DESC LIMIT 1) l ON true;"
```

Tarayıcıda Cenk Scoreboard sayfasında HTTP ve SSH hücreleri yeşil, `DirtyPipe Kernel Patch` uyumluluk hücresi yeşil **Kapatıldı** görünmelidir. `mock-target` durdurulduğunda servisler ve örnek denetim kırmızıya dönmelidir:

```bash
docker compose -f docker-compose.local.yml stop mock-target
docker compose -f docker-compose.local.yml start mock-target
```

## Sorun giderme

| Belirti | Kontrol ve çözüm |
|---|---|
| Liman açılmıyor | `docker compose -f docker-compose.local.yml logs --tail=100 liman` çıktısını inceleyin; 8000 portunu kullanan süreci kapatın. |
| Worker başlamıyor | `worker.log` dosyasını inceleyin; PostgreSQL grant ve `/etc/liman/locked-shields.php` izinlerini doğrulayın. |
| Denetim hep Açık | Tablo `command` anahtarının allowlist ile aynı olduğunu, `argv` dosyasının çalıştırılabilir olduğunu ve komutun `0` döndüğünü doğrulayın. |
| Aynı denetim iki kez çalışıyor | PID dosyası ve `flock` durumu için `worker.sh status` çalıştırın; ikinci worker başlatmayın. |
| UI eski görünümde | `./local_run.sh` çalıştırın; betik Liman konteynerini yeniden başlatır ve mount edilen kaynakları uygular. |

Bu rehberdeki parola, token, SSH anahtarı ve gerçek hedef bilgilerini kaynak koduna veya Git deposuna eklemeyin.
