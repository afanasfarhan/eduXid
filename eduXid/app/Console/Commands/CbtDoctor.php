<?php

namespace App\Console\Commands;

use App\Models\LmsSyncRecord;
use App\Services\Sync\LmsSyncService;
use App\Services\Sync\SyncClient;
use Illuminate\Console\Command;
use Throwable;

class CbtDoctor extends Command
{
    protected $signature = 'eduxid:cbt-doctor {--pull : Lakukan test pull ke Cloud (menandai event DELIVERED — aman, daemon tetap menariknya)}';

    protected $description = 'Diagnosa alur jawaban CBT PWA → laptop: konfigurasi, device, outbox daemon, sesi masuk.';

    public function handle(LmsSyncService $sync, SyncClient $client): int
    {
        $ok = true;

        // 1. Konfigurasi
        $this->info('1) KONFIGURASI');
        try {
            $this->line('   server  : ' . ($sync->syncServer() ?: '(KOSONG — belum setup cloud!)'));
            $this->line('   school  : ' . $sync->getSchoolId());
            $this->line('   device  : ' . $sync->getDeviceId());
        } catch (Throwable $e) {
            $this->error('   ✗ ' . $e->getMessage());
            return 1;
        }

        // 2. Device lokal
        $this->info('2) DEVICE LOKAL (sync_devices)');
        $deviceId = $sync->getDeviceId();
        $local = \App\Models\SyncDevice::where('device_id', $deviceId)->first();
        if ($local && $local->sync_token) {
            $this->line("   ✓ {$deviceId} terdaftar lokal, token ada");
        } else {
            $this->error("   ✗ {$deviceId} BELUM ter-pairing di laptop — lakukan pairing device guru.");
            $ok = false;
        }

        // 3. Outbox daemon untuk cbt_answer
        $this->info('3) RECORD cbt_answer (lms_sync_records)');
        $rows = LmsSyncRecord::where('entity_type', 'cbt_answer')->latest('id')->limit(5)->get();
        if ($rows->isEmpty()) {
            $this->line('   (kosong — daemon BELUM PERNAH menarik event jawaban)');
            $this->line('   → cek daemon hidup & kode baru: queue:restart; lalu CEK 2 (device di Cloud).');
        } else {
            foreach ($rows as $r) {
                $this->line(sprintf('   %s v%d attempts=%s %s | %s',
                    $r->status, $r->version, $r->attempts ?? '-',
                    \Illuminate\Support\Str::limit((string) $r->last_error, 80),
                    $r->updated_at
                ));
            }
        }

        // 4. Sesi masuk
        $this->info('4) EXAM_SESSIONS terbaru');
        $sessions = \Illuminate\Support\Facades\DB::table('exam_sessions')
            ->join('students', 'students.id', '=', 'exam_sessions.student_id')
            ->leftJoin('exams', 'exams.id', '=', 'exam_sessions.exam_id')
            ->orderByDesc('exam_sessions.id')->limit(5)
            ->select('exam_sessions.id', 'students.nama', 'exams.judul_ujian',
                     'exam_sessions.status', 'exam_sessions.nilai_akhir', 'exam_sessions.waktu_submit')
            ->get();
        if ($sessions->isEmpty()) {
            $this->line('   (kosong — ingest belum pernah membuat sesi)');
        }
        foreach ($sessions as $s) {
            $this->line(sprintf('   #%d %s | %s | %s | nilai=%s | submit=%s',
                $s->id, $s->nama, \Illuminate\Support\Str::limit((string) $s->judul_ujian, 30),
                $s->status, $s->nilai_akhir ?? 'NULL', $s->waktu_submit));
        }

        // 4b. Dead-letter & retry bermasalah
        $this->info('4b) DEAD-LETTER / RETRY BERMASALAH');
        $dead = LmsSyncRecord::where('entity_type', 'cbt_answer')->where('status', 'dead')->count();
        $stuck = LmsSyncRecord::where('entity_type', 'cbt_answer')->whereIn('status', ['pending', 'failed'])->count();
        $this->line("   dead={$dead} pending/failed={$stuck}");

        $stuckRows = LmsSyncRecord::where('entity_type', 'cbt_answer')
            ->whereIn('status', ['pending', 'failed', 'dead'])
            ->latest('id')->limit(3)->get();
        foreach ($stuckRows as $r) {
            $this->line(sprintf('   [%s] v%d att=%s | %s',
                $r->status, $r->version, $r->attempts ?? '-',
                \Illuminate\Support\Str::limit((string) $r->last_error, 110)));
        }

        // 5. Test pull (opsional)
        if ($this->option('pull')) {
            $this->info('5) TEST PULL ke Cloud');
            try {
                $res = $client->pull($sync->getSchoolId(), $deviceId);
                $this->line('   ✓ Cloud mengenal device ini. Event ditarik: ' . count($res['changes']));
            } catch (Throwable $e) {
                $this->error('   ✗ ' . \Illuminate\Support\Str::limit($e->getMessage(), 160));
                $this->line('   → HTTP 401/INVALID_DEVICE_TOKEN = device -PC belum terdaftar DI CLOUD → pairing ulang laptop.');
                $ok = false;
            }
        }

        $this->newLine();
        $this->info($ok ? 'Dasar sehat — bila antrian tetap kosong, jalankan dengan --pull untuk uji device di Cloud.'
                        : 'Ada masalah — ikuti ✗ di atas.');
        return 0;
    }
}