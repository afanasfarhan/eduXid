<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [REWRITE-02] MASTER SNAPSHOT — satu sumber kebenaran (final).
 *
 * Placeholder lama tidak memuat metadata.version → CloudMasterDataService
 * mengirim version=0 → Cloud menolak (422 "version harus bilangan bulat
 * positif"). File ini adalah versi final yang dipakai ketiga jalur
 * (LAN API, file m2g, Cloud push).
 *
 *   1. Semua tabel master dibaca dalam SATU transaksi (kohesif).
 *   2. version MONOTON via MasterRegistry::nextVersion() (kebal clock
 *      laptop yang melompat).
 *   3. content_hash kanonik (sha256) — hash sama = konten sama:
 *      push dapat skip, penerima dapat verifikasi.
 */
final class MasterSnapshotService
{
    public const FORMAT = 2;

    public const TABLE_MAP = [
        'sekolah'      => 'schools',
        'gurus'        => 'teachers',
        'mapels'       => 'subjects',
        'siswas'       => 'students',
        'rombels'      => 'homerooms',
        'enrollments'  => 'enrollments',
        'pembelajaran' => 'teaching_assignments',
        'kokurikulers' => 'kokurikulers',
    ];

    public function __construct(
        protected SchoolIdentity $identity,
        protected MasterRegistry $registry
    ) {
    }

    public function build(?string $requestedBy = null): array
    {
        $tables = DB::transaction(function (): array {
            $out = [];

            foreach (self::TABLE_MAP as $key => $table) {
                if (!Schema::hasTable($table)) {
                    $out[$key] = [];
                    continue;
                }

                $query = DB::table($table)->orderBy('id');

                if ($table === 'teachers' && Schema::hasColumn($table, 'deleted_at')) {
                    $query->whereNull('deleted_at');
                }

                $out[$key] = $query->get()
                    ->map(fn ($row) => $this->canonicalRow((array) $row))
                    ->all();
            }

            return $out;
        });

        $counts = [];
        foreach ($tables as $key => $rows) {
            $counts[$key] = count($rows);
        }

        return [
            'metadata' => [
                'tipe_file'    => 'm2g_master',
                'format'       => self::FORMAT,
                'version'      => $this->registry->nextVersion(),
                'content_hash' => self::canonicalHash($tables),
                'counts'       => $counts,
                'dibuat_pada'  => now()->toIso8601String(),
                'requested_by' => $requestedBy,
            ],
            ...$tables,
        ];
    }

    /**
     * Hash kanonik atas ISI master (metadata tidak ikut) —
     * konten sama selalu menghasilkan hash sama.
     */
    public static function canonicalHash(array $tables): string
    {
        $canonical = [];

        foreach ($tables as $name => $rows) {
            $list = array_map(
                static fn ($row) => self::canonicalRow(is_array($row) ? $row : (array) $row),
                is_array($rows) ? $rows : []
            );

            usort($list, static fn ($a, $b) => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));

            $canonical[(string) $name] = $list;
        }

        ksort($canonical);

        return hash(
            'sha256',
            json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    protected static function canonicalRow(array $row): array
    {
        ksort($row);

        return $row;
    }
}