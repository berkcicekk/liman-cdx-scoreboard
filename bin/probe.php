<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
if (function_exists('posix_setsid')) { posix_setsid(); }
$service = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$config = \LockedShields\Config::load();
try {
    \LockedShields\Policy::target($service['host'], $config['scopes'][$service['server_id']] ?? []);
    $options = is_array($service['options']) ? $service['options'] : json_decode($service['options'],true,16,JSON_THROW_ON_ERROR);
    if (isset($options['credential'])) {
        $service['credential'] = $config['credentials'][$service['server_id']][$options['credential']] ?? null;
        if (!is_array($service['credential'])) { throw new RuntimeException('Missing credential'); }
    }
    echo json_encode((new \LockedShields\Probe())->run($service), JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['is_up'=>false, 'latency_ms'=>0, 'detail'=>'Hedef ağı veya kimlik bilgisi referansı geçersiz']);
}
