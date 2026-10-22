<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/** V21R14 STAGING: signed camera quality, sealed-answer and manual-key binding. No route or Cloud transfer. */
final class CloudLjkScanQualityV21
{
    private const PROTOCOL = 'ljk-scan-quality-v21r14';
    private const DOMAIN = 'eduxid-ljk-scan-quality-v21r14';
    private const FIELDS = ['protocol','marker_ok','scan_ok','markers_found','ambiguous_count',
        'confidence_bp','key_fingerprint','answers_sha256','sealed_tag','tag'];

    /** @param array<string,mixed> $quality */
    public static function transcript(array $quality, array $sealed): string
    {
        self::assertShape($quality, $sealed, false);
        return implode("\x1f", [self::DOMAIN, $sealed['school_id'], $sealed['source_hp'],
            $sealed['target_pc'], $sealed['session_ref'], $sealed['scan_id'], $sealed['nonce'],
            (string) $sealed['issued_at'], $sealed['tag'], $quality['key_fingerprint'],
            $quality['answers_sha256'], $quality['marker_ok'] ? '1' : '0',
            $quality['scan_ok'] ? '1' : '0', (string) $quality['markers_found'],
            (string) $quality['ambiguous_count'], (string) $quality['confidence_bp']]);
    }

    /** @param array<string,mixed> $quality */
    public static function sign(array $quality, array $sealed, string $secret): array
    {
        $p = array_intersect_key($quality, array_flip(self::FIELDS));
        $p['protocol'] = self::PROTOCOL;
        $p['answers_sha256'] = $sealed['answers_sha256'] ?? null;
        $p['sealed_tag'] = $sealed['tag'] ?? null;
        unset($p['tag']);
        $transcript = self::transcript($p, $sealed);
        $p['tag'] = self::b64(hash_hmac('sha256', $transcript, CloudLjkPairProofV21::secret($secret), true));
        return $p;
    }

    /** @param array<string,mixed> $quality */
    public static function verify(array $quality, array $sealed, string $secret): void
    {
        self::assertShape($quality, $sealed, true);
        $unsigned = $quality;
        unset($unsigned['tag']);
        $expected = self::b64(hash_hmac('sha256', self::transcript($unsigned, $sealed),
            CloudLjkPairProofV21::secret($secret), true));
        if (!hash_equals($expected, $quality['tag'])) {
            throw new RuntimeException('BLOCKED V21R14: bukti kualitas kamera tidak cocok dengan pasangan perangkat.');
        }
    }

    /** @param array<string,mixed> $quality */
    private static function assertShape(array $quality, array $sealed, bool $withTag): void
    {
        $expected = $withTag ? self::FIELDS : array_slice(self::FIELDS, 0, -1);
        $actual = array_keys($quality);
        sort($expected); sort($actual);
        if ($actual !== $expected || ($quality['protocol'] ?? null) !== self::PROTOCOL
            || !is_bool($quality['marker_ok'] ?? null) || !is_bool($quality['scan_ok'] ?? null)
            || !is_int($quality['markers_found'] ?? null) || $quality['markers_found'] < 0 || $quality['markers_found'] > 4
            || !is_int($quality['ambiguous_count'] ?? null) || $quality['ambiguous_count'] < 0 || $quality['ambiguous_count'] > 50
            || !is_int($quality['confidence_bp'] ?? null) || $quality['confidence_bp'] < 0 || $quality['confidence_bp'] > 10000
            || !is_string($quality['key_fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $quality['key_fingerprint'])
            || !is_string($quality['answers_sha256'] ?? null) || !is_string($sealed['answers_sha256'] ?? null)
            || !hash_equals($sealed['answers_sha256'], $quality['answers_sha256'])
            || !is_string($quality['sealed_tag'] ?? null) || !is_string($sealed['tag'] ?? null)
            || !hash_equals($sealed['tag'], $quality['sealed_tag'])
            || ($withTag && (!is_string($quality['tag'] ?? null)
                || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $quality['tag'])))) {
            throw new RuntimeException('BLOCKED V21R14: kontrak kualitas scan tidak sah atau bukti jawaban tidak terikat.');
        }
        // Existing V21R13 envelope rejects extra keys, leaked real IDs and tampered answer digests.
        CloudLjkSealedProofV21::transcript($sealed, '0000000000');
    }

    /** Gate publication. Quality is a signed claim; this does NOT independently validate a photograph. */
    public static function assertPublishable(array $quality): void
    {
        if ($quality['marker_ok'] !== true || $quality['scan_ok'] !== true
            || $quality['markers_found'] !== 4 || $quality['ambiguous_count'] !== 0
            || $quality['confidence_bp'] < 6500) {
            throw new RuntimeException('BLOCKED V21R14: kualitas scan tidak layak terbit otomatis.');
        }
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
