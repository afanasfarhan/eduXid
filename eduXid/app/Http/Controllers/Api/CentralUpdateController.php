<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CentralUpdateAccessVerifier;
use App\Services\UpdateReleasePublisherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CentralUpdateController extends Controller
{
    public function __construct(
        protected UpdateReleasePublisherService $releases,
        protected CentralUpdateAccessVerifier $access
    ) {}

    public function check(Request $request): JsonResponse
    {
        try {
            $auth = $this->access->verify($request);
            $application = trim((string) $request->query('application', ''));
            $edition = strtolower(trim((string) $request->query('edition', '')));
            $channel = strtolower(trim((string) $request->query('channel', 'stable')));
            $currentVersion = trim((string) $request->query('current_version', '0.0.0'));

            if ($application !== (string) config('eduxid.name', 'eduXid')) throw new RuntimeException('Application tidak valid.');
            if ($edition !== 'school') throw new RuntimeException('Central update hanya melayani edition school.');

            $this->assertChannel($channel);
            $this->assertVersion($currentVersion);
            $manifest = $this->latestReleaseForSchool($currentVersion, $channel);

            return response()->json([
                'success' => true, 'schema' => 'EDUXID-UPDATE-CHECK-V1',
                'application' => (string) config('eduxid.name', 'eduXid'),
                'school_id' => $auth['school_id'], 'channel' => $channel,
                'current_version' => $currentVersion, 'release' => $manifest,
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'UPDATE_CHECK_FAILED');
        }
    }

    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        try {
            $this->access->verify($request);
            $version = trim((string) $request->query('version', ''));
            $target = strtolower(trim((string) $request->query('target', '')));
            $channel = strtolower(trim((string) $request->query('channel', 'stable')));

            $this->assertVersion($version);
            $this->assertChannel($channel);
            if (!in_array($target, ['core', 'school'], true)) throw new RuntimeException('Target download central tidak valid.');

            $manifest = collect($this->releases->manifests())->first(
                fn(array $item): bool => trim((string) ($item['version'] ?? '')) === $version
                    && strtolower(trim((string) ($item['target'] ?? ''))) === $target
            );
            if (!is_array($manifest)) throw new RuntimeException('Signed release tidak ditemukan.');

            $this->assertManifestChannel($manifest, $channel);
            $releaseFile = basename((string) ($manifest['file'] ?? ''));
            $expectedFile = 'patch_' . $version . '_' . $target . '.zip';
            if ($releaseFile !== $expectedFile) throw new RuntimeException('Nama file signed release tidak sesuai.');

            $releaseDir = (string) config('eduxid.release_path', storage_path('app/releases'));
            $path = $releaseDir . DIRECTORY_SEPARATOR . $releaseFile;
            if (!is_file($path) || filesize($path) <= 0) throw new RuntimeException('File signed release tidak tersedia.');

            $expectedSha = strtolower(trim((string) ($manifest['sha256'] ?? '')));
            $actualSha = strtolower((string) hash_file('sha256', $path));
            if ($expectedSha === '' || !hash_equals($expectedSha, $actualSha)) throw new RuntimeException('Integritas signed release gagal.');

            return response()->download($path, $releaseFile, [
                'Content-Type' => 'application/zip', 'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'UPDATE_DOWNLOAD_FAILED');
        }
    }

    protected function errorResponse(Throwable $e, string $fallback): JsonResponse
    {
        $status = (int) $e->getCode();
        if (!in_array($status, [400,401,403,404,422,503], true)) $status = 400;
        $code = match ($status) {
            401 => 'UPDATE_AUTH_REQUIRED', 403 => 'UPDATE_ACCESS_FORBIDDEN',
            503 => 'UPDATE_AUTH_UNAVAILABLE', default => $fallback,
        };
        $message = in_array($status, [401,403], true) ? 'Akses update tidak diizinkan.' : $e->getMessage();
        return response()->json(['success'=>false,'error'=>['code'=>$code,'message'=>$message]], $status, ['Cache-Control'=>'no-store']);
    }

    protected function latestReleaseForSchool(string $currentVersion, string $channel): ?array
    {
        $candidates = [];
        foreach ($this->releases->manifests() as $manifest) {
            $version = trim((string) ($manifest['version'] ?? ''));
            $target = strtolower(trim((string) ($manifest['target'] ?? '')));
            if (!in_array($target, ['core','school'], true)) continue;
            if (version_compare($version, $currentVersion, '<=')) continue;
            if (!$this->manifestBelongsToChannel($manifest, $channel)) continue;
            $candidates[] = $manifest;
        }
        if ($candidates === []) return null;
        usort($candidates, fn(array $a,array $b): int => version_compare((string)$b['version'], (string)$a['version']));
        return $candidates[0];
    }

    protected function manifestBelongsToChannel(array $manifest, string $channel): bool
    {
        return strtolower(trim((string)($manifest['channel'] ?? 'stable'))) === $channel;
    }
    protected function assertManifestChannel(array $manifest, string $channel): void
    {
        if (!$this->manifestBelongsToChannel($manifest,$channel)) throw new RuntimeException('Release tidak tersedia pada channel tersebut.');
    }
    protected function assertChannel(string $channel): void
    {
        if (!in_array($channel,['stable','beta','dev'],true)) throw new RuntimeException('Update channel tidak valid.');
    }
    protected function assertVersion(string $version): void
    {
        if (preg_match('/^\\d+\\.\\d+\\.\\d+(-[A-Za-z0-9._-]+)?$/',$version)!==1) throw new RuntimeException('Versi update tidak valid.');
    }
}
