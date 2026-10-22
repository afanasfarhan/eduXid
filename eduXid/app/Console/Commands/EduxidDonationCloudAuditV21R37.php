<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Security\DonationCloudIntegrityV21;
use App\Services\Security\DonationIntegrityV21;
use Illuminate\Console\Command;

final class EduxidDonationCloudAuditV21R37 extends Command
{
    protected $signature = 'eduxid:donation-cloud-audit {--report : Submit alias-only attestation using an existing paired teacher PC token}';
    protected $description = 'Periksa kebijakan integritas Donasi di Cloud tanpa menampilkan data pribadi.';

    public function handle(): int
    {
        $local = DonationIntegrityV21::verify(base_path(), (string)config('eduxid.update_public_key', ''));
        $mode = $local['mode'];
        $measured = DonationCloudIntegrityV21::measuredFiles(base_path());
        $cloud = DonationCloudIntegrityV21::check(base_path(), $mode, $measured);
        $report = 'not_requested';
        if ($this->option('report')) {
            $sent = DonationCloudIntegrityV21::heartbeat(base_path(), true, true);
            $report = $sent['report'];
        }
        $this->line('eduXid V21R37 CLOUD DONATION AUDIT');
        $this->line('LOCAL: ' . ($local['ok'] ? 'PASS' : 'MISMATCH'));
        $this->line('LOCAL_MODE: ' . $mode);
        $this->line('CLOUD_POLICY: ' . $cloud['state']);
        $this->line('CLOUD_REPORT: ' . $report);
        $this->line('CLOUD_DIFF_COUNT: ' . count($cloud['errors']));
        // Deliberately never print hashes, IDs, tokens, or payment details.
        return $local['ok'] && ($cloud['state'] !== 'mismatch') ? self::SUCCESS : self::FAILURE;
    }
}
