<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Pair key staging vault: only trusted authenticated teacher pairing UI may call begin/redeem. No HTTP routes provided. */
final class CloudLjkPairVaultV21
{
    public static function assertOwner(int $teacherId, string $sourceHp, string $targetPc): string
    {
        if (!OpaqueRouteRegistryV21::enabled() || $teacherId < 1) throw new RuntimeException('BLOCKED V21: channel belum siap.');
        $school = app(SchoolIdentity::class)->schoolId();
        $teacher = DB::table('teachers')->where('id', $teacherId)->first();
        if (!$teacher || !preg_match('/^[0-9]{8}$/D', $school)) throw new RuntimeException('BLOCKED V21: kepemilikan guru tidak diketahui.');
        $logins = array_unique(array_filter(array_map('trim', [(string) ($teacher->nip ?? ''), (string) ($teacher->nik ?? ''), (string) ($teacher->nuptk ?? ''), (string) ($teacher->login_id ?? '')])));
        foreach ($logins as $login) {
            try {
                $registry = app(OpaqueRouteRegistryV21::class);
                if (hash_equals($registry->deviceId($school, 'teacher', $login, 'HP'), $sourceHp)
                    && hash_equals($registry->deviceId($school, 'teacher', $login, 'PC'), $targetPc)) return $school;
            } catch (RuntimeException) {
                continue;
            }
        }
        throw new RuntimeException('BLOCKED V21: PC/HP tidak cocok dengan guru pemilik.');
    }

    private static function table(): void
    {
        if (!Schema::hasTable('cloud_ljk_pair_keys_v21')) throw new RuntimeException('BLOCKED V21: tabel kunci pasangan belum dimigrasikan.');
    }

    /** Return a one-time 256-bit claim token (never a reusable proof key); display through authenticated PC only. */
    public function begin(int $teacherId, string $hp, string $pc): string
    {
        $school = self::assertOwner($teacherId, $hp, $pc);
        self::table();
        $claim = self::b64(random_bytes(32));
        $secret = self::b64(random_bytes(32));
        DB::table('cloud_ljk_pair_keys_v21')->updateOrInsert(
            ['school_id'=>$school, 'source_hp'=>$hp, 'target_pc'=>$pc],
            ['teacher_id'=>$teacherId, 'encrypted_key'=>Crypt::encryptString($secret),
             'claim_hash'=>hash('sha256', $claim), 'claim_expires_at'=>time()+300,
             'activated_at'=>null, 'revoked_at'=>null, 'updated_at'=>now()]
        );
        return $claim;
    }

    /** Requires externally authenticated HP + HTTPS; consumes claim exactly once under a DB write lock. */
    public function redeem(int $teacherId, string $hp, string $pc, string $claim): string
    {
        $school = self::assertOwner($teacherId, $hp, $pc);
        self::table();
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $claim)) throw new RuntimeException('BLOCKED V21: token pairing tidak valid.');
        return DB::transaction(function () use ($teacherId, $hp, $pc, $school, $claim) {
            $query = DB::table('cloud_ljk_pair_keys_v21')->where('school_id',$school)->where('teacher_id',$teacherId)
                ->where('source_hp',$hp)->where('target_pc',$pc);
            $row = $query->lockForUpdate()->first();
            if (!$row || !$row->claim_hash || !hash_equals($row->claim_hash, hash('sha256',$claim))
                || (int)$row->claim_expires_at < time() || $row->activated_at || $row->revoked_at) {
                throw new RuntimeException('BLOCKED V21: klaim pairing tidak sah, kedaluwarsa atau sudah digunakan.');
            }
            $secret = Crypt::decryptString($row->encrypted_key);
            CloudLjkPairProofV21::secret($secret);
            DB::table('cloud_ljk_pair_keys_v21')->where('id',$row->id)->update([
                'claim_hash'=>null,'claim_expires_at'=>null,'activated_at'=>now(),'updated_at'=>now(),
            ]);
            return $secret;
        }, 3);
    }

    public function active(int $teacherId, string $hp, string $pc): string
    {
        $school = self::assertOwner($teacherId, $hp, $pc);
        self::table();
        $row = DB::table('cloud_ljk_pair_keys_v21')->where('school_id',$school)->where('teacher_id',$teacherId)
            ->where('source_hp',$hp)->where('target_pc',$pc)->whereNotNull('activated_at')->whereNull('revoked_at')->first();
        if (!$row) throw new RuntimeException('BLOCKED V21: HP/PC belum aktivasi kunci bukti.');
        $secret = Crypt::decryptString($row->encrypted_key);
        CloudLjkPairProofV21::secret($secret);
        return $secret;
    }

    private static function b64(string $bytes): string {return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
}
