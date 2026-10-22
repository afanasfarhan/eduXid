<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Sync\EduxidCloudConfig;
use Illuminate\Support\Facades\Cache;
use App\Models\SyncDevice;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * V21R37: Cloud owns the expected inventory; local verification is retained
 * offline. The bearer token is never sent to the UI or to integrity logs.
 * Attestation is advisory: a compromised local administrator can spoof it.
 */
final class DonationCloudIntegrityV21
{
    public const POLICY_SCHEMA = 'EDUXID-DONATION-CLOUD-POLICY-V1';
    public const REPORT_SCHEMA = 'EDUXID-DONATION-CLOUD-REPORT-V1';
    private const POLICY_CACHE = 'eduxid:donation:cloud-policy:v21r37';
    private const REPORT_CACHE = 'eduxid:donation:cloud-report:v21r37';

    /** @return array{state:string,errors:list<string>,version?:string} */
    public static function check(string $root, string $mode, array $measured): array
    {
        $policy = self::policy();
        if ($policy === null) return ['state' => 'offline_or_unverified', 'errors' => []];
        $expected = $policy['expected_files'];
        $differ = [];
        foreach ($expected as $file => $hash) {
            if (($measured[$file] ?? 'missing') !== $hash) $differ[] = $file;
        }
        if ($differ && $mode === 'signed') {
            // A locally publisher-signed release may be newer than the Cloud
            // inventory. Keep the signed V21R36 verifier authoritative here.
            return ['state' => 'signed_release_not_in_cloud_policy', 'errors' => [], 'version' => $policy['version']];
        }
        return [
            'state' => $differ ? 'mismatch' : 'match',
            'errors' => array_map(static fn ($f) => 'cloud_mismatch:' . $f, $differ),
            'version' => $policy['version'],
        ];
    }

    /** @return array<string,mixed>|null */
    private static function policy(): ?array
    {
        try {
            $cached = Cache::get(self::POLICY_CACHE);
            if (is_array($cached)) {
                if (($cached['state'] ?? '') === 'offline') return null;
                if (self::validPolicy($cached)) return $cached;
            }
        } catch (Throwable) { /* Laravel cache may be unavailable on startup. */ }

        $base = self::baseUrl();
        if ($base === null) return null;
        try {
            $res = self::http()->get($base . '/api/donation/integrity-policy');
            $body = $res->successful() ? $res->json('data') : null;
            if (is_array($body) && self::validPolicy($body)) {
                try { Cache::put(self::POLICY_CACHE, $body, 600); } catch (Throwable) {}
                return $body;
            }
        } catch (Throwable) {}
        try { Cache::put(self::POLICY_CACHE, ['state' => 'offline'], 60); } catch (Throwable) {}
        return null;
    }

    private static function validPolicy(array $value): bool
    {
        if (($value['schema'] ?? null) !== self::POLICY_SCHEMA
            || !is_string($value['version'] ?? null)
            || ($value['donation_voluntary'] ?? null) !== true
            || ($value['education_features_unrestricted'] ?? null) !== true
            || ($value['reminder_enabled'] ?? null) !== true
            || !is_array($value['expected_files'] ?? null)
        ) return false;
        if (array_keys($value['expected_files']) !== array_keys(DonationIntegrityV21::BASELINE)) return false;
        foreach ($value['expected_files'] as $hash) {
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) return false;
        }
        return true;
    }

    /** @return array<string,string> no identifiers, tokens or source data */
    public static function measuredFiles(string $root): array
    {
        $measured = [];
        foreach (DonationIntegrityV21::BASELINE as $file => $hash) {
            $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
            $actual = is_file($path) && is_readable($path) ? @hash_file('sha256', $path) : false;
            $measured[$file] = is_string($actual) ? strtolower($actual) : 'missing';
        }
        return $measured;
    }

    /**
     * Called by the normal sync daemon once connectivity is available.
     * No pairing modification and no school/teacher PII in the report.
     * @return array{state:string,report:string}
     */
    public static function heartbeat(string $root, bool $connected = true, bool $force = false): array
    {
        if (!$connected) return ['state'=>'offline', 'report'=>'skipped'];
        if (!$force) {
            try {
                if (Cache::get(self::REPORT_CACHE)) return ['state'=>'throttled','report'=>'skipped'];
                Cache::put(self::REPORT_CACHE, true, 900);
            } catch (Throwable) {}
        }
        $manifestPath = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, DonationIntegrityV21::SIGNED_MANIFEST);
        $mode = is_file($manifestPath) ? 'signed' : 'pinned-baseline';
        $measured = self::measuredFiles($root);
        $cloud = self::check($root, $mode, $measured);
        $base = self::baseUrl();
        if ($base === null) return ['state'=>$cloud['state'], 'report'=>'skipped'];
        try {
            // Only the active, already paired alias V21 teacher PC may report.
            $device = SyncDevice::query()
                ->where('device_type', 'teacher')->where('is_active', 1)
                ->where('device_id', 'like', '%-PC')
                ->orderByDesc('last_synced_at')
                ->get(['device_id','school_id','sync_token'])
                ->first(static function ($row) {
                    $id = (string)($row->device_id ?? '');
                    $school = (string)($row->school_id ?? '');
                    if ($school === '' || preg_match('/^'.preg_quote($school, '/').'-t_[a-z2-7]{26}-PC$/D', $id) !== 1) return false;
                    try { return trim((string)$row->sync_token) !== ''; }
                    catch (Throwable) { return false; }
                });
            if (!$device) return ['state'=>$cloud['state'],'report'=>'not_paired'];
            $payload = ['schema' => self::REPORT_SCHEMA, 'mode' => $mode, 'files' => $measured];
            if ($mode === 'signed' && is_file($manifestPath)) {
                $manifest = json_decode((string)@file_get_contents($manifestPath), true);
                // Never claim a signed release without local signature check.
                if (is_array($manifest) && DonationIntegrityV21::verifySignedManifest(
                    $manifest, (string) config('eduxid.update_public_key', '')
                )) $payload['signed_manifest'] = $manifest;
            }
            $response = self::http()->withToken((string)$device->sync_token)
                ->post($base . '/api/donation/integrity/report', $payload);
            return ['state'=>$cloud['state'],'report'=>$response->successful() ? 'submitted' : 'rejected'];
        } catch (Throwable) {
            return ['state'=>$cloud['state'], 'report'=>'unavailable'];
        }
    }

    private static function baseUrl(): ?string
    {
        try { $base = rtrim(EduxidCloudConfig::syncServer(), '/'); }
        catch (Throwable) { $base = rtrim((string)config('eduxid.sync_server', ''), '/'); }
        $parts = parse_url($base);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) return null;
        return $base;
    }

    private static function http(): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::acceptJson()->asJson()->connectTimeout(2)->timeout(4)
            ->withOptions(['allow_redirects' => false]);
        $ca = trim((string)config('eduxid.ca_cert',''));
        if ($ca !== '' && is_file($ca)) $req = $req->withOptions(['verify' => $ca]);
        return $req;
    }
}
