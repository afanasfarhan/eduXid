<?php

namespace App\Services\Sync;

use App\Models\SyncDeadLetter;
use Throwable;

/**
 * [F1 / P3-1 / DK-01] Lembar dead-letter — limit retry kini
 * BERBASIS WAKTU (usia sejak first_seen), bukan hitungan siklus
 * (720 siklus @ interval daemon 5 dtk hanya ≈ 1 jam; limit CBT
 * ingest lama ≈ 5 menit → false-positive EXAM-GONE).
 *
 * Kontrak baru touch(): maxAgeSeconds. attempts tetap dicatat
 * untuk observabilitas. Kolom first_seen_at dari migrasi F1.
 */
class SyncDeadLetterService
{
    public function touch(array $change, string $deviceId, string $reason, int $maxAgeSeconds): array
    {
        $row = SyncDeadLetter::firstOrNew([
            'event_id'  => trim((string) ($change['event_id'] ?? '')),
            'device_id' => $deviceId,
        ]);

        $now = now();

        if (!$row->exists || $row->first_seen_at === null) {
            $row->first_seen_at = $now;
        }

        $row->entity_type = strtolower(trim((string) ($change['entity_type'] ?? $change['entity'] ?? '')));
        $row->entity_id   = trim((string) ($change['entity_id'] ?? $change['sync_id'] ?? '')) ?: null;
        $row->operation   = strtolower(trim((string) ($change['action'] ?? $change['operation'] ?? 'update')));
        $row->version     = (int) ($change['version'] ?? $change['sync_version'] ?? 0) ?: null;
        $row->payload     = is_array($change['payload'] ?? null) ? $change['payload'] : null;
        $row->attempts    = (int) ($row->attempts ?? 0) + 1;
        $row->last_reason = $reason;

        $age = $row->first_seen_at ? $now->diffInSeconds($row->first_seen_at) : 0;
        $row->status = $age >= $maxAgeSeconds ? 'dead' : 'retrying';

        try {
            $row->save();
        } catch (Throwable) {
            // Race unique (event_id, device_id) — baris pemenang cukup.
        }

        return ['status' => $row->status, 'attempts' => $row->attempts, 'age_seconds' => $age];
    }

    public function recordDead(array $change, string $deviceId, string $reason): SyncDeadLetter
    {
        return SyncDeadLetter::updateOrCreate(
            [
                'event_id'  => trim((string) ($change['event_id'] ?? '')),
                'device_id' => $deviceId,
            ],
            [
                'entity_type' => strtolower(trim((string) ($change['entity_type'] ?? $change['entity'] ?? ''))),
                'entity_id'   => trim((string) ($change['entity_id'] ?? $change['sync_id'] ?? '')) ?: null,
                'operation'   => strtolower(trim((string) ($change['action'] ?? $change['operation'] ?? 'update'))),
                'version'     => (int) ($change['version'] ?? 0) ?: null,
                'payload'     => is_array($change['payload'] ?? null) ? $change['payload'] : null,
                'attempts'    => max(1, (int) (SyncDeadLetter::where('event_id', $change['event_id'] ?? '')
                    ->where('device_id', $deviceId)->value('attempts') ?? 0)),
                'status'      => 'dead',
                'last_reason' => $reason,
                'first_seen_at' => SyncDeadLetter::where('event_id', $change['event_id'] ?? '')
                    ->where('device_id', $deviceId)->value('first_seen_at') ?? now(),
            ]
        );
    }

    public function resolve(string $eventId, string $deviceId): void
    {
        SyncDeadLetter::where('event_id', $eventId)
            ->where('device_id', $deviceId)
            ->where('status', '!=', 'resolved')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}