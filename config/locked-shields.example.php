<?php
// Copy OUTSIDE the extension directory to /etc/liman/locked-shields.php.
// Use the SAME database/host as Liman's DB_* configuration, with a restricted role.
return [
    'database' => [
        'dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=liman;connect_timeout=5',
        'username' => 'ls_scoreboard',
        'password' => 'REPLACE_WITH_A_RANDOM_PASSWORD',
    ],
    // Exact Liman server UUIDs -> explicitly authorized exercise networks.
    // Loopback, link-local and public addresses are allowed ONLY if listed here.
    'scopes' => [
        'REPLACE_WITH_LIMAN_SERVER_UUID' => ['192.0.2.0/24'],
    ],
    'parallel_probes' => 8,
    // Database command values are allowlist keys. argv is executed directly,
    // never through a shell. Exit code 0 means the finding is remediated.
    'vulnerability_interval_seconds' => 10,
    'vulnerability_checks' => [
        // 'REPLACE_WITH_LIMAN_SERVER_UUID' => [
        //     'dirtypipe-kernel-patch' => ['check_type'=>'SSH_AUDIT','argv'=>['/usr/local/libexec/check-dirtypipe-patch'],'timeout_ms'=>3000],
        //     'remote-remediation-check' => ['check_type'=>'REMOTE_EXPLOIT','argv'=>['/usr/local/libexec/check-remote-remediation'],'timeout_ms'=>3000],
        // ],
    ],
    // HTTPS webhooks are sent only when a vulnerability changes status.
    // Never place credentials in a webhook URL; use the provider's secret store.
    'notifications' => [
        // 'REPLACE_WITH_LIMAN_SERVER_UUID' => ['webhooks'=>['https://alerts.example.invalid/cenk']],
    ],
    // Optional credentials, scoped to a Liman server. Only references go into PostgreSQL.
    // WINRM options: {"credential":"winrm-reader"}; LDAP: {"tls":true,"credential":"ldap-reader"}.
    'credentials' => [
        // 'REPLACE_WITH_LIMAN_SERVER_UUID' => [
        //     'winrm-reader' => ['username'=>'DOMAIN\\probe','password'=>'SECRET','auth'=>'ntlm'],
        //     'ldap-reader' => ['username'=>'cn=probe,dc=example,dc=test','password'=>'SECRET'],
        // ],
    ],
];
