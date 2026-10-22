<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/** Staging safety boundary: do not let a partly migrated client upload PII. */
final class CloudOutboundPrivacyGuardV21
{
    private const PERSONAL_KEYS = ['nip','nik','nisn','nuptk','student_nisn','student_nik','teacher_nip'];
    private const LOGIN_KEYS = ['login_id','teacher_login_id','student_login_id'];
    private const DEVICE_KEYS = ['device_id','source_device_id','target_device_id','student_device_id','teacher_device_id','pc_device_id','node_id'];

    public static function enabled(): bool
    {
        return CloudDirectoryAliasV21::stagingEnabled();
    }

    public static function assertDevice(string $school, string $device, ?string $role = null): void
    {
        if (!preg_match('/^[0-9]{8}$/D', $school)) throw new RuntimeException('BLOCKED V21: school tidak valid.');
        if (!preg_match('/^([0-9]{8})-([ts]_[a-z2-7]{26})-(PC|HP)$/D', $device, $m)
            || $m[1] !== $school || ($role !== null && !OpaqueIdentityV21::isAlias($m[2], $role))) {
            throw new RuntimeException('BLOCKED V21: identitas perangkat belum anonim.');
        }
    }

    public static function assertFileKey(string $key): void
    {
        if (preg_match('~(?<![0-9])[0-9]{10,20}(?![0-9])~', $key)) {
            throw new RuntimeException('BLOCKED V21: path file mengandung ID pribadi.');
        }
    }

    /** Iterate within fixed bounds; no raw payload contents are ever logged. */
    public static function assertPayload(array $data, ?string $school = null): void
    {
        $nodes = 0;
        $walk = function (array $data, int $depth) use (&$walk, &$nodes, $school): void {
            if (++$nodes > 30000 || $depth > 24) throw new RuntimeException('BLOCKED V21: payload terlalu besar/dalam.');
            foreach ($data as $key => $value) {
                $k = strtolower((string) $key);
                if (in_array($k, self::PERSONAL_KEYS, true) && $value !== null && trim((string) (is_scalar($value) ? $value : 'nonempty')) !== '') {
                    throw new RuntimeException('BLOCKED V21: field identitas pribadi masih ada dalam payload.');
                }
                // IDs used as entity keys may also be a local NISN; prohibit 10-20 digit values.
                if (in_array($k, ['student_id','entity_id','student_user_id'], true) &&
                    is_scalar($value) && preg_match('/^[0-9]{10,20}$/D', (string) $value)) {
                    throw new RuntimeException('BLOCKED V21: kunci referensi masih memuat identitas asli.');
                }
                if (in_array($k, self::LOGIN_KEYS, true) && $value !== null && $value !== ''
                    && (!is_string($value) || !OpaqueIdentityV21::isAlias($value))) {
                    throw new RuntimeException('BLOCKED V21: login Cloud masih menggunakan ID lama.');
                }
                if (in_array($k, self::DEVICE_KEYS, true) && $value !== null && $value !== '') {
                    if (!is_string($value)) throw new RuntimeException('BLOCKED V21: jenis device ID tidak valid.');
                    if ($school !== null) self::assertDevice($school, $value);
                    elseif (!preg_match('/^[0-9]{8}-[ts]_[a-z2-7]{26}-(PC|HP)$/D', $value)) {
                        throw new RuntimeException('BLOCKED V21: alamat perangkat lama ada dalam payload.');
                    }
                }
                if (in_array($k, ['file_key','r2_key','object_key','answer_file_key','file_path','r2_path','object_path'], true) && is_string($value)) {
                    self::assertFileKey($value);
                }
                if (is_array($value)) $walk($value, $depth + 1);
            }
        };
        $walk($data, 0);
    }
}
