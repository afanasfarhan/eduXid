<?php

namespace App\Http\Controllers\Guru;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Teacher;
use App\Services\Assessment\ItemAnalysisService;
use App\Services\Assessment\SimplePdfReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ItemAnalysisController extends Controller
{
    public function index(Request $request, Exam $exam, ItemAnalysisService $service)
    {
        $this->authorizeExam($exam);

        $source = $this->source($request);
        $analysis = $service->analyze($exam, $source);
        $latestSnapshot = $service->latestSnapshot($exam, $source);

        return view('guru.item-analysis.index', compact(
            'exam',
            'analysis',
            'source',
            'latestSnapshot'
        ));
    }

    public function snapshot(Request $request, Exam $exam, ItemAnalysisService $service)
    {
        $this->authorizeExam($exam);

        $source = $this->source($request);
        $analysis = $service->analyze($exam, $source);
        $runId = $service->saveSnapshot($exam, $analysis);

        return redirect()
            ->route('guru.item-analysis.index', ['exam' => $exam->id, 'source' => $source])
            ->with('success', 'Snapshot Analisis Butir berhasil disimpan. ID: ' . $runId);
    }

    public function pdf(
        Request $request,
        Exam $exam,
        ItemAnalysisService $service,
        SimplePdfReport $fallback
    ): Response {
        $this->authorizeExam($exam);

        $source = $this->source($request);
        $analysis = $service->analyze($exam, $source);
        $title = (string) ($analysis['meta']['exam_title'] ?? ('Paket Ujian ' . $exam->id));
        $filename = 'Analisis-Butir-' . Str::slug($title ?: ('ujian-' . $exam->id)) . '-' . date('Y-m-d') . '.pdf';

        $pdfBytes = null;

        if (class_exists(\Dompdf\Dompdf::class)) {
            try {
                $options = class_exists(\Dompdf\Options::class)
                    ? new \Dompdf\Options()
                    : null;

                if ($options) {
                    $options->set('isRemoteEnabled', false);
                    $options->set('isHtml5ParserEnabled', true);
                    $dompdf = new \Dompdf\Dompdf($options);
                } else {
                    $dompdf = new \Dompdf\Dompdf();
                }

                $html = view('guru.item-analysis.pdf', compact('analysis'))->render();
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('A4', 'landscape');
                $dompdf->render();
                $pdfBytes = $dompdf->output();
            } catch (\Throwable $e) {
                report($e);
                $pdfBytes = null;
            }
        }

        if (!is_string($pdfBytes) || $pdfBytes === '') {
            $pdfBytes = $fallback->render($analysis);
        }

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string) strlen($pdfBytes),
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function authorizeExam(Exam $exam): Teacher
    {
        $teacher = $this->currentTeacher();

        abort_unless(
            $teacher,
            403,
            'Akun Anda tidak terhubung dengan data Guru.'
        );

        abort_unless(
            (int) $exam->teacher_id === (int) $teacher->id,
            403,
            'Paket ujian ini bukan milik Anda.'
        );

        return $teacher;
    }

    private function currentTeacher(): ?Teacher
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $teacher = Teacher::query()->where('user_id', $user->id)->first();
        if ($teacher) {
            return $teacher;
        }

        $loginId = trim((string) ($user->login_id ?? $user->username ?? ''));
        if ($loginId === '') {
            return null;
        }

        return Teacher::query()
            ->where(function ($query) use ($loginId) {
                $query->where('nip', $loginId)
                    ->orWhere('nik', $loginId)
                    ->orWhere('nuptk', $loginId);
            })
            ->first();
    }

    private function source(Request $request): string
    {
        $source = strtolower(trim((string) $request->input('source', 'combined')));
        return in_array($source, ['combined', 'cbt', 'ljk'], true)
            ? $source
            : 'combined';
    }
}
