<?php

namespace App\Http\Controllers;

use App\Services\UpdateReleasePublisherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LocalUpdateReleaseController extends Controller
{
    public function checkVersion(UpdateReleasePublisherService $releases): JsonResponse
    {
        $items = $releases->manifests();

        $latest = (string) config('eduxid.version', '1.0.0');

        foreach ($items as $item) {
            $version = (string) ($item['version'] ?? '');
            if ($version !== '' && version_compare($version, $latest, '>')) {
                $latest = $version;
            }
        }

        return response()->json([
            'application' => (string) config('eduxid.name', 'eduXid'),
            'latest_version' => $latest,
            'signature_required' => true,
            'signature_algorithm' => 'Ed25519',
            'patches' => $items,
        ]);
    }

    public function downloadPatch(
        Request $request,
        UpdateReleasePublisherService $releases
    ): BinaryFileResponse|JsonResponse {
        $version = trim((string) $request->query('version'));
        $target = strtolower(trim((string) $request->query('target')));

        if (preg_match('/^\d+\.\d+\.\d+(-[A-Za-z0-9._-]+)?$/', $version) !== 1) {
            return response()->json(['message' => 'Format versi tidak valid.'], 400);
        }

        if (!in_array($target, ['core', 'school', 'teacher'], true)) {
            return response()->json(['message' => 'Target release tidak valid.'], 400);
        }

        $manifest = null;

        foreach ($releases->manifests() as $item) {
            if (
                (string) ($item['version'] ?? '') === $version
                && (string) ($item['target'] ?? '') === $target
            ) {
                $manifest = $item;
                break;
            }
        }

        if (!$manifest) {
            return response()->json(['message' => 'Signed release tidak ditemukan.'], 404);
        }

        $releaseDir = (string) config('eduxid.release_path', storage_path('app/releases'));
        $file = basename((string) ($manifest['file'] ?? ''));
        $path = $releaseDir . DIRECTORY_SEPARATOR . $file;

        if ($file === '' || !is_file($path)) {
            return response()->json(['message' => 'File signed release tidak ditemukan.'], 404);
        }

        $actual = strtolower((string) hash_file('sha256', $path));

        if (!hash_equals((string) $manifest['sha256'], $actual)) {
            return response()->json(['message' => 'Integritas signed release gagal.'], 409);
        }

        return response()->download($path, $file, [
            'X-Eduxid-Version' => $version,
            'X-Eduxid-Target' => $target,
            'X-Eduxid-Sha256' => $actual,
        ]);
    }
}
