<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * [F1 / P0-4 / P0-8 / WPA-02] WORK PRODUCT APPLIER — rev.3
 *
 *  - TOMBSTONE: handler delete per scope (D12) — penghapusan
 *    keseluruhan kini tersiar lintas laptop.
 *  - WPA-02 TERTUTUP oleh kombinasi [F1-BATCHVER] + guard versi
 *    per sync_id: flush laptop basi membawa versi LAMA (max
 *    updated_at) → ditolak 'stale'. Tidak perlu guard penulis.
 *  - P0-8: catatan_wali/rapor_recap — update 0 baris = GAGAL
 *    transien (enrollment mungkin belum sinkron) → retry berbasis
 *    waktu → dead-letter ber-payload, bukan hilang senyap.
 */
class WorkProductApplier
{
    public function apply(array $event): array
    {
        $entity = (string) ($event['entity_type'] ?? $event['entity'] ?? '');
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $version = (int) ($event['version'] ?? $payload['sync_version'] ?? 0);
        // Guard per entitas/scope, bukan event_id yang berubah setiap versi.
        $syncId = (string) ($payload['sync_id'] ?? $event['entity_id'] ?? $event['sync_id'] ?? '');
        $action = strtolower(trim((string) ($event['action'] ?? 'upsert')));

        if ($syncId === '' || $version <= 0) {
            return ['applied' => false, 'reason' => 'sync_id/version tidak tersedia'];
        }

        if ($action === 'delete') {
            return $this->guardAndApply($syncId, $version, fn () => $this->applyDeleteScope($entity, array_replace($payload, ['sync_version' => $version])));
        }

        return match ($entity) {
            'final_grade'            => $this->guardAndApply($syncId, $version, fn () => $this->applyFinalGrade($payload, $version)),
            'daily_grade'            => $this->guardAndApply($syncId, $version, fn () => $this->applyDailyGrade($payload, $version)),
            'bk_log_batch'           => $this->guardAndApply($syncId, $version, fn () => $this->applyBkBatch($payload, $version)),
            'catatan_wali_batch'     => $this->guardAndApply($syncId, $version, fn () => $this->applyCatatanWaliBatch($payload, $version)),
            'rapor_recap_batch'      => $this->guardAndApply($syncId, $version, fn () => $this->applyRaporRecapBatch($payload, $version)),
            'ekskul_grade_batch'     => $this->guardAndApply($syncId, $version, fn () => $this->applyEkskulGradeBatch($payload, $version)),
            'kokurikuler_grade_batch'=> $this->guardAndApply($syncId, $version, fn () => $this->applyKokurikulerGradeBatch($payload, $version)),
            default                  => ['applied' => false, 'reason' => "entity {$entity} bukan work product"],
        };
    }

    /* ------------------------------------------------------------
     * GUARD VERSI (tetap)
     * ------------------------------------------------------------ */
    protected function guardAndApply(string $syncId, int $version, callable $applier): array
    {
        $key = 'wpa::' . $syncId;
        try {
            return DB::transaction(function () use ($key, $version, $applier): array {
                DB::table('app_settings')->insertOrIgnore([
                    'key' => $key, 'value' => '0', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $lastApplied = (int) (DB::table('app_settings')->where('key', $key)
                    ->lockForUpdate()->value('value') ?? 0);
                if ($version <= $lastApplied) {
                    return ['applied' => false, 'reason' => 'stale/duplicate (applied=' . $lastApplied . ')'];
                }
                $applier();
                VersionService::observe($version);
                $this->storeAppliedVersion($key, $version);
                return ['applied' => true, 'reason' => null];
            }, 3);
        } catch (Throwable $e) {
            return ['applied' => false, 'reason' => $e->getMessage()];
        }
    }

    protected function storeAppliedVersion(string $key, int $version): void
    {
        $existing = DB::table('app_settings')->where('key', $key)->first();

        if ($existing) {
            DB::table('app_settings')->where('key', $key)->update([
                'value' => (string) $version,
                'updated_at' => now(),
            ]);

            return;
        }

        try {
            DB::table('app_settings')->insert([
                'key' => $key,
                'value' => (string) $version,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            DB::table('app_settings')->where('key', $key)->update([
                'value' => (string) $version,
                'updated_at' => now(),
            ]);
        }
    }

    /* ------------------------------------------------------------
     * TOMBSTONE — delete per scope (D12)
     * ------------------------------------------------------------ */
    protected function applyDeleteScope(string $entity, array $payload): void
    {
        switch ($entity) {
            case 'daily_grade':
                $taId = (int) ($payload['teaching_assignment_id'] ?? 0);

                if ($taId <= 0) {
                    throw new \RuntimeException('daily_grade delete tanpa teaching_assignment_id.');
                }

                $this->assertAssignment($taId, $payload);
                $this->assertScopeVersion('attitude_logs', $taId, (int) ($payload['sync_version'] ?? 0));
                $this->assertScopeVersion('assessment_scores', $taId, (int) ($payload['sync_version'] ?? 0));
                $this->assertScopeVersion('attendances', $taId, (int) ($payload['sync_version'] ?? 0));
                DB::table('attendances')->where('teaching_assignment_id', $taId)->delete();
                DB::table('assessment_scores')->where('teaching_assignment_id', $taId)->delete();
                DB::table('attitude_logs')->where('teaching_assignment_id', $taId)->delete();

                Log::info('[WPA] Tombstone daily_grade diterapkan.', ['ta_id' => $taId]);
                return;

            case 'final_grade':
                $taId = (int) ($payload['teaching_assignment_id'] ?? 0);

                if ($taId <= 0) {
                    throw new \RuntimeException('final_grade delete tanpa teaching_assignment_id.');
                }

                $this->assertAssignment($taId, $payload);
                $this->assertScopeVersion('final_grades', $taId, (int) ($payload['sync_version'] ?? 0));
                DB::table('final_grades')->where('teaching_assignment_id', $taId)->delete();
                return;

            case 'bk_log_batch':
                $teacherId = (int) ($payload['teacher_id'] ?? 0);

                if ($teacherId <= 0) {
                    throw new \RuntimeException('bk_log_batch delete tanpa teacher_id.');
                }

                DB::table('bk_logs')->where('teacher_id', $teacherId)->delete();
                return;

            case 'ekskul_grade_batch':
                $ekskulId = (int) ($payload['ekskul_homeroom_id'] ?? 0);

                if ($ekskulId <= 0) {
                    throw new \RuntimeException('ekskul_grade_batch delete tanpa ekskul_homeroom_id.');
                }

                DB::table('ekskul_grades')->where('homeroom_id', $ekskulId)->delete();
                return;

            case 'kokurikuler_grade_batch':
                $kokId = (int) ($payload['kokurikuler_id'] ?? 0);

                if ($kokId <= 0) {
                    throw new \RuntimeException('kokurikuler_grade_batch delete tanpa kokurikuler_id.');
                }

                DB::table('kokurikuler_grades')->where('kokurikuler_id', $kokId)->delete();
                return;

            case 'catatan_wali_batch':
            case 'rapor_recap_batch':
                // Menempel di enrollments (sync master) — tanpa tombstone.
                return;

            default:
                throw new \RuntimeException("entity {$entity} bukan work product (delete).");
        }
    }

    /* ------------------------------------------------------------
     * FILTER / STAMP (tetap)
     * ------------------------------------------------------------ */
    protected function filterColumns(string $table, array $row): array
    {
        static $cache = [];

        if (!isset($cache[$table])) {
            try {
                $cache[$table] = array_flip(Schema::getColumnListing($table));
            } catch (Throwable) {
                $cache[$table] = [];
            }
        }

        return $cache[$table] !== [] ? array_intersect_key($row, $cache[$table]) : $row;
    }

    protected function tableHasSyncVersion(string $table): bool
    {
        static $cache = [];

        if (!isset($cache[$table])) {
            try {
                $cache[$table] = Schema::hasColumn($table, 'sync_version');
            } catch (Throwable) {
                $cache[$table] = false;
            }
        }

        return $cache[$table];
    }

    protected function stampVersion(string $table, array $row, int $version): array
    {
        $sourceVersion = (int) ($row['sync_version'] ?? 0);
        unset($row['sync_version']);

        if ($this->tableHasSyncVersion($table)) {
            $row['sync_version'] = $sourceVersion > 0 ? $sourceVersion : $version;
        }

        return $row;
    }

    protected function assertScopeVersion(string $table, int $taId, int $version): void
    {
        if ($this->tableHasSyncVersion($table)) {
            $local = (int) (DB::table($table)->where('teaching_assignment_id', $taId)->max('sync_version') ?? 0);
            if ($local > $version) {
                throw new \RuntimeException('stale/duplicate (local=' . $local . ')');
            }
        }
    }

    protected function applyWeights(int $taId, array $payload): void
    {
        $weights = [];
        foreach (['bobot_diagnostik','bobot_formatif','bobot_sumatif'] as $field) {
            if (!array_key_exists($field, $payload)) return; // Paket legacy.
            $value = $payload[$field];
            if (!is_numeric($value) || (int) $value < 0 || (int) $value > 100) {
                throw new \RuntimeException('Bobot work product tidak valid.');
            }
            $weights[$field] = (int) $value;
        }
        $weightsVersion = (int) ($payload['weights_version'] ?? $payload['sync_version'] ?? 0);
        $key = 'grades::weights::' . $taId;
        $localVersion = (int) (DB::table('app_settings')->where('key', $key)->value('value') ?? 0);
        if ($weightsVersion < $localVersion) return;
        DB::table('teaching_assignments')->where('id', $taId)->update($weights);
        DB::table('app_settings')->upsert([[
            'key' => $key, 'value' => (string) $weightsVersion,
            'created_at' => now(), 'updated_at' => now(),
        ]], ['key'], ['value', 'updated_at']);
    }

    protected function assertAssignment(int $taId, array $payload, array ...$groups): void
    {
        $ta = DB::table('teaching_assignments')->where('id', $taId)->first();
        if (!$ta) throw new \RuntimeException('Teaching assignment belum tersinkron: ' . $taId);
        foreach (['teacher_id', 'subject_id', 'homeroom_id'] as $field) {
            if (isset($payload[$field]) && (int) $payload[$field] !== (int) $ta->{$field}) {
                throw new \RuntimeException('Scope penugasan work product tidak cocok: ' . $field);
            }
        }
        $studentIds = DB::table('enrollments')->where('homeroom_id', $ta->homeroom_id)
            ->pluck('student_id')->map(fn ($id) => (int) $id)->all();
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                if (!is_array($row) || !in_array((int) ($row['student_id'] ?? 0), $studentIds, true)) {
                    throw new \RuntimeException('ENROLLMENT_MISSING: siswa work product belum terdaftar pada rombel.');
                }
                if (array_key_exists('tanggal', $row)) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['tanggal']);
                    if (!$date || $date->format('Y-m-d') !== $row['tanggal']) {
                        throw new \RuntimeException('Tanggal work product tidak valid.');
                    }
                }
            }
        }
    }

    protected function sourceTime($timestamp, int $version): \Carbon\Carbon
    {
        if ($timestamp !== null && $timestamp !== '') {
            return \Carbon\Carbon::parse($timestamp)->timezone(config('app.timezone', 'Asia/Jakarta'));
        }
        // Paket legacy tanpa timestamp: versi epoch adalah waktu sumber.
        return \Carbon\Carbon::createFromTimestampMs($version)
            ->timezone(config('app.timezone', 'Asia/Jakarta'));
    }

    /* ------------------------------------------------------------
     * FINAL GRADE — replace per scope (tetap) + guard rows
     * ------------------------------------------------------------ */
    protected function applyFinalGrade(array $payload, int $version): void
    {
        $taId = (int) ($payload['teaching_assignment_id'] ?? 0);

        if ($taId <= 0) {
            throw new \RuntimeException('final_grade tanpa teaching_assignment_id - tidak dapat menentukan scope.');
        }

        $rows = $payload['grades'] ?? $payload['rows'] ?? $payload['final_grades'] ?? null;

        if (!is_array($rows)) {
            throw new \RuntimeException('final_grade: rows tidak valid.');
        }

        $this->assertAssignment($taId, $payload, $rows);
        $this->assertScopeVersion('final_grades', $taId, $version);
        $this->applyWeights($taId, $payload);
        DB::transaction(function () use ($taId, $rows, $version) {
            DB::table('final_grades')->where('teaching_assignment_id', $taId)->delete();

            foreach ($rows as $row) {
                $clean = $this->filterColumns('final_grades', (array) $row);
                unset($clean['id']);
                $clean['teaching_assignment_id'] = $taId;
                $clean = $this->stampVersion('final_grades', $clean, $version);
                $clean['created_at'] = $this->sourceTime($clean['created_at'] ?? $clean['updated_at'] ?? null, $version);
                $clean['updated_at'] = $this->sourceTime($clean['updated_at'] ?? null, $version);

                DB::table('final_grades')->insert($clean);
            }
        });
    }

    /* ------------------------------------------------------------
     * DAILY GRADE — replace per scope (tetap)
     * ------------------------------------------------------------ */
    protected function applyDailyGrade(array $payload, int $version): void
    {
        $taId = (int) ($payload['teaching_assignment_id'] ?? 0);

        if ($taId <= 0) {
            throw new \RuntimeException('daily_grade tanpa teaching_assignment_id.');
        }

        if (!isset($payload['assessment_scores']) || !is_array($payload['assessment_scores'])
            || !isset($payload['attitude_logs']) || !is_array($payload['attitude_logs'])) {
            throw new \RuntimeException('daily_grade: snapshot tidak lengkap.');
        }
        $scores = $payload['assessment_scores'];
        $attitudes = is_array($payload['attitude_logs'] ?? null) ? $payload['attitude_logs'] : [];

        $this->assertScopeVersion('assessment_scores', $taId, $version);
        $this->applyWeights($taId, $payload);
        $attendances = $payload['attendances'] ?? null;
        if ($attendances !== null && !is_array($attendances)) {
            throw new \RuntimeException('daily_grade: absensi tidak valid.');
        }
        $this->assertAssignment($taId, $payload, $scores, $attitudes, $attendances ?? []);
        $this->assertScopeVersion('attitude_logs', $taId, $version);
        if ($attendances !== null) $this->assertScopeVersion('attendances', $taId, $version);
        DB::transaction(function () use ($taId, $scores, $attitudes, $attendances, $version) {
            DB::table('assessment_scores')->where('teaching_assignment_id', $taId)->delete();
            DB::table('attitude_logs')->where('teaching_assignment_id', $taId)->delete();
            if ($attendances !== null) {
                DB::table('attendances')->where('teaching_assignment_id', $taId)->delete();
                foreach ($attendances as $row) {
                    $clean = $this->filterColumns('attendances', (array) $row);
                    unset($clean['id']);
                    $clean['teaching_assignment_id'] = $taId;
                    $clean = $this->stampVersion('attendances', $clean, $version);
                    $clean['updated_at'] = $this->sourceTime($clean['updated_at'] ?? null, $version);
                    $clean['created_at'] = $this->sourceTime($clean['created_at'] ?? $clean['updated_at'], $version);
                    DB::table('attendances')->insert($clean);
                }
            }


            foreach ($scores as $row) {
                $clean = $this->filterColumns('assessment_scores', (array) $row);
                unset($clean['id']);
                $clean['teaching_assignment_id'] = $taId;
                $clean = $this->stampVersion('assessment_scores', $clean, $version);
                $clean['created_at'] = $this->sourceTime($clean['created_at'] ?? $clean['updated_at'] ?? null, $version);
                $clean['updated_at'] = $this->sourceTime($clean['updated_at'] ?? null, $version);

                DB::table('assessment_scores')->insert($clean);
            }

            foreach ($attitudes as $row) {
                $clean = $this->filterColumns('attitude_logs', (array) $row);
                unset($clean['id']);
                $clean['teaching_assignment_id'] = $taId;
                $clean = $this->stampVersion('attitude_logs', $clean, $version);
                $clean['created_at'] = $this->sourceTime($clean['created_at'] ?? $clean['updated_at'] ?? null, $version);
                $clean['updated_at'] = $this->sourceTime($clean['updated_at'] ?? null, $version);

                DB::table('attitude_logs')->insert($clean);
            }
        });
    }

    /* ------------------------------------------------------------
     * BK — replace per scope (tetap)
     * ------------------------------------------------------------ */
    protected function applyBkBatch(array $payload, int $version): void
    {
        $teacherId = (int) ($payload['teacher_id'] ?? 0);

        if ($teacherId <= 0) {
            throw new \RuntimeException('bk_log_batch tanpa teacher_id.');
        }

        $logs = is_array($payload['bk_logs'] ?? null) ? $payload['bk_logs'] : [];

        DB::transaction(function () use ($teacherId, $logs, $version) {
            DB::table('bk_logs')->where('teacher_id', $teacherId)->delete();

            foreach ($logs as $row) {
                $clean = $this->filterColumns('bk_logs', (array) $row);
                unset($clean['id']);
                $clean['teacher_id'] = $teacherId;
                $clean = $this->stampVersion('bk_logs', $clean, $version);
                $clean['created_at'] = $this->sourceTime($clean['created_at'] ?? $clean['updated_at'] ?? null, $version);
                $clean['updated_at'] = $this->sourceTime($clean['updated_at'] ?? null, $version);

                DB::table('bk_logs')->insert($clean);
            }
        });
    }

    /* ------------------------------------------------------------
     * CATATAN WALI — [P0-8] 0 baris = gagal transien
     * ------------------------------------------------------------ */
    protected function applyCatatanWaliBatch(array $payload, int $version): void
    {
        $homeroomId = (int) ($payload['homeroom_id'] ?? 0);

        if ($homeroomId <= 0) {
            throw new \RuntimeException('catatan_wali_batch tanpa homeroom_id.');
        }

        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        DB::transaction(function () use ($homeroomId, $rows, $version) {
            $matched = 0;
            $missing = [];

            foreach ($rows as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);

                if ($studentId <= 0) {
                    throw new \RuntimeException('student_id work product tidak valid.');
                }

                if (!DB::table('enrollments')->where('homeroom_id', $homeroomId)
                    ->where('student_id', $studentId)->lockForUpdate()->exists()) {
                    $missing[] = $studentId;
                    continue;
                }
                DB::table('enrollments')
                    ->where('homeroom_id', $homeroomId)
                    ->where('student_id', $studentId)
                    ->update([
                        'catatan_wali' => $row['catatan_wali'] ?? null,
                    'status_akhir_semester' => \App\Services\PromotionStatusService::normalize($row['status_akhir_semester'] ?? null),
                        'updated_at' => $this->sourceTime($row['updated_at'] ?? null, $version),
                    ]);

                $matched++;
            }

            if ($missing !== []) {
                throw new \RuntimeException(
                    'ENROLLMENT_MISSING: ' . count($missing)
                    . ' enrollment belum ada di laptop ini (homeroom #' . $homeroomId . ') — menunggu sinkron master.'
                );
            }

        });
    }

    /* ------------------------------------------------------------
     * RAPOR RECAP — [P0-8] pola sama
     * ------------------------------------------------------------ */
    protected function applyRaporRecapBatch(array $payload, int $version): void
    {
        $homeroomId = (int) ($payload['homeroom_id'] ?? 0);

        if ($homeroomId <= 0) {
            throw new \RuntimeException('rapor_recap_batch tanpa homeroom_id.');
        }

        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        DB::transaction(function () use ($homeroomId, $rows, $version) {
            $matched = 0;
            $missing = [];

            foreach ($rows as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);

                if ($studentId <= 0) {
                    throw new \RuntimeException('student_id work product tidak valid.');
                }

                if (!DB::table('enrollments')->where('homeroom_id', $homeroomId)
                    ->where('student_id', $studentId)->lockForUpdate()->exists()) {
                    $missing[] = $studentId;
                    continue;
                }
                DB::table('enrollments')
                    ->where('homeroom_id', $homeroomId)
                    ->where('student_id', $studentId)
                    ->update([
                        'rapor_sakit' => (int) ($row['rapor_sakit'] ?? 0),
                        'rapor_izin' => (int) ($row['rapor_izin'] ?? 0),
                        'rapor_alpa' => (int) ($row['rapor_alpa'] ?? 0),
                        'updated_at' => $this->sourceTime($row['updated_at'] ?? null, $version),
                    ]);

                $matched++;
            }

            if ($missing !== []) {
                throw new \RuntimeException(
                    'ENROLLMENT_MISSING: ' . count($missing)
                    . ' enrollment belum ada di laptop ini (homeroom #' . $homeroomId . ') — menunggu sinkron master.'
                );
            }

        });
    }

    /* ------------------------------------------------------------
     * EKSKUL — replace per scope (tetap)
     * ------------------------------------------------------------ */
    protected function applyEkskulGradeBatch(array $payload, int $version): void
    {
        $ekskulHomeroomId = (int) ($payload['ekskul_homeroom_id'] ?? 0);

        if ($ekskulHomeroomId <= 0) {
            throw new \RuntimeException('ekskul_grade_batch tanpa ekskul_homeroom_id.');
        }

        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        DB::transaction(function () use ($ekskulHomeroomId, $rows, $version) {
            DB::table('ekskul_grades')->where('homeroom_id', $ekskulHomeroomId)->delete();

            foreach ($rows as $row) {
                $clean = $this->filterColumns('ekskul_grades', (array) $row);
                unset($clean['id']);
                $clean['homeroom_id'] = $ekskulHomeroomId;
                $clean = $this->stampVersion('ekskul_grades', $clean, $version);
                $clean['created_at'] = $this->sourceTime($clean['created_at'] ?? $clean['updated_at'] ?? null, $version);
                $clean['updated_at'] = $this->sourceTime($clean['updated_at'] ?? null, $version);

                DB::table('ekskul_grades')->insert($clean);
            }
        });
    }

    /* ------------------------------------------------------------
     * KOKURIKULER — upsert per (kegiatan, siswa) (tetap)
     * ------------------------------------------------------------ */
    protected function applyKokurikulerGradeBatch(array $payload, int $version): void
    {
        $kokurikulerId = (int) ($payload['kokurikuler_id'] ?? 0);

        if ($kokurikulerId <= 0) {
            throw new \RuntimeException('kokurikuler_grade_batch tanpa kokurikuler_id.');
        }

        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        DB::transaction(function () use ($kokurikulerId, $rows, $version) {
            foreach ($rows as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);

                if ($studentId <= 0) {
                    throw new \RuntimeException('student_id work product tidak valid.');
                }

                $existing = DB::table('kokurikuler_grades')
                    ->where('student_id', $studentId)
                    ->where('kokurikuler_id', $kokurikulerId)
                    ->first();

                $values = [
                    'predikat' => $row['predikat'] ?? null,
                    'd1' => $row['d1'] ?? null,
                    'd2' => $row['d2'] ?? null,
                    'd3' => $row['d3'] ?? null,
                    'd4' => $row['d4'] ?? null,
                    'd5' => $row['d5'] ?? null,
                    'd6' => $row['d6'] ?? null,
                    'd7' => $row['d7'] ?? null,
                    'd8' => $row['d8'] ?? null,
                    'deskripsi' => $row['deskripsi'] ?? null,
                ];

                $values = $this->stampVersion('kokurikuler_grades', $values, $version);
                $values['updated_at'] = $this->sourceTime($row['updated_at'] ?? null, $version);

                if ($existing) {
                    DB::table('kokurikuler_grades')->where('id', $existing->id)->update($values);
                } else {
                    DB::table('kokurikuler_grades')->insert(
                        $values + [
                            'student_id' => $studentId,
                            'kokurikuler_id' => $kokurikulerId,
                            'created_at' => $this->sourceTime($row['created_at'] ?? $row['updated_at'] ?? null, $version),
                        ]
                    );
                }
            }
        });
    }
}
