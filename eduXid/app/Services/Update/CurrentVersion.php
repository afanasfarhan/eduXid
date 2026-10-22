<?php

namespace App\Services\Update;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CurrentVersion
{
    public function get(): string
    {
        try {
            if (Schema::hasTable('app_versions') && Schema::hasColumn('app_versions', 'version')) {
                $query = DB::table('app_versions');

                if (Schema::hasColumn('app_versions', 'is_current')) {
                    $query->where('is_current', true);
                }

                $version = $query->orderByDesc('id')->value('version');

                if (is_string($version) && trim($version) !== '') {
                    return trim($version);
                }
            }
        } catch (Throwable) {
        }

        $marker = (string) config('eduxid.install_root') . DIRECTORY_SEPARATOR . 'eduxid-version.json';

        if (is_file($marker)) {
            try {
                $json = json_decode((string) file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR);
                $version = trim((string) ($json['version'] ?? ''));
                if ($version !== '') {
                    return $version;
                }
            } catch (Throwable) {
            }
        }

        return (string) config('eduxid.version', '1.0.0');
    }
}
