<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CbtDoctorCommand extends Command
{
    protected $signature = 'eduxid:doctor-cbt {--fix : Perbaiki sync_id yang hilang/salah dari payload event}';

    protected $description = '[FASE-5] Audit & perbaiki exam_sessions (sync_id PWA vs LAN, konsistensi chip).';

    public function handle(): int
    {
        if (!Schema::hasTable('exam_sessions')) {
            $this->error('Tabel exam_sessions tidak ada.');
            return self::FAILURE;
        }

        $sessions = DB::table('exam_sessions as es')
            ->join('students as s', 's.id', '=', 'es.student_id')
            ->orderBy('es.id')
            ->get(['es.id', 's.nama', 's.nisn', 'es.sync_id', 'es.nilai_akhir']);

        $pwa = $lan = $hilang = 0;

        foreach ($sessions as $s) {
            $sid = trim((string) ($s->sync_id ?? ''));

            if ($sid === '') {
                $hilang++;
                $this->line("{$s->id} | {$s->nama} | sync_id KOSONG");
            } elseif (str_starts_with($sid, 'lan::')) {
                $lan++;
            } else {
                $pwa++;
            }
        }

        $this->info("Ringkasan: PWA={$pwa}, LAN={$lan}, tanpa sync_id={$hilang}");

        if ($hilang === 0) {
            $this->info('✓ Semua sesi memiliki sync_id.');
            return self::SUCCESS;
        }

        if (!$this->option('fix')) {
            $this->warn('Jalankan dengan --fix untuk memulihkan dari payload event (selektif & aman).');
            return self::SUCCESS;
        }

        /* ---- Perbaikan selektif dari payload cbt_answer synced ---- */

        $records = DB::table('lms_sync_records')
            ->where('entity_type', 'cbt_answer')
            ->where('status', 'synced')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['entity_id', 'payload']);

        $fixed = 0;
        $seen = [];

        foreach ($records as $rec) {
            $payload = is_array($rec->payload)
                ? $rec->payload
                : (json_decode((string) $rec->payload, true) ?: []);

            $sid = trim((string) ($payload['sync_id'] ?? $rec->entity_id ?? ''));

            if ($sid === '' || str_starts_with($sid, 'lan::') || isset($seen[$sid])) {
                continue;
            }
            $seen[$sid] = true;

            $nisn = trim((string) ($payload['student_id'] ?? ''));
            if ($nisn === '') { continue; }

            $student = DB::table('students')->where('nisn', $nisn)->first(['id', 'nama']);
            if (!$student) { continue; }

            $updated = DB::table('exam_sessions')
                ->where('student_id', $student->id)
                ->where(function ($q) {
                    $q->whereNull('sync_id')->orWhere('sync_id', 'like', 'lan::%');
                })
                ->update(['sync_id' => $sid]);

            if ($updated > 0) {
                $this->info("FIX: {$student->nama} ({$nisn}) → {$sid}");
                $fixed++;
            }
        }

        $this->info("Selesai: {$fixed} sesi dipulihkan.");

        try {
            DB::table('app_settings')->insertOrIgnore([
                'key' => 'cbt::doctor_last_run',
                'value' => now()->toIso8601String(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Penanda tidak kritis.
        }

        return self::SUCCESS;
    }
}