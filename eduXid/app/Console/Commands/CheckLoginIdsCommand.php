<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckLoginIdsCommand extends Command
{
    protected $signature = 'eduxid:check-login-ids {--json : Output JSON}';

    protected $description = '[FASE-0 / SI-01] Sweep login_id users — kompatibilitas device ID SchoolIdentity (gagal-keras pada karakter terlarang).';

    protected const PATTERN = '/^[A-Za-z0-9._]+$/';

    public function handle(): int
    {
        if (!Schema::hasTable('users')) {
            $this->error('Tabel users tidak ada.');
            return self::FAILURE;
        }

        $rows = DB::table('users')
            ->select('id', 'name', 'role', 'login_id')
            ->orderBy('id')
            ->get();

        $issues = [];
        $seen = [];

        foreach ($rows as $row) {
            $loginId = trim((string) ($row->login_id ?? ''));

            if ($loginId === '') {
                $issues[] = $this->issue($row, 'login_id kosong — device ID tidak dapat dibentuk');
                continue;
            }

            if (preg_match(self::PATTERN, $loginId) !== 1) {
                $issues[] = $this->issue(
                    $row,
                    "mengandung karakter terlarang — SchoolIdentity::deviceId gagal keras (hanya A-Za-z0-9._)"
                );
                continue;
            }

            $seen[$loginId][] = $row;
        }

        foreach ($seen as $loginId => $holders) {
            if (count($holders) > 1) {
                $ids = implode(', ', array_map(static fn ($r) => '#' . $r->id, $holders));

                $issues[] = [
                    'user_id'  => null,
                    'role'     => null,
                    'name'     => null,
                    'login_id' => $loginId,
                    'issue'    => 'dipakai ' . count($holders) . ' akun (' . $ids . ') — device ID & routing event ambigu',
                ];
            }
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'total_users' => $rows->count(),
                'issues'      => $issues,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->info('Users diperiksa: ' . $rows->count());

            if ($issues === []) {
                $this->info('✓ Semua login_id kompatibel dengan pembentukan device ID.');
                return self::SUCCESS;
            }

            $this->warn('Masalah ditemukan: ' . count($issues));

            $this->table(
                ['user_id', 'role', 'name', 'login_id', 'masalah'],
                array_map(static fn (array $i) => [
                    $i['user_id'] ?? '-',
                    $i['role'] ?? '-',
                    $i['name'] ?? '-',
                    $i['login_id'] ?? '-',
                    $i['issue'],
                ], $issues)
            );

            $this->line('Tindakan: perbaiki login_id akun terkait (menu Admin) — sinkronisasi device terkait berhenti sampai diperbaiki.');
        }

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }

    protected function issue(object $row, string $message): array
    {
        return [
            'user_id'  => (int) $row->id,
            'role'     => $row->role ?? null,
            'name'     => $row->name ?? null,
            'login_id' => (string) ($row->login_id ?? ''),
            'issue'    => $message,
        ];
    }
}