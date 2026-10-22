<?php

namespace App\Services\Security;

use App\Support\SecureTemporaryPassword;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/** One-time local-only activation; never propagate secrets into Cloud/master payloads. */
final class InitialCredentialVault
{
    public static function createForNewUser(array $user): int
    {
        $password = SecureTemporaryPassword::generate();
        $user['password'] = Hash::make($password);
        $user['must_change_password'] = true;
        // Must execute inside the caller's DB::transaction: if vault cannot be written,
        // the user is NOT silently provisioned with a known or unavailable password.
        $id = (int) DB::table('users')->insertGetId($user);
        DB::table('eduxid_initial_credentials')->insert([
            'user_id' => $id,
            'ciphertext' => Crypt::encryptString($password),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);
        return $id;
    }

    /** Return secret only once; deleted atomically before it is shown. */
    public static function consume(int $userId): ?string
    {
        return DB::transaction(function () use ($userId) {
            $record = DB::table('eduxid_initial_credentials')
                ->where('user_id', $userId)->lockForUpdate()->first();
            if (!$record) return null;
            if (now()->greaterThan($record->expires_at)) {
                DB::table('eduxid_initial_credentials')->where('user_id', $userId)->delete();
                return null;
            }
            // Check decrypt before deleting the row: invalid key must not silently consume.
            $value = Crypt::decryptString($record->ciphertext);
            $deleted = DB::table('eduxid_initial_credentials')
                ->where('user_id', $userId)->where('ciphertext', $record->ciphertext)->delete();
            if ($deleted !== 1) throw new RuntimeException('Kredensial aktivasi telah diambil oleh proses lain.');
            return $value;
        });
    }
}
