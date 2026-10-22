<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Services\GtkBundle\Installation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Receive teacher final grades into the SCHOOL ADMIN database for report printing.
 * Fail closed: never advance cursor after a failed apply, and never poll on GTK.
 */
final class AdminReportGradeInbox
{
    private const CURSOR_KEY = 'rapor::cloud_final_grade_cursor_v1';

    public function sync(int $maxPages = 3): array
    {
        if (Installation::owner() !== null) return ['skipped' => 'gtk_installation'];
        $token = EduxidCloudConfig::adminToken();
        $url = EduxidCloudConfig::syncServer();
        if ($token === '' || $url === '' || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return ['skipped' => 'cloud_admin_not_paired'];
        }
        $schoolId = app(SchoolIdentity::class)->schoolId();
        $cursor = json_decode((string) DB::table('app_settings')
            ->where('key', self::CURSOR_KEY)->value('value'), true);
        $cursor = is_array($cursor) && isset($cursor['at'], $cursor['id']) ? $cursor : ['at'=>'','id'=>''];
        $applied = 0;
        $stale = 0;
        $pages = 0;
        $hasMore = false;
        $guard = app(CloudWorkProductAliasV21::class);
        $applier = app(WorkProductApplier::class);
        do {
            $client = Http::timeout(45)->connectTimeout(12)->acceptJson()->asJson()
                ->withHeaders(['Authorization' => 'Admin '.$token]);
            $ca = trim((string) config('eduxid.ca_cert',''));
            if ($ca !== '') {
                if (!is_file($ca)) throw new RuntimeException('CA Cloud Admin belum ditemukan.');
                $client = $client->withOptions(['verify' => $ca]);
            }
            $response = $client->post(rtrim($url,'/').'/api/admin/report-grades/pull', [
                'school_id'=>$schoolId, 'cursor'=>$cursor, 'limit'=>50,
            ]);
            if (!$response->successful() || $response->json('success') !== true) {
                // Old Worker without endpoint? Keep cursor and grades as-is.
                throw new RuntimeException('Inbox nilai akhir Admin belum tersedia di Cloud (HTTP '.$response->status().').');
            }
            $data = $response->json('data');
            if (!is_array($data) || ($data['school_id'] ?? null) !== $schoolId
                || !is_array($data['items'] ?? null) || !array_is_list($data['items'])
                || count($data['items']) > 50 || !is_array($data['cursor'] ?? null)) {
                throw new RuntimeException('Respons inbox rapor Cloud tidak sesuai kontrak.');
            }
            foreach ($data['items'] as $event) {
                if (!is_array($event) || ($event['entity_type'] ?? '') !== 'final_grade'
                    || !in_array($event['action'] ?? '', ['upsert','delete'], true)
                    || (int)($event['version'] ?? 0) <= 0
                    || !is_array($event['payload'] ?? null)) {
                    throw new RuntimeException('Event nilai akhir Cloud tidak valid.');
                }
                $source = trim((string)($event['source_device_id'] ?? ''));
                $payload = $event['payload'];
                $guard->assertAuthorizedFinalGradeSource($payload, $source);
                if ($event['action'] === 'upsert' && OpaqueRouteRegistryV21::enabled()) {
                    $event['payload'] = $guard->inbound('final_grade', $payload, $source);
                }
                $result = $applier->apply($event);
                if (($result['applied'] ?? false) === true) {
                    $applied++;
                } elseif (str_starts_with((string)($result['reason'] ?? ''), 'stale/duplicate')) {
                    $stale++;
                } else {
                    throw new RuntimeException('Gagal menerapkan nilai rapor Admin: '.(string)($result['reason'] ?? 'unknown'));
                }
            }
            $next = $data['cursor'];
            if (!is_string($next['at'] ?? null) || !is_string($next['id'] ?? null)
                || (count($data['items']) > 0 && $next === $cursor)) {
                throw new RuntimeException('Cursor inbox nilai akhir tidak bergerak.');
            }
            // Cursor advances ONLY after every event in the page is applied or
            // proven stale. Crash before this write = harmless replay.
            DB::table('app_settings')->updateOrInsert(['key'=>self::CURSOR_KEY], [
                'value'=>json_encode($next, JSON_THROW_ON_ERROR),
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
            $cursor=$next;
            $pages++;
            $hasMore=($data['has_more'] ?? false) === true;
        } while ($hasMore && $pages < max(1,min(10,$maxPages)));
        return compact('applied','stale','pages','hasMore');
    }
}
