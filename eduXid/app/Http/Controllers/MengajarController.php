<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MengajarController extends Controller
{
    /**
     * Menampilkan daftar kelas/mapel yang diajar guru yang sedang login
     */
    public function index()
    {
        $user = Auth::user();
        $guru = $user->teacher();
        $semester_id = session('semester_aktif'); // Baca session

        $mengajars = collect();

        if ($guru) {
            $mengajars = $guru->teachingAssignments()
                ->with(['homeroom:id,nama,jenis_rombel', 'subject:id,nama_mata_pelajaran'])
                ->whereHas('homeroom', function ($query) use ($semester_id) {
                    $query->where('semester_id', $semester_id); // Filter semester
                    $query->where('jenis_rombel', 'not like', '%Ekstrakurikuler%');
                })
                ->whereHas('subject', function ($query) {
                    $query->where('nama_mata_pelajaran', 'not like', '%Bimbingan%')
                          ->where('nama_mata_pelajaran', 'not like', '%Konseling%')
                          ->where('nama_mata_pelajaran', 'not like', '%Konselor%');
                })
                ->get()
                ->map(function ($assignment) {
                    return (object) [
                        'assignment_id' => $assignment->id,
                        'homeroom_id' => $assignment->homeroom_id,
                        'kelas' => $assignment->homeroom->nama ?? '-',
                        'mapel' => $assignment->subject->nama_mata_pelajaran ?? '-',
                    ];
                });
        }

        return view('guru.mengajar', compact('mengajars'));
    }
}