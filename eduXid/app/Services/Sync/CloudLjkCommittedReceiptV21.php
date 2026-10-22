<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use App\Services\Ljk\LjkStandaloneManualService;
use RuntimeException;

/**
 * V21R17 STAGING ONLY (hardening V21R16 candidate), NOT wired into LmsSyncEngine, HTTP or Worker.
 * A future trusted pull consumer MAY use this after independently verifying
 * Cloud source HP, PC owner, event ID, and recipient. The caller must NOT pass
 * unverified header/body values as verified transport context.
 *
 * This class returns an ACK *candidate*, and never sends an ACK itself.
 * It checks committed ledger/publication state after V21R15 adapter returns.
 */
final class CloudLjkCommittedReceiptV21
{
    public function __construct(private CloudLjkInboundAdapterV21 $adapter) {}

    /**
     * @param array<string,mixed> $event
     * @return array{ack_permitted:bool,ack_event_id:string,receipt_hash:string,already_applied:bool,score:float}
     */
    public function receiveAndVerifyCommit(
        int $trustedTeacherId,
        string $trustedPc,
        string $authenticatedSourceHp,
        string $verifiedTransportEventId,
        array $event
    ): array {
        // Transport-issued ID is NOT permitted to be silently taken from event.
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $verifiedTransportEventId)) {
            throw new RuntimeException('BLOCKED V21R16: ID pengiriman Cloud belum diverifikasi.');
        }
        // Do not claim durable commit while nested in a caller's open transaction.
        if (DB::connection()->transactionLevel() !== 0) {
            throw new RuntimeException('BLOCKED V21R16: transaksi luar belum committed.');
        }
        $result = $this->adapter->receiveFromAuthenticatedPull(
            $trustedTeacherId, $trustedPc, $authenticatedSourceHp, $event
        );
        if (DB::connection()->transactionLevel() !== 0) {
            throw new RuntimeException('BLOCKED V21R16: transaksi pipeline belum selesai.');
        }
        if (!isset($event['payload']['sealed'], $event['payload']['quality'])
            || !is_array($event['payload']['sealed']) || !is_array($event['payload']['quality'])) {
            throw new RuntimeException('BLOCKED V21R16: bukti tidak ditemukan sesudah apply.');
        }
        $sealed = $event['payload']['sealed'];
        $quality = $event['payload']['quality'];
        $nonceHash = hash('sha256', (string) ($sealed['nonce'] ?? ''));
        $school = (string) ($sealed['school_id'] ?? '');
        $scanId = (string) ($sealed['scan_id'] ?? '');
        $sessionRef = (string) ($sealed['session_ref'] ?? '');
        $receiptHash = (string) ($result['receipt_hash'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $receiptHash)
            || !is_bool($result['already_applied'] ?? null)
            || !is_string($quality['tag'] ?? null)) {
            throw new RuntimeException('BLOCKED V21R16: hasil pipeline tidak mempunyai receipt yang sah.');
        }
        $nonce = DB::table('cloud_ljk_nonces_v21')->where('nonce_hash', $nonceHash)->first();
        $published = DB::table('cloud_ljk_publications_v21r14')
            ->where('school_id', $school)
            ->where('teacher_id', $trustedTeacherId)
            ->where('session_ref', $sessionRef)
            ->where('scan_id', $scanId)
            ->where('nonce_hash', $nonceHash)
            ->first();
        // Verify the effects that matter, not just a 'published' marker.
        // Both rows must have been committed by the same V21R14 transaction.
        $scan = DB::table('ljk_scan_results')->where('scan_id', $scanId)->first();
        $daily = null;
        $dailyRowCount = 0;
        if ($published && in_array((string) ($published->score_type ?? ''), ['diagnostik','formatif','sumatif'], true)) {
            // V21R17: an ambiguous Buku Nilai row must NEVER authorize ACK.
            // This table historically had no unique index on (student, TA, tanggal).
            $dailyRows = DB::table('assessment_scores')
                ->where('student_id', (int) $published->student_id)
                ->where('teaching_assignment_id', (int) $published->teaching_assignment_id)
                ->where('tanggal', (string) $published->tanggal)->limit(2)->get();
            $dailyRowCount = $dailyRows->count();
            $daily = $dailyRowCount === 1 ? $dailyRows->first() : null;
        }
        $dailyField = (string) ($published->score_type ?? '');
        $dailyScore = $daily && in_array($dailyField, ['diagnostik','formatif','sumatif'], true)
            ? ($daily->{$dailyField} ?? null) : null;
        $qualityTagHash = hash('sha256', $quality['tag']);
        // Read back the stored scan, not merely its published_at flag.
        // An ACK must be denied if scan answers, marking, owner or score diverge.
        $scanAnswersDigest = null;
        $scanKeyFingerprint = null;
        if ($scan && is_string($scan->manual_keys_json ?? null)) {
            try {
                $snapshot = json_decode($scan->manual_keys_json, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($snapshot) && $snapshot !== []
                    && LjkStandaloneManualService::normalizeKeys($snapshot) === $snapshot) {
                    $scanKeyFingerprint = LjkStandaloneManualService::fingerprint($snapshot);
                }
            } catch (\Throwable) {
                $scanKeyFingerprint = null;
            }
        }
        if ($scan && is_string($scan->answers_json ?? null)) {
            try {
                $scanAnswers = json_decode($scan->answers_json, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($scanAnswers)) {
                    $scanAnswersDigest = CloudLjkSealedProofV21::digest($scanAnswers);
                }
            } catch (\Throwable) {
                $scanAnswersDigest = null;
            }
        }
        if (!$nonce || !$published || !$scan || !$daily || $dailyRowCount !== 1
            || ($scan->published_at ?? null) === null
            || ($scan->status ?? null) !== 'success'
            || ($scan->source_type ?? null) !== 'pwa'
            || ($scan->key_source ?? null) !== 'manual'
            || ($scan->exam_id ?? null) !== null
            || !hash_equals((string) ($scan->source_device_id ?? ''), $authenticatedSourceHp)
            || !hash_equals((string) ($scanAnswersDigest ?? ''), (string) ($sealed['answers_sha256'] ?? ''))
            || !hash_equals((string) ($scanKeyFingerprint ?? ''), (string) ($published->key_fingerprint ?? ''))
            || (int) ($scan->teaching_assignment_id ?? 0) !== (int) ($published->teaching_assignment_id ?? 0)
            || (int) ($scan->correct_count ?? -1) !== (int) ($published->correct_count ?? -2)
            || (int) ($scan->question_count ?? 0) < 1 || (int) ($scan->question_count ?? 0) > 50
            || !is_numeric($scan->score ?? null)
            || abs((float) $scan->score - (float) ($published->score ?? -1)) > 0.005
            || (int) ($scan->student_id ?? 0) !== (int) ($published->student_id ?? 0)
            || !hash_equals((string) ($scan->exam_sync_id ?? ''), $sessionRef)
            || !is_numeric($dailyScore)
            || abs((float) $dailyScore - (float) ($published->score ?? -1)) > 0.005
            || ($nonce->status ?? null) !== 'consumed'
            || !hash_equals((string) ($nonce->receipt_hash ?? ''), $receiptHash)
            || !hash_equals((string) ($nonce->school_id ?? ''), $school)
            || (int) ($nonce->teacher_id ?? 0) !== $trustedTeacherId
            || !hash_equals((string) ($nonce->source_hp ?? ''), $authenticatedSourceHp)
            || !hash_equals((string) ($nonce->target_pc ?? ''), $trustedPc)
            || !hash_equals((string) ($nonce->session_ref ?? ''), $sessionRef)
            || !hash_equals((string) ($nonce->scan_id ?? ''), $scanId)
            || ($published->status ?? null) !== 'published'
            || (int) ($published->student_id ?? 0) < 1
            || (int) ($published->correct_count ?? -1) < 0
            || (float) ($published->score ?? -1) < 0 || (float) ($published->score ?? -1) > 100
            || !hash_equals((string) ($published->answers_sha256 ?? ''), (string) ($sealed['answers_sha256'] ?? ''))
            || !hash_equals((string) ($published->quality_tag_hash ?? ''), $qualityTagHash)
            || !hash_equals((string) ($published->key_fingerprint ?? ''), (string) ($quality['key_fingerprint'] ?? ''))
            || !is_numeric($result['score'] ?? null)
            || abs((float) ($published->score ?? -1) - (float) $result['score']) > 0.005) {
            throw new RuntimeException('BLOCKED V21R16: hasil belum committed atau tidak sesuai bukti terbit.');
        }
        return [
            'ack_permitted' => true,
            'ack_event_id' => $verifiedTransportEventId,
            'receipt_hash' => $receiptHash,
            'already_applied' => $result['already_applied'],
            'score' => (float) $result['score'],
        ];
    }
}
