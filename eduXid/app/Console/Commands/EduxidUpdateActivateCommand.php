<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EduxidUpdateActivateCommand extends Command
{
    protected $signature = 'eduxid:update:activate {--version=} {--source=updater}';
    protected $description = 'Aktifkan nomor versi eduXid setelah patch berhasil diterapkan';

    public function handle(): int
    {
        $version = trim((string) $this->option('version'));
        $source = trim((string) $this->option('source')) ?: 'updater';

        if (preg_match('/^\d+\.\d+\.\d+(-[A-Za-z0-9._-]+)?$/', $version) !== 1) {
            $this->error('Nomor versi tidak valid.');
            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($version, $source) {
                $this->activateAppVersion($version);
                $this->recordPatch($version, $source);
            });

            $this->info('ACTIVE ' . $version);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function activateAppVersion(string $version): void
    {
        if (!Schema::hasTable('app_versions')) {
            return;
        }

        $hasVersion = Schema::hasColumn('app_versions', 'version');
        if (!$hasVersion) {
            return;
        }

        if (Schema::hasColumn('app_versions', 'is_current')) {
            DB::table('app_versions')->update(['is_current' => false]);
        }

        $row = ['version' => $version];

        if (Schema::hasColumn('app_versions', 'name')) {
            $row['name'] = 'eduXid ' . $version;
        }
        if (Schema::hasColumn('app_versions', 'is_current')) {
            $row['is_current'] = true;
        }
        if (Schema::hasColumn('app_versions', 'activated_at')) {
            $row['activated_at'] = now();
        }
        if (Schema::hasColumn('app_versions', 'updated_at')) {
            $row['updated_at'] = now();
        }

        $exists = DB::table('app_versions')->where('version', $version)->exists();

        if ($exists) {
            DB::table('app_versions')->where('version', $version)->update($row);
        } else {
            if (Schema::hasColumn('app_versions', 'created_at')) {
                $row['created_at'] = now();
            }
            DB::table('app_versions')->insert($row);
        }
    }

    private function recordPatch(string $version, string $source): void
    {
        if (!Schema::hasTable('patches') || !Schema::hasColumn('patches', 'version')) {
            return;
        }

        $row = [];

        if (Schema::hasColumn('patches', 'name')) {
            $row['name'] = 'Patch ' . $version;
        }
        if (Schema::hasColumn('patches', 'description')) {
            $row['description'] = 'Installed by eduXidUpdater (' . $source . ')';
        }
        if (Schema::hasColumn('patches', 'type')) {
            $row['type'] = 'patch';
        }
        if (Schema::hasColumn('patches', 'status')) {
            $row['status'] = 'installed';
        }
        if (Schema::hasColumn('patches', 'installed_at')) {
            $row['installed_at'] = now();
        }
        if (Schema::hasColumn('patches', 'updated_at')) {
            $row['updated_at'] = now();
        }

        $exists = DB::table('patches')->where('version', $version)->exists();

        if ($exists) {
            DB::table('patches')->where('version', $version)->update($row);
        } else {
            $row['version'] = $version;
            if (Schema::hasColumn('patches', 'created_at')) {
                $row['created_at'] = now();
            }
            DB::table('patches')->insert($row);
        }
    }
}
