<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * V21R98 — Bring eligible manually entered GTK into the *existing* teacher
 * directory pipeline. No direct Cloud writes and no alias/key generation here.
 * Identity alias provisioning remains authoritative in CloudDirectoryAliasV21.
 */
final class CloudManualTeacherReadinessV21R98
{
    private const VALID_ROLES = ['guru', 'wali_kelas', 'guru_bk', 'kepala_sekolah', 'tendik'];

    /** Decisions are deliberately independent from Laravel for regression tests. */
    public static function decision(string $status): string
    {
        return match ($status) {
            'new' => 'create',
            'existing' => 'ready',
            'missing_identity', 'invalid_identity' => 'needs_identity',
            default => 'needs_reconciliation',
        };
    }

    /**
     * Read-only summary. Never prints NIP, NIK, username, school key, or name.
     * @return array<string,int>
     */
    public function inspect(): array
    {
        return $this->collect()['counts'];
    }

    /**
     * Preflight all candidates, then provision safe, missing local users in one
     * transaction. Other GTK and Cloud workers are unchanged. Existing accounts
     * are reused, not rotated or re-created. Invalid records are reported.
     * @return array<string,int>
     */
    public function prepare(): array
    {
        $snapshot = $this->collect();
        $counts = $snapshot['counts'];
        if ($counts['needs_reconciliation'] > 0) {
            throw new RuntimeException('BLOCKED V21R98: terdapat '.$counts['needs_reconciliation'].' GTK manual dengan identitas atau relasi akun berbenturan. Push dihentikan; periksa akun lokal sebelum publikasi.');
        }
        if ($counts['to_create'] === 0) return $counts;

        if (!Schema::hasTable('eduxid_initial_credentials') || !Schema::hasTable('app_settings')) {
            throw new RuntimeException('BLOCKED V21R98: migrasi akun awal/setting lokal belum lengkap. Jalankan migrasi pada Admin sebelum publikasi guru manual.');
        }
        $service = app(ManualCloudDirectoryAccountsV21R80::class);
        DB::transaction(function () use ($snapshot, $service): void {
            foreach ($snapshot['create_ids'] as $teacherId) {
                // Re-check inside transaction. Prevent silent reassignment if
                // the record was edited between read-only audit and creation.
                $row = DB::table('teachers')->where('id', $teacherId)->first();
                if (!$row || $service->auditTeacher($row) !== 'new'
                    || $this->linkProblem($row) !== null) {
                    throw new RuntimeException('BLOCKED V21R98: identitas GTK manual berubah selama provisioning. Tidak ada akun baru diterbitkan.');
                }
                if ($service->provisionTeacher($teacherId) !== 'new') {
                    throw new RuntimeException('BLOCKED V21R98: provisioning akun GTK manual tidak konsisten.');
                }
                // A newly created manual GTK has an authoritative user login.
                // Link it only when the optional teacher.user_id column is
                // empty, so GTK bundle/pairing does not depend on name matching.
                if (Schema::hasColumn('teachers', 'user_id') && empty($row->user_id)) {
                    $nip = trim((string) ($row->nip ?? ''));
                    $login = $nip !== '' ? $nip : trim((string) ($row->nik ?? ''));
                    $accountId = (int) DB::table('users')->where('login_id', $login)->value('id');
                    if ($accountId <= 0) {
                        throw new RuntimeException('BLOCKED V21R98: akun baru GTK manual tidak ditemukan untuk pemetaan lokal.');
                    }
                    DB::table('teachers')->where('id', $teacherId)->whereNull('user_id')
                        ->update(['user_id' => $accountId]);
                }
            }
        }, 3);
        $counts['created'] = count($snapshot['create_ids']);
        $counts['to_create'] = 0;
        $counts['ready'] += $counts['created'];
        return $counts;
    }

    /** @return array{counts:array<string,int>,create_ids:list<int>} */
    private function collect(): array
    {
        foreach (['teachers', 'users'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException('BLOCKED V21R98: tabel GTK/akun lokal belum tersedia.');
            }
        }
        $counts = [
            'manual_total' => 0, 'ready' => 0, 'to_create' => 0,
            'created' => 0, 'needs_identity' => 0, 'needs_reconciliation' => 0,
        ];
        $create = [];
        if (!Schema::hasColumn('teachers', 'dapodik_ptk_id')) {
            // Older schema cannot reliably distinguish manual from Dapodik;
            // leave legacy account publishing untouched instead of guessing.
            return ['counts' => $counts, 'create_ids' => []];
        }
        $query = DB::table('teachers')->where(function ($q) {
            $q->whereNull('dapodik_ptk_id')
                ->orWhere('dapodik_ptk_id', '')
                ->orWhere('dapodik_ptk_id', 'like', 'manual-%');
        });
        if (Schema::hasColumn('teachers', 'deleted_at')) $query->whereNull('deleted_at');
        $service = app(ManualCloudDirectoryAccountsV21R80::class);
        foreach ($query->orderBy('id')->get() as $teacher) {
            $counts['manual_total']++;
            $problem = $this->linkProblem($teacher);
            $decision = $problem === null
                ? self::decision($service->auditTeacher($teacher))
                : 'needs_reconciliation';
            switch ($decision) {
                case 'create':
                    $counts['to_create']++;
                    $create[] = (int) $teacher->id;
                    break;
                case 'ready':
                    $counts['ready']++;
                    break;
                case 'needs_identity':
                    $counts['needs_identity']++;
                    break;
                default:
                    $counts['needs_reconciliation']++;
            }
        }
        return ['counts' => $counts, 'create_ids' => $create];
    }

    /** Existing user links and identity cannot be silently reassigned. */
    private function linkProblem(object $teacher): ?string
    {
        $loginIds = array_values(array_unique(array_filter(array_map(
            fn ($v) => trim((string) $v),
            [$teacher->nip ?? null, $teacher->nik ?? null]
        ), fn ($value) => $value !== '')));

        if (Schema::hasColumn('teachers', 'user_id')) {
            $linkedId = (int) ($teacher->user_id ?? 0);
            if ($linkedId > 0) {
                $linked = DB::table('users')->where('id', $linkedId)->first(['id','role','login_id']);
                if (!$linked || !in_array(trim((string) $linked->login_id), $loginIds, true)
                    || !in_array((string) $linked->role, self::VALID_ROLES, true)) {
                    return 'linked_user_mismatch';
                }
            }
            // Guard a different master GTK already linked to an account that
            // this record would otherwise match by NIP/NIK.
            if ($loginIds !== []) {
                $matched = DB::table('users')->whereIn('login_id', $loginIds)->pluck('id')->all();
                if ($matched !== [] && DB::table('teachers')
                    ->where('id', '!=', (int) $teacher->id)
                    ->whereIn('user_id', $matched)
                    ->when(Schema::hasColumn('teachers', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->exists()) {
                    return 'user_link_collision';
                }
            }
        }
        return null;
    }
}
