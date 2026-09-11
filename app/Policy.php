<?php
declare(strict_types=1);
namespace LockedShields;

final class Policy
{
    public static function authorize(string $permission): string
    {
        if (!function_exists('can') || !function_exists('user') || !function_exists('server') || empty(user()->id) || !can($permission)) {
            throw new \DomainException('Bu işlem için Liman fonksiyon yetkiniz yok.', 403);
        }
        return self::uuid((string) server()->id);
    }

    public static function uuid(string $value): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value)) {
            throw new \InvalidArgumentException('Geçerli UUID gerekli.');
        }
        return strtolower($value);
    }

    public static function target(string $ip, array $networks): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Hedef IPv4/IPv6 olmalıdır; HTTP sanal sunucu adı seçeneklerde belirtilir.');
        }
        $address = inet_pton($ip);
        foreach ($networks as $cidr) {
            $parts = explode('/', $cidr, 2);
            $network = @inet_pton($parts[0]);
            if ($network === false || strlen($network) !== strlen($address)) { continue; }
            $bits = isset($parts[1]) ? filter_var($parts[1], FILTER_VALIDATE_INT) : strlen($network) * 8;
            if ($bits === false || $bits < 0 || $bits > strlen($network) * 8) { continue; }
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($network, 0, $bytes) === substr($address, 0, $bytes) &&
                ($rest === 0 || (ord($network[$bytes]) & (255 << (8 - $rest))) === (ord($address[$bytes]) & (255 << (8 - $rest))))) {
                return $ip;
            }
        }
        throw new \InvalidArgumentException('Hedef bu Liman sunucusunun tatbikat ağlarında değil.');
    }
}
