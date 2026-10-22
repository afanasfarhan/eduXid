<?php

namespace App\Services\Sync;

/**
 * [REWRITE-04a] Lima nasib satu event inbound.
 *
 *   APPLIED / SKIPPED_* → boleh di-ACK (terminal).
 *   RETRY_TRANSIENT     → TIDAK di-ACK; cursor tidak maju;
 *                         event di-pull ulang siklus berikutnya.
 *   DEAD_LETTER         → di-ACK (cursor maju!), tercatat di
 *                         sync_dead_letters, replayable manual.
 */
final class SyncOutcome
{
    public const APPLIED          = 'applied';
    public const SKIPPED_STALE    = 'skipped_stale';
    public const SKIPPED_DUPLICATE = 'skipped_duplicate';
    public const RETRY_TRANSIENT  = 'retry_transient';
    public const DEAD_LETTER      = 'dead_letter';
}