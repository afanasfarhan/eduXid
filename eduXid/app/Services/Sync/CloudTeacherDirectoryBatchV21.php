<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/**
 * V21R56: Batch authoritative GTK directory snapshots within the Cloud V21
 * identifier scanner's existing 30,000-node / 24-depth limits.
 *
 * Preserve *whole* teacher accounts and their full assignments/rosters:
 * splitting one teacher across requests would overwrite the authoritative
 * assignments JSON and lose classes in Cloud.
 */
final class CloudTeacherDirectoryBatchV21
{
    // Deliberately below the Worker's hard cap to leave headroom for envelope
    // fields. This is NOT a change to the Cloud privacy scanner.
    private const SAFE_NODE_BUDGET = 24000;
    private const MAX_DEPTH = 24;

    /**
     * @param list<array<string,mixed>> $accounts Aliases already provisioned.
     * @return list<list<array<string,mixed>>>
     */
    public static function split(array $accounts, int $maxAccountsPerBatch = 25): array
    {
        if ($maxAccountsPerBatch < 1 || $maxAccountsPerBatch > 25) {
            throw new RuntimeException('BLOCKED V21R56: batas akun per batch tidak valid.');
        }

        // JSON envelope {school_id, accounts}: object + string + array.
        $envelopeNodes = 3;
        $batches = [];
        $batch = [];
        $nodes = $envelopeNodes;
        foreach ($accounts as $offset => $account) {
            if (!is_array($account)) {
                throw new RuntimeException('BLOCKED V21R56: akun direktori guru bukan object.');
            }
            $accountNodes = self::countNodes($account, 2);
            // Preflight *all* accounts before the first HTTP request. A single
            // oversized teacher must fail safely, not publish a partial roster.
            if ($accountNodes + $envelopeNodes > self::SAFE_NODE_BUDGET) {
                throw new RuntimeException(
                    'BLOCKED V21R56: satu akun guru (urutan ' . ($offset + 1) . ') '
                    . 'memiliki penugasan/roster terlalu besar untuk satu payload Cloud. '
                    . 'Pengiriman dihentikan sebelum HTTP; periksa duplikasi penugasan atau enrollment.'
                );
            }
            if ($batch !== [] && (
                count($batch) >= $maxAccountsPerBatch ||
                $nodes + $accountNodes > self::SAFE_NODE_BUDGET
            )) {
                $batches[] = $batch;
                $batch = [];
                $nodes = $envelopeNodes;
            }
            $batch[] = $account;
            $nodes += $accountNodes;
        }
        if ($batch !== []) $batches[] = $batch;
        return $batches;
    }

    /** Counts each JSON object, array and scalar as one visited scanner node. */
    private static function countNodes(mixed $value, int $depth): int
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('BLOCKED V21R56: struktur direktori guru terlalu dalam untuk Cloud V21.');
        }
        $nodes = 1;
        if (is_array($value) || is_object($value)) {
            foreach ($value as $child) {
                $nodes += self::countNodes($child, $depth + 1);
            }
        }
        return $nodes;
    }
}
