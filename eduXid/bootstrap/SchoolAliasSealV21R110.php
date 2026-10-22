<?php
/**
 * eduXid V21R110: local irreversible commitment to Admin's school-alias key.
 * Stores no key, no NISN/NIP, no tokens; never fetches Cloud or mutates SQLite.
 * Cloud D1 immutable epoch remains the final authority for published accounts.
 */
declare(strict_types=1);

namespace EduXid\Bootstrap;

use RuntimeException;

final class SchoolAliasSealV21R110
{
    public const RELATIVE = 'storage/app/identity/school-alias-seal-v21r110.json';
    private const FORMAT = 'EDUXID_SCHOOL_ALIAS_SEAL_V21R110_V1';

    public static function path(string $project): string
    {
        return rtrim($project, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE);
    }

    private static function decode(string $encoded): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $encoded) !== 1) {
            throw new RuntimeException('BLOCKED V21R110: format kunci alias sekolah tidak valid.');
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . '=', true);
        if (!is_string($decoded) || strlen($decoded) !== 32 ||
            !hash_equals($encoded, rtrim(strtr(base64_encode($decoded), '+/', '-_'), '='))) {
            throw new RuntimeException('BLOCKED V21R110: kunci alias sekolah tidak valid.');
        }
        return $decoded;
    }

    public static function fingerprint(string $rawSecret): string
    {
        if (strlen($rawSecret) !== 32) throw new RuntimeException('BLOCKED V21R110: panjang secret tidak valid.');
        return hash_hmac('sha256', 'EDUXID-V21R110-LOCAL-ALIAS-SEAL-v1', $rawSecret);
    }

    public static function epoch(string $rawSecret, string $school): string
    {
        self::checkSchool($school);
        return hash_hmac('sha256', "eduxid-v21-directory-epoch:v1\0" . $school, $rawSecret);
    }

    private static function checkSchool(string $school): void
    {
        if (preg_match('/^[0-9]{8}$/D', $school) !== 1) {
            throw new RuntimeException('BLOCKED V21R110: NPSN harus tepat 8 digit.');
        }
    }

    /** @return array<string,string> */
    public static function env(string $project): array
    {
        $file = rtrim($project, '/\\') . DIRECTORY_SEPARATOR . '.env';
        $source = @file_get_contents($file);
        if (!is_string($source) || strlen($source) > 1048576) {
            throw new RuntimeException('BLOCKED V21R110: konfigurasi Admin tidak dapat dibaca.');
        }
        $values = [];
        foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/D', $line, $match) !== 1) continue;
            if (array_key_exists($match[1], $values)) {
                throw new RuntimeException('BLOCKED V21R110: ada konfigurasi .env duplikat.');
            }
            $v = trim($match[2]);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $values[$match[1]] = $v;
        }
        return $values;
    }

    public static function adminSecret(string $project): string
    {
        $env = self::env($project);
        if (strtolower((string)($env['EDUXID_INSTALLATION_TYPE'] ?? '')) !== 'school' ||
            strtolower((string)($env['EDUXID_EDITION'] ?? '')) === 'teacher' ||
            ($env['EDUXID_IDENTITY_PROTOCOL'] ?? '') !== 'v21-opaque') {
            throw new RuntimeException('BLOCKED V21R110: hanya Admin Sekolah V21, bukan Master template atau GTK.');
        }
        return self::decode((string)($env['EDUXID_SCHOOL_ALIAS_KEY'] ?? ''));
    }

    private static function load(string $project): ?array
    {
        $file = self::path($project);
        if (!is_file($file)) return null;
        if (is_link($file) || filesize($file) > 4096) {
            throw new RuntimeException('BLOCKED V21R110: berkas segel identitas tidak dapat dipercaya.');
        }
        $seal = json_decode((string)@file_get_contents($file), true);
        if (!is_array($seal) || ($seal['format'] ?? null) !== self::FORMAT ||
            preg_match('/^[a-f0-9]{64}$/D', (string)($seal['key_commitment'] ?? '')) !== 1 ||
            !in_array((string)($seal['state'] ?? ''), ['initialized', 'cloud_verified'], true)) {
            throw new RuntimeException('BLOCKED V21R110: segel identitas rusak; tidak dibuat ulang otomatis.');
        }
        $school = (string)($seal['school_id'] ?? '');
        $epoch = (string)($seal['identity_epoch'] ?? '');
        if ($seal['state'] === 'cloud_verified') {
            self::checkSchool($school);
            if (preg_match('/^[a-f0-9]{64}$/D', $epoch) !== 1) {
                throw new RuntimeException('BLOCKED V21R110: epoch segel tidak valid.');
            }
        } elseif ($school !== '' || $epoch !== '') {
            throw new RuntimeException('BLOCKED V21R110: segel tahap awal tidak konsisten.');
        }
        return $seal;
    }

    private static function writeOnce(string $project, array $row): void
    {
        $dest = self::path($project);
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('BLOCKED V21R110: folder segel tidak dapat dibuat.');
        }
        if (is_link($dir)) throw new RuntimeException('BLOCKED V21R110: lokasi segel symlink tidak diizinkan.');
        $fp = @fopen($dir . DIRECTORY_SEPARATOR . '.seal.lock', 'c');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            if (is_resource($fp)) fclose($fp);
            throw new RuntimeException('BLOCKED V21R110: kunci file segel tidak tersedia.');
        }
        try {
            $existing = self::load($project);
            if ($existing !== null) {
                // Never silently rebind, even if the current .env secret changed.
                if ($existing === $row) return;
                throw new RuntimeException('BLOCKED V21R110: segel telah ada dan tidak boleh diganti/dirotasi.');
            }
            $json = json_encode($row, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . "\n";
            $tmp = $dest . '.tmp-' . bin2hex(random_bytes(6));
            try {
                if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
                    throw new RuntimeException('BLOCKED V21R110: penulisan segel gagal.');
                }
                @chmod($tmp, 0600);
                if (!@rename($tmp, $dest)) throw new RuntimeException('BLOCKED V21R110: segel tidak tersimpan.');
            } finally {
                if (is_file($tmp)) @unlink($tmp);
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** Only called by LauncherInstallGuard after its clean-database first-install preflight. */
    public static function pinFirstInstall(string $project): void
    {
        $marker = rtrim($project, '/\\') . '/storage/app/school-installation.json';
        $m = is_file($marker) ? json_decode((string)@file_get_contents($marker), true) : null;
        if (!is_array($m) || ($m['stage'] ?? '') !== 'initializing' ||
            ($m['initialized'] ?? null) !== false || ($m['launcher_first_install'] ?? null) !== true) {
            throw new RuntimeException('BLOCKED V21R110: segel baru hanya boleh dari launcher instalasi Sekolah pertama.');
        }
        $secret = self::adminSecret($project);
        self::writeOnce($project, [
            'format'=>self::FORMAT,
            'state'=>'initialized',
            'key_commitment'=>self::fingerprint($secret),
            'school_id'=>'',
            'identity_epoch'=>'',
        ]);
    }

    /** Safe read-only check before any alias can be generated or published. */
    public static function assertConfigured(string $project, string $configuredSecret): void
    {
        $live = self::adminSecret($project);
        if (!hash_equals($live, $configuredSecret)) {
            throw new RuntimeException('BLOCKED V21R110: konfigurasi Laravel tidak sama dengan .env; bersihkan cache konfigurasi secara aman.');
        }
        $seal = self::load($project);
        if ($seal === null) {
            throw new RuntimeException('BLOCKED V21R110: instalasi lama belum memiliki segel. Verifikasi epoch Cloud D1 dahulu, lalu jalankan perintah bind.');
        }
        if (!hash_equals((string)$seal['key_commitment'], self::fingerprint($live))) {
            throw new RuntimeException('BLOCKED V21R110: kunci alias Admin berbeda dari segel lama. Pulihkan kunci asli, jangan membuat alias baru.');
        }
    }

    /** Fail before any DB alias provisioning. Existing Cloud bindings cannot be replaced. */
    public static function assertSchool(string $project, string $configuredSecret, string $school): void
    {
        self::checkSchool($school);
        self::assertConfigured($project, $configuredSecret);
        $seal = self::load($project);
        if (($seal['state'] ?? '') === 'cloud_verified') {
            if (!hash_equals((string)$seal['school_id'], $school) ||
                !hash_equals((string)$seal['identity_epoch'], self::epoch($configuredSecret, $school))) {
                throw new RuntimeException('BLOCKED V21R110: NPSN/epoch berbeda dari identitas sekolah yang dikunci.');
            }
        } else {
            // Brand-new installations may use their key only after explicitly binding
            // with a verified D1 export, preventing accidental reuse of an existing NPSN.
            throw new RuntimeException('BLOCKED V21R110: segel instalasi baru belum dicocokkan dengan Cloud D1. Jalankan bind terverifikasi sebelum publikasi akun.');
        }
    }

    /** Explicit operator action; Cloud D1 export must have exactly one school+epoch record. */
    public static function bindFromCloud(string $project, string $school, string $cloudEpoch): void
    {
        self::checkSchool($school);
        if (preg_match('/^[a-f0-9]{64}$/D', $cloudEpoch) !== 1) {
            throw new RuntimeException('BLOCKED V21R110: epoch Cloud tidak valid.');
        }
        $secret = self::adminSecret($project);
        if (!hash_equals(self::epoch($secret, $school), $cloudEpoch)) {
            throw new RuntimeException('BLOCKED V21R110: epoch Cloud berbeda dari key Admin. Pulihkan key Admin lama menggunakan V21R94/V21R95.');
        }
        $existing = self::load($project);
        $commit = self::fingerprint($secret);
        if ($existing !== null && !hash_equals((string)$existing['key_commitment'], $commit)) {
            throw new RuntimeException('BLOCKED V21R110: segel lama berbeda. Tidak boleh diikat ulang.');
        }
        $dest = self::path($project);
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('BLOCKED V21R110: folder segel tidak dapat dibuat.');
        }
        $fp = @fopen($dir . '/.seal.lock', 'c');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            if (is_resource($fp)) fclose($fp);
            throw new RuntimeException('BLOCKED V21R110: tidak dapat mengunci segel.');
        }
        try {
            $current = self::load($project);
            if ($current !== null && !hash_equals((string)$current['key_commitment'], $commit)) {
                throw new RuntimeException('BLOCKED V21R110: kunci sekolah berubah selama proses bind.');
            }
            if ($current !== null && $current['state'] === 'cloud_verified') {
                if (($current['school_id'] ?? '') !== $school || !hash_equals((string)$current['identity_epoch'], $cloudEpoch)) {
                    throw new RuntimeException('BLOCKED V21R110: segel sekolah lain tidak boleh diganti.');
                }
                return; // idempotent.
            }
            $row = ['format'=>self::FORMAT,'state'=>'cloud_verified','key_commitment'=>$commit,
                    'school_id'=>$school,'identity_epoch'=>$cloudEpoch];
            $json=json_encode($row, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            $tmp=$dest.'.tmp-'.bin2hex(random_bytes(6));
            try {
                if (@file_put_contents($tmp,$json,LOCK_EX)!==strlen($json)) throw new RuntimeException('BLOCKED V21R110: tulis segel gagal.');
                @chmod($tmp,0600);
                if (!@rename($tmp,$dest)) throw new RuntimeException('BLOCKED V21R110: simpan segel gagal.');
            } finally {if (is_file($tmp)) @unlink($tmp);}
        } finally {flock($fp, LOCK_UN);fclose($fp);}
    }

    public static function state(string $project): string
    {
        $seal = self::load($project);
        if ($seal === null) return 'BELUM_DIIKAT';
        $secret = self::adminSecret($project);
        if (!hash_equals((string)$seal['key_commitment'], self::fingerprint($secret))) return 'KEY_BERUBAH_BLOCKED';
        return $seal['state'] === 'cloud_verified' ? 'COCOK_DENGAN_SEGEL_CLOUD' : 'SEGEL_AWAL_PERLU_EPOCH_CLOUD';
    }
}
