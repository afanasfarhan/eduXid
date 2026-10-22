<?php

namespace App\Console\Commands;

use App\Services\LocalMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class LocalMediaDoctorCommand extends Command
{
    protected $signature = 'eduxid:media-doctor';

    protected $description = 'Memeriksa kesiapan media lokal eduXid tanpa bergantung pada public/storage symlink.';

    public function handle(LocalMediaService $media): int
    {
        $this->info('eduXid Local Media Doctor');

        try {
            $root = $media->storageRoot();

            $this->line('Disk        : public');
            $this->line('Storage root: ' . $root);
            $this->line('Exists      : ' . (is_dir($root) ? 'YES' : 'NO'));
            $this->line('Writable    : ' . (is_writable($root) ? 'YES' : 'NO'));

            if (!is_dir($root)) {
                Storage::disk(LocalMediaService::DISK)->makeDirectory('');
            }

            $probe = '.eduxid-media-probe-' . bin2hex(random_bytes(6)) . '.txt';

            Storage::disk(LocalMediaService::DISK)->put(
                $probe,
                'eduXid local media probe'
            );

            if (!Storage::disk(LocalMediaService::DISK)->exists($probe)) {
                $this->error('WRITE TEST: FAILED');
                return self::FAILURE;
            }

            Storage::disk(LocalMediaService::DISK)->delete($probe);

            $this->info('WRITE TEST  : PASS');
            $this->info('P9 MEDIA   : READY');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('MEDIA DOCTOR FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
