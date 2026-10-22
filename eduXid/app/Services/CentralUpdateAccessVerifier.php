<?php
namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CentralUpdateAccessVerifier
{
    public function verify(Request $request): array
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        $schoolId = trim((string) $request->header('X-School-Id', ''));

        if ($authorization === '' || preg_match('/^Admin\\s+(.+)$/i', $authorization) !== 1) {
            throw new RuntimeException('Kredensial Admin School diperlukan.', 401);
        }
        if ($schoolId === '') {
            throw new RuntimeException('X-School-Id diperlukan.', 401);
        }

        $cloud = $this->cloudUrl();
        if ($cloud === '') {
            throw new RuntimeException('URL EDUXID Cloud untuk verifikasi akses belum dikonfigurasi.', 503);
        }

        try {
            $response = Http::timeout($this->timeout())
                ->connectTimeout(min(5, $this->timeout()))
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => $authorization,
                    'X-School-Id' => $schoolId,
                    'User-Agent' => 'eduXid-Central-Updater',
                ])
                ->withOptions($this->httpOptions())
                ->get($cloud . '/api/admin/auth/verify');
        } catch (Throwable $e) {
            throw new RuntimeException('Layanan autentikasi EDUXID Cloud tidak dapat dihubungi.', 503, $e);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('Kredensial Admin School tidak valid.', 401);
        }
        if (!$response->successful()) {
            throw new RuntimeException('Layanan autentikasi EDUXID Cloud gagal memverifikasi akses.', 503);
        }

        $data = $response->json();
        if (!is_array($data) || ($data['success'] ?? false) !== true || ($data['authenticated'] ?? false) !== true) {
            throw new RuntimeException('Respons autentikasi EDUXID Cloud tidak valid.', 503);
        }

        $verifiedSchool = trim((string) ($data['school_id'] ?? ''));
        if ($verifiedSchool === '' || !hash_equals($schoolId, $verifiedSchool)) {
            throw new RuntimeException('Scope sekolah hasil autentikasi tidak sesuai.', 403);
        }

        return ['school_id' => $verifiedSchool, 'scope' => trim((string) ($data['scope'] ?? 'school'))];
    }

    protected function cloudUrl(): string
    {
        foreach ([config('eduxid.sync_server'), config('services.eduxid_sync.url'), env('EDUXID_SYNC_SERVER'), env('EDUXID_SYNC_URL')] as $url) {
            if (is_string($url) && trim($url) !== '') return rtrim(trim($url), '/');
        }
        return '';
    }

    protected function timeout(): int
    {
        return max(5, min(30, (int) config('eduxid.timeout', 15)));
    }

    protected function httpOptions(): array
    {
        $ca = trim((string) config('eduxid.ca_cert', ''));
        return $ca !== '' && is_file($ca) ? ['verify' => $ca] : [];
    }
}
