<?php

declare(strict_types=1);

namespace App\Services\Sync;

use InvalidArgumentException;
use RuntimeException;

/**
 * V21R2: generator identitas Cloud anonim, tanpa koneksi jaringan.
 * Deterministik per sekolah, identitas lokal dan peran, kunci hanya di laptop Admin.
 * Tidak boleh menaruh kunci ini di JavaScript/PWA, ZIP GTK atau payload Worker.
 */
final class OpaqueIdentityV21
{
    private string $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new RuntimeException('EDUXID_IDENTITY_SCHOOL_SECRET minimal 32 byte dan wajib dirahasiakan.');
        }
        $this->secret = $secret;
    }

    public function alias(string $schoolId, string $role, string $localLoginId): string
    {
        if (!preg_match('/^[0-9]{8}$/D', $schoolId)) {
            throw new InvalidArgumentException('school_id harus NPSN 8 digit.');
        }
        if (!in_array($role, ['teacher', 'student'], true)) {
            throw new InvalidArgumentException('Peran identitas harus teacher atau student.');
        }
        $id = trim($localLoginId);
        if (self::isAlias($id)) {
            throw new InvalidArgumentException('Input harus identitas lokal asli, bukan alias Cloud yang telah diterbitkan.');
        }
        if ($id === '' || strlen($id) > 128 || preg_match('/[\x00-\x1F\x7F]/', $id)) {
            throw new InvalidArgumentException('Identitas lokal tidak valid.');
        }
        // Domain separation: school, role, local ID, protocol-version.
        // HMAC 128-bit tag: 26 chars Base32; tidak dapat dikembalikan ke NIK/NIP/NISN.
        $message = "eduxid-v21-opaque:v1\0{$role}\0{$schoolId}\0{$id}";
        $digest = substr(hash_hmac('sha256', $message, $this->secret, true), 0, 16);
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $result = ''; $bits = 0; $buffer = 0;
        foreach (str_split($digest) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= $alphabet[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $result .= $alphabet[($buffer << (5-$bits)) & 31];
        }
        return ($role === 'teacher' ? 't_' : 's_') . $result;
    }

    public function deviceId(string $schoolId, string $role, string $localLoginId, string $suffix): string
    {
        if (!in_array($suffix, ['PC','HP'], true)) {
            throw new InvalidArgumentException('Tipe perangkat tidak valid.');
        }
        if ($role === 'student' && $suffix !== 'HP') {
            throw new InvalidArgumentException('Perangkat siswa hanya HP.');
        }
        return $schoolId . '-' . $this->alias($schoolId, $role, $localLoginId) . '-' . $suffix;
    }

    public static function isAlias(string $alias, ?string $role = null): bool
    {
        if (!preg_match('/^([ts])_[a-z2-7]{26}$/D', $alias, $parts)) return false;
        return $role === null || $parts[1] === ($role === 'teacher' ? 't' : 's');
    }
}
