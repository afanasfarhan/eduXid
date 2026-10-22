<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Sync\WorkProductPushService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Throwable;

trait ManagesTeacherWorkData
{
    private array $tableColumnsCache = [];

    /* ============================================================
     * EKSPOR HASIL PEKERJAAN GURU
     * ============================================================ */

    /**
     * Bangun snapshot hasil pekerjaan dari akun yang sedang login.
     * Dipakai bersama oleh backup manual dan Kirim Data via LAN agar
     * kedua jalur selalu membawa payload yang identik.
     *
     * @return array{data:array<string,mixed>,filename_base:string,teacher_id:?int}
     */
    private function buildTeacherWorkPayload(): array
    {
        $user = Auth::user();

        if (!$user) {
            throw new \RuntimeException('Sesi pengguna tidak tersedia.');
        }

        $guru = $user->teacher();
        $role = method_exists($user, 'roleName')
            ? $user->roleName()
            : strtolower(trim((string) ($user->role ?? '')));

        $data = [
            'metadata' => [
                'tipe_file' => 'backup_guru',
                'guru_id' => null,
                'login_id' => $user->login_id,
                'user_id' => $user->id,
                'role' => $role,
                'nama_guru' => $user->name,
                'tanggal_backup' => date('Y-m-d H:i:s'),
                'sync_version' => (int) floor(microtime(true) * 1000),
            ],
            'surat_records' => [],
            'assessment_scores' => [],
            'final_grades' => [],
            'attitude_logs' => [],
            'bk_logs' => [],
            'question_banks' => [],
            'exams' => [],
            'exam_questions' => [],
        ];

        $namaBersih = str_replace(' ', '_', (string) $user->name);
        $guruId = null;

        if ($guru) {
            $guruId = (int) $guru->id;
            $assignments = DB::table('teaching_assignments')
                ->where('teacher_id', $guruId)
                ->pluck('id');
            $examIds = DB::table('exams')
                ->where('teacher_id', $guruId)
                ->pluck('id');

            $data['metadata']['guru_id'] = $guruId;
            $data['metadata']['nama_guru'] = $guru->nama;
            $namaBersih = str_replace(' ', '_', (string) $guru->nama);

            if ($assignments->isNotEmpty()) {
                $data['assessment_scores'] = DB::table('assessment_scores')
                    ->whereIn('teaching_assignment_id', $assignments)
                    ->get()->map(fn ($r) => (array) $r)->toArray();

                $data['final_grades'] = DB::table('final_grades')
                    ->whereIn('teaching_assignment_id', $assignments)
                    ->get()->map(fn ($r) => (array) $r)->toArray();

                $data['attitude_logs'] = DB::table('attitude_logs')
                    ->whereIn('teaching_assignment_id', $assignments)
                    ->get()->map(fn ($r) => (array) $r)->toArray();
            }

            $data['bk_logs'] = DB::table('bk_logs')
                ->where('teacher_id', $guruId)
                ->get()->map(fn ($r) => (array) $r)->toArray();

            $data['question_banks'] = DB::table('question_banks')
                ->where('teacher_id', $guruId)
                ->get()->map(fn ($r) => (array) $r)->toArray();

            $data['exams'] = DB::table('exams')
                ->where('teacher_id', $guruId)
                ->get()->map(fn ($r) => (array) $r)->toArray();

            if ($examIds->isNotEmpty()) {
                $data['exam_questions'] = DB::table('exam_questions')
                    ->whereIn('exam_id', $examIds)
                    ->get()->map(fn ($r) => (array) $r)->toArray();
            }
        }

        if (Schema::hasTable('surat_records')) {
            $data['surat_records'] = DB::table('surat_records')
                ->where('user_id', $user->id)
                ->get()
                ->map(fn ($s) => (array) $s)
                ->toArray();
        }

        return [
            'data' => $data,
            'filename_base' => $namaBersih,
            'teacher_id' => $guruId,
        ];
    }

    public function eksporTransaksiGuru()
    {
        $bundle = $this->buildTeacherWorkPayload();
        $data = $bundle['data'];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $namaFile = 'backup_guru_' . $bundle['filename_base'] . '_' . date('Y_m_d') . '.json';

        return Response::make($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $namaFile . '"',
        ]);
    }

    /**
     * Kirim snapshot pekerjaan ke laptop Admin melalui LAN.
     * Route ini sebelumnya sudah ada, tetapi method-nya hilang sehingga
     * tombol "Kirim Data" menghasilkan 500. Payload disamakan dengan
     * backup manual dan endpoint penerima Admin memakai LWW.
     */
    public function pushGradesApi(Request $request): RedirectResponse
    {
        $serverIp = trim((string) DB::table('app_settings')
            ->where('key', 'server_ip')
            ->value('value'));

        if ($serverIp === '' || !filter_var($serverIp, FILTER_VALIDATE_IP)) {
            return back()->with('error', 'IP komputer Admin belum valid. Simpan IP Server terlebih dahulu.');
        }

        $token = trim((string) config('eduxid.sync_token', ''));

        if ($token === '') {
            return back()->with(
                'error',
                'Token sinkronisasi LAN sekolah belum dikonfigurasi. '
                . 'Endpoint data pekerjaan tidak menerima token bawaan demi keamanan.'
            );
        }

        try {
            $bundle = $this->buildTeacherWorkPayload();
            $payload = $bundle['data'];

            $response = Http::withHeaders([
                'X-Sync-Token' => $token,
                'X-Login-Id' => trim((string) (Auth::user()?->login_id ?? '')),
                'Accept' => 'application/json',
            ])
                ->connectTimeout(10)
                ->timeout(120)
                ->post("http://{$serverIp}:8000/api/local/sync-grades", $payload);

            if (!$response->successful()) {
                $message = trim((string) ($response->json('error') ?? $response->json('message') ?? ''));
                if ($message === '') {
                    $message = 'HTTP ' . $response->status();
                }

                throw new \RuntimeException($message);
            }

            $report = is_array($response->json('report')) ? $response->json('report') : [];
            $role = strtolower(trim((string) (Auth::user()?->role ?? '')));

            if ($role === 'tendik' && empty($bundle['teacher_id'])) {
                return back()->with(
                    'success',
                    '✅ Data Tendik berhasil dikirim ke Admin melalui LAN. Arsip surat: '
                    . (int) ($report['surat'] ?? 0) . '.'
                );
            }

            return back()->with(
                'success',
                '✅ Hasil pekerjaan berhasil dikirim ke Admin melalui LAN. '
                . 'Nilai harian: ' . (int) ($report['scores'] ?? 0)
                . ', nilai akhir rapor: ' . (int) ($report['finals'] ?? 0)
                . ', sikap: ' . (int) ($report['attitudes'] ?? 0)
                . '. Nilai yang diterima Admin ditandai untuk diteruskan ke Cloud; '
                . 'nilai akhir tetap ter-scope ke wali kelas terkait.'
            );
        } catch (Throwable $e) {
            report($e);

            return back()->with(
                'error',
                'Kirim Data ke Admin gagal: ' . $e->getMessage()
            );
        }
    }

    public function imporTransaksiGuru(Request $request): RedirectResponse
    {
        $request->validate([
            'json_file' => 'required|file|mimes:json,txt',
        ]);

        $user = Auth::user();
        $guru = $user->teacher();
        $role = method_exists($user, 'roleName')
            ? $user->roleName()
            : strtolower(trim((string) ($user->role ?? '')));

        if (!$guru && $role !== 'tendik') {
            return back()->with('error', 'Akun Anda belum terhubung dengan data GTK.');
        }

        $data = json_decode(file_get_contents($request->file('json_file')->getRealPath()), true);

        if (!isset($data['metadata']['tipe_file']) || $data['metadata']['tipe_file'] !== 'backup_guru') {
            return back()->with('error', 'File JSON tidak valid. Pastikan menggunakan file Backup Guru dari eduXid.');
        }

        // V21R165: Validasi backup JSON *sebelum* mengganti identitas guru lama
        // dengan identitas tujuan. Jika ID siswa/penugasan bergeser, fail-closed.
        (new \App\Services\TeacherWorkBackupCompatibilityV21R165())
            ->inspectForCurrentUser(
                $data,
                trim((string) $user->login_id),
                $guru ? (int) $guru->id : null,
                $role
            );

        $data['metadata']['guru_id'] = $guru ? (int) $guru->id : null;
        $data['metadata']['login_id'] = $user->login_id;
        $data['metadata']['role'] = $role;
        $data['metadata']['sync_version'] = max(
            1,
            (int) ($data['metadata']['sync_version'] ?? 0) ?: (int) floor(microtime(true) * 1000)
        );

        try {
            $report = $this->processTransactionData($data);
        } catch (Throwable $e) {
            report($e);
            return back()->with('error', 'Restore gagal: ' . $e->getMessage());
        }

        if ($role === 'tendik' && !$guru) {
            return back()->with(
                'success',
                'Data pekerjaan Tendik berhasil dipulihkan. Arsip surat: ' . (int) ($report['surat'] ?? 0) . '.'
            );
        }

        return back()->with(
            'success',
            'Data pekerjaan berhasil dipulihkan (LWW)! '
            . "Nilai: {$report['scores']}, Final: {$report['finals']}, "
            . "dilewati (lokal lebih baru): {$report['skipped_lww']}."
        );
    }

    public function imporTransaksiAdmin(Request $request): RedirectResponse
    {
        $request->validate([
            'json_file' => 'required|file|mimes:json,txt',
        ]);

        $data = json_decode(file_get_contents($request->file('json_file')->getRealPath()), true);

        if (!isset($data['metadata']['tipe_file']) || $data['metadata']['tipe_file'] !== 'backup_guru') {
            return back()->with('error', 'Format JSON tidak valid. Bukan file Hasil Pekerjaan Guru.');
        }

        $namaGuru = $data['metadata']['nama_guru'] ?? 'Tidak Dikenal';

        try {
            $report = $this->processTransactionData($data);
        } catch (Throwable $e) {
            report($e);
            return back()->with('error', 'Impor data guru gagal: ' . $e->getMessage());
        }

        return back()->with(
            'success',
            "Data transaksional dari Guru ({$namaGuru}) berhasil digabungkan! "
            . "Nilai: {$report['scores']}, Final: {$report['finals']}, "
            . "dilewati (lokal lebih baru): {$report['skipped_lww']}."
        );
    }

    public function imporTransaksiAdminApi(Request $request)
    {
        try {
            $data = $request->all();

            if (!isset($data['metadata']['tipe_file']) || $data['metadata']['tipe_file'] !== 'backup_guru') {
                return response()->json(['error' => 'Format data tidak valid'], 400);
            }

            $namaGuru = $data['metadata']['nama_guru'] ?? 'Tidak Dikenal';
            $role = strtolower(trim((string) ($data['metadata']['role'] ?? 'guru')));

            $report = $this->processTransactionData($data);

            Log::info('[06.3] Data pekerjaan diterima via LAN.', [
                'pengirim' => $namaGuru,
                'role' => $role,
                'report' => $report,
            ]);

            $message = $role === 'tendik'
                ? "Data pekerjaan Tendik ({$namaGuru}) berhasil diterima Server Admin."
                : "Data pekerjaan Guru ({$namaGuru}) berhasil diterima Server Admin.";

            return response()->json([
                'success' => true,
                'message' => $message,
                'report' => $report,
            ]);
        } catch (Throwable $e) {
            Log::error('G2A API Error: ' . $e->getMessage());

            return response()->json(['error' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    /* ============================================================
     * [REWRITE-06.3] PENERIMA NILAI GURU (LAN / file backup)
     *
     * LWW-upsert by natural key + remap exams/question_banks +
     * markDirty (LAN dan Cloud konvergen). Detail di komentar
     * processTransactionData.
     * ============================================================
     */
    private function processTransactionData(array $data): array
    {
        $guruId = (int) ($data['metadata']['guru_id'] ?? 0);
        $loginId = trim((string) ($data['metadata']['login_id'] ?? ''));
        $role = strtolower(trim((string) ($data['metadata']['role'] ?? '')));
        $version = max(1, (int) ($data['metadata']['sync_version'] ?? 1));

        /*
         * Tendik dapat memakai halaman Pengaturan yang sama walaupun tidak
         * memiliki record teachers. Untuk akun ini, satu-satunya pekerjaan
         * yang boleh diterima oleh Admin adalah arsip surat miliknya sendiri.
         * Identitas selalu di-resolve ulang dari login_id di server; user_id
         * dari payload tidak pernah dipercaya.
         */
        if ($guruId <= 0 && $role === 'tendik') {
            if ($loginId === '') {
                throw new \RuntimeException('metadata.login_id Tendik wajib tersedia.');
            }

            $serverUser = DB::table('users')
                ->where('login_id', $loginId)
                ->first(['id', 'role']);

            if (!$serverUser || strtolower(trim((string) $serverUser->role)) !== 'tendik') {
                throw new \RuntimeException('Identitas Tendik tidak ditemukan atau tidak cocok di server Admin.');
            }

            $report = [
                'scores' => 0, 'finals' => 0, 'attitudes' => 0,
                'exams' => 0, 'questions' => 0, 'exam_questions' => 0,
                'bk' => 0, 'surat' => 0, 'skipped_lww' => 0,
            ];

            DB::transaction(function () use ($data, $serverUser, &$report) {
                if (!Schema::hasTable('surat_records')) {
                    return;
                }

                $suratRows = is_array($data['surat_records'] ?? null)
                    ? $data['surat_records']
                    : [];

                DB::table('surat_records')->where('user_id', (int) $serverUser->id)->delete();

                foreach ($suratRows as $row) {
                    $values = $this->filterRowColumns('surat_records', (array) $row);
                    unset($values['id'], $values['user_id']);
                    $values['user_id'] = (int) $serverUser->id;
                    $values['created_at'] = $values['created_at'] ?? now();
                    $values['updated_at'] = now();

                    DB::table('surat_records')->insert($values);
                    $report['surat']++;
                }
            });

            return $report;
        }

        if ($guruId <= 0 || !DB::table('teachers')->where('id', $guruId)->exists()) {
            throw new \RuntimeException('metadata.guru_id tidak valid (guru tidak ditemukan di server).');
        }

        /*
         * [06.3-SECURITY] Token LAN sekolah-wide: guru_id payload
         * wajib terikat login_id guru yang sama (diresolve server).
         * Bila login_id kosong (file lama), verifikasi dilewati
         * dengan peringatan - kompatibilitas mundur.
         */
        if ($loginId && trim((string) $loginId) !== '') {
            $ownerLogin = DB::table('teachers')
                ->where('id', $guruId)
                ->selectRaw("COALESCE(NULLIF(TRIM(nip),''), NULLIF(TRIM(nik),''), NULLIF(TRIM(nuptk),'')) AS owner_login")
                ->value('owner_login');

            if ($ownerLogin !== null && trim((string) $ownerLogin) !== trim((string) $loginId)) {
                throw new \RuntimeException(
                    'metadata.guru_id tidak cocok dengan metadata.login_id - data ditolak.'
                );
            }
        } else {
            Log::warning('[06.3] Payload tanpa metadata.login_id - verifikasi kepemilikan dilewati.', [
                'guru_id' => $guruId,
            ]);
        }

        $serverUserId = null;

        if ($loginId) {
            $serverUserId = DB::table('users')->where('login_id', $loginId)->value('id');
        }

        $report = [
            'scores' => 0, 'finals' => 0, 'attitudes' => 0,
            'exams' => 0, 'questions' => 0, 'exam_questions' => 0,
            'bk' => 0, 'surat' => 0, 'skipped_lww' => 0,
        ];

        DB::transaction(function () use ($data, $guruId, $serverUserId, $version, &$report) {

            /* ---------- 1. Tabel nilai: LWW upsert by natural key ---------- */

            $scores = is_array($data['assessment_scores'] ?? null) ? $data['assessment_scores'] : [];
            foreach ($scores as $row) {
                if ($this->upsertVersionedRow(
                    'assessment_scores',
                    ['student_id', 'teaching_assignment_id', 'tanggal'],
                    (array) $row,
                    $version,
                    $report
                )) {
                    $report['scores']++;
                }
            }

            $finals = is_array($data['final_grades'] ?? null) ? $data['final_grades'] : [];
            foreach ($finals as $row) {
                if ($this->upsertVersionedRow(
                    'final_grades',
                    ['student_id', 'teaching_assignment_id'],
                    (array) $row,
                    $version,
                    $report
                )) {
                    $report['finals']++;
                }
            }

            $attitudes = is_array($data['attitude_logs'] ?? null) ? $data['attitude_logs'] : [];
            foreach ($attitudes as $row) {
                if ($this->upsertVersionedRow(
                    'attitude_logs',
                    ['student_id', 'teaching_assignment_id', 'tanggal'],
                    (array) $row,
                    $version,
                    $report
                )) {
                    $report['attitudes']++;
                }
            }

            /* ---------- 2. exams + question_banks: upsert + REMAP ---------- */

            $examMap = $this->upsertExamsWithRemap(
                is_array($data['exams'] ?? null) ? $data['exams'] : [],
                $guruId,
                $version
            );

            $questionMap = $this->upsertQuestionBanksWithRemap(
                is_array($data['question_banks'] ?? null) ? $data['question_banks'] : [],
                $guruId,
                $version
            );

            $report['exams'] = count($examMap);
            $report['questions'] = count($questionMap);

            /* ---------- 3. exam_questions: remapped ---------- */

            $examQuestions = is_array($data['exam_questions'] ?? null) ? $data['exam_questions'] : [];
            foreach ($examQuestions as $row) {
                $r = (array) $row;

                $newExamId = $examMap[(int) ($r['exam_id'] ?? 0)] ?? null;
                $newQbId = $questionMap[(int) ($r['question_bank_id'] ?? 0)] ?? null;

                if ($newExamId === null || $newQbId === null) {
                    continue; // induk tidak terpetakan - jangan tulis yatim
                }

                $values = $this->filterRowColumns('exam_questions', $r);
                unset($values['id'], $values['exam_id'], $values['question_bank_id'], $values['sync_version']);

                $existing = DB::table('exam_questions')
                    ->where('exam_id', $newExamId)
                    ->where('question_bank_id', $newQbId)
                    ->first();

                if ($existing) {
                    DB::table('exam_questions')
                        ->where('id', $existing->id)
                        ->update($values + ['updated_at' => now()]);
                } else {
                    DB::table('exam_questions')->insert($values + [
                        'exam_id' => $newExamId,
                        'question_bank_id' => $newQbId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $report['exam_questions']++;
            }

            /* ---------- 4. bk_logs: replace-per-scope ---------- */

            $bkRows = is_array($data['bk_logs'] ?? null) ? $data['bk_logs'] : [];

            if ($bkRows !== []) {
                DB::table('bk_logs')->where('teacher_id', $guruId)->delete();

                foreach ($bkRows as $row) {
                    $values = $this->filterRowColumns('bk_logs', (array) $row);
                    unset($values['id'], $values['sync_version']);
                    $values['teacher_id'] = $guruId;
                    $values = $this->stampTableSyncVersion('bk_logs', $values, $version);
                    $values['created_at'] = $values['created_at'] ?? now();
                    $values['updated_at'] = now();

                    DB::table('bk_logs')->insert($values);
                    $report['bk']++;
                }
            }

            /* ---------- 5. surat_records: replace per user ---------- */

            $suratRows = is_array($data['surat_records'] ?? null) ? $data['surat_records'] : [];

            if ($serverUserId && $suratRows !== []) {
                DB::table('surat_records')->where('user_id', $serverUserId)->delete();

                foreach ($suratRows as $row) {
                    $values = $this->filterRowColumns('surat_records', (array) $row);
                    unset($values['id']);
                    $values['user_id'] = $serverUserId;
                    $values['created_at'] = $values['created_at'] ?? now();
                    $values['updated_at'] = now();

                    DB::table('surat_records')->insert($values);
                    $report['surat']++;
                }
            }
        });

        /*
         * [06.3] LAN dan Cloud konvergen: nilai via LAN di-fan-out
         * juga ke Cloud oleh daemon admin.
         */
        WorkProductPushService::markDirty($guruId);

        return $report;
    }

    /* ============================================================
     * [06.3] HELPERS
     * ============================================================ */

    private function tableColumns(string $table): array
    {
        if (!isset($this->tableColumnsCache[$table])) {
            try {
                $this->tableColumnsCache[$table] = Schema::getColumnListing($table);
            } catch (Throwable) {
                $this->tableColumnsCache[$table] = [];
            }
        }

        return $this->tableColumnsCache[$table];
    }

    private function filterRowColumns(string $table, array $row): array
    {
        $allowed = $this->tableColumns($table);

        return $allowed !== [] ? array_intersect_key($row, array_flip($allowed)) : $row;
    }

    private function stampTableSyncVersion(string $table, array $values, int $version): array
    {
        if (in_array('sync_version', $this->tableColumns($table), true)) {
            $values['sync_version'] = $version;
        }

        return $values;
    }

    /**
     * LWW-upsert by natural key. Null pada key ditangani whereNull.
     * Return true = ditulis; false = di-skip (lokal lebih baru).
     */
    private function upsertVersionedRow(
        string $table,
        array $keyCols,
        array $row,
        int $version,
        array &$report
    ): bool {
        $cols = $this->tableColumns($table);

        $values = array_intersect_key($row, array_flip($cols));
        unset($values['id'], $values['sync_version']);

        $query = DB::table($table);

        foreach ($keyCols as $col) {
            if (!in_array($col, $cols, true)) {
                continue;
            }

            $val = $values[$col] ?? null;

            if ($val === null) {
                $query->whereNull($col);
            } else {
                $query->where($col, $val);
            }
        }

        $existing = $query->first();

        if ($existing) {
            $localVersion = (int) ($existing->sync_version ?? 0);

            if ($localVersion > $version) {
                $report['skipped_lww']++;
                return false;
            }

            $update = $this->stampTableSyncVersion($table, $values, $version);
            $update['updated_at'] = now();

            DB::table($table)->where('id', $existing->id)->update($update);

            return true;
        }

        $insert = $this->stampTableSyncVersion($table, $values, $version);
        $insert['created_at'] = $insert['created_at'] ?? now();
        $insert['updated_at'] = now();

        DB::table($table)->insert($insert);

        return true;
    }

    /**
     * Upsert exams milik guru (sync_id, fallback judul). Token tidak
     * ditimpa pada update; unik pada insert. Map idLaptop => idServer.
     */
    private function upsertExamsWithRemap(array $rows, int $guruId, int $version): array
    {
        $map = [];

        $hasSyncIdCol = in_array('sync_id', $this->tableColumns('exams'), true);

        foreach ($rows as $row) {
            $r = (array) $row;
            $oldId = (int) ($r['id'] ?? 0);

            if ($oldId <= 0) {
                continue;
            }

            $values = $this->filterRowColumns('exams', $r);
            unset($values['id'], $values['sync_version']);

            $syncId = $hasSyncIdCol ? trim((string) ($r['sync_id'] ?? '')) : '';

            $existing = null;

            if ($syncId !== '') {
                $existing = DB::table('exams')->where('sync_id', $syncId)->first();
            }

            if (!$existing) {
                $existing = DB::table('exams')
                    ->where('teacher_id', $guruId)
                    ->where('judul_ujian', (string) ($r['judul_ujian'] ?? ''))
                    ->first();
            }

            if ($existing) {
                unset($values['token']);

                $values = $this->stampTableSyncVersion('exams', $values, $version);
                $values['teacher_id'] = $guruId;
                $values['updated_at'] = now();

                DB::table('exams')->where('id', $existing->id)->update($values);
                $map[$oldId] = (int) $existing->id;

                continue;
            }

            if (isset($values['token']) && trim((string) $values['token']) !== '') {
                $taken = DB::table('exams')->where('token', $values['token'])->exists();

                while ($taken) {
                    $values['token'] = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $taken = DB::table('exams')->where('token', $values['token'])->exists();
                }
            }

            $values = $this->stampTableSyncVersion('exams', $values, $version);
            $values['teacher_id'] = $guruId;
            $values['created_at'] = $values['created_at'] ?? now();
            $values['updated_at'] = now();

            $newId = (int) DB::table('exams')->insertGetId($values);
            $map[$oldId] = $newId;
        }

        return $map;
    }

    /**
     * Upsert question_banks milik guru (sync_id, fallback pertanyaan).
     * Map idLaptop => idServer.
     */
    private function upsertQuestionBanksWithRemap(array $rows, int $guruId, int $version): array
    {
        $map = [];

        $hasSyncIdCol = in_array('sync_id', $this->tableColumns('question_banks'), true);

        foreach ($rows as $row) {
            $r = (array) $row;
            $oldId = (int) ($r['id'] ?? 0);

            if ($oldId <= 0) {
                continue;
            }

            $values = $this->filterRowColumns('question_banks', $r);
            unset($values['id'], $values['sync_version']);

            $syncId = $hasSyncIdCol ? trim((string) ($r['sync_id'] ?? '')) : '';

            $existing = null;

            if ($syncId !== '') {
                $existing = DB::table('question_banks')->where('sync_id', $syncId)->first();
            }

            if (!$existing) {
                $existing = DB::table('question_banks')
                    ->where('teacher_id', $guruId)
                    ->where('pertanyaan', (string) ($r['pertanyaan'] ?? ''))
                    ->first();
            }

            if ($existing) {
                $values = $this->stampTableSyncVersion('question_banks', $values, $version);
                $values['teacher_id'] = $guruId;
                $values['updated_at'] = now();

                DB::table('question_banks')->where('id', $existing->id)->update($values);
                $map[$oldId] = (int) $existing->id;

                continue;
            }

            $values = $this->stampTableSyncVersion('question_banks', $values, $version);
            $values['teacher_id'] = $guruId;
            $values['created_at'] = $values['created_at'] ?? now();
            $values['updated_at'] = now();

            $newId = (int) DB::table('question_banks')->insertGetId($values);
            $map[$oldId] = $newId;
        }

        return $map;
    }

    public function eksporBackupGuruAdmin(Request $request)
    {
        $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
        ]);

        $guru = DB::table('teachers')->where('id', $request->teacher_id)->first();

        $loginId = $this->firstNonEmpty(
            $guru->nip ?? null,
            $guru->nik ?? null,
            $guru->nuptk ?? null
        );

        $guruUser = $loginId ? DB::table('users')->where('login_id', $loginId)->first() : null;
        $userId = $guruUser ? $guruUser->id : null;

        $assignments = DB::table('teaching_assignments')->where('teacher_id', $guru->id)->pluck('id');
        $examIds = DB::table('exams')->where('teacher_id', $guru->id)->pluck('id');

        $data = [
            'metadata' => [
                'tipe_file' => 'backup_guru',
                'guru_id' => $guru->id,
                'user_id' => $userId,
                'login_id' => $loginId,
                'nama_guru' => $guru->nama,
                'tanggal_backup' => date('Y-m-d H:i:s'),
            ],
            'surat_records' => [],
            'assessment_scores' => [],
            'final_grades' => [],
            'attitude_logs' => [],
            'bk_logs' => [],
            'question_banks' => [],
            'exams' => [],
            'exam_questions' => [],
        ];

        $data['metadata']['sync_version'] = (int) floor(microtime(true) * 1000);

        if ($userId) {
            $data['surat_records'] = DB::table('surat_records')
                ->where('user_id', $userId)
                ->get()->map(fn ($s) => (array) $s)->toArray();
        }

        if ($assignments->isNotEmpty()) {
            $data['assessment_scores'] = DB::table('assessment_scores')
                ->whereIn('teaching_assignment_id', $assignments)
                ->get()->map(fn ($r) => (array) $r)->toArray();

            $data['final_grades'] = DB::table('final_grades')
                ->whereIn('teaching_assignment_id', $assignments)
                ->get()->map(fn ($r) => (array) $r)->toArray();

            $data['attitude_logs'] = DB::table('attitude_logs')
                ->whereIn('teaching_assignment_id', $assignments)
                ->get()->map(fn ($r) => (array) $r)->toArray();
        }

        $data['bk_logs'] = DB::table('bk_logs')
            ->where('teacher_id', $guru->id)
            ->get()->map(fn ($r) => (array) $r)->toArray();

        $data['question_banks'] = DB::table('question_banks')
            ->where('teacher_id', $guru->id)
            ->get()->map(fn ($r) => (array) $r)->toArray();

        $data['exams'] = DB::table('exams')
            ->where('teacher_id', $guru->id)
            ->get()->map(fn ($r) => (array) $r)->toArray();

        if ($examIds->isNotEmpty()) {
            $data['exam_questions'] = DB::table('exam_questions')
                ->whereIn('exam_id', $examIds)
                ->get()->map(fn ($r) => (array) $r)->toArray();
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $namaBersih = str_replace(' ', '_', $guru->nama);
        $namaFile = 'backup_guru_' . $namaBersih . '_' . date('Y_m_d') . '.json';

        return Response::make($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $namaFile . '"',
        ]);
    }

    public function imporBackupGuruAdmin(Request $request): RedirectResponse
    {
        $request->validate([
            'json_file' => 'required|file|mimes:json,txt',
        ]);

        $data = json_decode(file_get_contents($request->file('json_file')->getRealPath()), true);

        if (!isset($data['metadata']['tipe_file']) || $data['metadata']['tipe_file'] !== 'backup_guru') {
            return back()->with('error', 'File JSON tidak valid. Pastikan menggunakan file Backup Guru (backup_guru_*).');
        }

        $guruId = (int) ($data['metadata']['guru_id'] ?? 0);

        if ($guruId <= 0 || !DB::table('teachers')->where('id', $guruId)->exists()) {
            return back()->with('error', 'Data Guru pada file backup tidak ditemukan di server Admin.');
        }

        try {
            $report = $this->processTransactionData($data);
        } catch (Throwable $e) {
            report($e);
            return back()->with('error', 'Impor backup guru gagal: ' . $e->getMessage());
        }

        $namaGuru = $data['metadata']['nama_guru'] ?? 'Tidak Dikenal';

        return back()->with(
            'success',
            "Data backup pekerjaan guru ({$namaGuru}) berhasil diimpor ke Server! "
            . "Nilai: {$report['scores']}, Final: {$report['finals']}, "
            . "dilewati (lokal lebih baru): {$report['skipped_lww']}. "
            . 'Batch guru akan di-fan-out ke Cloud otomatis.'
        );
    }
}