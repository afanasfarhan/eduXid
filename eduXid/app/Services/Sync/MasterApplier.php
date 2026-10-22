<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * REWRITE-02 (rev.2): MASTER APPLIER
 *
 * Dua perbaikan dari hasil uji LAN di laptop admin:
 *   1. [FIX-CAPTURE] Closure transaksi kini meng-capture $version
 *      dan $actualHash (dulu lupa - TypeError recordApplied, yang
 *      kebetulan menyelamatkan DB lewat rollback).
 *   2. [FIX-RECONCILE] Rekonsiliasi kini menghitung SELISIH id
 *      (existing - snapshot) dulu, lalu menghapus by id eksplisit
 *      (chunked whereIn). Versi lama memakai whereNotIn per chunk
 *      yang secara logika menghapus SEMUA baris bila snapshot
 *      lebih dari satu chunk - fatal.
 */
final class MasterApplier
{
    public function __construct(
        protected SchoolIdentity $identity,
        protected MasterRegistry $registry
    ) {
    }

    public function apply(array $data, array $options = []): array
    {
        $cloudOpaque = (bool) ($options['cloud_opaque'] ?? false);
        // A redacted master must NEVER cause destructive reconciliations.
        $reconcile = !$cloudOpaque && (bool) ($options['reconcile'] ?? true);
        $dryRun    = (bool) ($options['dry_run'] ?? false);

        /* ---------- 1. Validasi bentuk ---------- */

        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        if (($meta['tipe_file'] ?? '') !== 'm2g_master') {
            throw new \RuntimeException(
                'File/payload bukan master eduXid (metadata.tipe_file != m2g_master).'
            );
        }

        $tables = [];

        foreach (MasterSnapshotService::TABLE_MAP as $key => $table) {
            $rows = $data[$key] ?? null;

            if (!is_array($rows)) {
                throw new \RuntimeException(
                    "Snapshot tidak memuat kunci '{$key}' - payload tidak lengkap."
                );
            }

            $tables[$key] = ['table' => $table, 'rows' => $rows];
        }

        if ($cloudOpaque) {
            if (($meta['cloud_projection'] ?? '') !== 'v21-opaque') {
                throw new \RuntimeException('BLOCKED V21: format master anonim Cloud tidak sah.');
            }
            CloudMasterOpaqueProjectionV21::assertOpaque($data);
            // Cloud carries only non-private columns. An incoming row with an
            // unknown local ID cannot be inserted without original Dapodik
            // identifiers (which must never be transported through Cloud).
            // Check all tables BEFORE opening the write transaction.
            foreach ($tables as $entry) {
                $table = $entry['table'];
                if (!Schema::hasTable($table)) {
                    throw new \RuntimeException('BLOCKED V21: struktur database GTK belum lengkap; perbarui paket lokal.');
                }
                $ids = [];
                foreach ($entry['rows'] as $row) {
                    if (!is_array($row) || !is_numeric($row['id'] ?? null) || (int)$row['id'] <= 0) {
                        throw new \RuntimeException('BLOCKED V21: referensi master anonim tidak valid.');
                    }
                    $ids[] = (int)$row['id'];
                }
                foreach (array_chunk(array_unique($ids), 400) as $chunk) {
                    $found = DB::table($table)->whereIn('id', $chunk)->pluck('id')->all();
                    if (count($found) !== count($chunk)) {
                        throw new \RuntimeException('BLOCKED V21: Data Pokok baru belum tersedia di laptop GTK. Perbarui paket GTK secara resmi; identitas Dapodik tidak boleh diambil mentah dari Cloud.');
                    }
                }
            }
        }

        if ($tables['sekolah']['rows'] === []) {
            throw new \RuntimeException(
                'Snapshot tanpa data sekolah - ditolak (payload korup atau salah file).'
            );
        }

        /* ---------- 2. Verifikasi hash ---------- */

        $payloadTables = array_map(
            static fn (array $t) => $t['rows'],
            $tables
        );

        $actualHash  = MasterSnapshotService::canonicalHash($payloadTables);
        $claimedHash = trim((string) ($meta['content_hash'] ?? ''));

        if ($claimedHash !== '' && !hash_equals($claimedHash, $actualHash)) {
            throw new \RuntimeException(
                'Master gagal verifikasi checksum (content_hash tidak cocok) - '
                . 'payload rusak di jalan atau file salah. Transfer ulang.'
            );
        }

        /* ---------- 3. Guard versi ---------- */

        $version        = (int) ($meta['version'] ?? 0);
        $appliedVersion = $this->registry->appliedVersion();
        $appliedHash    = (string) ($this->registry->get(MasterRegistry::KEY_APPLIED_HASH) ?? '');

        if ($version > 0 && $appliedVersion > 0 && $version < $appliedVersion) {
            throw new \RuntimeException(
                "Snapshot lebih tua (v{$version}) dari master terpasang (v{$appliedVersion}) - ditolak."
            );
        }

        if ($actualHash !== '' && $actualHash === $appliedHash) {
            return [
                'status'  => 'up_to_date',
                'version' => $version,
                'hash'    => $actualHash,
                'report'  => ['message' => 'Master lokal sudah identik dengan snapshot ini.'],
            ];
        }

        /* ---------- 4. Guard sekolah (NPSN) ---------- */

        $snapshotNpsn = $this->firstNpsn($tables['sekolah']['rows']);

        if ($snapshotNpsn !== null) {
            $local = $this->identity->status();

            if ($local['ready'] && $local['school_id'] !== $snapshotNpsn) {
                throw new \RuntimeException(
                    "Master berasal dari sekolah berbeda (NPSN {$snapshotNpsn}, "
                    . "lokal {$local['school_id']}) - DITOLAK. Pastikan file/push "
                    . 'berasal dari admin sekolah ini.'
                );
            }
        }

        /* ---------- 5. Kolom per tabel ---------- */

        $columns = [];

        foreach ($tables as $key => $t) {
            $columns[$key] = Schema::hasTable($t['table'])
                ? Schema::getColumnListing($t['table'])
                : [];
        }

        /* ---------- 6. Apply transaksional ---------- */

        $report = [
            'upserted' => [],
            'deleted'  => [],
        ];

        if ($dryRun) {
            foreach ($tables as $key => $t) {
                $report['upserted'][$key] = count($t['rows']);
            }

            return [
                'status'  => 'dry_run',
                'version' => $version,
                'hash'    => $actualHash,
                'report'  => $report,
            ];
        }

        /*
         * [FIX-CAPTURE] $version dan $actualHash WAJIB di-capture.
         */
        DB::transaction(function () use ($version, $actualHash, $tables, $columns, $reconcile, &$report) {

            // 6a. Upsert by id - model transport-ID: snapshot membawa
            //     id laptop admin; menulis id eksplisit menjaga kesamaan
            //     kunci antarlaptop.
            foreach ($tables as $key => $t) {
                $allowed = $columns[$key];
                $count = 0;

                foreach ($t['rows'] as $row) {
                    $r = is_array($row) ? $row : (array) $row;

                    if (!isset($r['id']) || !is_numeric($r['id'])) {
                        continue;
                    }

                    if ($allowed !== []) {
                        $r = array_intersect_key($r, array_flip($allowed));
                    }

                    $existing = DB::table($t['table'])->where('id', (int) $r['id'])->first();
                    // Master memperbarui referensi Dapodik. Data hasil kerja
                    // guru tetap dimiliki jalur work-product yang berversi.
                    if ($existing && $t['table'] === 'enrollments'
                        && (int) ($r['student_id'] ?? 0) === (int) $existing->student_id
                        && (int) ($r['homeroom_id'] ?? 0) === (int) $existing->homeroom_id) {
                        foreach (['catatan_wali','catatan_wali_manual','rapor_sakit','rapor_izin','rapor_alpa','updated_at'] as $field) {
                            if (property_exists($existing, $field)) $r[$field] = $existing->{$field};
                        }
                    }
                    if ($existing && $t['table'] === 'teaching_assignments'
                        && (int) ($r['teacher_id'] ?? 0) === (int) $existing->teacher_id
                        && (int) ($r['subject_id'] ?? 0) === (int) $existing->subject_id
                        && (int) ($r['homeroom_id'] ?? 0) === (int) $existing->homeroom_id) {
                        foreach (['bobot_diagnostik','bobot_formatif','bobot_sumatif'] as $field) {
                            if (property_exists($existing, $field)) $r[$field] = $existing->{$field};
                        }
                    }
                    DB::table($t['table'])
                        ->updateOrInsert(['id' => (int) $r['id']], $r);

                    $count++;
                }

                $report['upserted'][$key] = $count;
            }

            // 6b. Rekonsiliasi - hapus baris yang TIDAK ada di snapshot.
            //
            // [FIX-RECONCILE] Hitung selisih id dulu (existing - snapshot),
            // lalu hapus by id eksplisit (chunked whereIn). JANGAN memakai
            // whereNotIn per chunk - itu menghapus semua baris.
            if ($reconcile) {
                foreach (self::RECONCILE_DELETE_ORDER as $key) {
                    if (!isset($tables[$key])) {
                        continue;
                    }

                    $table = $tables[$key]['table'];

                    if (!Schema::hasTable($table)) {
                        continue;
                    }

                    $snapshotIds = array_values(array_filter(array_map(
                        static fn ($row) => (int) ($row['id'] ?? 0),
                        $tables[$key]['rows']
                    ), static fn ($id) => $id > 0));

                    $existingIds = DB::table($table)->pluck('id')->all();

                    if ($snapshotIds === []) {
                        $toDelete = $existingIds;
                    } else {
                        $toDelete = array_values(array_diff($existingIds, $snapshotIds));
                    }

                    $deleted = 0;

                    foreach (array_chunk($toDelete, 500) as $chunk) {
                        $deleted += DB::table($table)
                            ->whereIn('id', $chunk)
                            ->delete();
                    }

                    $report['deleted'][$key] = $deleted;
                }
            }

            // 6c. Catat versi terpasang - atomik dengan apply.
            MasterRegistry::recordApplied(
                max(1, $version),
                $actualHash
            );
        });

        return [
            'status'  => 'applied',
            'version' => $version,
            'hash'    => $actualHash,
            'report'  => $report,
        ];
    }

    /**
     * Urutan hapus rekonsiliasi: anak dulu, induk belakangan.
     */
    protected const RECONCILE_DELETE_ORDER = [
        'enrollments',
        'pembelajaran',
        'rombels',
        'siswas',
        'mapels',
        'kokurikulers',
        'gurus',
        'sekolah',
    ];

    protected function firstNpsn(array $schoolRows): ?string
    {
        foreach ($schoolRows as $row) {
            $r = is_array($row) ? $row : (array) $row;
            $npsn = trim((string) ($r['npsn'] ?? ''));

            if ($npsn !== '') {
                return $npsn;
            }
        }

        return null;
    }
}