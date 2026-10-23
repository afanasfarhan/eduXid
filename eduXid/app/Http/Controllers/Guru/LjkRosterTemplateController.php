<?php

declare(strict_types=1);

namespace App\Http\Controllers\Guru;

use App\Http\Controllers\Controller;
use App\Services\Ljk\LjkStandaloneManualService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cetak LJK A4-V3 berisi biodata resmi. Tidak membuat paket ujian dan
 * tidak membocorkan NISN melalui Cloud Hub / URL / logging.
 */
class LjkRosterTemplateController extends Controller
{
    public function __construct(private LjkStandaloneManualService $service) {}

    public function index(Request $request): \Illuminate\Http\Response
    {
        $assignments = collect($this->service->assignments()); // hanya penugasan guru login
        $selectedId = $request->integer('ta');
        $active = null;
        $roster = [];

        if ($selectedId > 0) {
            $active = $assignments->first(fn ($a) => (int) ($a['id'] ?? 0) === $selectedId);
            abort_unless($active, 403, 'Penugasan ini bukan milik guru yang login.');

            $homeroomId = (int) $active['homeroom_id'];
            // Roster hanya diperoleh dari SQLite sekolah dan kelas dalam penugasan.
            $roster = DB::table('enrollments as e')
                ->join('students as s', 's.id', '=', 'e.student_id')
                ->where('e.homeroom_id', $homeroomId)
                ->orderBy('s.nama')
                ->limit(301)
                ->get(['s.id', 's.nama', 's.nisn'])
                ->map(fn ($s) => [
                    'nama' => (string) ($s->nama ?? ''),
                    'nisn' => (string) ($s->nisn ?? ''),
                ])->all();
            abort_if(count($roster) > 300, 422, 'Rombel melebihi batas 300 siswa untuk cetak sekali jalan.');
        }

        return response()->view('guru.ljk-auto.roster-print', compact('assignments', 'active', 'roster'))
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
