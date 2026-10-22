<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/**
 * V21R6 staging boundary for LMS submission and grade payloads only.
 * Local NISN stays local; Cloud sees the already provisioned student alias.
 * Never infer an alias from an untrusted HP/Cloud payload.
 */
final class CloudStudentSubmissionV21
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function outbound(string $school, string $localNisn, array $payload): array
    {
        if (!CloudOutboundPrivacyGuardV21::enabled()) return $payload;
        $localNisn = trim($localNisn);
        if (!preg_match('/^[0-9]{10,20}$/D', $localNisn)) {
            throw new RuntimeException('BLOCKED V21: NISN lokal tidak valid untuk resolusi alias submission.');
        }
        $alias = app(OpaqueRouteRegistryV21::class)->alias($school, 'student', $localNisn);
        if (!OpaqueIdentityV21::isAlias($alias, 'student')) {
            throw new RuntimeException('BLOCKED V21: alias siswa bukan alias terverifikasi.');
        }
        // Preserve local DB identifiers; change only the Cloud payload copy.
        $payload['student_id'] = $alias;
        unset($payload['student_nisn'], $payload['nisn'], $payload['student_nik']);
        if (array_key_exists('student_device_id', $payload)) {
            $payload['student_device_id'] = $school . '-' . $alias . '-HP';
        }
        foreach (['file_path', 'file_key', 'answer_file_key'] as $field) {
            if (is_string($payload[$field] ?? null)) {
                CloudOutboundPrivacyGuardV21::assertFileKey($payload[$field]);
            }
        }
        CloudOutboundPrivacyGuardV21::assertPayload($payload, $school);
        return $payload;
    }
}
