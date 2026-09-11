<?php
$runtimePassword = getenv('TEST_RUNTIME_DB_PASSWORD');
$adminPassword = getenv('TEST_POSTGRES_PASSWORD');
if (!is_string($runtimePassword) || $runtimePassword === '' || !is_string($adminPassword) || $adminPassword === '') { throw new RuntimeException('Test database credentials are required.'); }
return [
    'database'=>['dsn'=>'pgsql:host=postgres;port=5432;dbname=ls_test;connect_timeout=5','username'=>'liman','password'=>$runtimePassword],
    'admin_database'=>['dsn'=>'pgsql:host=postgres;port=5432;dbname=ls_test;connect_timeout=5','username'=>'ls_test','password'=>$adminPassword],
    'scopes'=>['33333333-3333-4333-8333-333333333333'=>['127.0.0.0/8']],
    'parallel_probes'=>8,
    'api'=>['base_url'=>'http://127.0.0.1:18889','engine_path'=>'/engine/','token_file'=>'/tmp/ls-api.jwt'],
    'smoke'=>['server_id'=>'33333333-3333-4333-8333-333333333333','alpha_ip'=>'127.0.0.1','bravo_ip'=>'127.0.0.1','alpha_https_hostname'=>'localhost','bravo_https_hostname'=>'localhost','timeout_seconds'=>15,'expect_all_up'=>true],
];
