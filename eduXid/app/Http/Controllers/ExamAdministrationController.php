<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ExamAdministrationController extends Controller
{
    public function index(Request $request): View
    {
        $programs = DB::table('exam_administrations')
            ->orderByDesc('id')
            ->get();

        $selectedProgramId = (int) $request->integer('program');
        if ($selectedProgramId <= 0 && $programs->isNotEmpty()) {
            $selectedProgramId = (int) $programs->first()->id;
        }

        $program = $selectedProgramId > 0
            ? DB::table('exam_administrations')->where('id', $selectedProgramId)->first()
            : null;

        $currentSemesterId = $this->resolveCurrentSemesterId();
        $currentHomerooms = $currentSemesterId
            ? $this->regularHomerooms($currentSemesterId)
            : collect();

        $schedules = collect();
        $rooms = collect();
        $participants = collect();
        $students = collect();
        $invigilatorAssignments = collect();
        $teacherOptions = $this->teacherOptions();

        if ($program) {
            $schedules = DB::table('exam_administration_schedules')
                ->where('exam_administration_id', $program->id)
                ->orderBy('exam_date')
                ->orderBy('start_time')
                ->orderBy('id')
                ->get();

            $rooms = DB::table('exam_administration_rooms')
                ->where('exam_administration_id', $program->id)
                ->orderBy('room_name')
                ->get()
                ->map(function ($room) {
                    $room->capacity_live = $this->capacityForRoom($room);
                    $room->assigned_count = DB::table('exam_administration_participants')
                        ->where('exam_administration_room_id', $room->id)
                        ->count();
                    $room->remaining = max(0, $room->capacity_live - $room->assigned_count);
                    $room->over_capacity = $room->assigned_count > $room->capacity_live;
                    $room->seat_columns = max(4, min(10, (int) ($room->seat_columns ?? 6)));
                    return $room;
                });

            $participants = $this->participantsWithRoom((int) $program->id);
            $students = $this->eligibleStudents($program);

            if (Schema::hasTable('exam_administration_invigilators')) {
                $invigilatorAssignments = DB::table('exam_administration_invigilators as ei')
                    ->join('exam_administration_schedules as es', 'es.id', '=', 'ei.exam_administration_schedule_id')
                    ->join('exam_administration_rooms as er', 'er.id', '=', 'ei.exam_administration_room_id')
                    ->where('ei.exam_administration_id', $program->id)
                    ->select(
                        'ei.*',
                        'es.title as schedule_title',
                        'es.exam_date',
                        'er.room_name'
                    )
                    ->orderBy('es.exam_date')
                    ->orderBy('es.start_time')
                    ->orderBy('er.room_name')
                    ->orderBy('ei.supervisor_no')
                    ->get();
            }
        }

        $examPrintAssets = $this->printContext();

        return view('tu.ujian.index', compact(
            'programs',
            'program',
            'schedules',
            'rooms',
            'participants',
            'students',
            'invigilatorAssignments',
            'teacherOptions',
            'currentSemesterId',
            'currentHomerooms',
            'examPrintAssets'
        ));
    }

    /** Exam administration shares local stationery with the Student ID card. */
    public function uploadStationery(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:kop,tanda_tangan'],
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);
        $kop = $data['kind'] === 'kop';
        $filename = ($kop ? 'kop_ujian_' : 'ttd_kepsek_ujian_')
            . now()->format('Ymd_His') . '_' . Str::lower(Str::random(8))
            . '.' . strtolower($request->file('image')->extension());
        $path = app(\App\Services\LocalMediaService::class)->storeUploadedAs(
            $request->file('image'), $kop ? 'kop_surat' : 'tanda_tangan', $filename
        );
        DB::table('app_settings')->updateOrInsert(
            ['key' => $kop ? 'kop_surat' : 'tanda_tangan_kartu'],
            ['value' => $path, 'updated_at' => now(), 'created_at' => now()]
        );
        return back()->with('success', $kop
            ? 'Kop sekolah tersimpan dan berlaku untuk kartu ujian, daftar hadir, dan denah.'
            : 'Scan tanda tangan tersimpan dan berlaku untuk seluruh cetakan administrasi ujian.');
    }

    public function storeProgram(Request $request): RedirectResponse
    {
        $semesterId = $this->resolveCurrentSemesterId();

        if (!$semesterId) {
            return back()->with('error', 'Semester/rombel reguler belum tersedia. Pilih semester yang memiliki rombel terlebih dahulu.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'academic_year' => ['nullable', 'string', 'max:50'],
            'semester' => ['nullable', 'string', 'max:50'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['required', 'in:draft,active,closed'],
        ]);

        $id = DB::table('exam_administrations')->insertGetId([
            'name' => trim($data['name']),
            'academic_year' => $data['academic_year'] ? trim($data['academic_year']) : null,
            'semester' => $data['semester'] ? trim($data['semester']) : null,
            'semester_id' => $semesterId,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'status' => $data['status'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->syncRoomsInternal($id, $semesterId);

        return redirect()
            ->route('tu.ujian.index', ['program' => $id])
            ->with('success', 'Program ujian dibuat. Ruang ujian telah disiapkan dari rombel reguler semester aktif.');
    }

    public function updateProgram(Request $request, int $program): RedirectResponse
    {
        $this->programOrFail($program);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'academic_year' => ['nullable', 'string', 'max:50'],
            'semester' => ['nullable', 'string', 'max:50'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['required', 'in:draft,active,closed'],
        ]);

        DB::table('exam_administrations')->where('id', $program)->update([
            'name' => trim($data['name']),
            'academic_year' => $data['academic_year'] ? trim($data['academic_year']) : null,
            'semester' => $data['semester'] ? trim($data['semester']) : null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'status' => $data['status'],
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('tu.ujian.index', ['program' => $program])
            ->with('success', 'Program ujian diperbarui.');
    }

    public function destroyProgram(int $program): RedirectResponse
    {
        $this->programOrFail($program);

        DB::transaction(function () use ($program) {
            DB::table('exam_administration_participants')
                ->where('exam_administration_id', $program)
                ->delete();
            DB::table('exam_administration_schedules')
                ->where('exam_administration_id', $program)
                ->delete();
            DB::table('exam_administration_rooms')
                ->where('exam_administration_id', $program)
                ->delete();
            DB::table('exam_administrations')
                ->where('id', $program)
                ->delete();
        });

        return redirect()
            ->route('tu.ujian.index')
            ->with('success', 'Program ujian dihapus.');
    }

    public function syncRooms(int $program): RedirectResponse
    {
        $programRow = $this->programOrFail($program);
        $semesterId = (int) ($programRow->semester_id ?: $this->resolveCurrentSemesterId());

        if (!$semesterId) {
            return back()->with('error', 'Semester untuk program ujian tidak ditemukan.');
        }

        $result = $this->syncRoomsInternal($program, $semesterId);

        return redirect()
            ->route('tu.ujian.index', ['program' => $program])
            ->with(
                'success',
                "Ruang diperbarui dari rombel: {$result['upserted']} aktif, {$result['removed']} ruang lama dihapus. Ruang yang masih berisi peserta tidak dihapus."
            );
    }

    public function storeSchedule(Request $request, int $program): RedirectResponse
    {
        $this->programOrFail($program);
        $data = $this->validateSchedule($request);

        DB::table('exam_administration_schedules')->insert([
            'exam_administration_id' => $program,
            'title' => trim($data['title']),
            'exam_date' => $data['exam_date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'] ?? null,
            'mode' => $data['mode'],
            'notes' => $data['notes'] ? trim($data['notes']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('tu.ujian.index', ['program' => $program])
            ->with('success', 'Jadwal ujian ditambahkan.');
    }

    public function updateSchedule(Request $request, int $schedule): RedirectResponse
    {
        $row = DB::table('exam_administration_schedules')->where('id', $schedule)->first();
        abort_unless($row, 404);

        $data = $this->validateSchedule($request);

        DB::table('exam_administration_schedules')->where('id', $schedule)->update([
            'title' => trim($data['title']),
            'exam_date' => $data['exam_date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'] ?? null,
            'mode' => $data['mode'],
            'notes' => $data['notes'] ? trim($data['notes']) : null,
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('tu.ujian.index', ['program' => $row->exam_administration_id])
            ->with('success', 'Jadwal ujian diperbarui.');
    }

    public function destroySchedule(int $schedule): RedirectResponse
    {
        $row = DB::table('exam_administration_schedules')->where('id', $schedule)->first();
        abort_unless($row, 404);

        DB::table('exam_administration_schedules')->where('id', $schedule)->delete();

        return redirect()
            ->route('tu.ujian.index', ['program' => $row->exam_administration_id])
            ->with('success', 'Jadwal ujian dihapus.');
    }

    public function assignParticipants(Request $request, int $program): RedirectResponse
    {
        $programRow = $this->programOrFail($program);

        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer'],
        ]);

        $room = DB::table('exam_administration_rooms')
            ->where('id', $data['room_id'])
            ->where('exam_administration_id', $program)
            ->first();
        abort_unless($room, 404);

        $studentIds = array_values(array_unique(array_map('intval', $data['student_ids'])));
        $validStudents = $this->eligibleStudentQuery($programRow)
            ->whereIn('students.id', $studentIds)
            ->pluck('students.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        sort($studentIds);
        sort($validStudents);

        if ($studentIds !== $validStudents) {
            return back()->with(
                'error',
                'Sebagian peserta tidak terdaftar dalam database murid sekolah ini.'
            );
        }

        $alreadyInTarget = DB::table('exam_administration_participants')
            ->where('exam_administration_id', $program)
            ->where('exam_administration_room_id', $room->id)
            ->whereIn('student_id', $studentIds)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $currentCount = DB::table('exam_administration_participants')
            ->where('exam_administration_room_id', $room->id)
            ->count();
        $capacity = $this->capacityForRoom($room);
        $additional = count(array_diff($studentIds, $alreadyInTarget));

        if (($currentCount + $additional) > $capacity) {
            return back()->with(
                'error',
                "Kapasitas ruang {$room->room_name} tidak cukup. Kapasitas {$capacity} kursi, terisi {$currentCount}, tambahan yang dipilih {$additional}."
            );
        }

        $oldRoomIds = DB::table('exam_administration_participants')
            ->where('exam_administration_id', $program)
            ->whereIn('student_id', $studentIds)
            ->pluck('exam_administration_room_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        DB::transaction(function () use ($program, $room, $studentIds) {
            foreach ($studentIds as $studentId) {
                $existing = DB::table('exam_administration_participants')
                    ->where('exam_administration_id', $program)
                    ->where('student_id', $studentId)
                    ->first();

                if ($existing && (int) $existing->exam_administration_room_id === (int) $room->id) {
                    continue;
                }

                if ($existing) {
                    DB::table('exam_administration_participants')
                        ->where('id', $existing->id)
                        ->update([
                            'exam_administration_room_id' => $room->id,
                            'seat_number' => null,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('exam_administration_participants')->insert([
                        'exam_administration_id' => $program,
                        'student_id' => $studentId,
                        'exam_administration_room_id' => $room->id,
                        'seat_number' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        foreach (array_unique(array_merge($oldRoomIds, [(int) $room->id])) as $roomId) {
            $this->resequenceRoom($roomId);
        }

        return redirect()
            ->route('tu.ujian.index', ['program' => $program])
            ->with('success', count($studentIds) . " peserta ditempatkan ke ruang {$room->room_name}.");
    }

    public function updateRoomLayout(Request $request, int $program, int $room): RedirectResponse
    {
        $this->programOrFail($program);
        $roomRow = $this->roomForProgramOrFail($program, $room);

        $data = $request->validate([
            'seat_columns' => ['required', 'integer', 'min:4', 'max:10'],
        ]);

        DB::table('exam_administration_rooms')
            ->where('id', $roomRow->id)
            ->update([
                'seat_columns' => (int) $data['seat_columns'],
                'updated_at' => now(),
            ]);

        return redirect()
            ->route('tu.ujian.index', ['program' => $program])
            ->with('success', "Denah ruang {$roomRow->room_name} diperbarui menjadi {$data['seat_columns']} kolom kursi.");
    }

    public function updateParticipantSeat(Request $request, int $program, int $participant): RedirectResponse
    {
        $this->programOrFail($program);

        $row = DB::table('exam_administration_participants')
            ->where('id', $participant)
            ->where('exam_administration_id', $program)
            ->first();
        abort_unless($row, 404);

        $room = $this->roomForProgramOrFail($program, (int) $row->exam_administration_room_id);
        $capacity = $this->capacityForRoom($room);

        $data = $request->validate([
            'seat_number' => ['required', 'integer', 'min:1', 'max:' . max(1, $capacity)],
        ]);

        $targetSeat = (int) $data['seat_number'];
        $currentSeat = $row->seat_number ? (int) $row->seat_number : null;

        if ($currentSeat === $targetSeat) {
            return redirect()
                ->route('tu.ujian.index', ['program' => $program])
                ->with('success', 'Posisi kursi tidak berubah.');
        }

        $occupied = DB::table('exam_administration_participants')
            ->where('exam_administration_room_id', $room->id)
            ->where('seat_number', $targetSeat)
            ->where('id', '<>', $participant)
            ->first();

        DB::transaction(function () use ($participant, $targetSeat, $currentSeat, $occupied) {
            if ($occupied) {
                DB::table('exam_administration_participants')
                    ->where('id', $occupied->id)
                    ->update([
                        'seat_number' => $currentSeat,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('exam_administration_participants')
                ->where('id', $participant)
                ->update([
                    'seat_number' => $targetSeat,
                    'updated_at' => now(),
                ]);
        });

        return redirect()
            ->route('tu.ujian.index', ['program' => $program])
            ->with(
                'success',
                $occupied
                    ? "Kursi {$targetSeat} sudah terisi; posisi kedua peserta ditukar."
                    : "Peserta dipindahkan ke kursi {$targetSeat}."
            );
    }

    public function removeParticipant(int $program, int $participant): RedirectResponse
    {
        $this->programOrFail($program);

        $row = DB::table('exam_administration_participants')
            ->where('id', $participant)
            ->where('exam_administration_id', $program)
            ->first();
        abort_unless($row, 404);

        DB::table('exam_administration_participants')->where('id', $participant)->delete();
        $this->resequenceRoom((int) $row->exam_administration_room_id);

        return redirect()
            ->route('tu.ujian.index', ['program' => $program])
            ->with('success', 'Peserta dikeluarkan dari ruang ujian.');
    }

    public function saveInvigilators(Request $request, int $program): RedirectResponse
    {
        $this->programOrFail($program);

        if (!Schema::hasTable('exam_administration_invigilators')) {
            return back()->with('error', 'Tabel pengawas ujian belum tersedia. Jalankan php artisan migrate terlebih dahulu.');
        }

        $data = $request->validate([
            'schedule_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'supervisor_1_teacher_id' => ['nullable', 'integer'],
            'supervisor_1_name' => ['nullable', 'string', 'max:150'],
            'supervisor_1_nip' => ['nullable', 'string', 'max:50'],
            'supervisor_2_teacher_id' => ['nullable', 'integer'],
            'supervisor_2_name' => ['nullable', 'string', 'max:150'],
            'supervisor_2_nip' => ['nullable', 'string', 'max:50'],
        ]);

        $schedule = DB::table('exam_administration_schedules')
            ->where('id', $data['schedule_id'])
            ->where('exam_administration_id', $program)
            ->first();
        abort_unless($schedule, 404);

        $room = $this->roomForProgramOrFail($program, (int) $data['room_id']);

        $supervisors = [
            1 => $this->resolveInvigilatorInput(
                (int) ($data['supervisor_1_teacher_id'] ?? 0),
                $data['supervisor_1_name'] ?? null,
                $data['supervisor_1_nip'] ?? null
            ),
            2 => $this->resolveInvigilatorInput(
                (int) ($data['supervisor_2_teacher_id'] ?? 0),
                $data['supervisor_2_name'] ?? null,
                $data['supervisor_2_nip'] ?? null
            ),
        ];

        if (!$supervisors[1] || !$supervisors[2]) {
            return back()
                ->withInput()
                ->with('error', 'Pengawas 1 dan Pengawas 2 wajib diisi. Pilih GTK atau isi nama pengawas secara manual.');
        }

        DB::transaction(function () use ($program, $schedule, $room, $supervisors) {
            foreach ($supervisors as $number => $supervisor) {
                DB::table('exam_administration_invigilators')->updateOrInsert(
                    [
                        'exam_administration_schedule_id' => $schedule->id,
                        'exam_administration_room_id' => $room->id,
                        'supervisor_no' => $number,
                    ],
                    [
                        'exam_administration_id' => $program,
                        'teacher_id' => $supervisor['teacher_id'],
                        'name' => $supervisor['name'],
                        'nip' => $supervisor['nip'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        });

        return redirect()
            ->route('tu.ujian.index', ['program' => $program])
            ->with('success', "Pengawas untuk {$schedule->title} - {$room->room_name} berhasil disimpan.");
    }

    public function printCards(Request $request, int $program): View
    {
        $programRow = $this->programOrFail($program);
        $orientation = $request->validate(['orientation' => ['nullable', 'in:portrait,landscape']])['orientation'] ?? 'portrait';
        $roomId = $request->integer('room_id') ?: null;

        if ($roomId) {
            $this->roomForProgramOrFail($program, $roomId);
        }

        $participants = $this->participantsWithRoom($program, $roomId);
        $schedules = DB::table('exam_administration_schedules')
            ->where('exam_administration_id', $program)
            ->orderBy('exam_date')
            ->orderBy('start_time')
            ->get();
        $print = $this->printContext();

        return view('tu.ujian.print-cards', [
            'orientation' => $orientation,
            'program' => $programRow,
            'participants' => $participants,
            'schedules' => $schedules,
            'school' => $print['school'],
            'kopUrl' => $print['kopUrl'],
            'principal' => $print['principal'],
            'signatureUrl' => $print['signatureUrl'],
        ]);
    }

    public function printAttendance(Request $request, int $program): View
    {
        $programRow = $this->programOrFail($program);
        [$schedule, $room] = $this->resolvePrintScheduleRoom($request, $program);
        $participants = $this->participantsWithRoom($program, (int) $room->id);
        $print = $this->printContext();
        $supervisors = $this->invigilatorsFor((int) $schedule->id, (int) $room->id);

        return view('tu.ujian.print-attendance', [
            'program' => $programRow,
            'schedule' => $schedule,
            'room' => $room,
            'participants' => $participants,
            'school' => $print['school'],
            'kopUrl' => $print['kopUrl'],
            'principal' => $print['principal'],
            'signatureUrl' => $print['signatureUrl'],
            'examLocation' => $this->examLocation($print['school']),
            'supervisor1' => $supervisors->get(1),
            'supervisor2' => $supervisors->get(2),
        ]);
    }

    public function printMinutes(Request $request, int $program): View
    {
        $programRow = $this->programOrFail($program);
        [$schedule, $room] = $this->resolvePrintScheduleRoom($request, $program);
        $participants = $this->participantsWithRoom($program, (int) $room->id);
        $print = $this->printContext();

        return view('tu.ujian.print-minutes', [
            'program' => $programRow,
            'schedule' => $schedule,
            'room' => $room,
            'participants' => $participants,
            'school' => $print['school'],
            'kopUrl' => $print['kopUrl'],
            'principal' => $print['principal'],
            'signatureUrl' => $print['signatureUrl'],
        ]);
    }

    public function printSeating(Request $request, int $program): View
    {
        $programRow = $this->programOrFail($program);
        $orientation = $request->validate(['orientation' => ['nullable', 'in:portrait,landscape']])['orientation'] ?? 'landscape';

        $request->validate([
            'room_id' => ['required', 'integer'],
        ]);

        $room = $this->roomForProgramOrFail($program, $request->integer('room_id'));
        $room->capacity_live = $this->capacityForRoom($room);
        $room->seat_columns = max(4, min(10, (int) ($room->seat_columns ?? 6)));

        $participants = $this->participantsWithRoom($program, (int) $room->id);
        $bySeat = $participants->keyBy(fn ($participant) => (int) $participant->seat_number);

        // V21R67: Hitung metadata halaman di Controller, bukan di Blade.
        // PageCount wajib tersedia sebelum Blade memulai @for/@if.
        $columns = (int) $room->seat_columns;
        $perPage = $columns * ($orientation === 'portrait' ? 6 : 4);
        $maxSeat = (int) ($participants->max('seat_number') ?? 0);
        $capacity = max(0, (int) ($room->capacity_live ?? 0), $maxSeat);
        $pageCount = max(1, (int) ceil($capacity / max(1, $perPage)));

        $print = $this->printContext();

        return view('tu.ujian.print-seating', [
            'orientation' => $orientation,
            'columns' => $columns,
            'perPage' => $perPage,
            'capacity' => $capacity,
            'pageCount' => $pageCount,
            'program' => $programRow,
            'room' => $room,
            'participants' => $participants,
            'bySeat' => $bySeat,
            'school' => $print['school'],
            'kopUrl' => $print['kopUrl'],
            'principal' => $print['principal'],
            'signatureUrl' => $print['signatureUrl'],
        ]);
    }

    private function validateSchedule(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'exam_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'mode' => ['required', 'in:paper,cbt'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function programOrFail(int $program): object
    {
        $row = DB::table('exam_administrations')->where('id', $program)->first();
        abort_unless($row, 404);
        return $row;
    }

    private function roomForProgramOrFail(int $program, int $roomId): object
    {
        $room = DB::table('exam_administration_rooms')
            ->where('id', $roomId)
            ->where('exam_administration_id', $program)
            ->first();
        abort_unless($room, 404);
        return $room;
    }

    private function resolvePrintScheduleRoom(Request $request, int $program): array
    {
        $request->validate([
            'schedule_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
        ]);

        $schedule = DB::table('exam_administration_schedules')
            ->where('id', $request->integer('schedule_id'))
            ->where('exam_administration_id', $program)
            ->first();
        abort_unless($schedule, 404);

        $room = $this->roomForProgramOrFail($program, $request->integer('room_id'));

        return [$schedule, $room];
    }

    private function resolveCurrentSemesterId(): ?int
    {
        $sessionId = (int) session('semester_id', 0);
        if ($sessionId > 0 && DB::table('homerooms')->where('semester_id', $sessionId)->exists()) {
            return $sessionId;
        }

        $id = DB::table('homerooms')
            ->where('jenis_rombel', 'Kelas')
            ->whereNotNull('semester_id')
            ->max('semester_id');

        return $id ? (int) $id : null;
    }

    private function regularHomerooms(int $semesterId): Collection
    {
        return DB::table('homerooms')
            ->where('semester_id', $semesterId)
            ->where('jenis_rombel', 'Kelas')
            ->orderBy('nama')
            ->get()
            ->map(function ($homeroom) {
                $homeroom->capacity = DB::table('enrollments')
                    ->where('homeroom_id', $homeroom->id)
                    ->distinct()
                    ->count('student_id');
                return $homeroom;
            });
    }

    private function syncRoomsInternal(int $program, int $semesterId): array
    {
        $homerooms = $this->regularHomerooms($semesterId);
        $activeIds = [];

        foreach ($homerooms as $homeroom) {
            $activeIds[] = (int) $homeroom->id;

            DB::table('exam_administration_rooms')->updateOrInsert(
                [
                    'exam_administration_id' => $program,
                    'homeroom_id' => $homeroom->id,
                ],
                [
                    'room_name' => $homeroom->nama ?: ('Ruang ' . $homeroom->id),
                    'capacity_snapshot' => (int) $homeroom->capacity,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $roomId = DB::table('exam_administration_rooms')
                ->where('exam_administration_id', $program)
                ->where('homeroom_id', $homeroom->id)
                ->value('id');

            if ($roomId) {
                $this->resequenceRoom((int) $roomId);
            }
        }

        $staleQuery = DB::table('exam_administration_rooms')
            ->where('exam_administration_id', $program);

        if ($activeIds) {
            $staleQuery->whereNotIn('homeroom_id', $activeIds);
        }

        $stale = $staleQuery->get();
        $removed = 0;

        foreach ($stale as $room) {
            $hasParticipants = DB::table('exam_administration_participants')
                ->where('exam_administration_room_id', $room->id)
                ->exists();

            if (!$hasParticipants) {
                DB::table('exam_administration_rooms')->where('id', $room->id)->delete();
                $removed++;
            }
        }

        return [
            'upserted' => $homerooms->count(),
            'removed' => $removed,
        ];
    }

    private function capacityForRoom(object $room): int
    {
        if (DB::table('homerooms')->where('id', $room->homeroom_id)->exists()) {
            return (int) DB::table('enrollments')
                ->where('homeroom_id', $room->homeroom_id)
                ->distinct()
                ->count('student_id');
        }

        return (int) ($room->capacity_snapshot ?? 0);
    }

    private function classSubquery(int $semesterId)
    {
        return DB::table('enrollments as e')
            ->join('homerooms as h', 'h.id', '=', 'e.homeroom_id')
            ->where('h.semester_id', $semesterId)
            ->where('h.jenis_rombel', 'Kelas')
            ->select(
                'e.student_id',
                DB::raw('MIN(h.id) as original_homeroom_id'),
                DB::raw('MIN(h.nama) as class_name')
            )
            ->groupBy('e.student_id');
    }

    private function eligibleStudentQuery(object $program)
    {
        $semesterId = (int) $program->semester_id;
        $classes = $this->classSubquery($semesterId);

        // Peserta sekolah berasal dari SELURUH baris students lokal, tanpa filter
        // kelas yang diajar GTK. Join hanya untuk label kelas asal. Murid yang
        // belum tercatat di rombel semester program tetap dapat diikutsertakan.
        return DB::table('students')
            ->leftJoinSub($classes, 'student_classes', function ($join) {
                $join->on('student_classes.student_id', '=', 'students.id');
            });
    }

    private function eligibleStudents(object $program): Collection
    {
        return $this->eligibleStudentQuery($program)
            ->leftJoin('exam_administration_participants as ep', function ($join) use ($program) {
                $join->on('ep.student_id', '=', 'students.id')
                    ->where('ep.exam_administration_id', '=', $program->id);
            })
            ->leftJoin('exam_administration_rooms as er', 'er.id', '=', 'ep.exam_administration_room_id')
            ->select(
                'students.id',
                'students.nama',
                'students.nisn',
                'student_classes.class_name',
                'ep.id as participant_id',
                'ep.exam_administration_room_id as assigned_room_id',
                'er.room_name as assigned_room_name'
            )
            ->orderBy('student_classes.class_name')
            ->orderBy('students.nama')
            ->get();
    }

    private function participantsWithRoom(int $program, ?int $roomId = null): Collection
    {
        $programRow = $this->programOrFail($program);
        $classes = $this->classSubquery((int) $programRow->semester_id);

        // Restore database lama mungkin belum memiliki kolom foto_path.
        $hasStudentPhoto = Schema::hasColumn('students', 'foto_path');

        $query = DB::table('exam_administration_participants as ep')
            ->join('students', 'students.id', '=', 'ep.student_id')
            ->join('exam_administration_rooms as er', 'er.id', '=', 'ep.exam_administration_room_id')
            ->leftJoinSub($classes, 'student_classes', function ($join) {
                $join->on('student_classes.student_id', '=', 'students.id');
            })
            ->where('ep.exam_administration_id', $program)
            ->select(
                'ep.id as participant_id',
                'ep.student_id',
                'ep.exam_administration_room_id as room_id',
                'ep.seat_number',
                'students.nama',
                'students.nisn',
                $hasStudentPhoto ? 'students.foto_path' : DB::raw('NULL AS foto_path'),
                'student_classes.class_name',
                'er.room_name'
            );

        if ($roomId) {
            $query->where('ep.exam_administration_room_id', $roomId);
        }

        $media = app(\App\Services\LocalMediaService::class);

        return $query
            ->orderBy('er.room_name')
            ->orderByRaw('CASE WHEN ep.seat_number IS NULL THEN 1 ELSE 0 END')
            ->orderBy('ep.seat_number')
            ->orderBy('students.nama')
            ->get()
            ->map(function ($participant) use ($media) {
                // Foto mengikuti sumber Kartu Pelajar / ZIP V21R47.
                // Jika belum ada atau berkas lokal hilang, denah memakai placeholder.
                $fotoPath = $participant->foto_path ?? null;
                // Foto tidak boleh menggagalkan seluruh cetak denah (HTTP 500).
                $participant->foto_url = null;
                if ($fotoPath) {
                    try {
                        if ($media->exists($fotoPath)) {
                            $participant->foto_url = $media->url($fotoPath);
                        }
                    } catch (\Throwable $ignored) {
                        // File foto rusak/tidak ditemukan: gunakan placeholder.
                    }
                }
                unset($participant->foto_path);

                return $participant;
            });
    }

    private function resequenceRoom(int $roomId): void
    {
        $room = DB::table('exam_administration_rooms')->where('id', $roomId)->first();
        if (!$room) {
            return;
        }

        $capacity = max(0, $this->capacityForRoom($room));
        $rows = DB::table('exam_administration_participants as ep')
            ->join('students', 'students.id', '=', 'ep.student_id')
            ->where('ep.exam_administration_room_id', $roomId)
            ->select('ep.id', 'ep.seat_number', 'students.nama')
            ->orderByRaw('CASE WHEN ep.seat_number IS NULL THEN 1 ELSE 0 END')
            ->orderBy('ep.seat_number')
            ->orderBy('students.nama')
            ->orderBy('ep.id')
            ->get();

        $used = [];
        $pending = [];

        foreach ($rows as $row) {
            $seat = $row->seat_number ? (int) $row->seat_number : null;

            if ($seat && $seat >= 1 && $seat <= $capacity && !isset($used[$seat])) {
                $used[$seat] = true;
                continue;
            }

            $pending[] = $row;
        }

        $available = [];
        for ($seat = 1; $seat <= $capacity; $seat++) {
            if (!isset($used[$seat])) {
                $available[] = $seat;
            }
        }

        foreach ($pending as $index => $row) {
            DB::table('exam_administration_participants')
                ->where('id', $row->id)
                ->update([
                    'seat_number' => $available[$index] ?? null,
                    'updated_at' => now(),
                ]);
        }
    }

    private function teacherOptions(): Collection
    {
        if (!Schema::hasTable('teachers')) {
            return collect();
        }

        $columns = Schema::getColumnListing('teachers');
        $query = DB::table('teachers');

        if (in_array('deleted_at', $columns, true)) {
            $query->whereNull('deleted_at');
        }

        return $query
            ->get()
            ->map(function ($teacher) use ($columns) {
                $parts = array_filter([
                    trim((string) ($teacher->gelar_depan ?? '')),
                    trim((string) ($teacher->nama ?? $teacher->name ?? '')),
                    trim((string) ($teacher->gelar_belakang ?? '')),
                ]);

                $teacher->display_name = trim(implode(' ', $parts));
                $teacher->display_nip = '';

                foreach (['nip', 'nik', 'nuptk'] as $column) {
                    if (in_array($column, $columns, true) && trim((string) ($teacher->{$column} ?? '')) !== '') {
                        $teacher->display_nip = trim((string) $teacher->{$column});
                        break;
                    }
                }

                return $teacher;
            })
            ->filter(fn ($teacher) => trim((string) $teacher->display_name) !== '')
            ->sortBy(fn ($teacher) => Str::lower((string) $teacher->display_name))
            ->values();
    }

    private function resolveInvigilatorInput(int $teacherId, ?string $manualName, ?string $manualNip): ?array
    {
        if ($teacherId > 0 && Schema::hasTable('teachers')) {
            $teacher = DB::table('teachers')->where('id', $teacherId)->first();
            if ($teacher) {
                $parts = array_filter([
                    trim((string) ($teacher->gelar_depan ?? '')),
                    trim((string) ($teacher->nama ?? $teacher->name ?? '')),
                    trim((string) ($teacher->gelar_belakang ?? '')),
                ]);

                $name = trim(implode(' ', $parts));
                $nip = '';
                foreach (['nip', 'nik', 'nuptk'] as $column) {
                    $candidate = trim((string) ($teacher->{$column} ?? ''));
                    if ($candidate !== '') {
                        $nip = $candidate;
                        break;
                    }
                }

                if ($name !== '') {
                    return [
                        'teacher_id' => $teacherId,
                        'name' => $name,
                        'nip' => $nip !== '' ? $nip : null,
                    ];
                }
            }
        }

        $name = trim((string) $manualName);
        $nip = trim((string) $manualNip);

        if ($name === '') {
            return null;
        }

        return [
            'teacher_id' => null,
            'name' => $name,
            'nip' => $nip !== '' ? $nip : null,
        ];
    }

    private function invigilatorsFor(int $scheduleId, int $roomId): Collection
    {
        if (!Schema::hasTable('exam_administration_invigilators')) {
            return collect();
        }

        return DB::table('exam_administration_invigilators')
            ->where('exam_administration_schedule_id', $scheduleId)
            ->where('exam_administration_room_id', $roomId)
            ->orderBy('supervisor_no')
            ->get()
            ->keyBy(fn ($row) => (int) $row->supervisor_no);
    }

    private function examLocation(?object $school): string
    {
        if (!$school) {
            return '';
        }

        $location = trim((string) (
            $school->kabupaten_kota
            ?? $school->kabupaten
            ?? $school->kota
            ?? ''
        ));

        if ($location === '') {
            return '';
        }

        $location = preg_replace('/^Kabupaten\s+/i', 'Kab. ', $location) ?: $location;

        return $location;
    }

    private function printContext(): array
    {
        $school = Schema::hasTable('schools')
            ? DB::table('schools')->first()
            : null;

        $kopPath = null;
        $signaturePath = null;
        if (Schema::hasTable('app_settings')) {
            $kopPath = DB::table('app_settings')
                ->where('key', 'kop_surat')
                ->value('value');
            $signaturePath = DB::table('app_settings')
                ->where('key', 'tanda_tangan_kartu')
                ->value('value');
        }

        $principal = $this->resolvePrincipal();

        return [
            'school' => $school,
            'kopUrl' => $this->publicFileUrl($kopPath),
            'signatureUrl' => $this->publicFileUrl($signaturePath),
            'principal' => $principal,
        ];
    }

    private function resolvePrincipal(): ?object
    {
        if (Schema::hasTable('teachers')) {
            $query = DB::table('teachers');
            $columns = Schema::getColumnListing('teachers');

            if (in_array('deleted_at', $columns, true)) {
                $query->whereNull('deleted_at');
            }

            $teacher = $query->get()->first(function ($row) use ($columns) {
                foreach (['jabatan', 'jenis_ptk', 'tugas_tambahan', 'role'] as $column) {
                    if (!in_array($column, $columns, true)) {
                        continue;
                    }

                    $value = Str::lower((string) ($row->{$column} ?? ''));
                    if (Str::contains($value, ['kepala sekolah', 'kepala_sekolah'])) {
                        return true;
                    }
                }
                return false;
            });

            if ($teacher) {
                $parts = array_filter([
                    trim((string) ($teacher->gelar_depan ?? '')),
                    trim((string) ($teacher->nama ?? $teacher->name ?? '')),
                    trim((string) ($teacher->gelar_belakang ?? '')),
                ]);
                $teacher->nama_lengkap = trim(implode(' ', $parts));
                return $teacher;
            }
        }

        if (Schema::hasTable('users')) {
            $user = DB::table('users')->where('role', 'kepala_sekolah')->first();
            if ($user) {
                $user->nama_lengkap = trim((string) ($user->name ?? $user->nama ?? ''));
                return $user;
            }
        }

        return null;
    }

    private function publicFileUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        $media = app(\App\Services\LocalMediaService::class);
        return $media->exists($path) ? $media->url($path) : null;
    }
}
