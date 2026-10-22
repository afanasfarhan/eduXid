<?php

namespace App\Console\Commands;

use App\Services\Update\ReleaseVerifier;
use Illuminate\Console\Command;
use Throwable;

class EduxidUpdateVerifyCommand extends Command
{
    protected $signature = 'eduxid:update:verify {--file=} {--edition=}';
    protected $description = 'Verifikasi manifest, SHA-256, dan signature Ed25519 patch eduXid';

    public function handle(ReleaseVerifier $verifier): int
    {
        try {
            $file = trim((string) $this->option('file'));
            $edition = trim((string) ($this->option('edition') ?: config('eduxid.edition', '')));

            $manifest = $verifier->verify($file, $edition);

            $this->info('VALID ' . $manifest['version'] . ' [' . $edition . ']');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
