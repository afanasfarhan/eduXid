<?php

namespace App\Support;

/**
 * Redaksi untuk TAMPILAN / LOG SAJA.
 * Jangan digunakan sebelum autentikasi, pencocokan Dapodik, pairing, atau sync.
 * Identitas asli tetap disimpan oleh modul resmi eduXid.
 */
final class SensitiveDataProtector
{
    public static function maskIdentifier(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return '-';
        $length = strlen($value);
        if ($length <= 4) return str_repeat('*', $length);
        return str_repeat('*', $length - 4) . substr($value, -4);
    }

    public static function maskDeviceId(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return '-';
        if (preg_match('/^([0-9]{8})-([A-Za-z0-9._]+)-(PC|HP)$/iD', $value, $m)) {
            return $m[1] . '-' . self::maskIdentifier($m[2]) . '-' . strtoupper($m[3]);
        }
        return self::maskIdentifier($value);
    }

    public static function redactText(mixed $text): string
    {
        if (!is_scalar($text) && $text !== null) return '[data disembunyikan]';
        $text = (string) ($text ?? '');
        // Jangan pernah menuliskan token, password, cookie atau authorization pada log.
        $text = preg_replace('/((?:authorization|bearer|sync_token|device_token|enrollment_token|admin_key|admin_token|password|api_key|secret|cookie)\s*[=:]\s*)(["\']?)[^\s,;}&"\']+/i', '$1[REDACTED]', $text);
        $text = preg_replace('/\bBearer\s+\S+/i', 'Bearer [REDACTED]', $text);
        // Bentuk JSON seperti {"password":"rahasia"} harus tertutup juga.
        $text = preg_replace('/(["\'](?:password|api_key|device_token|sync_token|admin_key|admin_token|enrollment_token|secret|authorization|cookie)["\']\s*:\s*["\'])[^"\']+/i', '$1[REDACTED]', $text);
        $text = preg_replace('/\b([0-9]{8})-([0-9]{10,20})-(PC|HP)\b/i', '$1-[IDENTITAS]-$3', $text);
        // NIK, NIP, NUPTK numerik umumnya 16–18 digit; hindari nomor panjang utuh dalam log.
        $text = preg_replace('/(?<![0-9])([0-9]{12,20})(?![0-9])/', '[IDENTITAS]', $text);
        return $text;
    }

    public static function redactContext(mixed $value, string $key = '', int $depth = 0): mixed
    {
        if ($depth > 8) return '[data disembunyikan]';
        $name = strtolower($key);
        if (preg_match('/(?:token|password|secret|cookie|authorization|api[_-]?key|credential|private[_-]?key|enrollment)/', $name)) return '[REDACTED]';
        if (preg_match('/(?:^|_)(?:nip|nik|nuptk|nisn|login_id|identity)(?:$|_)/', $name)) return self::maskIdentifier($value);
        if (str_contains($name, 'device_id')) return self::maskDeviceId($value);
        if ($value instanceof \Throwable) return '[' . get_class($value) . ': detail disembunyikan]';
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $item) $result[$k] = self::redactContext($item, (string)$k, $depth+1);
            return $result;
        }
        if (is_object($value)) return '[objek disembunyikan]';
        return is_string($value) ? self::redactText($value) : $value;
    }
}
