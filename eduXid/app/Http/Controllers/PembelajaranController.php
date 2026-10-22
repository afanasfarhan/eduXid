<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PembelajaranController extends Controller
{
    public function index(Request $request): View
    {
        $sort = $request->get('sort', 'kelas');
        $direction = $request->get('direction', 'asc');
        $search = $request->get('search', '');

        $allowedSort = [
            'kelas' => 'homerooms.nama',
            'mapel' => 'subjects.nama_mata_pelajaran',
            'guru' => 'teachers.nama'
        ];
        
        if (!array_key_exists($sort, $allowedSort)) $sort = 'kelas';
        $sortCol = $allowedSort[$sort];
        
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'asc';

        $pembelajaran = DB::table('teaching_assignments')
            ->join('homerooms', 'teaching_assignments.homeroom_id', '=', 'homerooms.id')
            ->join('teachers', 'teaching_assignments.teacher_id', '=', 'teachers.id')
            ->join('subjects', 'teaching_assignments.subject_id', '=', 'subjects.id')
            ->select(
                'teaching_assignments.id',
                'homerooms.nama as kelas',
                'teachers.nama as guru',
                'teachers.gelar_depan',
                'teachers.gelar_belakang',
                'teachers.id as guru_id',
                'subjects.nama_mata_pelajaran as mapel'
            )
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('homerooms.nama', 'like', '%'.$search.'%')
                      ->orWhere('subjects.nama_mata_pelajaran', 'like', '%'.$search.'%')
                      ->orWhere('teachers.nama', 'like', '%'.$search.'%');
                });
            })
            ->orderBy($sortCol, $direction)
            ->paginate(20)
            ->withQueryString();

        $gurus = DB::table('teachers')->orderBy('nama')->get();
        
        // TAMBAHKAN 2 BARIS INI
        $rombels = DB::table('homerooms')->orderBy('nama')->get();
        $mapels = DB::table('subjects')->orderBy('nama_mata_pelajaran')->get();

        return view('admin.pembelajaran.index', compact('pembelajaran', 'gurus', 'rombels', 'mapels', 'sort', 'direction', 'search'));
    }

    // TAMBAHKAN METHOD INI
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'homeroom_id' => 'required|exists:homerooms,id',
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'required|exists:teachers,id',
        ]);

        DB::table('teaching_assignments')->insert([
            'dapodik_pembelajaran_id' => 'manual-' . Str::uuid(),
            'homeroom_id' => $request->homeroom_id,
            'subject_id' => $request->subject_id,
            'teacher_id' => $request->teacher_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Data pembelajaran baru berhasil ditambahkan!');
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
        ]);

        DB::transaction(function () use ($request, $id) {
            DB::table('teaching_assignments')->where('id', $id)->update([
                'teacher_id' => $request->teacher_id,
                'updated_at' => now(),
            ]);
        });

        return redirect()->back()->with('success', 'Data pembelajaran berhasil diperbarui!');
    }

    public function destroy($id): RedirectResponse
    {
        // Hapus data nilai yang terkait dengan pembelajaran ini agar tidak melanggar foreign key
        DB::table('assessment_scores')->where('teaching_assignment_id', $id)->delete();
        DB::table('final_grades')->where('teaching_assignment_id', $id)->delete();
        DB::table('attitude_logs')->where('teaching_assignment_id', $id)->delete();
        
        // Baru hapus data pembelajarannya
        DB::table('teaching_assignments')->where('id', $id)->delete();
        
        return redirect()->back()->with('success', 'Data pembelajaran berhasil dihapus!');
    }

}