<?php
declare(strict_types=1);
namespace LockedShields;

final class Input
{
    public static function text($value, int $max): string
    {
        if (!is_scalar($value)) { throw new \InvalidArgumentException('Metin bekleniyor.'); }
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
            throw new \InvalidArgumentException('Metin boş veya izin verilen uzunluğu aşıyor.');
        }
        return $value;
    }

    public static function number($value, float $min, float $max): float
    {
        if (!is_scalar($value) || !is_numeric($value) || !is_finite((float) $value) || (float) $value < $min || (float) $value > $max) {
            throw new \InvalidArgumentException("Sayı $min ile $max arasında olmalıdır.");
        }
        return (float) $value;
    }

    public static function integer($value, int $min, int $max): int
    {
        $number = self::number($value, $min, $max);
        if (floor($number) !== $number) { throw new \InvalidArgumentException('Tam sayı gerekli.'); }
        return (int) $number;
    }

    public static function service(array $data, array $networks): array
    {
        $protocol = strtoupper(self::text($data['protocol'] ?? '', 5));
        $ports = ['HTTP'=>80, 'HTTPS'=>443, 'SSH'=>22, 'DNS'=>53, 'LDAP'=>389, 'WINRM'=>5985, 'PING'=>1];
        if (!isset($ports[$protocol])) { throw new \InvalidArgumentException('Desteklenmeyen protokol.'); }
        $raw = $data['options'] ?? '{}';
        if (!is_string($raw) || strlen($raw) > 8192) { throw new \InvalidArgumentException('Seçenekler çok uzun.'); }
        $object = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) { throw new \InvalidArgumentException('Seçenekler JSON nesnesi olmalıdır.'); }
        $options = (array) $object;
        $allowed = ['path','hostname','expected_status','contains','dns_name','dns_type','ldap_base','tls','credential'];
        if (array_diff(array_keys($options), $allowed)) { throw new \InvalidArgumentException('Bilinmeyen probe seçeneği.'); }
        foreach (['path','hostname','contains','dns_name','dns_type','ldap_base'] as $key) {
            if (isset($options[$key])) { $options[$key] = self::text($options[$key], $key === 'contains' ? 500 : 253); }
        }
        if (isset($options['path']) && (!preg_match('~^/[^\r\n]*$~D', $options['path']) || strpos($options['path'], '//') === 0)) {
            throw new \InvalidArgumentException('HTTP yolu / ile başlamalıdır.');
        }
        foreach (['hostname', 'dns_name'] as $key) {
            if (isset($options[$key]) && !filter_var(rtrim($options[$key], '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new \InvalidArgumentException('Geçersiz alan adı.');
            }
        }
        if ($protocol === 'DNS' && empty($options['dns_name'])) { throw new \InvalidArgumentException('DNS için dns_name gerekli.'); }
        if (isset($options['dns_type']) && !in_array($options['dns_type'], ['A','AAAA'], true)) { throw new \InvalidArgumentException('DNS türü A veya AAAA olmalıdır.'); }
        if (isset($options['expected_status'])) { $options['expected_status'] = self::integer($options['expected_status'], 200, 599); }
        if (isset($options['tls']) && !is_bool($options['tls'])) { throw new \InvalidArgumentException('tls boolean olmalıdır.'); }
        if (isset($options['credential']) && (!is_string($options['credential']) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$options['credential']))) {
            throw new \InvalidArgumentException('Geçersiz kimlik bilgisi referansı.');
        }
        return [
            'team_id' => self::integer($data['team_id'] ?? '', 1, PHP_INT_MAX),
            'name' => self::text($data['name'] ?? '', 80), 'protocol' => $protocol,
            'host' => Policy::target(self::text($data['host'] ?? '', 45), $networks),
            'port' => self::integer(($data['port'] ?? '') === '' ? $ports[$protocol] : $data['port'], 1, 65535),
            'weight' => round(self::number($data['weight'] ?? 100, 0.01, 10000), 2),
            'timeout_ms' => self::integer($data['timeout_ms'] ?? 3000, 100, 5000),
            'interval_seconds' => self::integer($data['interval_seconds'] ?? 10, 10, 300),
            'options' => json_encode((object) $options, JSON_THROW_ON_ERROR),
        ];
    }
}
