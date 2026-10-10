<?php
// TATBIKAT ORNEGI — makine-hazirlayan ekibin BT-1..BT-3 icerigini Cenk'in
// SSH_AUDIT zafiyet kontrollerine baglayan allowlist taslagidir.
//
// Ag-yuzeyli foothold'lar (anonim FTP, Tomcat yonetici paneli) Red Range'in
// port/beacon vektoruyle olculur; bu dosya YEREL YETKI YUKSELTME zafiyetlerini
// Cenk'in zafiyet worker'iyla olcer. argv dogrudan calistirilir, SHELL ACILMAZ.
// Exit code 0 => zafiyet KAPANDI (Patched). Baska her kod/timeout => Vulnerable.
//
// Wrapper betiklerini ops yazar; her anahtarin yaninda wrapper'in dogrulamasi
// gereken "Patched kosulu" yorum olarak verilmistir. Wrapper SSH gerekiyorsa
// baglantiyi kendisi yonetir; parola/anahtar bu dosyaya KONMAZ.
return [
    'database' => [
        'dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=liman;connect_timeout=5',
        'username' => 'ls_scoreboard',
        'password' => 'REPLACE_WITH_A_RANDOM_PASSWORD',
    ],
    // Yalnizca izole tatbikat agi. Gercek BT-1..BT-3 IP'leriyle degistirin.
    'scopes' => [
        'REPLACE_WITH_LIMAN_SERVER_UUID' => ['192.0.2.0/24'],
    ],
    'parallel_probes' => 8,
    'vulnerability_interval_seconds' => 10,

    // Anahtarlar ls_vulnerabilities.command ile BIREBIR eslesmelidir.
    'vulnerability_checks' => [
        'REPLACE_WITH_LIMAN_SERVER_UUID' => [

            // --- BT-1: otomasyon / capability ---
            // Patched: getcap /usr/bin/python3 BOS (cap_setuid+ep kaldirilmis).
            'bt1-python-setuid-cap' => ['check_type'=>'SSH_AUDIT','argv'=>['/usr/local/libexec/check-bt1-python-cap'],'timeout_ms'=>3000],
            // Patched: cron'daki Ansible task dosyasi dusuk-yetkili kullaniciya YAZILAMAZ.
            'bt1-ansible-task-writable' => ['check_type'=>'SSH_AUDIT','argv'=>['/usr/local/libexec/check-bt1-ansible-task-perm'],'timeout_ms'=>3000],

            // --- BT-2: sudo / izolasyon ---
            // Patched: sudoers'ta 'stajyer' icin NOPASSWD find yetkisi YOK.
            'bt2-sudo-find-nopasswd' => ['check_type'=>'SSH_AUDIT','argv'=>['/usr/local/libexec/check-bt2-sudo-find'],'timeout_ms'=>3000],
            // Patched: systemd timer'in tetikledigi betik world-writable DEGIL (stat -c %a).
            'bt2-timer-script-writable' => ['check_type'=>'SSH_AUDIT','argv'=>['/usr/local/libexec/check-bt2-timer-perm'],'timeout_ms'=>3000],

            // --- BT-3: web / savunma araci ---
            // Patched: 'nano' uzerinde SUID biti YOK.
            'bt3-suid-nano' => ['check_type'=>'SSH_AUDIT','argv'=>['/usr/local/libexec/check-bt3-nano-suid'],'timeout_ms'=>3000],
            // Patched: sudoers'ta env_keep+=LD_PRELOAD YOK.
            'bt3-sudo-ld-preload' => ['check_type'=>'SSH_AUDIT','argv'=>['/usr/local/libexec/check-bt3-ld-preload'],'timeout_ms'=>3000],
            // Patched: SOC betigi girdiyi eval ile islemiyor / sanitize ediliyor.
            'bt3-soc-eval-injection' => ['check_type'=>'SSH_AUDIT','argv'=>['/usr/local/libexec/check-bt3-soc-eval'],'timeout_ms'=>3000],
        ],
    ],

    'notifications' => [
        // 'REPLACE_WITH_LIMAN_SERVER_UUID' => ['webhooks'=>['https://alerts.example.invalid/cenk']],
    ],
    'credentials' => [
    ],
];
