# Tatbikat denetim wrapper'ları (BT-1…BT-3)

`config/locked-shields.exercise.example.php` içindeki `SSH_AUDIT` anahtarlarının
çalıştırdığı **başlangıç şablonlarıdır**. Her biri tek bir yanlış yapılandırmayı ölçer:

| Anahtar (allowlist) | Wrapper | Patched koşulu (exit 0) |
|---|---|---|
| `bt1-python-setuid-cap` | `check-bt1-python-cap` | `getcap python3` içinde `cap_setuid` yok |
| `bt1-ansible-task-writable` | `check-bt1-ansible-task-perm` | task YAML grup/dünya-yazılabilir değil |
| `bt2-sudo-find-nopasswd` | `check-bt2-sudo-find` | sudoers'ta NOPASSWD `find` yok |
| `bt2-timer-script-writable` | `check-bt2-timer-perm` | timer betiği dünyaya yazılabilir değil |
| `bt3-suid-nano` | `check-bt3-nano-suid` | `nano` üzerinde SUID yok |
| `bt3-sudo-ld-preload` | `check-bt3-ld-preload` | sudoers'ta `env_keep LD_PRELOAD` yok |
| `bt3-soc-eval-injection` | `check-bt3-soc-eval` | SOC betiği `eval` içermiyor |

## Sözleşme
- **`exit 0` = zafiyet KAPANDI (Patched).** Sıfır dışı/timeout = `Vulnerable`.
- Cenk worker bunları allowlist'ten **argv** olarak, shell açmadan çalıştırır.
- Yol/kullanıcı varsayılanları ortam değişkeniyle ayarlanır: `TARGET_PYTHON`,
  `ANSIBLE_TASK`, `SUDO_USER_CHECK`, `TIMER_SCRIPT`, `SOC_SCRIPT`. Kendi makinelerinize
  göre bu yolları güncelleyin — bunlar şablondur, olduğu gibi üretime koymayın.

## Kurulum
```sh
sudo install -m 0755 scripts/exercise-audit/check-* /usr/local/libexec/
```
`SSH_AUDIT` yerel bir denetim wrapper'ıdır; denetim uzak hedefte çalışacaksa wrapper
SSH taşımasını kendisi yönetir (örn. kontrol komutunu `ssh hedef -- '...'` içine sarın).
Gizli bilgi (parola/anahtar) bu betiklere veya komut satırına yazılmaz.
