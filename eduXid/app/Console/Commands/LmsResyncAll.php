<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Models\LmsAssignment;
use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsTopic;
use App\Services\Sync\LmsSyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * ============================================================================
 * LMS RESYNC ALL — Push ulang SELURUH konten published ke Sync Server
 * ============================================================================
 *
 * KAPAN DIPAKAI:
 *
 *   - Transisi school_id (mis. SMAN001 → NPSN 30102991):
 *     semua konten lama ter-tag school_id lama, tidak akan pernah
 *     diterima device dengan school_id baru. Command ini me-push
 *     ulang semuanya ke school_id aktif.
 *
 *   - Migrasi/pemulihan worker: setelah worker atau D1 diganti,
 *     konten perlu di-broadcast ulang.
 *
 *   - "Pastikan sinkron": guru/admin ingin menjamin perangkat siswa
 *     memiliki versi terbaru dari semuanya.
 *
 * CARA KERJA:
 *
 *   Untuk SETIAP item published milik guru:
 *     1. increment sync_version (+1) — agar perangkat siswa yang
 *        sudah pernah menerima versi lama MENERIMA versi baru ini
 *        (version guard client), tidak di-skip.
 *     2. Push via LmsSyncService (broadcast, target null).
 *     3. Gagal transport TIDAK menghentikan — dilaporkan per item,
 *        dan outbox (queueAndPush) menyapunya via auto-retry guru.
 *
 *   Entitas yang di-push:
 *     - LmsCourse      (published)
 *     - LmsTopic       (published, dari course published)
 *     - LmsMaterial    (published, dari topic published)
 *     - LmsAssignment  (published, dari topic published)
 *     - Exam           (milik guru; dikirim apa pun statusnya agar
 *                       status_aktif di siswa selalu akurat)
 *
 * PENGGUNAAN:
 *
 *   php artisan lms:resync-all
 *
 * ============================================================================
 */
class LmsResyncAll extends Command
{
    /**
     * Signature command.
     *
     * --dry-run : hanya menampilkan apa yang akan di-push,
     *             tanpa benar-benar meng-push.
     */
    protected $signature = 'lms:resync-all
                            {--dry-run : Tampilkan rencana tanpa meng-push}';

    protected $description = 'Push ulang seluruh konten LMS published (kelas, topik, materi, tugas, CBT) ke Sync Server untuk school_id aktif';

    public function handle(LmsSyncService $lmsSync): int
    {
        $this->newLine();
        $this->info('================================================');
        $this->info('  LMS RESYNC ALL — PUSH ULANG KONTEN PUBLISHED');
        $this->info('================================================');
        $this->newLine();

        $schoolId = $lmsSync->getSchoolId();
        $deviceId = $lmsSync->getDeviceId();

        $this->line("  School ID : <comment>{$schoolId}</comment>");
        $this->line("  Device ID : <comment>{$deviceId}</comment>");
        $this->newLine();

        if ($schoolId === '') {
            $this->error('  School ID (NPSN) belum tersedia. Lakukan sync Dapodik atau isi EDUXID_SCHOOL_ID.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn('  MODE DRY-RUN — tidak ada yang di-push.');
            $this->newLine();
        }


        /*
         * ========================================================
         * KUMPULKAN SEMUA ITEM
         * ========================================================
         *
         * Hanya konten published yang dikirim ke siswa.
         * Urutan: course → topic → material → assignment → exam
         * (parent dulu, anak kemudian — memudahkan apply di sisi
         * penerima meski worker mengirim sesuai id).
         */

        $courses =
            LmsCourse::query()
                ->where('is_published', true)
                ->orderBy('id')
                ->get();

        $courseIds =
            $courses->pluck('id');

        $topics =
            LmsTopic::query()
                ->whereIn('course_id', $courseIds)
                ->where('is_published', true)
                ->orderBy('id')
                ->get();

        $topicIds =
            $topics->pluck('id');

        $materials =
            \App\Models\LmsMaterial::query()
                ->whereIn('topic_id', $topicIds)
                ->where('is_published', true)
                ->orderBy('id')
                ->get();

        $assignments =
            LmsAssignment::query()
                ->whereIn('topic_id', $topicIds)
                ->where('is_published', true)
                ->orderBy('id')
                ->get();

        $exams =
            Exam::query()
                ->orderBy('id')
                ->get();


        /*
         * ----------------------------------------------------
         * HITUNG TOTAL
         * ----------------------------------------------------
         */
        $totalItems =
            $courses->count()
            + $topics->count()
            + $materials->count()
            + $assignments->count()
            + $exams->count();


        if ($totalItems === 0) {

            $this->warn('  Tidak ada konten published yang perlu di-push.');

            return self::SUCCESS;
        }


        $this->line(
            "  Akan di-push ulang: "
            . $courses->count() . " kelas, "
            . $topics->count() . " topik, "
            . $materials->count() . " materi, "
            . $assignments->count() . " tugas, "
            . $exams->count() . " CBT "
            . "(total {$totalItems} item)"
        );

        $this->newLine();


        if ($this->option('dry-run')) {

            foreach ($courses as $course) {
                $this->line("  [course]      {$course->name}");
            }

            foreach ($topics as $topic) {
                $this->line("  [topic]       {$topic->title}");
            }

            foreach ($materials as $material) {
                $this->line("  [material]    {$material->title}");
            }

            foreach ($assignments as $assignment) {
                $this->line("  [assignment]  {$assignment->title}");
            }

            foreach ($exams as $exam) {
                $this->line("  [exam]        {$exam->judul_ujian}");
            }

            $this->newLine();

            return self::SUCCESS;
        }


        if (
            !$this->confirm(
                'Lanjutkan push ulang seluruh konten ke Sync Server?'
            )
        ) {
            $this->line('  Dibatalkan.');
            return self::SUCCESS;
        }


        $this->newLine();


        /*
         * ========================================================
         * PROGRESS BAR
         * ========================================================
         */
        $bar =
            $this->output->createProgressBar(
                $totalItems
            );

        $bar->start();


        $ok = 0;
        $queued = 0;
        $gagal = 0;

        $gagalList = [];


        /*
         * ========================================================
         * HELPER PUSH SATU ITEM
         * ========================================================
         *
         * increment version +1 SEBELUM push — agar perangkat
         * siswa yang sudah pernah menerima versi lama MENERIMA
         * versi baru ini (tidak di-skip version guard).
         */

        $pushItem =
            function (
                callable $versionBump,
                callable $doPush,
                string $label,
                string $entityId
            ) use (
                $lmsSync,
                $bar,
                &$ok,
                &$queued,
                &$gagal,
                &$gagalList
            ): void {

                try {

                    $versionBump();

                    $result =
                        $doPush();

                    $isQueued =
                        is_array($result)
                        && ($result['queued'] ?? false) === true;


                    if ($isQueued) {
                        $queued++;
                    } else {
                        $ok++;
                    }

                } catch (Throwable $e) {

                    $gagal++;

                    $gagalList[] = [
                        'label' => $label,
                        'entity_id' => $entityId,
                        'error' => $e->getMessage(),
                    ];
                }

                $bar->advance();
            };


        /*
         * ========================================================
         * 1. COURSES
         * ========================================================
         */
        foreach ($courses as $course) {

            $pushItem(
                function () use ($course) {
                    $course->increment('sync_version');
                },
                function () use ($lmsSync, $course) {
                    return $lmsSync->pushCourse(
                        $course->fresh(),
                        'update'
                    );
                },
                'course: ' . $course->name,
                (string) $course->sync_id
            );
        }


        /*
         * ========================================================
         * 2. TOPICS
         * ========================================================
         */
        foreach ($topics as $topic) {

            $pushItem(
                function () use ($topic) {
                    $topic->increment('sync_version');
                },
                function () use ($lmsSync, $topic) {
                    return $lmsSync->pushTopic(
                        $topic->fresh(),
                        'update'
                    );
                },
                'topic: ' . $topic->title,
                (string) $topic->sync_id
            );
        }


        /*
         * ========================================================
         * 3. MATERIALS
         * ========================================================
         */
        foreach ($materials as $material) {

            $pushItem(
                function () use ($material) {
                    $material->increment('sync_version');
                },
                function () use ($lmsSync, $material) {
                    return $lmsSync->pushMaterial(
                        $material->fresh(),
                        'update'
                    );
                },
                'material: ' . $material->title,
                (string) $material->sync_id
            );
        }


        /*
         * ========================================================
         * 4. ASSIGNMENTS
         * ========================================================
         */
        foreach ($assignments as $assignment) {

            $pushItem(
                function () use ($assignment) {
                    $assignment->increment('sync_version');
                },
                function () use ($lmsSync, $assignment) {
                    return $lmsSync->pushAssignment(
                        $assignment->fresh(),
                        'update'
                    );
                },
                'assignment: ' . $assignment->title,
                (string) $assignment->sync_id
            );
        }


        /*
         * ========================================================
         * 5. EXAMS (CBT)
         * ========================================================
         */
        foreach ($exams as $exam) {

            $pushItem(
                function () use ($exam) {
                    $exam->increment('sync_version');
                },
                function () use ($lmsSync, $exam) {
                    return $lmsSync->pushExam(
                        $exam->fresh(),
                        'update'
                    );
                },
                'exam: ' . $exam->judul_ujian,
                (string) $exam->sync_id
            );
        }


        $bar->finish();

        $this->newLine(2);


        /*
         * ========================================================
         * RINGKASAN
         * ========================================================
         */
        $this->info('================================================');
        $this->info('  RESYNC SELESAI');
        $this->info('================================================');

        $this->line("  Berhasil langsung : <info>{$ok}</info>");
        $this->line("  Menunggu (queued) : <comment>{$queued}</comment>");
        $this->line("  Gagal             : <error>{$gagal}</error>");

        $this->newLine();


        if ($gagalList !== []) {

            $this->warn('  Detail gagal:');

            foreach ($gagalList as $item) {
                $this->line(
                    "   - {$item['label']}: {$item['error']}"
                );
            }

            $this->newLine();
        }


        /*
         * ----------------------------------------------------
         * OUTBOX — retry pending bila ada yang queued
         * ----------------------------------------------------
         */
        if ($queued > 0) {

            $this->line('  Menyapu outbox (retry pending)...');

            try {

                $retryResult =
                    $lmsSync->retryPending(200);

                $this->line(
                    "  Outbox: synced {$retryResult['synced']}, "
                    . "failed {$retryResult['failed']}"
                );

            } catch (Throwable $retryError) {

                $this->warn(
                    '  Outbox retry gagal: '
                    . $retryError->getMessage()
                    . ' (akan dicoba ulang oleh auto-sync guru)'
                );
            }

            $this->newLine();
        }


        $this->line(
            '  Selanjutnya: perangkat siswa akan menerima seluruh '
            . 'konten ini otomatis saat sinkronisasi berikutnya.'
        );

        $this->newLine();


        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}