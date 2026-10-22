<?php

namespace App\Services\Ljk;

use App\Models\Teacher;
use App\Services\Sync\DailyAssessmentWriter;
use App\Services\Sync\LmsSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Koreksi LJK tanpa record exams atau question_banks. Seluruh data disimpan lokal. */
class LjkStandaloneManualService
{
    public function __construct(private DailyAssessmentWriter $writer, private LmsSyncService $sync) {}

    private function teacherId(): int
    {
        $login = trim((string) (Auth::user()?->login_id ?? ''));
        if ($login === '') throw new RuntimeException('Login guru tidak tersedia.');
        $id = Teacher::query()->where('nip', $login)->orWhere('nik', $login)->orWhere('nuptk', $login)->value('id');
        if (!$id) throw new RuntimeException('Identitas guru tidak ditemukan.');
        return (int) $id;
    }

    public function assignments(): array
    {
        $teacherId = $this->teacherId();
        return DB::table('teaching_assignments as ta')
            ->leftJoin('subjects as s', 's.id', '=', 'ta.subject_id')
            ->leftJoin('homerooms as h', 'h.id', '=', 'ta.homeroom_id')
            ->where('ta.teacher_id', $teacherId)->whereNotNull('ta.subject_id')->whereNotNull('ta.homeroom_id')
            ->orderBy('h.nama')->orderBy('s.nama_mata_pelajaran')
            ->get(['ta.id','ta.subject_id','ta.homeroom_id','s.nama_mata_pelajaran as subject_name','h.nama as class_name'])
            ->map(fn($r) => (array) $r)->all();
    }

    public function sessions(): array
    {
        return DB::table('ljk_manual_sessions')->where('teacher_id', $this->teacherId())
            ->whereNull('deleted_at')->orderByDesc('created_at')->get()->map(fn($r) => $this->summary($r))->all();
    }

    private function summary(object $row): array
    {
        return [
            'session_id' => (string) $row->session_id,
            'title' => (string) $row->title,
            'teaching_assignment_id' => (int) $row->teaching_assignment_id,
            'subject_id' => (int) $row->subject_id,
            'homeroom_id' => (int) $row->homeroom_id,
            'tanggal' => (string) $row->tanggal,
            'jenis_penilaian' => (string) $row->jenis_penilaian,
            'question_count' => (int) $row->question_count,
            'version' => (int) $row->version,
            'key_fingerprint' => (string) $row->key_fingerprint,
            'created_at' => (string) $row->created_at,
        ];
    }

    private static function allowedType(string $type): string
    {
        $t = strtolower(trim($type));
        if (!in_array($t, ['pilihan_ganda','majemuk','benar_salah'], true)) {
            throw new RuntimeException('Jenis kunci harus pilihan_ganda, majemuk, atau benar_salah.');
        }
        return $t;
    }

    /** Kunci per nomor: {type,answer}; diterapkan identik laptop dan PWA. */
    public static function normalizeKeys(array $input): array
    {
        if (count($input) < 1 || count($input) > 50) throw new RuntimeException('Jumlah soal harus 1–50.');
        $out = [];
        foreach (array_values($input) as $i => $raw) {
            if (!is_array($raw)) throw new RuntimeException('Kunci nomor ' . ($i + 1) . ' tidak valid.');
            $type = self::allowedType((string) ($raw['type'] ?? 'pilihan_ganda'));
            $v = $raw['answer'] ?? null;
            if ($type === 'majemuk') {
                $a = is_array($v) ? $v : preg_split('/[\s,;|]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY);
                $a = array_values(array_unique(array_map(fn($x) => strtoupper(trim((string) $x)), $a)));
                sort($a);
                if (!$a || count(array_filter($a, fn($x) => preg_match('/^[A-E]$/', $x))) !== count($a)) {
                    throw new RuntimeException('Kunci majemuk nomor ' . ($i + 1) . ' harus pilihan A–E.');
                }
                $v = $a;
            } else {
                $v = strtoupper(trim((string) $v));
                if (!preg_match($type === 'benar_salah' ? '/^[AB]$/' : '/^[A-E]$/', $v)) {
                    throw new RuntimeException('Kunci nomor ' . ($i + 1) . ' harus ' . ($type === 'benar_salah' ? 'A atau B' : 'A sampai E') . '.');
                }
            }
            $out[] = ['type' => $type, 'answer' => $v];
        }
        return $out;
    }

    public static function fingerprint(array $keys): string
    {
        return hash('sha256', json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function create(array $payload): array
    {
        $teacherId = $this->teacherId();
        $taId = (int) ($payload['teaching_assignment_id'] ?? 0);
        $ta = DB::table('teaching_assignments')->where('id', $taId)->where('teacher_id', $teacherId)->first();
        if (!$ta || !(int) $ta->subject_id || !(int) $ta->homeroom_id) throw new RuntimeException('Penugasan guru, mata pelajaran, atau rombel tidak valid.');
        $keys = self::normalizeKeys($payload['keys'] ?? []);
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150) throw new RuntimeException('Nama koreksi wajib diisi, maksimal 150 karakter.');
        $tanggal = (string) ($payload['tanggal'] ?? now()->format('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || !checkdate((int) substr($tanggal,5,2), (int) substr($tanggal,8,2), (int) substr($tanggal,0,4))) {
            throw new RuntimeException('Tanggal penilaian tidak valid.');
        }
        $jenis = (string) ($payload['jenis_penilaian'] ?? 'sumatif');
        if (!in_array($jenis, DailyAssessmentWriter::VALID_SCORE_TYPES, true)) throw new RuntimeException('Jenis penilaian tidak valid.');
        $id = 'manual::' . (string) Str::uuid();
        DB::table('ljk_manual_sessions')->insert([
            'session_id' => $id, 'teacher_id' => $teacherId, 'teaching_assignment_id' => $taId,
            'subject_id' => (int) $ta->subject_id, 'homeroom_id' => (int) $ta->homeroom_id,
            'title' => $title, 'tanggal' => $tanggal, 'jenis_penilaian' => $jenis,
            'question_count' => count($keys), 'keys_json' => json_encode($keys, JSON_UNESCAPED_UNICODE),
            'key_fingerprint' => self::fingerprint($keys), 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->details($id);
    }

    private function owned(string $id, ?int $teacherId = null): object
    {
        $teacherId ??= $this->teacherId();
        if (!preg_match('/^manual::[a-f\d-]{36}$/i', $id)) throw new RuntimeException('ID sesi manual tidak valid.');
        $row = DB::table('ljk_manual_sessions')->where('session_id', $id)->where('teacher_id', $teacherId)->whereNull('deleted_at')->first();
        if (!$row) throw new RuntimeException('Sesi koreksi manual bukan milik guru atau tidak ditemukan.');
        return $row;
    }

    /** Arsip sesi + kosongkan kunci aktif. Nilai Buku Nilai dan snapshot hasil tetap untuk audit. */
    public function deleteSession(string $id): array
    {
        $session = $this->owned($id);
        $pc = trim((string) $this->sync->getDeviceId());
        if (!preg_match('/-PC$/i', $pc)) {
            throw new RuntimeException('Identitas laptop -PC tidak aktif. Perbaiki pairing sebelum menghapus agar kunci pada PWA Guru bisa ditarik.');
        }
        $target = (string) preg_replace('/-PC$/i', '-HP', $pc);
        $syncId = 'ljkpkg::' . $id;
        $lastVersion = (int) (DB::table('lms_sync_records')
            ->where('entity_type', 'lms_assignment_submission')
            ->where('entity_id', $syncId)->max('version') ?? 0);
        $version = max((int) floor(microtime(true) * 1000) + 1, $lastVersion + 1, (int) $session->version + 1);
        // Server PWA sudah mengerti tombstone Paket LJK, termasuk sesi manual.
        // queueAndPush membuat outbox lokal (tetap pending saat offline).
        $queued = $this->sync->queueAndPush('lms_assignment_submission', $syncId,
            'delete', $version, [
                'entity' => 'ljk_private_package', 'sync_id' => $syncId,
                'exam_id' => null, 'exam_sync_id' => $id, 'manual_session_id' => $id,
                'deleted' => true, 'key_source' => 'manual',
                // V19R2: Worker mewajibkan owner scope bahkan untuk tombstone privat.
                // Nilai berasal dari sesi resmi lokal, bukan dari parameter browser.
                'teacher_id' => (int) $session->teacher_id,
                'teaching_assignment_id' => (int) $session->teaching_assignment_id,
                'homeroom_id' => (int) $session->homeroom_id,
                'target_device_id' => $target, 'sync_version' => $version,
            ], $target, null);
        $published = DB::table('ljk_scan_results')->where('exam_sync_id', $id)
            ->whereNotNull('published_at')->count();
        $archived = DB::table('ljk_scan_results')->where('exam_sync_id', $id)
            ->where('is_active', true)->count();
        DB::transaction(function () use ($session, $id): void {
            DB::table('ljk_scan_results')->where('exam_sync_id', $id)
                ->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('ljk_manual_sessions')->where('id', $session->id)
                ->whereNull('deleted_at')->update([
                    'keys_json' => '[]', 'deleted_at' => now(), 'updated_at' => now(),
                ]);
        });
        return [
            'deleted' => true, 'session_id' => $id, 'archived_results' => $archived,
            'preserved_published' => $published, 'pwa_sync' => $queued,
        ];
    }

    private function roster(int $homeroom): array
    {
        return DB::table('enrollments as e')->join('students as st', 'st.id', '=', 'e.student_id')
            ->where('e.homeroom_id', $homeroom)->orderBy('st.nama')
            ->get(['st.id','st.nama','st.nisn','e.homeroom_id'])->map(fn($r) => (array) $r)->all();
    }

    public function details(string $id): array
    {
        $row = $this->owned($id);
        $data = $this->summary($row);
        $data['keys'] = json_decode((string) $row->keys_json, true) ?: [];
        $data['students'] = $this->roster((int) $row->homeroom_id);
        return $data;
    }

    private static function answerAt(array $answers, int $index): mixed
    {
        // OMR menggunakan nomor soal 1..50. Jangan gunakan indeks nol bila nomor valid ada.
        if (array_key_exists((string) $index, $answers)) return $answers[(string) $index];
        if (array_key_exists($index, $answers)) return $answers[$index];
        return '';
    }

    private static function choiceSet(mixed $answer): array
    {
        $arr = is_array($answer) ? $answer : preg_split('~[\s,;|/+]+~', strtoupper(trim((string) $answer)), -1, PREG_SPLIT_NO_EMPTY);
        $arr = array_values(array_unique(array_map(fn($x) => strtoupper(trim((string) $x)), $arr)));
        sort($arr);
        return $arr;
    }

    public static function grade(array $keys, array $answers): array
    {
        $correct = 0;
        foreach ($keys as $i => $entry) {
            $actual = self::choiceSet(self::answerAt($answers, $i + 1));
            $expected = self::choiceSet($entry['answer']);
            if ($expected && $actual === $expected) $correct++;
        }
        return ['question_count' => count($keys), 'correct_count' => $correct,
            'score' => count($keys) ? round(100 * $correct / count($keys), 2) : 0];
    }

    /** Menolak hasil HP lain, NISN di luar rombel, dan versi kunci yang tidak cocok. */
    public function saveScan(string $id, array $input, ?UploadedFile $image = null, ?int $remoteTeacherId = null, ?string $remoteDeviceId = null): array
    {
        $session = $this->owned($id, $remoteTeacherId);
        $keys = json_decode((string) $session->keys_json, true) ?: [];
        $fingerprint = (string) ($input['manual_key_fingerprint'] ?? $session->key_fingerprint);
        if (!hash_equals((string) $session->key_fingerprint, $fingerprint)) throw new RuntimeException('Versi kunci PWA berbeda dari sesi laptop. Sinkronkan kunci terbaru.');
        $scanId = trim((string) ($input['scan_id'] ?? '')) ?: (string) Str::uuid();
        if (!Str::isUuid($scanId)) throw new RuntimeException('scan_id harus UUID valid.');
        $existing = DB::table('ljk_scan_results')->where('scan_id', $scanId)->first();
        if ($existing && ((string) $existing->exam_sync_id !== $id || (int) $existing->teaching_assignment_id !== (int) $session->teaching_assignment_id)) {
            throw new RuntimeException('ID pemindaian sudah dipakai oleh sesi lain.');
        }
        if ($existing && $remoteDeviceId && (string) ($existing->source_device_id ?? '') !== '' && (string) $existing->source_device_id !== $remoteDeviceId) {
            throw new RuntimeException('Pemindaian milik perangkat lain.');
        }
        if ($existing && $existing->published_at) {
            $incoming = $input['answers'] ?? [];
            if (is_string($incoming)) $incoming = json_decode($incoming, true) ?: [];
            if (!is_array($incoming) || $incoming != (json_decode((string) $existing->answers_json, true) ?: [])) {
                throw new RuntimeException('Nilai sudah masuk Buku Nilai; jawaban berbeda tidak boleh menimpa hasil terpublikasi.');
            }
            return $this->serializeResult($existing);
        }
        $answers = $input['answers'] ?? [];
        if (is_string($answers)) $answers = json_decode($answers, true);
        if (!is_array($answers)) throw new RuntimeException('Format jawaban tidak valid.');
        $nisn = preg_replace('/\D+/', '', (string) ($input['nisn'] ?? '')) ?: '';
        $student = strlen($nisn) === 10 ? DB::table('students')->where('nisn', $nisn)->first() : null;
        $isMember = $student && DB::table('enrollments')->where('student_id', $student->id)
            ->where('homeroom_id', $session->homeroom_id)->exists();
        $duplicate = $student && DB::table('ljk_scan_results')->where('exam_sync_id', $id)
            ->where('student_id', $student->id)->where('is_active', true)->where('scan_id', '<>', $scanId)->exists();
        $grade = self::grade($keys, $answers);
        $issue = null; $status = 'success';
        if (!$student) { $issue = 'NISN_NOT_FOUND'; $status = 'review'; }
        elseif (!$isMember) { $issue = 'STUDENT_OUTSIDE_ROSTER'; $status = 'review'; }
        elseif ($duplicate) { $issue = 'DUPLICATE'; $status = 'duplicate'; }
        elseif ((array_key_exists('marker_ok', $input) && !$input['marker_ok']) || (array_key_exists('scan_ok', $input) && !$input['scan_ok'])) { $issue = 'MARKER_OR_SCAN'; $status = 'review'; }
        elseif ((int) ($input['ambiguous_count'] ?? 0) > 0) { $issue = 'AMBIGUOUS_ANSWER'; $status = 'review'; }
        elseif (isset($input['confidence']) && (float) $input['confidence'] < 0.65) { $issue = 'LOW_CONFIDENCE'; $status = 'review'; }
        $path = $existing->image_path ?? null;
        if ($image) $path = $image->store('ljk/manual', 'local');
        $data = [
            'exam_id' => null, 'exam_sync_id' => $id,
            'teaching_assignment_id' => (int) $session->teaching_assignment_id,
            'student_id' => $student?->id, 'nisn' => $nisn, 'student_name' => (string) ($student->nama ?? ''),
            'template_version' => 'LJK-A4-V3', 'source_type' => $remoteDeviceId ? 'pwa' : (string) ($input['source_type'] ?? 'image'),
            'source_name' => (string) ($input['source_name'] ?? ''), 'image_path' => $path,
            'answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'question_count' => $grade['question_count'], 'correct_count' => $grade['correct_count'], 'score' => $grade['score'],
            'confidence' => isset($input['confidence']) ? (float) $input['confidence'] : null,
            'status' => $status, 'issue_code' => $issue, 'issue_message' => $issue,
            'is_active' => true, 'marker_ok' => null,
            'key_source' => 'manual', 'manual_keys_json' => (string) $session->keys_json,
            'source_device_id' => $remoteDeviceId, 'scanned_at' => now(), 'updated_at' => now(),
        ];
        unset($data['marker_ok']); // kolom marker_ok bukan bagian skema pemindaian lama.
        if ($existing) {
            DB::table('ljk_scan_results')->where('scan_id', $scanId)->update($data);
        } else {
            $data['scan_id'] = $scanId; $data['created_at'] = now();
            DB::table('ljk_scan_results')->insert($data);
        }
        return $this->resultById($scanId, $session->teacher_id);
    }

    private function resultById(string $scanId, int $teacherId): array
    {
        $row = DB::table('ljk_scan_results as r')->join('ljk_manual_sessions as m', 'm.session_id', '=', 'r.exam_sync_id')
            ->where('r.scan_id', $scanId)->where('m.teacher_id', $teacherId)
            ->first(['r.*', 'm.homeroom_id as session_homeroom_id']);
        if (!$row) throw new RuntimeException('Hasil pemindaian tidak ditemukan.');
        $classNames = $this->classNamesForStudents(
            $row->student_id ? [(int) $row->student_id] : [],
            (int) ($row->session_homeroom_id ?? 0)
        );
        return $this->serializeResult($row, (string) ($classNames[(int) ($row->student_id ?? 0)] ?? ''));
    }

    /** Nama kelas berasal dari rombel siswa; prioritaskan rombel sesi koreksi. */
    private function classNamesForStudents(array $studentIds, int $preferredHomeroomId): array
    {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        if (!$studentIds) return [];
        $rows = DB::table('enrollments as e')
            ->join('homerooms as h', 'h.id', '=', 'e.homeroom_id')
            ->whereIn('e.student_id', $studentIds)
            ->orderByRaw('CASE WHEN e.homeroom_id = ? THEN 0 ELSE 1 END', [$preferredHomeroomId])
            ->orderByDesc('e.id')
            ->get(['e.student_id', 'h.nama as class_name']);
        $names = [];
        foreach ($rows as $item) {
            $studentId = (int) $item->student_id;
            if (!array_key_exists($studentId, $names)) $names[$studentId] = (string) $item->class_name;
        }
        return $names;
    }

    private function serializeResult(object $row, string $className = ''): array
    {
        return [
            'scan_id' => $row->scan_id, 'exam_sync_id' => $row->exam_sync_id,
            'nisn' => $row->nisn, 'student_id' => $row->student_id, 'student_name' => $row->student_name,
            'class_name' => $className,
            'answers' => json_decode((string) $row->answers_json, true) ?: [],
            'question_count' => (int) $row->question_count, 'correct_count' => (int) $row->correct_count,
            'score' => (float) $row->score, 'status' => $row->status, 'issue_message' => $row->issue_message,
            'key_source' => 'manual', 'published' => (bool) $row->published_at, 'published_at' => $row->published_at,
            'has_image' => (bool) $row->image_path,
        ];
    }

    public function results(string $id): array
    {
        $session = $this->owned($id);
        $results = DB::table('ljk_scan_results')->where('exam_sync_id', $id)->where('is_active', true)
            ->orderByDesc('scanned_at')->get();
        $classNames = $this->classNamesForStudents(
            $results->pluck('student_id')->filter()->all(),
            (int) $session->homeroom_id
        );
        return $results->map(fn($r) => $this->serializeResult(
            $r, (string) ($classNames[(int) ($r->student_id ?? 0)] ?? '')
        ))->all();
    }

    public function imagePath(string $id, string $scanId): string
    {
        $session = $this->owned($id);
        $row = DB::table('ljk_scan_results')->where('scan_id', $scanId)
            ->where('exam_sync_id', $session->session_id)->first();
        if (!$row || !$row->image_path || !Storage::disk('local')->exists($row->image_path)) {
            throw new RuntimeException('Foto pemindaian tidak ditemukan.');
        }
        return Storage::disk('local')->path($row->image_path);
    }

    /** Review hasil sebelum masuk Buku Nilai; hasil yang sudah terbit tidak ditimpa. */
    public function review(string $id, string $scanId, array $payload): array
    {
        $session = $this->owned($id);
        $row = DB::table('ljk_scan_results')->where('scan_id', $scanId)
            ->where('exam_sync_id', $id)->where('is_active', true)->first();
        if (!$row) throw new RuntimeException('Hasil tidak ditemukan.');
        if ($row->published_at) throw new RuntimeException('Hasil sudah masuk Buku Nilai dan tidak boleh diubah melalui review ini.');
        return $this->saveScan($id, [
            'scan_id' => $scanId,
            'manual_key_fingerprint' => (string) $session->key_fingerprint,
            'nisn' => (string) ($payload['nisn'] ?? $row->nisn),
            'answers' => $payload['answers'] ?? (json_decode((string) $row->answers_json, true) ?: []),
            'marker_ok' => true,
            'scan_ok' => true,
            'confidence' => 1,
            'ambiguous_count' => 0,
            'source_type' => (string) $row->source_type,
            'source_name' => (string) $row->source_name,
        ]);
    }

    public function clearAll(string $id): array
    {
        $this->owned($id);
        $query = DB::table('ljk_scan_results')->where('exam_sync_id', $id)->where('is_active', true);
        $preserved = (clone $query)->whereNotNull('published_at')->count();
        $removed = $query->update(['is_active' => false, 'updated_at' => now()]);
        return ['archived' => $removed, 'published_preserved' => $preserved];
    }

    public function deleteScan(string $id, string $scanId, ?int $remoteTeacherId = null, ?string $remoteDeviceId = null): array
    {
        $session = $this->owned($id, $remoteTeacherId);
        $row = DB::table('ljk_scan_results')->where('exam_sync_id', $id)->where('scan_id', $scanId)->first();
        if (!$row) return ['deleted' => false];
        if ($remoteDeviceId && $row->source_device_id && (string) $row->source_device_id !== $remoteDeviceId) throw new RuntimeException('Sumber penghapusan hasil tidak cocok.');
        DB::table('ljk_scan_results')->where('id', $row->id)->update(['is_active' => false, 'updated_at' => now()]);
        return ['deleted' => true, 'published_preserved' => (bool) $row->published_at];
    }

    public function publish(string $id, array $scanIds = [], ?int $remoteTeacherId = null): array
    {
        $session = $this->owned($id, $remoteTeacherId);
        $base = DB::table('ljk_scan_results')->where('exam_sync_id', $id)->where('is_active', true)
            ->where('status', 'success')->whereNull('published_at');
        if ($scanIds) $base->whereIn('scan_id', $scanIds);
        $rows = $base->get();
        $summary = ['published' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($rows as $row) {
            try {
                $savedKeys = json_decode((string) $row->manual_keys_json, true);
                if (!$savedKeys || !hash_equals($session->key_fingerprint, self::fingerprint($savedKeys))) throw new RuntimeException('Snapshot kunci pada hasil tidak cocok.');
                $grade = self::grade($savedKeys, json_decode((string) $row->answers_json, true) ?: []);
                if ($grade['correct_count'] !== (int) $row->correct_count || abs($grade['score'] - (float) $row->score) > 0.011) throw new RuntimeException('Nilai hasil tidak konsisten dengan kunci.');
                if (!$row->student_id || !DB::table('enrollments')->where('student_id', $row->student_id)->where('homeroom_id', $session->homeroom_id)->exists()) throw new RuntimeException('Siswa tidak sesuai rombel.');
                $written = $this->writer->writeScore((int) $row->student_id, (int) $session->teaching_assignment_id,
                    (string) $session->tanggal, (string) $session->jenis_penilaian, $grade['score'],
                    'Ujian LJK Manual: ' . $session->title);
                if (!($written['saved'] ?? false)) throw new RuntimeException((string) ($written['reason'] ?? 'Nilai tidak tersimpan.'));
                DB::table('ljk_scan_results')->where('id', $row->id)->update(['published_at' => now(), 'updated_at' => now()]);
                $summary['published']++;
            } catch (\Throwable $error) {
                $summary['errors'][] = ($row->student_name ?: $row->nisn ?: $row->scan_id) . ': ' . $error->getMessage();
            }
        }
        $summary['skipped'] = max(0, count($scanIds) - $summary['published']);
        return $summary;
    }

    public function syncToPwa(string $id): array
    {
        $session = $this->owned($id);
        $keys = json_decode((string) $session->keys_json, true) ?: [];
        $pc = trim((string) $this->sync->getDeviceId());
        if (!preg_match('/-PC$/i', $pc)) throw new RuntimeException('Laptop guru belum memiliki identitas perangkat -PC yang aktif.');
        $target = preg_replace('/-PC$/i', '-HP', $pc);
        $questions = [];
        foreach ($keys as $i => $key) {
            $questions[] = [
                'question_bank_id' => $i + 1, 'number' => $i + 1,
                'type' => $key['type'], 'jenis_soal' => $key['type'], 'bobot_nilai' => 1,
                'kunci_jawaban' => $key['answer'],
            ];
        }
        $version = max((int) floor(microtime(true) * 1000), (int) $session->version);
        $payload = [
            'entity' => 'ljk_private_package', 'sync_id' => 'ljkpkg::' . $id,
            'exam_id' => null, 'exam_sync_id' => $id, 'manual_session_id' => $id,
            'key_source' => 'manual', 'manual_key_fingerprint' => (string) $session->key_fingerprint,
            'key_version' => (int) $session->version,
            'judul_ujian' => $session->title, 'title' => $session->title,
            'subject_id' => (int) $session->subject_id, 'homeroom_id' => (int) $session->homeroom_id,
            'teacher_id' => (int) $session->teacher_id,
            'teaching_assignment_id' => (int) $session->teaching_assignment_id,
            'jenis_penilaian' => $session->jenis_penilaian, 'tanggal' => $session->tanggal,
            'template_version' => 'LJK-A4-V3', 'questions' => $questions,
            'students' => $this->roster((int) $session->homeroom_id),
            'target_device_id' => $target, 'sync_version' => $version, 'updated_at' => now()->toIso8601String(),
        ];
        $queued = $this->sync->queueAndPush('lms_assignment_submission', 'ljkpkg::' . $id, 'update', $version, $payload, $target, null);
        return ['target_device_id' => $target, 'package_sync_id' => 'ljkpkg::' . $id, 'sync' => $queued];
    }
}
