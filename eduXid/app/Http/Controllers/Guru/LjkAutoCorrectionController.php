<?php

namespace App\Http\Controllers\Guru;

use App\Http\Controllers\Controller;
use App\Services\Ljk\LjkAutoCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class LjkAutoCorrectionController extends Controller
{
    public function __construct(protected LjkAutoCorrectionService $service)
    {
    }

    public function index(Request $request)
    {
        $exams = $this->service->exams();
        $requestedExam = $request->integer('exam');
        $initialExamId = $requestedExam > 0 && $exams->contains(
            fn ($exam) => (int) $exam->id === $requestedExam && (bool) ($exam->ljk_compatible ?? false)
        ) ? $requestedExam : null;

        $tools = $this->toolRoutes();
        $templateTool = collect($tools)->first(fn (array $tool) => $tool['type'] === 'template');
        return view('guru.ljk-auto.index', compact(
            'exams',
            'initialExamId',
            'templateTool'
        ));
    }

    /**
     * Ambil fungsi khusus dari modul LJK lama TANPA membuka halaman koreksi lama.
     *
     * Yang dipertahankan:
     * - Template / Cetak LJK
     * - Kalibrasi
     *
     * Halaman scanner/koreksi lama ditangani oleh route redirect v14R5.
     */
    private function toolRoutes(): array
    {
        $items = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (!in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = trim((string) $route->uri(), '/');
            $name = trim((string) ($route->getName() ?? ''));
            $action = trim((string) $route->getActionName());
            $haystack = strtolower($uri . ' ' . $name . ' ' . $action);

            if (!str_contains($haystack, 'ljk')) {
                continue;
            }

            if (
                str_starts_with($uri, 'guru/ljk-auto')
                || str_starts_with($name, 'guru.ljk-auto.')
                || str_contains($haystack, 'legacy.redirect')
                || str_contains($haystack, 'api/')
            ) {
                continue;
            }

            $type = null;
            $score = 0;

            if (str_contains($haystack, 'kalibr') || str_contains($haystack, 'calibr')) {
                $type = 'calibration';
                $score += 100;
            } elseif (
                str_contains($haystack, 'template')
                || str_contains($haystack, 'cetak')
                || str_contains($haystack, 'print')
            ) {
                $type = 'template';
                $score += 100;
            } else {
                continue;
            }

            // Route dengan parameter wajib tidak dapat dipanggil dari tombol global.
            if (preg_match('/\{[^}]+\}/', $uri)) {
                $required = preg_match_all('/\{([^}?]+)\}/', $uri, $matches);
                if ($required > 0) {
                    continue;
                }
            }

            if ($name !== '') $score += 10;
            if (str_contains($haystack, 'download')) $score += 4;
            if (str_contains($haystack, 'pdf')) $score += 3;

            $url = null;

            if ($name !== '') {
                try {
                    $url = route($name);
                } catch (Throwable) {
                    $url = null;
                }
            }

            if (!$url) {
                $cleanUri = preg_replace('~/\{[^}]+\?\}~', '', $uri) ?: $uri;
                $url = url($cleanUri);
            }

            $items[] = [
                'type' => $type,
                'label' => $type === 'template' ? 'Template LJK' : 'Kalibrasi',
                'url' => $url,
                'route_name' => $name,
                'uri' => $uri,
                'score' => $score,
            ];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return strcmp($a['type'], $b['type']);
            }
            return ($b['score'] <=> $a['score']) ?: strcmp($a['uri'], $b['uri']);
        });

        $best = [];
        foreach ($items as $item) {
            if (!isset($best[$item['type']])) {
                unset($item['score']);
                $best[$item['type']] = $item;
            }
        }

        return array_values($best);
    }

    public function package(int $exam): JsonResponse
    {
        return $this->guard(fn () => $this->service->package($exam));
    }

    public function saveManualKey(Request $request, int $exam): JsonResponse
    {
        return $this->guard(fn () => $this->service->saveManualKeys($exam, $request->only(['source', 'keys'])));
    }

    public function results(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->service->results($request->integer('exam_id') ?: null));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $payload = $request->all();
            foreach (['answers', 'answer_confidence'] as $field) {
                if (isset($payload[$field]) && is_string($payload[$field])) {
                    $decoded = json_decode($payload[$field], true);
                    if (is_array($decoded)) $payload[$field] = $decoded;
                }
            }
            return $this->service->saveScan($payload, $request->file('image'));
        });
    }

    public function update(Request $request, string $scanId): JsonResponse
    {
        return $this->guard(function () use ($request, $scanId) {
            $payload = $request->all();
            if (isset($payload['answers']) && is_string($payload['answers'])) {
                $payload['answers'] = json_decode($payload['answers'], true) ?: [];
            }
            return $this->service->review($scanId, $payload);
        });
    }

    public function clearAll(int $exam): JsonResponse
    {
        return $this->guard(fn () => $this->service->clearExamResults($exam));
    }

    public function destroy(string $scanId): JsonResponse
    {
        return $this->guard(function () use ($scanId) {
            $this->service->delete($scanId);
            return ['deleted' => true];
        });
    }

    public function image(string $scanId)
    {
        try {
            return response()->file($this->service->imagePath($scanId), ['Cache-Control' => 'private, no-store']);
        } catch (Throwable $e) {
            abort(404, $e->getMessage());
        }
    }

    public function publish(Request $request, int $exam): JsonResponse
    {
        return $this->guard(function () use ($request, $exam) {
            $scanIds = $request->input('scan_ids', []);
            return $this->service->publish($exam, is_array($scanIds) ? $scanIds : []);
        });
    }

    public function deleteManualKey(int $exam): JsonResponse
    {
        return $this->guard(function () use ($exam) {
            $config = $this->service->deleteManualKeys($exam);
            // Supaya PWA Guru tidak tetap menggunakan kunci manual lama.
            try {
                $config['pwa_sync'] = $this->service->syncPrivatePackageToPwa($exam);
            } catch (Throwable $error) {
                report($error);
                $config['pwa_sync_warning'] = $error->getMessage();
            }
            return $config;
        });
    }

    public function syncPwa(int $exam): JsonResponse
    {
        return $this->guard(fn () => $this->service->syncPrivatePackageToPwa($exam));
    }

    private function importRoot(string $batch): string
    {
        return storage_path('app/private/ljk-import/' . $batch);
    }

    private function cleanupImports(): void
    {
        $root = storage_path('app/private/ljk-import');
        if (!is_dir($root)) return;
        foreach (File::directories($root) as $dir) {
            if (@filemtime($dir) !== false && @filemtime($dir) < time() - 86400) {
                File::deleteDirectory($dir);
            }
        }
    }

    public function importZip(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:204800']]);
        return $this->guard(function () use ($request) {
            $this->cleanupImports();
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException('Ekstensi ZIP PHP belum tersedia pada PHP bundled eduXid.');
            }

            $zip = new ZipArchive();
            if ($zip->open($request->file('file')->getRealPath()) !== true) {
                throw new RuntimeException('File ZIP tidak dapat dibuka.');
            }

            $batch = (string) Str::uuid();
            $dir = $this->importRoot($batch);
            File::ensureDirectoryExists($dir);
            $items = [];

            for ($i = 0; $i < $zip->numFiles && count($items) < 250; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) continue;
                $original = str_replace('\\', '/', (string) $stat['name']);
                $base = basename($original);
                $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) continue;

                $bytes = $zip->getFromIndex($i);
                if ($bytes === false || strlen($bytes) > 20 * 1024 * 1024) continue;
                $safe = sprintf('%04d-%s', count($items) + 1, preg_replace('/[^A-Za-z0-9._-]/', '_', $base));
                file_put_contents($dir . DIRECTORY_SEPARATOR . $safe, $bytes);
                $items[] = ['name' => $base, 'stored' => $safe];
            }
            $zip->close();

            usort($items, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
            foreach ($items as $i => &$item) {
                $item['index'] = $i + 1;
                $item['url'] = route('guru.ljk-auto.import-file', ['batch' => $batch, 'name' => $item['stored']]);
                unset($item['stored']);
            }

            if ($items === []) throw new RuntimeException('ZIP tidak berisi file JPG/JPEG/PNG yang dapat diproses.');
            return ['batch' => $batch, 'items' => $items];
        });
    }

    public function importPdf(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:204800']]);
        return $this->guard(function () use ($request) {
            $this->cleanupImports();
            $batch = (string) Str::uuid();
            $dir = $this->importRoot($batch);
            File::ensureDirectoryExists($dir);
            $pdf = $dir . DIRECTORY_SEPARATOR . 'scan.pdf';
            $request->file('file')->move($dir, 'scan.pdf');
            $generated = [];

            if (class_exists(\Imagick::class)) {
                $im = new \Imagick();
                $im->setResolution(180, 180);
                $im->readImage($pdf);
                $page = 1;
                foreach ($im as $frame) {
                    $frame->setImageFormat('png');
                    $target = $dir . DIRECTORY_SEPARATOR . sprintf('page-%04d.png', $page++);
                    $frame->writeImage($target);
                    $generated[] = $target;
                }
                $im->clear();
            } elseif (function_exists('exec')) {
                $candidates = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                    ? [
                        ['pdftoppm', '-png', '-r', '180', $pdf, $dir . DIRECTORY_SEPARATOR . 'page'],
                        ['magick', '-density', '180', $pdf, $dir . DIRECTORY_SEPARATOR . 'page-%04d.png'],
                    ]
                    : [
                        ['pdftoppm', '-png', '-r', '180', $pdf, $dir . DIRECTORY_SEPARATOR . 'page'],
                        ['convert', '-density', '180', $pdf, $dir . DIRECTORY_SEPARATOR . 'page-%04d.png'],
                    ];

                foreach ($candidates as $parts) {
                    $exe = array_shift($parts);
                    $where = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                        ? @shell_exec('where ' . escapeshellarg($exe) . ' 2>NUL')
                        : @shell_exec('command -v ' . escapeshellarg($exe) . ' 2>/dev/null');
                    if (!$where) continue;
                    $cmd = escapeshellarg($exe) . ' ' . implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
                    $out = [];
                    @exec($cmd, $out, $code);
                    if ($code === 0) {
                        $generated = glob($dir . DIRECTORY_SEPARATOR . 'page*.png') ?: [];
                        if ($generated !== []) break;
                    }
                }
            }

            if ($generated === []) {
                throw new RuntimeException('Rasterizer PDF server tidak tersedia dan PDF.js browser belum berhasil digunakan. Pastikan patch v14R12 memasang public/pdfjs/pdf.min.js dan pdf.worker.min.js; bila pemasangan library gagal karena offline, sambungkan internet sekali lalu jalankan installer v14R12 kembali.');
            }

            natsort($generated);
            $items = [];
            foreach (array_values($generated) as $i => $path) {
                $name = basename($path);
                $items[] = [
                    'name' => 'Halaman ' . ($i + 1),
                    'index' => $i + 1,
                    'url' => route('guru.ljk-auto.import-file', ['batch' => $batch, 'name' => $name]),
                ];
            }
            return ['batch' => $batch, 'items' => $items];
        });
    }

    public function importFile(string $batch, string $name)
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $batch) || basename($name) !== $name) abort(404);
        $path = $this->importRoot($batch) . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) abort(404);
        return response()->file($path, ['Cache-Control' => 'private, max-age=600']);
    }

    private function guard(callable $callback): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $callback()]);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
