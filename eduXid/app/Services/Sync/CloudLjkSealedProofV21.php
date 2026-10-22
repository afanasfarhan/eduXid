<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/** V21R13 staging: binds physical LJK identity AND the exact answer set. No grading, Cloud transfer or ACK. */
final class CloudLjkSealedProofV21
{
    private const DOMAIN = 'eduxid-ljk-sealed-v21r13';
    private const PROTOCOL = 'ljk-sealed-proof-v21r13';

    /** @param array<string,mixed> $answers */
    public static function canonicalAnswers(array $answers): string
    {
        if (count($answers) < 1 || count($answers) > 50 || array_is_list($answers) && count($answers) > 50) {
            throw new RuntimeException('BLOCKED V21: jawaban LJK tidak lengkap atau terlalu banyak.');
        }
        $entries = [];
        foreach ($answers as $key => $answer) {
            $number = (string) $key;
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $number)) {
                throw new RuntimeException('BLOCKED V21: nomor soal di luar LJK A4 V3.');
            }
            if (is_string($answer)) {
                $value = strtoupper(trim($answer));
                if ($value === '' || $value === '-') {
                    $options = [];
                } else {
                    if (!preg_match('/^[A-E]{1,5}$/D', $value)) throw new RuntimeException('BLOCKED V21: pilihan tidak valid.');
                    $options = str_split($value);
                }
            } elseif (is_array($answer) && array_is_list($answer)) {
                $options = [];
                foreach ($answer as $option) {
                    if (!is_string($option) || !preg_match('/^[A-E]$/Di', $option)) {
                        throw new RuntimeException('BLOCKED V21: pilihan majemuk tidak valid.');
                    }
                    $options[] = strtoupper($option);
                }
            } else {
                throw new RuntimeException('BLOCKED V21: jenis jawaban LJK tidak sah.');
            }
            if (count($options) !== count(array_unique($options))) {
                throw new RuntimeException('BLOCKED V21: opsi jawaban terduplikasi.');
            }
            sort($options, SORT_STRING);
            $entries[(int) $number] = implode('', $options) ?: '-';
        }
        ksort($entries, SORT_NUMERIC);
        $lines = [];
        foreach ($entries as $number => $value) $lines[] = $number . ':' . $value;
        return implode('|', $lines);
    }

    /** @param array<string,mixed> $answers */
    public static function digest(array $answers): string
    {
        return hash('sha256', self::canonicalAnswers($answers));
    }

    /** @param array<string,mixed> $proof */
    public static function transcript(array $proof, string $nisn): string
    {
        if (($proof['protocol'] ?? null) !== self::PROTOCOL
            || !is_bool($proof['publish_requested'] ?? null)
            || !is_string($proof['answers_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $proof['answers_sha256'])) {
            throw new RuntimeException('BLOCKED V21: kontrak bukti isi LJK tidak valid.');
        }
        $v12 = $proof;
        $v12['protocol'] = 'ljk-pair-proof-v21r12';
        return CloudLjkPairProofV21::transcript($v12, $nisn)
            . "\x1f" . self::DOMAIN . "\x1f" . $proof['answers_sha256']
            . "\x1f" . ($proof['publish_requested'] ? '1' : '0');
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $answers */
    public static function sign(array $context, array $answers, string $localNisn, string $secret): array
    {
        $proof = $context;
        $proof['protocol'] = self::PROTOCOL;
        $proof['answers_sha256'] = self::digest($answers);
        if (!is_bool($proof['publish_requested'] ?? null)) throw new RuntimeException('BLOCKED V21: publish flag tidak valid.');
        $proof['tag'] = self::base64url(hash_hmac('sha256', self::transcript($proof, $localNisn), CloudLjkPairProofV21::secret($secret), true));
        // Explicit transport allowlist: never copy NISN/student_id/secret from input.
        $fields = ['protocol','school_id','source_hp','target_pc','session_ref','scan_id','nonce','issued_at','publish_requested','answers_sha256','tag'];
        return array_intersect_key($proof, array_flip($fields));
    }

    /** Cloud transport must carry only an explicitly sanctioned proof, never raw identity. */
    public static function assertEnvelope(array $proof, array $answers): void
    {
        $allow = ['protocol','school_id','source_hp','target_pc','session_ref','scan_id',
            'nonce','issued_at','publish_requested','answers_sha256','tag'];
        if (array_diff(array_keys($proof), $allow) || count($proof) !== count($allow)
            || ($proof['protocol'] ?? null) !== self::PROTOCOL
            || !is_bool($proof['publish_requested'] ?? null)
            || !is_int($proof['issued_at'] ?? null)
            || !is_string($proof['tag'] ?? null)
            || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $proof['tag'])
            || !is_string($proof['answers_sha256'] ?? null)
            || !hash_equals(self::digest($answers), $proof['answers_sha256'])) {
            throw new RuntimeException('BLOCKED V21: envelope LJK bocor, berubah atau tidak lengkap.');
        }
        // Validate the cross-device/session/nonce envelope without learning a real NISN.
        self::transcript($proof, '0000000000');
    }

    /** @param array<string,mixed> $proof @param array<string,mixed> $answers
     *  @param list<array{student_id:int,nisn:string}> $roster
     */
    public static function verify(array $proof, array $answers, array $roster, string $secret, int $now): int
    {
        self::assertEnvelope($proof, $answers);
        if (!OpaqueRouteRegistryV21::enabled() || !is_int($proof['issued_at'] ?? null)
            || $now < $proof['issued_at'] - 60 || $now > $proof['issued_at'] + 300
            || !is_string($proof['tag'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $proof['tag'])
            || !hash_equals(self::digest($answers), (string) ($proof['answers_sha256'] ?? ''))) {
            throw new RuntimeException('BLOCKED V21: bukti, waktu, atau isi jawaban LJK berubah.');
        }
        if (!$roster || count($roster) > 1000) throw new RuntimeException('BLOCKED V21: roster LJK tidak valid.');
        $secretBytes = CloudLjkPairProofV21::secret($secret);
        $studentSeen = [];
        $nisnSeen = [];
        $matches = [];
        foreach ($roster as $row) {
            if (!is_array($row) || !is_int($row['student_id'] ?? null) || $row['student_id'] < 1
                || !is_string($row['nisn'] ?? null) || !preg_match('/^[0-9]{10}$/D', $row['nisn'])
                || isset($studentSeen[$row['student_id']]) || isset($nisnSeen[$row['nisn']])) {
                throw new RuntimeException('BLOCKED V21: identitas roster duplikat/tidak sah.');
            }
            $studentSeen[$row['student_id']] = true;
            $nisnSeen[$row['nisn']] = true;
            $expected = self::base64url(hash_hmac('sha256', self::transcript($proof, $row['nisn']), $secretBytes, true));
            if (hash_equals($expected, $proof['tag'])) $matches[] = $row['student_id'];
        }
        if (count($matches) !== 1) throw new RuntimeException('BLOCKED V21: tidak ada satu siswa yang cocok dengan bukti LJK.');
        return $matches[0];
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
