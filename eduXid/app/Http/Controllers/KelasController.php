<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class KelasController extends Controller
{
    public function index(Request $request): View
    {
        $sort = $request->get('sort', 'nama');
        $direction = $request->get('direction', 'asc');
        $search = $request->get('search', '');

        // PERBAIKAN: Gunakan array asosiatif untuk memetakan kolom agar tidak ambiguous di SQLite
        $allowedSort = [
            'nama' => 'homerooms.nama',
            'tingkat_pendidikan' => 'homerooms.tingkat_pendidikan',
            'jenis_rombel' => 'homerooms.jenis_rombel',
            'wali_kelas' => 'teachers.nama'
        ];
        
        if (!array_key_exists($sort, $allowedSort)) $sort = 'nama';
        $sortCol = $allowedSort[$sort];
        
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'asc';

        $kelas = DB::table('homerooms')
            ->leftJoin('teachers', 'homerooms.homeroom_teacher_id', '=', 'teachers.id')
            ->select('homerooms.*', 'teachers.nama as wali_kelas', 'teachers.id as wali_id')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('homerooms.nama', 'like', '%'.$search.'%')
                      ->orWhere('homerooms.jenis_rombel', 'like', '%'.$search.'%')
                      ->orWhere('teachers.nama', 'like', '%'.$search.'%');
                });
            })
            ->orderBy($sortCol, $direction)
            ->paginate(15)
            ->withQueryString();

        $gurus = DB::table('teachers')->orderBy('nama')->get();

        return view('admin.kelas.index', compact('kelas', 'gurus', 'sort', 'direction', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'nama' => 'required|string|max:255',
            'tingkat_pendidikan' => 'nullable|string|max:255',
            'semester_id' => 'nullable|string|max:10',
            'jenis_rombel' => 'required|string|max:255',
            'kurikulum' => 'nullable|string|max:255',
            'homeroom_teacher_id' => 'nullable|exists:teachers,id',
            'jurusan' => 'nullable|string|max:255',
        ]);

        DB::table('homerooms')->insert([
            'dapodik_rombel_id' => 'manual-' . Str::uuid(),
            'nama' => $request->nama,
            'tingkat_pendidikan' => $request->tingkat_pendidikan,
            'semester_id' => $request->semester_id,
            'jenis_rombel' => $request->jenis_rombel,
            'kurikulum' => $request->kurikulum,
            'homeroom_teacher_id' => $request->homeroom_teacher_id,
            'jurusan' => $request->jurusan,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Kelas/Rombel baru berhasil ditambahkan!');
    }

    public function updateWali(Request $request, $id): RedirectResponse
    {
        $request->validate([
            'homeroom_teacher_id' => 'nullable|exists:teachers,id'
        ]);

        DB::table('homerooms')->where('id', $id)->update([
            'homeroom_teacher_id' => $request->homeroom_teacher_id,
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Wali kelas / Pembina berhasil diperbarui!');
    }

    // Method Baru: Hapus Kelas
    public function destroy($id): RedirectResponse
    {
        // Hapus keanggotaan siswa di kelas ini terlebih dahulu
        DB::table('enrollments')->where('homeroom_id', $id)->delete();
        
        // Lalu hapus data kelasnya
        DB::table('homerooms')->where('id', $id)->delete();
        
        return redirect()->back()->with('success', 'Data kelas berhasil dihapus!');
    }

    public function anggota($id): View
    {
        $rombel = DB::table('homerooms')->where('id', $id)->first();
        
        $anggota = DB::table('enrollments')
            ->join('students', 'enrollments.student_id', '=', 'students.id')
            ->where('enrollments.homeroom_id', $id)
            ->select('enrollments.id as enrollment_id', 'students.id as student_id', 'students.nama', 'students.nisn')
            ->orderBy('students.nama')
            ->get();

        $existingIds = $anggota->pluck('student_id')->toArray();
        $siswaTersedia = DB::table('students')
            ->whereNotIn('id', $existingIds)
            ->orderBy('nama')
            ->get();

        return view('admin.kelas.anggota', compact('rombel', 'anggota', 'siswaTersedia'));
    }

    public function anggotaStore(Request $request, $id): RedirectResponse
    {
        $request->validate([
            'student_id' => 'required|exists:students,id'
        ]);

        DB::transaction(function () use ($request, $id) {
            $studentId = $request->student_id;

            // Cek jika siswa ini sudah terdaftar di kelas reguler lain
            $existingEnrollment = DB::table('enrollments')
                ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
                ->where('enrollments.student_id', $studentId)
                ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
                ->where('homerooms.jenis_rombel', 'not like', '%Pilihan%')
                ->select('enrollments.id')
                ->first();

            // Jika sudah ada di kelas lain, hapus pendaftaran lama agar tidak dobel kelas
            if ($existingEnrollment) {
                DB::table('enrollments')->where('id', $existingEnrollment->id)->delete();
            }

            // Daftarkan ke kelas baru
            DB::table('enrollments')->insert([
                'dapodik_anggota_rombel_id' => 'manual-' . Str::uuid(),
                'student_id' => $studentId,
                'homeroom_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'Murid berhasil ditambahkan ke kelas!');
    }

    public function anggotaDestroy($enrollment_id): RedirectResponse
    {
        DB::table('enrollments')->where('id', $enrollment_id)->delete();
        
        return back()->with('success', 'Murid berhasil dikeluarkan dari kelas!');
    }
}