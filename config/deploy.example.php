<?php
// Save as /etc/liman/lockedshields-deploy.php, root:root 0600.
// Never put this filled file or tokens in the extension ZIP.
return [
    'database' => [
        'dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=liman;connect_timeout=5',
        'username' => 'liman',
        'password' => 'REPLACE_RUNTIME_DB_PASSWORD',
    ],
    'admin_database' => [
        // Must point to the SAME PostgreSQL instance and database as above.
        'dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=liman;connect_timeout=5',
        'username' => 'postgres',
        'password' => 'REPLACE_MIGRATION_DB_PASSWORD',
    ],
    'scopes' => ['REPLACE_LIMAN_SERVER_UUID' => ['192.0.2.0/24']],
    'parallel_probes' => 8,
    'credentials' => [],
    'api' => [
        'base_url' => 'https://liman.example.test',
        'engine_path' => '/engine/',
        // A valid Liman admin JWT for native upload/assignment and White Team calls.
        // Read from disk, never passed in argv or logged. Do not use an access-token
        // string here: this client uses Core Bearer JWT + Render Engine token form.
        'token_file' => '/root/.config/lockedshields/liman.jwt',
        // 'ca_file' => '/etc/ssl/certs/exercise-ca.pem',
        // Optional existing ID: validated against the native API registration.
        // 'extension_id' => 'REPLACE_LIMAN_EXTENSION_UUID',
    ],
    'smoke' => [
        // Use a dedicated Liman test server scope to keep exercise scores separate.
        'server_id' => 'REPLACE_LIMAN_SERVER_UUID',
        'alpha_ip' => '192.0.2.10',
        'bravo_ip' => '198.51.100.10',
        // TLS names must match trusted certificates; IP remains pinned to alpha/bravo_ip.
        'alpha_https_hostname' => 'alpha.example.test',
        'bravo_https_hostname' => 'bravo.example.test',
        'timeout_seconds' => 60,
        'expect_all_up' => true,
    ],
];
