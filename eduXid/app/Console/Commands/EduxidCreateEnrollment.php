<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class EduxidCreateEnrollment extends Command
{
    protected $signature = 'eduxid:create-enrollment
        {--max-uses=0 : Batas pemakaian (0 = unlimited)}
        {--expires-at= : Tanggal kedaluwarsa ISO (opsional)}';

    protected $description = 'Membuat Enrollment Code pairing device di EDUXID CLOUD';

    public function handle(): int
    {
        $adminKey = trim((string) config('eduxid.admin_key', env('EDUXID_ADMIN_KEY', '')));
        if ($adminKey === '') {
            $this->error('EDUXID_ADMIN_KEY belum dikonfigurasi.');
            return self::FAILURE;
        }

        $baseUrl = rtrim((string) config('eduxid.sync_server'), '/');
        if ($baseUrl === '') {
            $this->error('EDUXID_SYNC_SERVER belum dikonfigurasi.');
            return self::FAILURE;
        }

        $schoolId = trim((string) DB::table('schools')->value('npsn'));
        if ($schoolId === '') {
            $this->error('NPSN sekolah tidak ditemukan (tabel schools).');
            return self::FAILURE;
        }

        $maxUses = max(0, (int) $this->option('max-uses'));
        $expiresAt = trim((string) $this->option('expires-at')) ?: null;

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['Authorization' => 'Admin ' . $adminKey])
                ->post($baseUrl . '/api/admin/enrollment/create', [
                    'school_id' => $schoolId,
                    'max_uses' => $maxUses,
                    'expires_at' => $expiresAt,
                ]);

            $data = $response->json();

            if ($response->failed() || !is_array($data) || ($data['success'] ?? false) !== true) {
                $detail = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $response->body();
                $this->error('Gagal membuat enrollment code. HTTP ' . $response->status() . ': ' . $detail);
                return self::FAILURE;
            }

            $code = $data['data']['enrollment_code'] ?? '';
            $this->info('Enrollment Code berhasil dibuat untuk sekolah ' . $schoolId . ':');
            $this->line('');
            $this->line('    ' . $code);
            $this->line('');
            $this->warn('Plaintext hanya ditampilkan SEKALI di sini — Cloud hanya menyimpan hash.');
            $this->line('Batas pemakaian: ' . ($maxUses > 0 ? $maxUses . 'x' : 'unlimited')
                . ($expiresAt ? ' | Kedaluwarsa: ' . $expiresAt : ''));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}