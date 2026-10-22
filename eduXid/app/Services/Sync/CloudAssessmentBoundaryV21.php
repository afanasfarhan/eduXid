<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/**
 * V21R8 staging — pagar data untuk kontrak yang belum bisa dianonimkan penuh.
 * Dijalankan pada enqueue DAN push ulang sehingga outbox lama tidak lolos.
 * Tidak menghapus data, tidak memalsukan ACK/grade, dan tidak mengubah legacy.
 */
final class CloudAssessmentBoundaryV21
{
    private const WORK_PRODUCTS = [
        'bk_log_batch','catatan_wali_batch',
        'ekskul_grade_batch','kokurikuler_grade_batch',
    ];

    /** @param array<string,mixed> $payload */
    public static function outgoing(string $entity, array $payload): void
    {
        if (!OpaqueRouteRegistryV21::enabled()) return;
        $entity = strtolower(trim($entity));
        if (CloudWorkProductAliasV21::supports($entity)) {
            CloudWorkProductAliasV21::assertEnvelope($entity, $payload);
            return;
        }
        if (CloudHomeroomRaporAliasV21::supports($entity)) {
            CloudHomeroomRaporAliasV21::assertEnvelope($entity, $payload);
            return;
        }
        if (in_array($entity, self::WORK_PRODUCTS, true)) {
            // Snapshot masih menggunakan enrollment_id/student_id lokal.
            throw new RuntimeException('BLOCKED V21: snapshot nilai/rapor menunggu pemetaan alias yang terotorisasi.');
        }
        if ($entity === 'cbt_answer') {
            throw new RuntimeException('BLOCKED V21: CBT outbound GTK belum menggunakan payload anonim yang terverifikasi.');
        }
        if ($entity === 'lms_assignment_submission' &&
            strtolower((string) ($payload['entity'] ?? '')) === 'ljk_scan_result') {
            // Kamera membaca NISN fisik; PWA belum memiliki bukti terpercaya
            // pemetaan NISN-ke-alias. Jangan kirim sekalipun payload diklaim alias.
            throw new RuntimeException('BLOCKED V21: hasil LJK PWA belum mempunyai pemetaan alias siswa tepercaya.');
        }
    }

    /** @param array<string,mixed> $payload */
    public static function inbound(string $entity, array $payload): void
    {
        if (!OpaqueRouteRegistryV21::enabled()) return;
        $entity = strtolower(trim($entity));
        if (CloudWorkProductAliasV21::supports($entity)) {
            CloudWorkProductAliasV21::assertEnvelope($entity, $payload);
            return;
        }
        if (CloudHomeroomRaporAliasV21::supports($entity)) {
            CloudHomeroomRaporAliasV21::assertEnvelope($entity, $payload);
            return;
        }
        if (in_array($entity, self::WORK_PRODUCTS, true)) {
            throw new RuntimeException('BLOCKED V21: produk kerja belum dapat diterapkan aman dalam mode anonim.');
        }
        if ($entity === 'lms_assignment_submission' &&
            strtolower((string) ($payload['entity'] ?? '')) === 'ljk_scan_result') {
            throw new RuntimeException('BLOCKED V21: event LJK ditahan sampai verifikasi alias/hasil pindai lengkap.');
        }
        if ($entity === 'lms_assignment_submission' &&
            strtolower((string) ($payload['entity'] ?? '')) === 'ljk_exam_recovery') {
            throw new RuntimeException('BLOCKED V21: snapshot recovery LJK belum menggunakan identitas alias lengkap.');
        }
    }
}
