<?php

declare(strict_types=1);

namespace App\Services\Sync;

use EduXid\Bootstrap\SchoolAliasSealV21R110;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * V21R114: authenticate Admin to Cloud and compare the existing D1 epoch
 * without asking operators to export JSON/handle keys. Fail closed on 401,
 * 409, network errors and mismatched pinned identity. No D1 writes.
 */
final class CloudSchoolIdentityAutoBindV21R114
{
    public static function ensure(string $schoolId): void
    {
        require_once base_path('bootstrap/SchoolAliasSealV21R110.php');
        $project = base_path();
        $state = SchoolAliasSealV21R110::state($project);
        if ($state === 'COCOK_DENGAN_SEGEL_CLOUD') return;
        if ($state !== 'SEGEL_AWAL_PERLU_EPOCH_CLOUD') {
            throw new RuntimeException('BLOCKED V21R114: segel Admin perlu dipulihkan sebelum publikasi.');
        }
        if (preg_match('/^[0-9]{8}$/D', $schoolId) !== 1) {
            throw new RuntimeException('BLOCKED V21R114: NPSN belum valid.');
        }
        $server = trim(EduxidCloudConfig::syncServer());
        // Fresh installations may use EDUXID_SYNC_SERVER before any
        // eduxid_settings row exists; match the existing bundle service.
        if ($server === '') {
            $server = trim((string) config('eduxid.sync_server', env('EDUXID_SYNC_SERVER', '')));
        }
        $token = trim(EduxidCloudConfig::adminToken());
        if ($server === '' || !str_starts_with(strtolower($server), 'https://') || $token === '') {
            throw new RuntimeException('BLOCKED V21R114: konfigurasi Admin Cloud belum siap. Buka Pengaturan > Setup Cloud.');
        }

        $request = Http::acceptJson()->timeout(12)->withHeaders([
            'Authorization' => 'Admin ' . $token,
            'X-School-Id' => $schoolId,
        ]);
        $ca = trim((string) config('eduxid.ca_cert', ''));
        if ($ca !== '' && is_file($ca)) {
            $request = $request->withOptions(['verify' => $ca]);
        }
        try {
            $response = $request->get(rtrim($server, '/') . '/api/admin/directory/identity-status');
        } catch (\Throwable) {
            throw new RuntimeException('BLOCKED V21R114: Cloud belum dapat diverifikasi. Tetap offline sampai koneksi tersedia.');
        }
        if (!$response->successful()) {
            $status = $response->status();
            throw new RuntimeException('BLOCKED V21R114: verifikasi identitas Admin ditolak Cloud (HTTP ' . $status . '). Periksa Setup Cloud; jangan reset D1.');
        }
        $data = $response->json('data');
        if (!is_array($data) || (string) ($data['school_id'] ?? '') !== $schoolId) {
            throw new RuntimeException('BLOCKED V21R114: respons identitas sekolah dari Cloud tidak cocok.');
        }
        $secret = SchoolAliasSealV21R110::adminSecret($project);
        $localEpoch = SchoolAliasSealV21R110::epoch($secret, $schoolId);
        if (($data['state'] ?? '') === 'pinned') {
            $remoteEpoch = (string) ($data['identity_epoch'] ?? '');
            if (!hash_equals($localEpoch, $remoteEpoch)) {
                throw new RuntimeException('BLOCKED V21R114: epoch sekolah di Cloud berbeda. Pulihkan identitas asli; kode pairing bukan izin merotasi identitas sekolah.');
            }
        } elseif (($data['state'] ?? '') !== 'new' || ($data['identity_epoch'] ?? null) !== null) {
            throw new RuntimeException('BLOCKED V21R114: Cloud tidak menyatakan sekolah baru yang kosong.');
        }
        // For 'new', Cloud verifies absence of prior pinned epoch and accounts,
        // and the first directory push will atomically pin the same epoch.
        SchoolAliasSealV21R110::bindFromCloud($project, $schoolId, $localEpoch);
    }
}
