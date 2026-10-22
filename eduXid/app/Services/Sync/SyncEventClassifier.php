<?php

namespace App\Services\Sync;

/**
 * [FASE-0 → F1] Dua dimensi klasifikasi.
 *
 * F1 BARU (dua entity PRIVATE):
 *   - submission_unlock : guru membuka kunci revisi jawaban per siswa
 *                         (D21) — target device HP siswa.
 *   - apply_ack         : laptop melaporkan hasil APPLY balik ke HP
 *                         guru (D19) — target device HP pengirim.
 * Keduanya PRIVATE (target eksplisit), TIDAK masuk fanout fallback.
 */
final class SyncEventClassifier
{
    public const BROADCAST       = 'broadcast';
    public const TEACHER_ROUTED  = 'teacher_routed';
    public const PRIVATE         = 'private';
    public const UNKNOWN         = 'unknown';

    private const MAP = [
        'lms_course'     => self::BROADCAST,
        'lms_topic'      => self::BROADCAST,
        'lms_material'   => self::BROADCAST,
        'lms_assignment' => self::BROADCAST,
        'cbt_exam'       => self::BROADCAST,

        'final_grade'             => self::TEACHER_ROUTED,
        'daily_grade'             => self::TEACHER_ROUTED,
        'bk_log_batch'            => self::TEACHER_ROUTED,
        'catatan_wali_batch'      => self::TEACHER_ROUTED,
        'rapor_recap_batch'       => self::TEACHER_ROUTED,
        'ekskul_grade_batch'      => self::TEACHER_ROUTED,
        'kokurikuler_grade_batch' => self::TEACHER_ROUTED,
        'attendance_session'      => self::TEACHER_ROUTED,
        'arena_grade_request'     => self::TEACHER_ROUTED,
        'arena_grade_receipt'     => self::PRIVATE,

        'lms_assignment_submission' => self::PRIVATE,
        'cbt_answer'                => self::PRIVATE,
        'cbt_checkpoint'            => self::PRIVATE,
        'cbt_result'                => self::PRIVATE,
        'submission_unlock'         => self::PRIVATE,
        'apply_ack'                 => self::PRIVATE,
        'atp_item'                  => self::PRIVATE,
    ];

    public const FANOUT_FALLBACK_ENTITIES = [
        'lms_assignment_submission',
        'cbt_answer',
        'cbt_checkpoint',
        'final_grade',
        'daily_grade',
        'bk_log_batch',
        'catatan_wali_batch',
        'rapor_recap_batch',
        'ekskul_grade_batch',
        'attendance_session',
        'kokurikuler_grade_batch',
    ];

    public static function category(string $entity): string
    {
        return self::MAP[strtolower(trim($entity))] ?? self::UNKNOWN;
    }

    public static function fanoutFallback(string $entity): bool
    {
        return in_array(strtolower(trim($entity)), self::FANOUT_FALLBACK_ENTITIES, true);
    }

    /** @return string[] */
    public static function knownEntities(): array
    {
        return array_keys(self::MAP);
    }
}