<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SikapController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'teaching_assignment_id' => 'required|integer|exists:teaching_assignments,id',
            'tanggal' => 'required|date',
            'd1' => 'required|integer|min:1|max:5',
            'd2' => 'required|integer|min:1|max:5',
            'd3' => 'required|integer|min:1|max:5',
            'd4' => 'required|integer|min:1|max:5',
            'd5' => 'required|integer|min:1|max:5',
            'd6' => 'required|integer|min:1|max:5',
            'd7' => 'required|integer|min:1|max:5',
            'd8' => 'required|integer|min:1|max:5',
        ]);

        // Simpan sebagai Log Harian (Update jika di hari yang sama diklik ulang)
        DB::table('attitude_logs')->updateOrInsert(
            [
                'student_id' => $validated['student_id'], 
                'teaching_assignment_id' => $validated['teaching_assignment_id'],
                'tanggal' => $validated['tanggal']
            ],
            [
                'd1' => $validated['d1'], 'd2' => $validated['d2'], 'd3' => $validated['d3'], 'd4' => $validated['d4'],
                'd5' => $validated['d5'], 'd6' => $validated['d6'], 'd7' => $validated['d7'], 'd8' => $validated['d8'],
                'updated_at' => now(), 'created_at' => now(),
            ]
        );

        return response()->json(['success' => true]);
    }
}