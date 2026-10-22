<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeatingController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'enrollment_id' => 'required|integer|exists:enrollments,id',
            'pos_x' => 'required|integer',
            'pos_y' => 'required|integer',
        ]);

        DB::table('enrollments')->where('id', $validated['enrollment_id'])->update([
            'pos_x' => $validated['pos_x'],
            'pos_y' => $validated['pos_y'],
        ]);

        return response()->json(['success' => true]);
    }
    

public function reset(Request $request)
    {
        $validated = $request->validate([
            'homeroom_id' => 'required|integer|exists:homerooms,id',
        ]);

        // Set pos_x dan pos_y menjadi NULL agar kembali ke perhitungan default 6x6
        DB::table('enrollments')
            ->where('homeroom_id', $validated['homeroom_id'])
            ->update(['pos_x' => null, 'pos_y' => null]);

        return response()->json(['success' => true]);
    }
}