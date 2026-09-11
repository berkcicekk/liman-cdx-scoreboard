<?php
declare(strict_types=1);
namespace LockedShields;

final class Database
{
    public static function connect(array $config): \PDO
    {
        $db = $config['database'];
        if (strpos($db['dsn'], 'pgsql:') !== 0) {
            throw new \RuntimeException('Liman PostgreSQL bağlantısı gereklidir.');
        }
        $pdo = new \PDO($db['dsn'], $db['username'], $db['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET TIME ZONE 'UTC'; SET search_path TO public; SET statement_timeout TO '8s'; SET lock_timeout TO '2s'");
        return $pdo;
    }

    public static function query(\PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
