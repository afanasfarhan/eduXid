<?php

namespace App\Http\Controllers;

use App\Services\AtpService;
use App\Services\Sync\LmsSyncService;
use App\Services\Sync\SchoolIdentity;
use App\Services\Sync\VersionService;
use App\Services\Sync\WorkProductPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AtpController extends Controller
{
    public function __construct(
        protected AtpService $atp,
        protected LmsSyncService $sync
    ) {
    }

    public function index(Request $request): View
    {
        $teacher = $this->teacher();
        $subjects = $this->atp->teacherSubjects((int) $teacher->id);

        $subjectId = (int) $request->query('subject', 0);

        if ($subjectId <= 0 && $subjects->isNotEmpty()) {
            $subjectId = (int) $subjects->first()->id;
        }

        if ($subjectId > 0) {
            $this->assertSubjectOwned((int) $teacher->id, $subjectId);
        }

        $grades = $subjectId > 0
            ? $this->atp->teacherGradesForSubject((int) $teacher->id, $subjectId)
            : collect();

        $gradeLevel = (int) $request->query('grade', 0);

        if ($gradeLevel <= 0 && $grades->isNotEmpty()) {
            $gradeLevel = (int) $grades->first();
        }

        if ($subjectId > 0 && $gradeLevel > 0) {
            $this->assertSubjectGradeOwned((int) $teacher->id, $subjectId, $gradeLevel);
        }

        $items = ($subjectId > 0 && $gradeLevel > 0)
            ? $this->atp->items((int) $teacher->id, $subjectId, $gradeLevel)
            : collect();

        $selectedSubject = $subjects->firstWhere('id', $subjectId);

        $legacyCount = 0;

        if ($subjectId > 0) {
            $legacyCount = DB::table('atp_items')
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $subjectId)
                ->whereNull('grade_level')
                ->count();
        }

        return view('guru.atp.index', compact(
            'subjects',
            'subjectId',
            'selectedSubject',
            'grades',
            'gradeLevel',
            'items',
            'legacyCount'
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $teacher = $this->teacher();

        $validated = $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'grade_level' => 'required|integer|min:1|max:13',
            'materi' => 'required|string|max:255',
            'tujuan_pembelajaran' => 'required|string|max:3000',
            'indikator_penilaian' => 'required|string|max:3000',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);

        $subjectId = (int) $validated['subject_id'];
        $gradeLevel = (int) $validated['grade_level'];

        $this->assertSubjectGradeOwned(
            (int) $teacher->id,
            $subjectId,
            $gradeLevel
        );

        $version = VersionService::next();
        $syncId = (string) Str::uuid();

        $id = DB::table('atp_items')->insertGetId([
            'sync_id' => $syncId,
            'teacher_id' => $teacher->id,
            'subject_id' => $subjectId,
            'grade_level' => $gradeLevel,
            'semester_id' => $this->atp->semesterId(),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'materi' => trim($validated['materi']),
            'tujuan_pembelajaran' => trim($validated['tujuan_pembelajaran']),
            'indikator_penilaian' => trim($validated['indikator_penilaian']),
            'is_active' => true,
            'sync_version' => $version,
            'sync_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = DB::table('atp_items')->where('id', $id)->first();
        $this->syncAtpToPwa($item, 'upsert');

        return redirect()
            ->route('guru.atp.index', [
                'subject' => $subjectId,
                'grade' => $gradeLevel,
            ])
            ->with('success', 'ATP berhasil ditambahkan dan masuk antrean sinkron PWA Guru.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $teacher = $this->teacher();
        $item = $this->ownedItem((int) $teacher->id, $id);

        $validated = $request->validate([
            'grade_level' => 'required|integer|min:1|max:13',
            'materi' => 'required|string|max:255',
            'tujuan_pembelajaran' => 'required|string|max:3000',
            'indikator_penilaian' => 'required|string|max:3000',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);

        $gradeLevel = (int) $validated['grade_level'];

        $this->assertSubjectGradeOwned(
            (int) $teacher->id,
            (int) $item->subject_id,
            $gradeLevel
        );

        $version = VersionService::above((int) ($item->sync_version ?? 0));

        DB::table('atp_items')
            ->where('id', $id)
            ->where('teacher_id', $teacher->id)
            ->update([
                'grade_level' => $gradeLevel,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
                'materi' => trim($validated['materi']),
                'tujuan_pembelajaran' => trim($validated['tujuan_pembelajaran']),
                'indikator_penilaian' => trim($validated['indikator_penilaian']),
                'sync_version' => $version,
                'sync_status' => 'pending',
                'updated_at' => now(),
            ]);

        $fresh = DB::table('atp_items')->where('id', $id)->first();
        $this->syncAtpToPwa($fresh, 'upsert');

        return redirect()
            ->route('guru.atp.index', [
                'subject' => $item->subject_id,
                'grade' => $gradeLevel,
            ])
            ->with('success', 'ATP berhasil diperbarui dan disinkronkan.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $teacher = $this->teacher();
        $item = $this->ownedItem((int) $teacher->id, $id);

        $version = VersionService::above((int) ($item->sync_version ?? 0));

        DB::table('atp_items')
            ->where('id', $id)
            ->where('teacher_id', $teacher->id)
            ->delete();

        $item->sync_version = $version;
        $this->syncAtpToPwa($item, 'delete');

        return redirect()
            ->route('guru.atp.index', [
                'subject' => $item->subject_id,
                'grade' => $item->grade_level,
            ])
            ->with('success', 'ATP berhasil dihapus dan penghapusan disinkronkan.');
    }

    /**
     * Hapus seluruh ATP yang sedang tampil pada mapel/tingkat kelas guru ini.
     * Semester mengikuti filter AtpService::items(), dan data lain tidak disentuh.
     */
    public function destroyAll(Request $request): RedirectResponse
    {
        $teacher = $this->teacher();

        $validated = $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'grade_level' => 'required|integer|min:1|max:13',
            'confirm_delete_all' => 'required|accepted',
        ]);

        $subjectId = (int) $validated['subject_id'];
        $gradeLevel = (int) $validated['grade_level'];

        // Cegah penghapusan ATP milik guru, mapel, atau tingkat kelas lain.
        $this->assertSubjectGradeOwned((int) $teacher->id, $subjectId, $gradeLevel);

        $deletedItems = DB::transaction(function () use ($teacher, $subjectId, $gradeLevel) {
            $query = DB::table('atp_items')
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $subjectId)
                ->where('grade_level', $gradeLevel)
                ->where('is_active', true);

            // Sama persis dengan batas semester pada daftar ATP yang ditampilkan.
            $semester = $this->atp->semesterId();
            if ($semester !== null) {
                $query->where(function ($q) use ($semester) {
                    $q->whereNull('semester_id')
                        ->orWhere('semester_id', '')
                        ->orWhere('semester_id', $semester);
                });
            }

            $items = $query->orderBy('id')->get();

            // Bagi ID menjadi kelompok kecil agar aman pada batas parameter SQLite.
            foreach ($items->chunk(300) as $chunk) {
                DB::table('atp_items')
                    ->where('teacher_id', $teacher->id)
                    ->where('subject_id', $subjectId)
                    ->where('grade_level', $gradeLevel)
                    ->whereIn('id', $chunk->pluck('id')->all())
                    ->delete();
            }

            return $items;
        });

        // Ikuti mekanisme delete ATP satu per satu agar PWA Guru menerima tombstone.
        // Dijalankan setelah commit supaya proses Cloud tidak menahan transaksi SQLite.
        foreach ($deletedItems as $item) {
            $item->sync_version = VersionService::above((int) ($item->sync_version ?? 0));
            $this->syncAtpToPwa($item, 'delete');
        }

        return redirect()
            ->route('guru.atp.index', [
                'subject' => $subjectId,
                'grade' => $gradeLevel,
            ])
            ->with('success', $deletedItems->count() > 0
                ? $deletedItems->count() . ' ATP berhasil dihapus dan penghapusan masuk proses sinkronisasi PWA Guru.'
                : 'Tidak ada ATP untuk dihapus pada mata pelajaran dan kelas ini.');
    }

    public function options(Request $request): JsonResponse
    {
        $teacher = $this->teacher();
        $subjectId = (int) $request->query('subject_id');
        $gradeLevel = (int) $request->query('grade_level', 0);
        $homeroomId = (int) $request->query('homeroom_id', 0);

        $this->assertSubjectOwned((int) $teacher->id, $subjectId);

        if ($gradeLevel <= 0 && $homeroomId > 0) {
            $gradeLevel = $this->gradeFromHomeroom($homeroomId) ?? 0;
        }

        if ($gradeLevel > 0) {
            $this->assertSubjectGradeOwned(
                (int) $teacher->id,
                $subjectId,
                $gradeLevel
            );
        }

        return response()->json([
            'items' => $this->atp->items(
                (int) $teacher->id,
                $subjectId,
                $gradeLevel > 0 ? $gradeLevel : null
            )->map(fn ($item) => $this->option($item))->values(),
        ]);
    }

    public function optionsByAssignment(int $assignment): JsonResponse
    {
        $teacher = $this->teacher();

        $ta = DB::table('teaching_assignments')
            ->where('id', $assignment)
            ->where('teacher_id', $teacher->id)
            ->first();

        abort_unless($ta, 403, 'Penugasan mengajar tidak ditemukan.');

        $gradeLevel = $this->atp->assignmentGradeLevel($assignment);

        return response()->json([
            'grade_level' => $gradeLevel,
            'items' => $this->atp->items(
                (int) $teacher->id,
                (int) $ta->subject_id,
                $gradeLevel
            )->map(fn ($item) => $this->option($item))->values(),
        ]);
    }

    public function generateDescriptions(Request $request, int $assignment): RedirectResponse
    {
        $teacher = $this->teacher();
        $ta = $this->ownedAssignment((int) $teacher->id, $assignment);

        $studentIds = DB::table('enrollments')
            ->where('homeroom_id', $ta->homeroom_id)
            ->pluck('student_id');

        $generated = 0;

        DB::transaction(function () use ($assignment, $studentIds, &$generated) {
            foreach ($studentIds as $studentId) {
                $description = $this->atp->generateDescription(
                    $assignment,
                    (int) $studentId
                );

                $this->saveDescription(
                    $assignment,
                    (int) $studentId,
                    $description
                );

                $generated++;
            }
        });

        $this->syncFinalGrades((int) $ta->teacher_id, $assignment);

        return back()->with(
            'success',
            "Deskripsi capaian kompetensi berbasis ATP berhasil dibuat untuk {$generated} murid."
        );
    }

    public function autoDescription(
        Request $request,
        int $assignment,
        int $student
    ): JsonResponse|RedirectResponse {
        $teacher = $this->teacher();
        $ta = $this->ownedAssignment((int) $teacher->id, $assignment);

        $this->assertStudentInHomeroom((int) $ta->homeroom_id, $student);

        $description = $this->atp->generateDescription(
            $assignment,
            $student
        );

        $this->saveDescription($assignment, $student, $description);
        $this->syncFinalGrades((int) $ta->teacher_id, $assignment);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'description' => $description,
            ]);
        }

        return back()->with('success', 'Deskripsi otomatis murid berhasil dibuat.');
    }

    public function deleteDescription(
        Request $request,
        int $assignment,
        int $student
    ): JsonResponse|RedirectResponse {
        $teacher = $this->teacher();
        $ta = $this->ownedAssignment((int) $teacher->id, $assignment);

        $this->assertStudentInHomeroom((int) $ta->homeroom_id, $student);
        $this->saveDescription($assignment, $student, null);
        $this->syncFinalGrades((int) $ta->teacher_id, $assignment);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'description' => null,
            ]);
        }

        return back()->with('success', 'Deskripsi capaian kompetensi murid berhasil dihapus.');
    }

    public function download(Request $request): BinaryFileResponse
    {
        $teacher = $this->teacher();

        $subjectId = (int) $request->query('subject', 0);
        $gradeLevel = (int) $request->query('grade', 0);

        $this->assertSubjectGradeOwned(
            (int) $teacher->id,
            $subjectId,
            $gradeLevel
        );

        $subjectName = (string) DB::table('subjects')
            ->where('id', $subjectId)
            ->value('nama_mata_pelajaran');

        $items = $this->atp->items(
            (int) $teacher->id,
            $subjectId,
            $gradeLevel
        );

        $template = resource_path('atp-template.xlsx');

        $spreadsheet = is_file($template)
            ? IOFactory::load($template)
            : new Spreadsheet();

        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('ATP');

        $headers = [
            'Tingkat Kelas',
            'Urutan',
            'Materi',
            'Tujuan Pembelajaran',
            'Indikator Penilaian',
        ];

        for ($col = 1; $col <= count($headers); $col++) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', $headers[$col - 1]);
        }

        // Bersihkan data template/contoh sebelum menulis data riil.
        $last = max(500, $sheet->getHighestRow());
        for ($row = 2; $row <= $last; $row++) {
            for ($col = 1; $col <= 5; $col++) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $row, null);
            }
        }

        if ($items->isEmpty()) {
            // File tetap dapat dipakai sebagai template upload.
            $sheet->setCellValueExplicit('A2', (string) $gradeLevel, DataType::TYPE_NUMERIC);
        } else {
            foreach ($items->values() as $index => $item) {
                $row = $index + 2;

                $sheet->setCellValue("A{$row}", (int) $item->grade_level);
                $sheet->setCellValue("B{$row}", (int) $item->sort_order);
                $sheet->setCellValueExplicit("C{$row}", (string) $item->materi, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("D{$row}", (string) $item->tujuan_pembelajaran, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("E{$row}", (string) $item->indikator_penilaian, DataType::TYPE_STRING);
            }
        }

        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['RGB' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['RGB' => '4F46E5'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['RGB' => 'D1D5DB'],
                ],
            ],
        ]);

        $endRow = max(2, $items->count() + 1);

        $sheet->getStyle("A1:E{$endRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(30);
        $sheet->getColumnDimension('D')->setWidth(48);
        $sheet->getColumnDimension('E')->setWidth(48);
        $sheet->freezePane('A2');

        $safeSubject = preg_replace('/[^A-Za-z0-9_\-]+/u', '-', trim($subjectName)) ?: 'Mapel';
        $filename = 'ATP-' . trim($safeSubject, '-') . '-Kelas-' . $gradeLevel . '.xlsx';

        $tmp = storage_path('app/' . uniqid('atp-export-', true) . '.xlsx');
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();

        return response()
            ->download($tmp, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    public function upload(Request $request): RedirectResponse
    {
        $teacher = $this->teacher();

        $validated = $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'grade_level' => 'required|integer|min:1|max:13',
            'atp_file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $subjectId = (int) $validated['subject_id'];
        $gradeLevel = (int) $validated['grade_level'];

        $this->assertSubjectGradeOwned(
            (int) $teacher->id,
            $subjectId,
            $gradeLevel
        );

        try {
            $spreadsheet = IOFactory::load(
                $request->file('atp_file')->getRealPath()
            );
        } catch (Throwable $e) {
            return back()
                ->withInput()
                ->with('error', 'File Excel ATP tidak dapat dibaca: ' . $e->getMessage());
        }

        $sheet = $spreadsheet->getSheet(0);

        $expected = [
            'Tingkat Kelas',
            'Urutan',
            'Materi',
            'Tujuan Pembelajaran',
            'Indikator Penilaian',
        ];

        $headers = [];

        for ($col = 1; $col <= 5; $col++) {
            $headers[] = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($col) . '1')->getValue());
        }

        if ($headers !== $expected) {
            $spreadsheet->disconnectWorksheets();

            return back()->with(
                'error',
                'Format Excel ATP tidak sesuai. Header wajib: '
                . implode(' | ', $expected)
                . '. Gunakan tombol Download ATP sebagai template.'
            );
        }

        $rows = [];
        $errors = [];

        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $rawGrade = trim((string) $sheet->getCell("A{$row}")->getFormattedValue());
            $rawOrder = trim((string) $sheet->getCell("B{$row}")->getFormattedValue());
            $materi = trim((string) $sheet->getCell("C{$row}")->getValue());
            $tp = trim((string) $sheet->getCell("D{$row}")->getValue());
            $indikator = trim((string) $sheet->getCell("E{$row}")->getValue());

            // Baris kosong diabaikan.
            if (
                $rawGrade === ''
                && $rawOrder === ''
                && $materi === ''
                && $tp === ''
                && $indikator === ''
            ) {
                continue;
            }

            $rowGrade = $rawGrade === '' ? $gradeLevel : (int) $rawGrade;
            $sortOrder = $rawOrder === '' ? ($row - 1) : (int) $rawOrder;

            if ($rowGrade !== $gradeLevel) {
                $errors[] = "Baris {$row}: Tingkat Kelas harus {$gradeLevel}.";
                continue;
            }

            if ($sortOrder < 0 || $sortOrder > 9999) {
                $errors[] = "Baris {$row}: Urutan tidak valid.";
                continue;
            }

            if ($materi === '' || $tp === '' || $indikator === '') {
                $errors[] = "Baris {$row}: Materi, Tujuan Pembelajaran, dan Indikator Penilaian wajib diisi.";
                continue;
            }

            $rows[] = [
                'excel_row' => $row,
                'grade_level' => $rowGrade,
                'sort_order' => $sortOrder,
                'materi' => $materi,
                'tujuan_pembelajaran' => $tp,
                'indikator_penilaian' => $indikator,
            ];
        }

        $spreadsheet->disconnectWorksheets();

        if ($errors !== []) {
            return back()->with(
                'error',
                'Upload ATP dibatalkan. ' . implode(' ', array_slice($errors, 0, 10))
            );
        }

        if ($rows === []) {
            return back()->with('error', 'File Excel tidak memiliki baris ATP untuk diimpor.');
        }

        $inserted = 0;
        $updated = 0;
        $changedItems = [];

        DB::transaction(function () use (
            $teacher,
            $subjectId,
            $gradeLevel,
            $rows,
            &$inserted,
            &$updated,
            &$changedItems
        ) {
            foreach ($rows as $row) {
                /*
                 * Workbook hasil Download ATP harus bisa diedit lalu di-upload ulang.
                 * Karena format Excel sengaja tidak membawa ID internal, Urutan menjadi
                 * identitas utama dalam scope guru + mapel + tingkat. Jika Urutan tidak
                 * menemukan data lama, fallback Materi + Tujuan Pembelajaran digunakan.
                 */
                $base = DB::table('atp_items')
                    ->where('teacher_id', $teacher->id)
                    ->where('subject_id', $subjectId)
                    ->where('grade_level', $gradeLevel);

                $existing = null;

                if ($row['sort_order'] > 0) {
                    $existing = (clone $base)
                        ->where('sort_order', $row['sort_order'])
                        ->first();
                }

                if (!$existing) {
                    $existing = (clone $base)
                        ->where('materi', $row['materi'])
                        ->where('tujuan_pembelajaran', $row['tujuan_pembelajaran'])
                        ->first();
                }

                if ($existing) {
                    $version = VersionService::above(
                        (int) ($existing->sync_version ?? 0)
                    );

                    DB::table('atp_items')
                        ->where('id', $existing->id)
                        ->update([
                            'sort_order' => $row['sort_order'],
                            'materi' => $row['materi'],
                            'tujuan_pembelajaran' => $row['tujuan_pembelajaran'],
                            'indikator_penilaian' => $row['indikator_penilaian'],
                            'is_active' => true,
                            'sync_version' => $version,
                            'sync_status' => 'pending',
                            'updated_at' => now(),
                        ]);

                    $changedItems[] = DB::table('atp_items')
                        ->where('id', $existing->id)
                        ->first();

                    $updated++;
                    continue;
                }

                $version = VersionService::next();

                $id = DB::table('atp_items')->insertGetId([
                    'sync_id' => (string) Str::uuid(),
                    'teacher_id' => $teacher->id,
                    'subject_id' => $subjectId,
                    'grade_level' => $gradeLevel,
                    'semester_id' => $this->atp->semesterId(),
                    'sort_order' => $row['sort_order'],
                    'materi' => $row['materi'],
                    'tujuan_pembelajaran' => $row['tujuan_pembelajaran'],
                    'indikator_penilaian' => $row['indikator_penilaian'],
                    'is_active' => true,
                    'sync_version' => $version,
                    'sync_status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $changedItems[] = DB::table('atp_items')
                    ->where('id', $id)
                    ->first();

                $inserted++;
            }
        });

        foreach ($changedItems as $item) {
            $this->syncAtpToPwa($item, 'upsert');
        }

        return redirect()
            ->route('guru.atp.index', [
                'subject' => $subjectId,
                'grade' => $gradeLevel,
            ])
            ->with(
                'success',
                "Upload ATP selesai: {$inserted} ditambahkan, {$updated} diperbarui."
            );
    }

    private function saveDescription(
        int $assignmentId,
        int $studentId,
        ?string $description
    ): void {
        $existing = DB::table('final_grades')
            ->where('student_id', $studentId)
            ->where('teaching_assignment_id', $assignmentId)
            ->first();

        if ($existing) {
            DB::table('final_grades')
                ->where('id', $existing->id)
                ->update([
                    'deskripsi' => $description,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('final_grades')->insert([
            'student_id' => $studentId,
            'teaching_assignment_id' => $assignmentId,
            'nilai_akhir' => null,
            'deskripsi' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function syncFinalGrades(int $teacherId, int $assignmentId): void
    {
        WorkProductPushService::markDirty($teacherId);

        try {
            $this->sync->pushFinalGradeBatchForAssignment($assignmentId);
        } catch (Throwable $e) {
            Log::warning('[ATP] Nilai akhir tersimpan lokal tetapi push final_grade tertunda.', [
                'teaching_assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncAtpToPwa(?object $item, string $action): void
    {
        if (!$item) {
            return;
        }

        try {
            $user = auth()->user();
            $loginId = trim((string) ($user->login_id ?? ''));

            if ($loginId === '') {
                return;
            }

            $targetDevice = app(SchoolIdentity::class)
                ->deviceId($loginId, 'HP');

            $syncId = trim((string) ($item->sync_id ?? ''));

            if ($syncId === '') {
                return;
            }

            $version = max(
                1,
                (int) ($item->sync_version ?? VersionService::next())
            );

            $payload = [
                'sync_id' => $syncId,
                'entity' => 'atp_item',
                'id' => (int) ($item->id ?? 0),
                'teacher_id' => (int) $item->teacher_id,
                'subject_id' => (int) $item->subject_id,
                'grade_level' => $item->grade_level !== null
                    ? (int) $item->grade_level
                    : null,
                'semester_id' => $item->semester_id ?? null,
                'sort_order' => (int) ($item->sort_order ?? 0),
                'materi' => $item->materi ?? null,
                'tujuan_pembelajaran' => $item->tujuan_pembelajaran ?? null,
                'indikator_penilaian' => $item->indikator_penilaian ?? null,
                'is_active' => (bool) ($item->is_active ?? true),
                'sync_version' => $version,
                'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
            ];

            if ($action === 'delete') {
                $payload['deleted'] = true;
            }

            $result = $this->sync->queueAndPush(
                'atp_item',
                $syncId,
                $action,
                $version,
                $payload,
                $targetDevice,
                null
            );

            if (($result['success'] ?? false) === true) {
                DB::table('atp_items')
                    ->where('sync_id', $syncId)
                    ->update([
                        'sync_status' => 'synced',
                        'last_synced_at' => now(),
                    ]);
            }
        } catch (Throwable $e) {
            Log::warning('[ATP] Push ATP ke PWA Guru tertunda.', [
                'sync_id' => $item->sync_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function teacher(): object
    {
        $user = auth()->user();
        abort_unless($user, 401);

        $teacher = $user->teacher();
        abort_unless($teacher, 403, 'Data guru tidak ditemukan untuk akun ini.');

        return $teacher;
    }

    private function assertSubjectOwned(int $teacherId, int $subjectId): void
    {
        abort_unless($subjectId > 0, 422, 'Mata pelajaran wajib dipilih.');

        $exists = DB::table('teaching_assignments')
            ->where('teacher_id', $teacherId)
            ->where('subject_id', $subjectId)
            ->exists();

        abort_unless(
            $exists,
            403,
            'Mata pelajaran ini bukan bagian dari penugasan mengajar Anda.'
        );
    }

    private function assertSubjectGradeOwned(
        int $teacherId,
        int $subjectId,
        int $gradeLevel
    ): void {
        $this->assertSubjectOwned($teacherId, $subjectId);

        $grades = $this->atp
            ->teacherGradesForSubject($teacherId, $subjectId)
            ->map(fn ($v) => (int) $v);

        abort_unless(
            $grades->contains($gradeLevel),
            403,
            'Tingkat kelas ini bukan bagian dari penugasan mata pelajaran Anda.'
        );
    }

    private function ownedAssignment(int $teacherId, int $assignmentId): object
    {
        $ta = DB::table('teaching_assignments')
            ->where('id', $assignmentId)
            ->where('teacher_id', $teacherId)
            ->first();

        abort_unless($ta, 403, 'Penugasan mengajar tidak ditemukan.');

        return $ta;
    }

    private function assertStudentInHomeroom(
        int $homeroomId,
        int $studentId
    ): void {
        $exists = DB::table('enrollments')
            ->where('homeroom_id', $homeroomId)
            ->where('student_id', $studentId)
            ->exists();

        abort_unless($exists, 403, 'Murid bukan anggota rombel penugasan ini.');
    }

    private function ownedItem(int $teacherId, int $id): object
    {
        $item = DB::table('atp_items')
            ->where('id', $id)
            ->where('teacher_id', $teacherId)
            ->first();

        abort_unless($item, 404, 'ATP tidak ditemukan.');

        return $item;
    }

    private function gradeFromHomeroom(int $homeroomId): ?int
    {
        $value = DB::table('homerooms')
            ->where('id', $homeroomId)
            ->value('tingkat_pendidikan');

        return $this->atp->normalizeGradeLevel($value);
    }

    private function option(object $item): array
    {
        return [
            'id' => (int) $item->id,
            'sync_id' => (string) ($item->sync_id ?? ''),
            'grade_level' => $item->grade_level !== null
                ? (int) $item->grade_level
                : null,
            'materi' => (string) $item->materi,
            'tujuan_pembelajaran' => (string) $item->tujuan_pembelajaran,
            'indikator_penilaian' => (string) $item->indikator_penilaian,
            'sort_order' => (int) $item->sort_order,
        ];
    }
}
