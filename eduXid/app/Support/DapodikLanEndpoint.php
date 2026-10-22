<?php
/**
 * V21R146 — endpoint Dapodik lokal / jaringan privat sekolah.
 * Tidak memperbolehkan alamat DNS, URL, IP publik, atau port input pengguna.
 */
namespace App\Support;

final class DapodikLanEndpoint
{
    public const PORT = '5774';

    public static function resolveHost(string $mode, ?string $ip): string
    {
        if ($mode === 'local') {
            return '127.0.0.1';
        }
        if ($mode !== 'lan') {
            throw new \InvalidArgumentException('Mode Dapodik tidak dikenali.');
        }

        $host = trim((string) $ip);
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException('IP LAN harus IPv4 privat.');
        }
        $octet = array_map('intval', explode('.', $host));
        $private = $octet[0] === 10
            || ($octet[0] === 172 && $octet[1] >= 16 && $octet[1] <= 31)
            || ($octet[0] === 192 && $octet[1] === 168);
        if (!$private) {
            throw new \InvalidArgumentException('Hanya alamat IPv4 privat yang diizinkan.');
        }
        return $host;
    }

    public static function portReachable(string $host): bool
    {
        $connection = @stream_socket_client(
            'tcp://' . $host . ':' . self::PORT,
            $errno,
            $errstr,
            2.5,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($connection)) {
            return false;
        }
        fclose($connection);
        return true;
    }
}
