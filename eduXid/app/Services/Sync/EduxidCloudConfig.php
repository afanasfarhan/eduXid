<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;

class EduxidCloudConfig
{
    /*
     * ============================================================
     * eduXid CLOUD CONFIG — kunci ter-scope per sekolah (G1)
     * ============================================================
     *
     * Sumber kunci, urutan prioritas:
     *   1. Tabel eduxid_settings  (diisi via panel "Setup Cloud")
     *      → inilah jalur distribusi ke sekolah lain: admin sekolah
     *        tempel token dari operator pusat, TANPA edit .env.
     *   2. config eduxid.admin_token   (.env EDUXID_ADMIN_TOKEN)
     *   3. config eduxid.admin_key     (.env EDUXID_ADMIN_KEY — master,
     *      hanya di laptop operator pusat)
     *
     * URL Cloud dikelola internal aplikasi. Nilainya tidak diekspos
     * melalui status panel Admin dan tidak dapat diubah dari browser.
     */

    public static function adminToken(): string
    {
        $token = self::fromTable('admin_token');

        if ($token !== '') {
            return $token;
        }

        $token = trim((string) config('eduxid.admin_token', ''));

        if ($token !== '') {
            return $token;
        }

        return trim(
            (string) config('eduxid.admin_key', env('EDUXID_ADMIN_KEY', ''))
        );
    }

    public static function syncServer(): string
    {
        $candidate = self::fromTable('sync_server');
        if ($candidate === '') {
            $candidate = (string) config('services.eduxid_sync.url', env('EDUXID_SYNC_URL', ''));
        }
        $candidate = rtrim(trim($candidate), '/');
        // Fail CLOSED. URL with NIP/NIK or credentials in an HTTP request must
        // never cross LAN or Cloud in plaintext. HTTPS remains required even
        // when a legacy school setting contains an HTTP URL.
        if ($candidate !== '' && strtolower((string) parse_url($candidate, PHP_URL_SCHEME)) !== 'https') {
            return '';
        }
        return $candidate;
    }

    public static function set(string $key, ?string $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            DB::table('eduxid_settings')
                ->where('key', $key)
                ->delete();
            return;
        }

        DB::table('eduxid_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()]
        );
    }

    public static function status(): array
    {
        $token = self::adminToken();
        $server = self::syncServer();

        $tableToken = self::fromTable('admin_token');

        return [
            'ready' =>
                $token !== '' && $server !== '',

            'token_preview' =>
                $token !== ''
                    ? substr($token, 0, 12)
                      . '…'
                      . substr($token, -4)
                    : null,

            'token_source' =>
                self::tokenSource($tableToken),

            'cloud_configured' =>
                $server !== '',
        ];
    }

    protected static function tokenSource(string $tableToken): string
    {
        if ($tableToken !== '') {
            return 'Database (Setup Cloud) — kunci sekolah ini';
        }

        if (trim((string) config('eduxid.admin_token', '')) !== '') {
            return '.env — EDUXID_ADMIN_TOKEN';
        }

        if (trim((string) config('eduxid.admin_key', '')) !== '') {
            return '.env — EDUXID_ADMIN_KEY (MASTER — sebaiknya hanya di laptop operator pusat)';
        }

        return 'Belum dikonfigurasi';
    }

    protected static function fromTable(string $key): string
    {
        try {
            return trim(
                (string) DB::table('eduxid_settings')
                    ->where('key', $key)
                    ->value('value')
            );
        } catch (\Throwable) {
            return '';
        }
    }
}