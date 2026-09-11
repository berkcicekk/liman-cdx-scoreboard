<?php
declare(strict_types=1);
namespace LockedShields;

final class Config
{
    public static function load(): array
    {
        $path = getenv('LS_CONFIG_PATH') ?: '/etc/liman/locked-shields.php';
        if (!is_readable($path)) {
            throw new \RuntimeException('Scoreboard yapılandırması bulunamadı. Kurulum rehberini uygulayın.');
        }
        $config = require $path;
        if (!is_array($config) || !isset($config['database'], $config['scopes'])) {
            throw new \RuntimeException('Scoreboard yapılandırması geçersiz.');
        }
        return $config;
    }
}
