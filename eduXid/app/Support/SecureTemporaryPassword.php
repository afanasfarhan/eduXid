<?php

namespace App\Support;

/**
 * Password sementara lokal untuk akun yang secara eksplisit di-reset Admin.
 * Tidak berdasarkan NPSN, NIP/NIK, NISN, atau identitas perangkat.
 */
final class SecureTemporaryPassword
{
    public static function generate(): string
    {
        // 24 byte CSPRNG = 192 bit entropi sebelum encoding.
        // Pastikan memenuhi persyaratan campuran pada form ganti password.
        do {
            $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        } while (!preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/[0-9]/', $password)
            || !preg_match('/[-_]/', $password));

        return $password;
    }
}
