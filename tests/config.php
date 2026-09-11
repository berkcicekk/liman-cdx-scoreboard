<?php
$password = getenv('TEST_POSTGRES_PASSWORD');
if (!is_string($password) || $password === '') { throw new RuntimeException('TEST_POSTGRES_PASSWORD is required.'); }
return [
    'database'=>['dsn'=>'pgsql:host=postgres;port=5432;dbname=ls_test;connect_timeout=5','username'=>'ls_test','password'=>$password],
    'scopes'=>[
        '11111111-1111-4111-8111-111111111111'=>['127.0.0.0/8','::1/128'],
        '22222222-2222-4222-8222-222222222222'=>['127.0.0.0/8'],
    ],
    'parallel_probes'=>8,
];
