<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Staging one-time PC challenge ledger. Caller MUST authorize exam/roster, regrade with local key and save in callback. */
final class CloudLjkNonceLedgerV21
{
    private static function ready(): void
    {
        if (!OpaqueRouteRegistryV21::enabled() || !Schema::hasTable('cloud_ljk_nonces_v21')) {
            throw new RuntimeException('BLOCKED V21: ledger tantangan belum siap.');
        }
    }

    /** Challenge is minted by PC, not HP. Do not expose issuance without authorized paired teacher and scoped exam. */
    public function issue(int $teacherId, string $hp, string $pc, string $sessionRef, string $scanId, int $now): array
    {
        self::ready();
        $school = CloudLjkPairVaultV21::assertOwner($teacherId, $hp, $pc);
        // Require active PC/HP pair, not merely matching alias strings.
        app(CloudLjkPairVaultV21::class)->active($teacherId, $hp, $pc);
        $context = [
            'protocol'=>'ljk-pair-proof-v21r12','school_id'=>$school,
            'source_hp'=>$hp,'target_pc'=>$pc,'session_ref'=>$sessionRef,'scan_id'=>$scanId,
            'nonce'=>rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'='), 'issued_at'=>$now,
        ];
        CloudLjkPairProofV21::transcript($context, '0000000000');
        if ($now < 1600000000 || abs(time() - $now) > 60) throw new RuntimeException('BLOCKED V21: jam perangkat tidak sesuai.');
        // Fresh, bounded lifetime, no NISN or answer key in nonce ledger.
        DB::table('cloud_ljk_nonces_v21')->insert([
            'nonce_hash'=>hash('sha256',$context['nonce']), 'school_id'=>$school,'teacher_id'=>$teacherId,
            'source_hp'=>$hp,'target_pc'=>$pc,'session_ref'=>$sessionRef,'scan_id'=>$scanId,
            'issued_at'=>$now,'expires_at'=>$now+300,'status'=>'pending',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $context;
    }

    /**
     * Transport-agnostic local apply; the caller must independently authenticate the Cloud source/target,
     * construct an authorized roster for the teacher's exact exam/TA/room and pass an authoritative
     * scorer/writer callback which MUST write the grade within this transaction.
     * @param array<string,mixed> $proof @param array<string,mixed> $answers
     * @param list<array{student_id:int,nisn:string}> $trustedRoster
     * @return array{already_applied:bool,receipt_hash:string,student_id:int|null}
     */
    public function consume(
        int $teacherId, array $proof, array $answers, array $trustedRoster, callable $regradeAndWrite
    ): array {
        self::ready();
        CloudLjkSealedProofV21::assertEnvelope($proof, $answers);
        $hp = (string)($proof['source_hp'] ?? '');
        $pc = (string)($proof['target_pc'] ?? '');
        $school = CloudLjkPairVaultV21::assertOwner($teacherId, $hp, $pc);
        if (($proof['school_id'] ?? '') !== $school || !is_string($proof['nonce'] ?? null)
            || !preg_match('/^[A-Za-z0-9_-]{32}$/D', $proof['nonce'])) {
            throw new RuntimeException('BLOCKED V21: sumber tantangan tidak valid.');
        }
        $secret = app(CloudLjkPairVaultV21::class)->active($teacherId, $hp, $pc);
        $receipt = hash('sha256', CloudLjkSealedProofV21::digest($answers).'|'
            .(string)($proof['tag'] ?? '').'|'.(string)($proof['session_ref'] ?? '').'|'
            .(string)($proof['scan_id'] ?? '').'|'.($proof['publish_requested'] ?? false ? '1' : '0'));
        return DB::transaction(function () use ($teacherId,$proof,$answers,$trustedRoster,$regradeAndWrite,$school,$hp,$pc,$secret,$receipt) {
            $row = DB::table('cloud_ljk_nonces_v21')->where('nonce_hash',hash('sha256',$proof['nonce']))->lockForUpdate()->first();
            if (!$row || $row->school_id !== $school || (int)$row->teacher_id !== $teacherId
                || $row->source_hp !== $hp || $row->target_pc !== $pc
                || $row->session_ref !== ($proof['session_ref'] ?? null)
                || $row->scan_id !== ($proof['scan_id'] ?? null)
                || (int)$row->issued_at !== ($proof['issued_at'] ?? null)) {
                throw new RuntimeException('BLOCKED V21: tantangan tidak pernah dikeluarkan untuk sesi ini.');
            }
            if ($row->status === 'consumed') {
                if (!$row->receipt_hash || !hash_equals($row->receipt_hash,$receipt)) {
                    throw new RuntimeException('BLOCKED V21: replay diubah setelah hasil diterapkan.');
                }
                return ['already_applied'=>true,'receipt_hash'=>$receipt,'student_id'=>null];
            }
            if ($row->status !== 'pending' || time() > (int)$row->expires_at) {
                throw new RuntimeException('BLOCKED V21: nonce kedaluwarsa/dibatalkan.');
            }
            $studentId = CloudLjkSealedProofV21::verify($proof,$answers,$trustedRoster,$secret,time());
            // Re-grade exclusively from the locally trusted package/answer key, never the client score.
            // Callback must write in THIS transaction; throwing rolls both value and nonce back.
            $regradeAndWrite($studentId,$answers,$proof);
            $affected = DB::table('cloud_ljk_nonces_v21')->where('id',$row->id)->where('status','pending')->update([
                'status'=>'consumed','receipt_hash'=>$receipt,'consumed_at'=>now(),'updated_at'=>now(),
            ]);
            if ($affected !== 1) throw new RuntimeException('BLOCKED V21: race nonce sudah dikonsumsi.');
            return ['already_applied'=>false,'receipt_hash'=>$receipt,'student_id'=>$studentId];
        }, 3);
    }
}
