<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Update\ReleaseVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class UpdateLanController extends Controller
{
    public function __construct(
        protected ReleaseVerifier $verifier,
    ) {
    }

    public function latest(Request $request): JsonResponse
    {
        $edition = strtolower(trim((string) $request->query('edition', 'teacher')));
        $current = trim((string) $request->query('current_version', '0.0.0'));
        $channel = trim((string) $request->query('channel', 'stable'));

        if (!in_array($edition, ['school', 'teacher'], true)) {
            return response()->json(['message' => 'Edition tidak valid.'], 422);
        }

        $release = $this->findLatest($edition, $current, $channel);

        return response()->json([
            'application' => 'eduXid',
            'update_available' => $release !== null,
            'release' => $release,
        ]);
    }

    public function download(Request $request, string $version): BinaryFileResponse|JsonResponse
    {
        if (preg_match('/^\d+\.\d+\.\d+(-[A-Za-z0-9._-]+)?$/', $version) !== 1) {
            return response()->json(['message' => 'Versi tidak valid.'], 422);
        }

        $root = rtrim((string) config('eduxid.install_root'), '\\/');
        $path = $root . DIRECTORY_SEPARATOR . 'eduxid-patch-' . $version . '.zip';

        if (!is_file($path)) {
            return response()->json(['message' => 'Patch tidak ditemukan.'], 404);
        }

        try {
            $manifest = $this->verifier->verify($path, 'teacher');
            if ((string) ($manifest['version'] ?? '') !== $version) {
                return response()->json(['message' => 'Manifest patch tidak cocok.'], 409);
            }
        } catch (Throwable $e) {
            Log::warning('Patch LAN ditolak.', ['version' => $version, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Patch gagal diverifikasi.'], 409);
        }

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function findLatest(string $edition, string $current, string $channel): ?array
    {
        $root = rtrim((string) config('eduxid.install_root'), '\\/');
        $files = glob($root . DIRECTORY_SEPARATOR . 'eduxid-patch-*.zip') ?: [];
        $candidates = [];

        foreach ($files as $file) {
            try {
                $manifest = $this->verifier->verify($file, $edition);
                $version = (string) ($manifest['version'] ?? '');

                if ($version === '' || version_compare($version, $current, '<=')) {
                    continue;
                }

                if ((string) ($manifest['channel'] ?? 'stable') !== $channel) {
                    continue;
                }

                $candidates[] = [
                    'version' => $version,
                    'channel' => (string) ($manifest['channel'] ?? 'stable'),
                    'released_at' => $manifest['released_at'] ?? null,
                    'components' => $manifest['components'] ?? [],
                    'download_url' => route('local.update.download', ['version' => $version], false),
                ];
            } catch (Throwable $e) {
                Log::warning('Release root tidak ditawarkan melalui LAN.', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => version_compare($b['version'], $a['version']));
        return $candidates[0];
    }
}
