<?php

namespace App\Console\Commands;

use App\Models\LmsAssignment;
use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsTopic;
use App\Services\Sync\LmsSyncService;
use Illuminate\Console\Command;
use Throwable;

class LmsResyncBroadcast extends Command
{
    protected $signature = 'lms:resync-broadcast';

    protected $description = 'Push ulang seluruh konten LMS yang dipublikasikan sebagai broadcast (arsip Cloud + fan-out rombel)';

    protected function pushAndReport(LmsSyncService $sync, string $label, callable $push, int &$ok, int &$fail): void
    {
        try {
            $result = $push();
            $status = strtoupper((string) ($result['status'] ?? ''));
            $success = ($result['success'] ?? false) === true && in_array($status, ['ACCEPTED', 'DUPLICATE'], true);

            if ($success) {
                $ok++;
                $this->line("  OK   {$label} [{$status}]");
                return;
            }

            $fail++;
            $this->warn("  FAIL {$label} status=" . ($status ?: 'NONE') . ' error=' . ($result['error'] ?? ($result['message'] ?? 'unknown')));
        } catch (Throwable $e) {
            $fail++;
            $this->warn("  FAIL {$label} exception=" . $e->getMessage());
        }
    }

    public function handle(LmsSyncService $sync): int
    {
        $ok = 0;
        $fail = 0;

        $this->info('Push Courses...');
        LmsCourse::query()->where('is_published', true)->chunkById(50, function ($rows) use ($sync, &$ok, &$fail) {
            foreach ($rows as $row) {
                $this->pushAndReport($sync, "course:{$row->id}", fn () => $sync->pushCourse($row, 'update'), $ok, $fail);
            }
        });

        $this->info('Push Topics...');
        LmsTopic::query()->where('is_published', true)->chunkById(50, function ($rows) use ($sync, &$ok, &$fail) {
            foreach ($rows as $row) {
                $this->pushAndReport($sync, "topic:{$row->id}", fn () => $sync->pushTopic($row, 'update'), $ok, $fail);
            }
        });

        $this->info('Push Materials...');
        LmsMaterial::query()->where('is_published', true)->chunkById(50, function ($rows) use ($sync, &$ok, &$fail) {
            foreach ($rows as $row) {
                $this->pushAndReport($sync, "material:{$row->id}", fn () => $sync->pushMaterial($row, 'update'), $ok, $fail);
            }
        });

        $this->info('Push Assignments...');
        LmsAssignment::query()->where('is_published', true)->chunkById(50, function ($rows) use ($sync, &$ok, &$fail) {
            foreach ($rows as $row) {
                $this->pushAndReport($sync, "assignment:{$row->id}", fn () => $sync->pushAssignment($row, 'update'), $ok, $fail);
            }
        });

        $this->info("Selesai. Berhasil: {$ok}, Gagal: {$fail}.");
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}