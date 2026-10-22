<?php

namespace App\Http\Controllers;

use App\Services\AtpService;
use App\Services\ModulAjarGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class ModulAjarController extends Controller
{
    public function __construct(private AtpService $atp, private ModulAjarGenerator $generator) {}

    private function teacherId(): int
    {
        $user = auth()->user();
        abort_unless($user && $user->canTeach(), 403, 'Akses hanya untuk tenaga pengajar.');
        $teacher = $user->teacher();
        abort_unless($teacher, 403, 'Data GTK belum ditautkan.');
        return (int) $teacher->id;
    }

    private function ownedModule(int $id, int $teacherId): object
    {
        $module = DB::table('modul_ajar')->where('id', $id)->where('teacher_id', $teacherId)->first();
        abort_unless($module, 404, 'Modul Ajar tidak ditemukan.');
        return $module;
    }

    private function scope(int $teacherId, int $subjectId, int $grade): void
    {
        abort_unless($subjectId > 0 && $grade > 0, 422, 'Mata pelajaran dan kelas wajib dipilih.');
        $subjects = $this->atp->teacherSubjects($teacherId)->pluck('id')->map(fn ($v) => (int) $v);
        abort_unless($subjects->contains($subjectId), 403, 'Mata pelajaran bukan penugasan Anda.');
        $grades = $this->atp->teacherGradesForSubject($teacherId, $subjectId)->map(fn ($v) => (int) $v);
        abort_unless($grades->contains($grade), 403, 'Tingkat kelas bukan penugasan Anda.');
    }

    private function atpOption(int $teacherId, int $subjectId, int $grade, ?int $atpId): ?object
    {
        if (!$atpId) { return null; }
        $item = $this->atp->items($teacherId, $subjectId, $grade)->firstWhere('id', $atpId);
        abort_unless($item, 422, 'ATP tidak sesuai mapel, kelas, guru, atau semester.');
        return $item;
    }

    public function index(Request $request): View { return $this->render($request); }

    public function edit(Request $request, int $id): View
    {
        $module = $this->ownedModule($id, $this->teacherId());
        return $this->render($request, $module);
    }

    private function render(Request $request, ?object $module = null): View
    {
        $teacherId = $this->teacherId();
        $subjects = $this->atp->teacherSubjects($teacherId);
        $subjectId = $module ? (int) $module->subject_id : (int) $request->query('subject', ($subjects->first()->id ?? 0));
        if ($subjectId > 0) {
            abort_unless($subjects->contains('id', $subjectId), 403);
        }
        $grades = $subjectId ? $this->atp->teacherGradesForSubject($teacherId, $subjectId) : collect();
        $grade = $module ? (int) $module->grade_level : (int) $request->query('grade', ($grades->first() ?? 0));
        if ($subjectId && $grade) { $this->scope($teacherId, $subjectId, $grade); }
        $atpItems = ($subjectId && $grade) ? $this->atp->items($teacherId, $subjectId, $grade) : collect();
        $modules = DB::table('modul_ajar')->where('teacher_id', $teacherId)->orderByDesc('updated_at')->limit(60)->get();
        $subjectName = (string) ($subjects->firstWhere('id', $subjectId)->nama_mata_pelajaran ?? '');
        $content = $module ? (json_decode((string) $module->content, true) ?: []) : [];
        $teacher = auth()->user()->teacher();
        return view('guru.modul-ajar.index', compact('subjects', 'subjectId', 'grades', 'grade', 'atpItems', 'modules', 'subjectName', 'content', 'teacher', 'module'))
            ->with('aiProvider', $this->generator->provider());
    }

    private function validated(Request $request, int $teacherId): array
    {
        $data = $request->validate([
            'subject_id' => 'required|integer|min:1',
            'grade_level' => 'required|integer|between:1,13',
            'atp_item_id' => 'nullable|integer|min:1',
            'title' => 'required|string|max:255',
            'duration_jp' => 'required|integer|between:1,60',
            'content' => 'nullable|array',
            'content.*' => 'nullable',
        ]);
        $subjectId = (int) $data['subject_id']; $grade = (int) $data['grade_level'];
        $this->scope($teacherId, $subjectId, $grade);
        $atp = $this->atpOption($teacherId, $subjectId, $grade, isset($data['atp_item_id']) ? (int) $data['atp_item_id'] : null);
        $content = [];
        foreach (ModulAjarGenerator::FIELDS as $key) {
            $value = $request->input('content.'.$key, '');
            abort_unless(is_string($value) && mb_strlen($value) <= 8000, 422, 'Konten bidang '.$key.' tidak valid.');
            $content[$key] = trim($value);
        }
        foreach (['dimensi' => array_keys(ModulAjarGenerator::DIMENSIONS), 'prinsip' => ['Berkesadaran', 'Bermakna', 'Menggembirakan']] as $key => $allowed) {
            $values = $request->input('content.'.$key, []);
            abort_unless(is_array($values), 422, 'Pilihan '.$key.' tidak valid.');
            $content[$key] = array_values(array_unique(array_intersect($allowed, $values)));
        }
        return [
            'subject_id' => $subjectId, 'grade_level' => $grade,
            'atp_item_id' => $atp ? (int) $atp->id : null,
            'title' => trim($data['title']), 'phase' => $this->phase($grade),
            'duration_jp' => (int) $data['duration_jp'],
            'semester_id' => $this->atp->semesterId(),
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
    }

    private function phase(int $grade): string
    {
        return match (true) {
            $grade <= 2 => 'A', $grade <= 4 => 'B', $grade <= 6 => 'C',
            $grade <= 9 => 'D', $grade == 10 => 'E', default => 'F',
        };
    }

    public function store(Request $request): RedirectResponse
    {
        $teacherId = $this->teacherId();
        $row = $this->validated($request, $teacherId);
        $row += ['teacher_id' => $teacherId, 'status' => 'draft',
            'generation_source' => $this->source($request), 'created_at' => now(), 'updated_at' => now()];
        $id = DB::table('modul_ajar')->insertGetId($row);
        return redirect()->route('guru.modul-ajar.edit', $id)->with('success', 'Draf Modul Ajar tersimpan pada laptop ini.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $teacherId = $this->teacherId();
        $module = $this->ownedModule($id, $teacherId);
        $row = $this->validated($request, $teacherId);
        $row['generation_source'] = $this->source($request, (string) $module->generation_source);
        $row['updated_at'] = now();
        DB::table('modul_ajar')->where('id', $id)->where('teacher_id', $teacherId)->update($row);
        return redirect()->route('guru.modul-ajar.edit', $id)->with('success', 'Perubahan Modul Ajar berhasil disimpan.');
    }

    private function source(Request $request, string $default = 'manual'): string
    {
        $source = (string) $request->input('generation_source', $default);
        return in_array($source, ['manual','offline','ai_ollama','ai_openai'], true) ? $source : $default;
    }

    public function destroy(int $id): RedirectResponse
    {
        $teacherId = $this->teacherId();
        $this->ownedModule($id, $teacherId);
        DB::table('modul_ajar')->where('id', $id)->where('teacher_id', $teacherId)->delete();
        return redirect()->route('guru.modul-ajar.index')->with('success', 'Modul Ajar dihapus dari penyimpanan lokal.');
    }

    public function duplicate(int $id): RedirectResponse
    {
        $teacherId = $this->teacherId();
        $module = $this->ownedModule($id, $teacherId);
        $row = (array) $module;
        unset($row['id']);
        $row['title'] = mb_substr($row['title'], 0, 240).' (Salinan)';
        $row['generation_source'] = 'manual'; $row['status'] = 'draft';
        $row['created_at'] = now(); $row['updated_at'] = now();
        $copyId = DB::table('modul_ajar')->insertGetId($row);
        return redirect()->route('guru.modul-ajar.edit', $copyId)->with('success', 'Modul Ajar berhasil diduplikasi.');
    }

    public function print(int $id): View
    {
        $module = $this->ownedModule($id, $this->teacherId());
        $content = json_decode((string) $module->content, true) ?: [];
        $subject = DB::table('subjects')->where('id', $module->subject_id)->value('nama_mata_pelajaran');
        $teacher = auth()->user()->teacher();
        return view('guru.modul-ajar.print', compact('module', 'content', 'subject', 'teacher'));
    }

    public function generate(Request $request): JsonResponse
    {
        $teacherId = $this->teacherId();
        $data = $request->validate([
            'subject_id' => 'required|integer|min:1',
            'grade_level' => 'required|integer|between:1,13',
            'duration_jp' => 'required|integer|between:1,60',
            'title' => 'required|string|max:255',
            'atp_item_id' => 'nullable|integer|min:1',
        ]);
        $this->scope($teacherId, (int) $data['subject_id'], (int) $data['grade_level']);
        $atp = $this->atpOption($teacherId, (int) $data['subject_id'], (int) $data['grade_level'],
            isset($data['atp_item_id']) ? (int) $data['atp_item_id'] : null);
        $data['subject_name'] = (string) DB::table('subjects')->where('id', $data['subject_id'])->value('nama_mata_pelajaran');
        try {
            return response()->json($this->generator->create($data, $atp));
        } catch (Throwable $e) {
            Log::warning('[Modul Ajar] Gagal menyusun otomatis', ['provider' => $this->generator->provider(), 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Penyusunan otomatis gagal: '.$e->getMessage()], 503);
        }
    }
}
