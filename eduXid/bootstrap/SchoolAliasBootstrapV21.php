<?php

declare(strict_types=1);

namespace EduXid\Bootstrap;

use RuntimeException;

/**
 * Bootstrap key alias sekolah pada instalasi eduXid SEKOLAH.
 *
 * Berjalan sebelum Laravel membaca Dotenv/config: launcher EXE lama yang
 * memulai Artisan tetap menghasilkan key pada instalasi pertama.
 * Tidak pernah membuat/menyalin kunci ini pada instalasi GTK.
 */
final class SchoolAliasBootstrapV21
{
    public static function ensure(string $projectRoot): void
    {
        $envPath = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envPath)) {
            return; // Instalasi Laravel belum menyiapkan .env.
        }

        // Jalur cepat: instalasi yang sudah siap tidak perlu mengunci file
        // pada setiap HTTP request, autosync PWA atau job antrean.
        $initial = @file_get_contents($envPath);
        if (!is_string($initial)) {
            throw new RuntimeException('Konfigurasi .env sekolah tidak dapat dibaca.');
        }
        $peek = self::readValues($initial);
        $edition = strtolower((string) ($peek['EDUXID_EDITION'] ?? ''));
        $type = strtolower((string) ($peek['EDUXID_INSTALLATION_TYPE'] ?? ''));
        if ($type === 'teacher' || $edition === 'teacher'
            || ($type !== 'school' && $edition !== 'school')
            || trim((string) ($peek['APP_KEY'] ?? '')) === '') {
            return;
        }
        $candidate = (string) ($peek['EDUXID_SCHOOL_ALIAS_KEY'] ?? '');
        if ($candidate !== '' && !self::validSecret($candidate)) {
            throw new RuntimeException('EDUXID_SCHOOL_ALIAS_KEY ada tetapi tidak valid; key tidak diganti.');
        }
        if ($candidate !== '' && ($peek['EDUXID_IDENTITY_PROTOCOL'] ?? '') === 'v21-opaque') {
            return;
        }

        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Folder bootstrap identitas eduXid tidak dapat dibuat.');
        }
        $lock = @fopen($dir . DIRECTORY_SEPARATOR . 'eduxid-school-alias.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Tidak dapat mengunci bootstrap identitas sekolah.');
        }
        try {
            $current = @file_get_contents($envPath);
            if (!is_string($current)) {
                throw new RuntimeException('Konfigurasi .env sekolah tidak dapat dibaca.');
            }
            $values = self::readValues($current);
            $edition = strtolower((string) ($values['EDUXID_EDITION'] ?? ''));
            $type = strtolower((string) ($values['EDUXID_INSTALLATION_TYPE'] ?? ''));
            if ($type === 'teacher' || $edition === 'teacher') {
                return; // Key sumber alias tidak boleh masuk laptop GTK.
            }
            if ($type !== 'school' && $edition !== 'school') {
                return; // Bukan instalasi Admin Sekolah.
            }
            if (trim((string) ($values['APP_KEY'] ?? '')) === '') {
                return; // Hanya launcher first-install yang membuat APP_KEY.
            }
            $secret = (string) ($values['EDUXID_SCHOOL_ALIAS_KEY'] ?? '');
            if ($secret !== '' && !self::validSecret($secret)) {
                throw new RuntimeException('EDUXID_SCHOOL_ALIAS_KEY ada tetapi tidak valid; key tidak diganti.');
            }
            $protocol = (string) ($values['EDUXID_IDENTITY_PROTOCOL'] ?? '');
            if ($protocol === 'v21-opaque' && $secret !== '') {
                return;
            }
            if ($secret === '') {
                // V21R94: No silent rekey. Initial creation belongs ONLY to
                // LauncherInstallGuardV21::prepare() on a clean, new School.
                // Existing schools must restore the original alias secret;
                // otherwise published Cloud IDs become unrelated aliases.
                throw new RuntimeException(
                    'BLOCKED V21R94: kunci alias sekolah hilang. Pulihkan melalui vault identitas sekolah; '
                    . 'bootstrap tidak boleh membuat kunci baru secara diam-diam.'
                );
            }
            $next = self::setValue($current, 'EDUXID_IDENTITY_PROTOCOL', 'v21-opaque');
            $next = self::setValue($next, 'EDUXID_SCHOOL_ALIAS_KEY', $secret);
            self::saveAtomically($envPath, $next);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function readValues(string $source): array
    {
        $values = [];
        foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/D', $line, $match) !== 1) {
                continue;
            }
            $key = $match[1];
            if (array_key_exists($key, $values)) {
                throw new RuntimeException('Ada entri .env ganda untuk konfigurasi ' . $key . '.');
            }
            $value = trim($match[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }
        return $values;
    }

    private static function validSecret(string $secret): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $secret) !== 1) {
            return false;
        }
        $decoded = base64_decode(strtr($secret, '-_', '+/') . '=', true);
        return is_string($decoded) && strlen($decoded) === 32
            && rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') === $secret;
    }

    private static function setValue(string $source, string $key, string $value): string
    {
        $newline = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $count = 0;
        $replacement = preg_replace_callback(
            '/^' . preg_quote($key, '/') . '[ \t]*=[^\r\n]*(\r?)(?=\n|$)/m',
            static fn (array $match): string => $key . '=' . $value . $match[1],
            $source,
            -1,
            $count
        );
        if (!is_string($replacement)) {
            throw new RuntimeException('Gagal memproses .env eduXid.');
        }
        if ($count > 1) {
            throw new RuntimeException('Ada entri ' . $key . ' ganda pada .env.');
        }
        if ($count === 0) {
            $replacement = rtrim($replacement, "\r\n") . $newline . $key . '=' . $value . $newline;
        }
        return $replacement;
    }

    private static function saveAtomically(string $path, string $contents): void
    {
        $temp = $path . '.alias-v21-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            $bytes = @file_put_contents($temp, $contents, LOCK_EX);
            if ($bytes === false || $bytes !== strlen($contents)) {
                throw new RuntimeException('Gagal menulis konfigurasi identitas sementara.');
            }
            @chmod($temp, 0600);
            if (!@rename($temp, $path)) {
                throw new RuntimeException('Gagal menyimpan .env secara atomik; file asli dipertahankan.');
            }
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
    }
}
