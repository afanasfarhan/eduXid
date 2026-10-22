<?php

namespace App\Http\Controllers\Guru;

use App\Http\Controllers\Controller;
use App\Services\Assessment\ManualLjkItemAnalysisService;
use App\Services\Assessment\SimplePdfReport;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ManualLjkItemAnalysisController extends Controller
{
    public function index(string $session, ManualLjkItemAnalysisService $service)
    {
        $analysis = $service->analyze($session); // ownership diterapkan di service
        $source = 'ljk';
        $exam = null;
        $manualSession = $session;
        $latestSnapshot = null;

        return view('guru.item-analysis.index', compact('analysis', 'source', 'exam', 'manualSession', 'latestSnapshot'));
    }

    public function pdf(string $session, ManualLjkItemAnalysisService $service, SimplePdfReport $fallback): Response
    {
        $analysis = $service->analyze($session); // ownership diterapkan di service
        $title = (string) ($analysis['meta']['exam_title'] ?? 'LJK-Manual');
        $filename = 'Analisis-Butir-Manual-' . Str::slug($title) . '-' . date('Y-m-d') . '.pdf';
        $pdfBytes = null;

        if (class_exists(\Dompdf\Dompdf::class)) {
            try {
                $options = new \Dompdf\Options();
                $options->set('isRemoteEnabled', false);
                $options->set('isHtml5ParserEnabled', true);
                $dompdf = new \Dompdf\Dompdf($options);
                $dompdf->loadHtml(view('guru.item-analysis.pdf', compact('analysis'))->render(), 'UTF-8');
                $dompdf->setPaper('A4', 'landscape');
                $dompdf->render();
                $pdfBytes = $dompdf->output();
            } catch (\Throwable $e) {
                report($e);
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
}
