<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/**
 * V21R12 staging-only: HMAC identity proof for LJK across an authenticated
 * teacher PC/HP pairing. NEVER provide the key in a Cloud event or archive.
 * The caller MUST independently authenticate teacher, source and target,
 * check the one-time challenge in persistent local storage, and re-grade
 * answers from the authoritative local exam before saving any result.
 * This pure verifier DOES NOT consume a nonce, grade, write, push, or ACK.
 */
final class CloudLjkPairProofV21
{
    private const DOMAIN = 'eduxid-ljk-pairproof-v21r12';

    /** @param array<string,mixed> $p */
    public static function transcript(array $p, string $nisn): string
    {
        if (trim($nisn) !== $nisn || !preg_match('/^[0-9]{10}$/D', $nisn)) {
            throw new RuntimeException('BLOCKED V21: identitas LJK lokal tidak valid.');
        }
        if (($p['protocol'] ?? null) !== 'ljk-pair-proof-v21r12'
            || !preg_match('/^[0-9]{8}$/D', (string) ($p['school_id'] ?? ''))
            || !preg_match('/^[0-9]{8}-t_[a-z2-7]{26}-HP$/D', (string) ($p['source_hp'] ?? ''))
            || !preg_match('/^[0-9]{8}-t_[a-z2-7]{26}-PC$/D', (string) ($p['target_pc'] ?? ''))
            || substr((string)$p['source_hp'], 0, 8) !== $p['school_id']
            || substr((string)$p['target_pc'], 0, 8) !== $p['school_id']
            || substr((string)$p['source_hp'], 0, -3) !== substr((string)$p['target_pc'], 0, -3)
            || !preg_match('/^(manual::[a-zA-Z0-9._:-]{1,180}|exam::[1-9][0-9]{0,12})$/D', (string) ($p['session_ref'] ?? ''))
            || !preg_match('/^[a-zA-Z0-9._:-]{1,100}$/D', (string) ($p['scan_id'] ?? ''))
            || !preg_match('/^[a-zA-Z0-9_-]{32}$/D', (string) ($p['nonce'] ?? ''))
            || !is_int($p['issued_at'] ?? null) || $p['issued_at'] < 1600000000 || $p['issued_at'] > 4102444800) {
            throw new RuntimeException('BLOCKED V21: kontrak bukti LJK tidak sah.');
        }
        $values = [self::DOMAIN, $p['school_id'], $p['source_hp'], $p['target_pc'], $p['session_ref'], $p['scan_id'], $p['nonce'], (string)$p['issued_at'], $nisn];
        return implode("\x1f", $values);
    }

    public static function secret(string $base64url): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{43}$/D', $base64url)) {
            throw new RuntimeException('BLOCKED V21: kunci bukti perangkat invalid.');
        }
        $raw = base64_decode(strtr($base64url, '-_', '+/').'=', true);
        if (!is_string($raw) || strlen($raw) !== 32 || self::b64($raw) !== $base64url) {
            throw new RuntimeException('BLOCKED V21: kunci bukti tidak kanonik.');
        }
        return $raw;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Never call with data originated from Cloud as the secret. */
    public static function sign(array $context, string $localNisn, string $base64urlKey): string
    {
        return self::b64(hash_hmac('sha256', self::transcript($context, $localNisn), self::secret($base64urlKey), true));
    }

    /**
     * Strictly match exactly ONE student in the locally authorized roster.
     * Returns ONLY the local student_id, never NISN or any PII.
     * @param array<string,mixed> $proof
     * @param list<array{student_id:int,nisn:string}> $localRoster
     */
    public static function matchLocalRoster(array $proof, string $base64urlKey, array $localRoster, int $now): int
    {
        if (!OpaqueRouteRegistryV21::enabled()) throw new RuntimeException('BLOCKED V21: bukti LJK hanya staging anonim.');
        $tag = $proof['tag'] ?? null;
        if (!is_string($tag) || !preg_match('/^[a-zA-Z0-9_-]{43}$/D', $tag)
            || !is_int($now) || abs($now - (int)($proof['issued_at'] ?? 0)) > 300
            || count($localRoster) === 0 || count($localRoster) > 1000) {
            throw new RuntimeException('BLOCKED V21: tag, jendela waktu atau roster LJK invalid.');
        }
        $count = 0;
        $selected = 0;
        $studentIds = [];
        foreach ($localRoster as $row) {
            if (!is_array($row) || !isset($row['student_id'], $row['nisn']) || !is_int($row['student_id'])
                || $row['student_id'] < 1 || isset($studentIds[$row['student_id']])
                || !is_string($row['nisn']) || !preg_match('/^[0-9]{10}$/D', $row['nisn'])) {
                throw new RuntimeException('BLOCKED V21: daftar siswa lokal ambigu atau tidak sah.');
            }
            $studentIds[$row['student_id']] = true;
            $expected = self::sign($proof, $row['nisn'], $base64urlKey);
            if (hash_equals($expected, $tag)) {
                $count++;
                $selected = $row['student_id'];
            }
        }
        if ($count !== 1) throw new RuntimeException('BLOCKED V21: bukti tidak cocok tepat satu siswa dalam rombel.');
        return $selected;
    }
}
