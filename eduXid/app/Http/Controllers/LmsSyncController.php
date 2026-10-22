<?php

namespace App\Http\Controllers;

use App\Models\SyncDevice;
use App\Services\Sync\LmsSyncEngine;
use App\Services\Sync\LmsSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class LmsSyncController extends Controller
{
    protected const MAX_PUSH_CHANGES = 100;
    protected const MAX_DEVICE_ID_LENGTH = 64;
    protected const SYNC_LOCK_TTL = 180;
    protected const PULL_PAGE_SIZE = 50;

    /*
     * [F1 / D20] Jalur device SISWA DIHAPUS — PWA siswa online-only
     * (Q20) dan tidak pernah sync ke laptop. Endpoint ini khusus
     * device guru (-PC web / -HP PWA-guru via LAN).
     */

    public function sync(
        Request $request,
        LmsSyncService $syncService,
        LmsSyncEngine $syncEngine
    ): JsonResponse {

        $schoolId = trim((string) $syncService->getSchoolId());
        $deviceId = $this->resolveDeviceId($request, $syncService);
        $deviceType = $this->resolveDeviceType($deviceId, $schoolId);

        [$clientChanges, $rejectedCount] = $this->sanitizeClientChanges($request->input('changes'));

        $incomingAckIds = $this->normalizeIncomingAckIds($request->input('ack_ids', []));

        if ($schoolId === '') {
            return $this->configError('EDUXID_SCHOOL_ID belum dikonfigurasi.');
        }

        if ($deviceId === '') {
            return $this->configError('Device ID belum tersedia.');
        }

        if (!$this->isValidDeviceId($deviceId)) {
            return response()->json([
                'success' => false,
                'message' => 'Device ID tidak valid.',
            ], 422);
        }

        if ($deviceType === 'student') {
            return response()->json([
                'success' => false,
                'message' => 'Device murid tidak sync ke laptop — PWA murid berjalan via Cloud.',
            ], 403);
        }

        $forbidden = $this->authorizeDeviceAccess($request, $syncService, $deviceId, $deviceType);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $lock = Cache::lock($this->lockKey($schoolId, $deviceId), self::SYNC_LOCK_TTL);

        if (!$lock->get()) {
            return response()->json([
                'success' => false,
                'online' => true,
                'message' => 'Sinkronisasi device ini sedang berjalan.',
                'retry_after' => self::SYNC_LOCK_TTL,
            ], 409);
        }

        try {

            $syncDevice = $this->registerDevice($schoolId, $deviceId, $deviceType);

            if (!(bool) $syncDevice->is_active) {
                return response()->json([
                    'success' => false,
                    'online' => true,
                    'message' => 'Device dinonaktifkan. Sinkronisasi ditolak.',
                    'device_id' => $deviceId,
                    'device_type' => $deviceType,
                ], 403);
            }

            /*
             * [DK-04] Device yatim (auto-register tanpa token) tidak
             * diiterasi daemon & ditolak di sini dengan pesan jelas —
             * bukan error berulang tiap siklus.
             */
            if (trim((string) ($syncDevice->sync_token ?? '')) === '') {
                return response()->json([
                    'success' => false,
                    'online' => true,
                    'message' => 'Device belum ter-pairing (sync_token kosong). Lakukan pairing ke eduXid Cloud.',
                    'device_id' => $deviceId,
                ], 409);
            }

            /* 1) ACK masuk dari browser. */
            $ackResult = ['success' => true, 'acknowledged' => 0];

            if ($incomingAckIds !== []) {
                try {
                    $ackResult = $syncService->acknowledge($deviceId, $incomingAckIds);
                    $syncDevice->last_synced_at = now();
                    $syncDevice->save();
                } catch (Throwable $e) {
                    Log::warning('ACK device gagal, sync dilanjutkan.', [
                        'device_id' => $deviceId,
                        'error' => $e->getMessage(),
                    ]);
                    $ackResult = ['success' => false, 'acknowledged' => 0, 'error' => $e->getMessage()];
                }
            }

            /* 2) PUSH changes browser. */
            $pushResult = [
                'success' => true, 'pushed' => 0, 'failed' => 0,
                'rejected' => $rejectedCount, 'change_ids' => [], 'results' => [],
            ];

            if ($clientChanges !== []) {
                try {
                    $pushResult = $syncService->pushClientChanges($deviceId, $clientChanges);
                    $pushResult['rejected'] = $rejectedCount;
                } catch (Throwable $e) {
                    Log::error('PUSH client gagal total, PULL tetap jalan.', [
                        'device_id' => $deviceId,
                        'error' => $e->getMessage(),
                    ]);
                    $pushResult = [
                        'success' => false, 'pushed' => 0,
                        'failed' => count($clientChanges), 'rejected' => $rejectedCount,
                        'change_ids' => [], 'results' => [],
                        'error' => $e->getMessage(),
                    ];
                }
            }

            /* 3) PULL satu halaman (browser; daemon yang menguras penuh). */
            $pullResponse = $syncService->pull($deviceId, 0);

            $changes = is_array($pullResponse['changes'] ?? null) ? $pullResponse['changes'] : [];
            $received = count($changes);

            $applied = 0;
            $failed = 0;
            $appliedAckIds = [];
            $failedChanges = [];

            /* 4) OUTBOX guru — retry tiap siklus (ringan di web). */
            $retryResult = ['success' => true, 'processed' => 0, 'synced' => 0, 'failed' => 0];

            try {
                $retryResult = $syncService->retryPending(20);

                if (is_array($retryResult) && (($retryResult['synced'] ?? 0) > 0)) {
                    Log::info('Outbox guru: sebagian berhasil dikirim ulang.', [
                        'device_id' => $deviceId,
                        'synced' => $retryResult['synced'] ?? 0,
                    ]);
                }
            } catch (Throwable $retryError) {
                Log::debug('Outbox guru: retry ditunda.', [
                    'device_id' => $deviceId,
                    'error' => $retryError->getMessage(),
                ]);
                $retryResult = ['success' => false, 'processed' => 0, 'synced' => 0, 'failed' => 0, 'error' => $retryError->getMessage()];
            }

            /* 5) APPLY (guru). */
            if ($received > 0) {
                try {
                    $applyResult = $syncEngine->applyChanges($changes, $deviceId);

                    $applied = (int) ($applyResult['applied'] ?? 0);
                    $failed = (int) ($applyResult['failed'] ?? 0);
                    $appliedAckIds = array_values(array_filter((array) ($applyResult['ack_ids'] ?? [])));
                    $failedChanges = array_values((array) ($applyResult['failures'] ?? []));

                    if ($appliedAckIds !== []) {
                        try {
                            $ackResponse = $syncService->acknowledge($deviceId, $appliedAckIds);
                            $ackResult['acknowledged'] += max(0, (int) ($ackResponse['acknowledged'] ?? 0));
                        } catch (Throwable $e) {
                            Log::warning('ACK guru gagal (idempoten akan men-skip ulang).', [
                                'device_id' => $deviceId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                } catch (Throwable $e) {
                    Log::error('Apply batch guru gagal total.', [
                        'device_id' => $deviceId,
                        'error' => $e->getMessage(),
                    ]);
                    $failed = $received;
                }
            }

            $syncDevice->last_synced_at = now();
            $syncDevice->save();

            $eventIds = collect($changes)
                ->map(fn ($c) => trim((string) ($c['event_id'] ?? '')))
                ->filter(fn ($e) => $e !== '')
                ->unique()
                ->values()
                ->all();

            return response()->json([
                'success' => true,
                'online' => true,
                'school_id' => $schoolId,
                'device_id' => $deviceId,
                'device_type' => $deviceType,

                'push' => $pushResult,
                'retry' => $retryResult,
                'ack' => $ackResult,

                'pull' => [
                    'received' => $received,
                    'applied' => $applied,
                    'failed' => $failed,
                    'failed_changes' => $failedChanges,
                    'has_more' => $received >= self::PULL_PAGE_SIZE,
                ],

                'changes' => $changes,
                'event_ids' => $eventIds,
                'received' => $received,
                'applied' => $applied,
                'failed' => $failed,
                'acknowledged' => (int) ($ackResult['acknowledged'] ?? 0),
            ]);
        } catch (Throwable $e) {
            Log::error('Sinkronisasi LMS gagal.', [
                'school_id' => $schoolId,
                'device_id' => $deviceId,
                'device_type' => $deviceType,
                'push_count' => count($clientChanges),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'online' => true,
                'message' => 'Sinkronisasi LMS gagal.',
                'error' => config('app.debug') ? $e->getMessage() : null,
                'school_id' => $schoolId,
                'device_id' => $deviceId,
                'device_type' => $deviceType,
            ], 500);
        } finally {
            optional($lock)->release();
        }
    }

    public function resetCursor(Request $request, LmsSyncService $syncService): JsonResponse
    {
        $schoolId = trim((string) $syncService->getSchoolId());
        $deviceId = $this->resolveDeviceId($request, $syncService);

        if ($schoolId === '' || $deviceId === '') {
            return $this->configError('Konfigurasi perangkat belum lengkap.');
        }

        if (!$this->isValidDeviceId($deviceId)) {
            return response()->json([
                'success' => false,
                'message' => 'Device ID tidak valid.',
            ], 422);
        }

        $deviceType = $this->resolveDeviceType($deviceId, $schoolId);

        if ($deviceType === 'student') {
            return response()->json(['success' => false, 'message' => 'Device murid tidak didukung.'], 403);
        }

        $forbidden = $this->authorizeDeviceAccess($request, $syncService, $deviceId, $deviceType);
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $syncDevice = $this->registerDevice($schoolId, $deviceId, $deviceType);

            if (!(bool) $syncDevice->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device dinonaktifkan. Reset cursor ditolak.',
                ], 403);
            }

            $syncDevice->sync_cursor = 0;
            $syncDevice->last_synced_at = null;
            $syncDevice->save();

            return response()->json([
                'success' => true,
                'message' => 'Cursor sinkronisasi berhasil direset.',
                'school_id' => $schoolId,
                'device_id' => $deviceId,
                'device_type' => $deviceType,
                'cursor' => 0,
            ]);
        } catch (Throwable $e) {
            Log::error('Reset cursor LMS gagal.', [
                'school_id' => $schoolId,
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Cursor sinkronisasi gagal direset.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function status(Request $request, LmsSyncService $syncService): JsonResponse
    {
        $schoolId = trim((string) $syncService->getSchoolId());
        $deviceId = $this->resolveDeviceId($request, $syncService);

        if ($schoolId === '' || $deviceId === '') {
            return $this->configError('Konfigurasi perangkat belum lengkap.');
        }

        try {
            $syncDevice = SyncDevice::where('school_id', $schoolId)->where('device_id', $deviceId)->first();

            return response()->json([
                'success' => true,
                'school_id' => $schoolId,
                'device_id' => $deviceId,
                'device_type' => $this->resolveDeviceType($deviceId, $schoolId),
                'sync_server' => $syncService->getSyncServer(),
                'cursor' => $syncDevice ? max(0, (int) ($syncDevice->sync_cursor ?? 0)) : 0,
                'device_registered' => $syncDevice !== null,
                'device_active' => (bool) ($syncDevice->is_active ?? false),
                'paired' => $syncDevice !== null && trim((string) ($syncDevice->sync_token ?? '')) !== '',
                'last_synced_at' => $syncDevice?->last_synced_at,
            ]);
        } catch (Throwable $e) {
            Log::error('Status sinkronisasi LMS gagal.', [
                'school_id' => $schoolId,
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Status sinkronisasi LMS gagal.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /* ============================================================
     * HELPERS
     * ============================================================ */

    protected function authorizeDeviceAccess(
        Request $request,
        LmsSyncService $syncService,
        string $deviceId,
        string $deviceType
    ): ?JsonResponse {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'online' => true,
                'message' => 'Sesi berakhir. Silakan login kembali.',
            ], 401);
        }

        $loginId = trim((string) $user->login_id);
        $parts = explode('-', $deviceId);
        $ownerSegment = count($parts) >= 3 ? trim($parts[1]) : '';

        if (\App\Services\Sync\OpaqueRouteRegistryV21::enabled()) {
            try {
                $school = $syncService->getSchoolId();
                $registry = app(\App\Services\Sync\OpaqueRouteRegistryV21::class);
                $expectedPc = $registry->deviceId($school, 'teacher', $loginId, 'PC');
                $expectedHp = $registry->deviceId($school, 'teacher', $loginId, 'HP');
                if (in_array($deviceId, [$expectedPc, $expectedHp], true)) return null;
            } catch (\Throwable) {
                // Provisioning absent or invalid must remain blocked.
            }
            return response()->json([
                'success' => false, 'online' => true,
                'message' => 'BLOCKED V21: perangkat bukan alias akun yang login.',
            ], 403);
        }

        $ownedBySegment = $ownerSegment !== '' && $ownerSegment === $loginId;

        if ($ownedBySegment) {
            return null;
        }

        $registered = SyncDevice::query()
            ->where('school_id', trim((string) $syncService->getSchoolId()))
            ->where('device_id', $deviceId)
            ->first();

        if ($registered !== null && (int) $registered->user_id === (int) $user->id) {
            return null;
        }

        Log::warning('Akses device guru ditolak: device bukan milik akun.', [
            'user_id' => $user->id,
            'login_id' => $loginId,
            'device_id' => $deviceId,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => false,
            'online' => true,
            'message' => 'Device ID tidak sesuai dengan akun guru yang login.',
        ], 403);
    }

    protected function resolveDeviceId(Request $request, LmsSyncService $syncService): string
    {
        $requested = trim((string) ($request->input('device_id') ?? ''));

        return $requested !== ''
            ? $requested
            : trim((string) $syncService->getDeviceId());
    }

    protected function resolveDeviceType(string $deviceId, ?string $schoolId = null): string
    {
        $deviceId = strtoupper(trim($deviceId));

        if (str_starts_with($deviceId, 'MURID-')) {
            return 'student';
        }

        // In strict V21 the alias prefix (t_/s_) is the role, not the suffix.
        if (\App\Services\Sync\OpaqueRouteRegistryV21::enabled()
            && preg_match('/^[0-9]{8}-([ts]_[a-z2-7]{26})-HP$/D', strtolower($deviceId), $match)) {
            return str_starts_with($match[1], 't_') ? 'teacher' : 'student';
        }

        // V19: -HP is shared by teacher and student PWAs. Never infer role
        // from suffix alone. Trust bound device record or authenticated teacher
        // identity with a matching owner segment; unknown HP is student.
        if (str_ends_with($deviceId, '-HP')) {
            $record = SyncDevice::query()->where('device_id', $deviceId)
                ->where('school_id', $schoolId ?? (string) config('eduxid.school_id', ''))
                ->first();
            if ($record !== null) {
                return $record->device_type === 'teacher' ? 'teacher' : 'student';
            }
            $user = auth()->user();
            $segments = explode('-', $deviceId);
            $loginId = trim((string) ($user?->login_id ?? ''));
            $teacherRoles = ['guru', 'wali_kelas', 'guru_bk', 'kepala_sekolah'];
            if ($user && in_array(strtolower((string) $user->role), $teacherRoles, true)
                && $loginId !== '' && ($segments[1] ?? '') === $loginId
                && ($segments[0] ?? '') === ($schoolId ?? (string) config('eduxid.school_id', ''))) {
                return 'teacher';
            }
            return 'student';
        }

        return 'teacher';
    }

    protected function isValidDeviceId(string $deviceId): bool
    {
        return $deviceId !== ''
            && strlen($deviceId) <= self::MAX_DEVICE_ID_LENGTH
            && preg_match('/^[A-Za-z0-9._-]+$/', $deviceId) === 1;
    }

    protected function normalizeIncomingAckIds(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : [];

        return collect($items)
            ->map(fn ($id) => trim((string) $id))
            ->filter(fn ($id) => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function sanitizeClientChanges(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : [];
        $valid = [];
        $rejected = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                $rejected++;
                continue;
            }

            $entityType = $item['entity_type'] ?? $item['entity'] ?? null;
            $entityId = $item['entity_id'] ?? null;
            $action = $item['action'] ?? null;

            $ok = is_string($entityType)
                && trim($entityType) !== ''
                && $entityId !== null
                && $entityId !== ''
                && is_string($action)
                && trim($action) !== '';

            if (!$ok || count($valid) >= self::MAX_PUSH_CHANGES) {
                $rejected++;
                continue;
            }

            $valid[] = $item;
        }

        return [$valid, $rejected];
    }

    protected function registerDevice(string $schoolId, string $deviceId, string $deviceType): SyncDevice
    {
        try {
            $device = SyncDevice::firstOrCreate(
                [
                    'school_id' => $schoolId,
                    'device_id' => $deviceId,
                ],
                [
                    'device_type' => $deviceType,
                    'user_id' => null,
                    'device_name' => $deviceId,
                    'is_active' => true,
                    'sync_cursor' => 0,
                ]
            );
        } catch (\Illuminate\Database\QueryException) {
            $device = SyncDevice::where('school_id', $schoolId)->where('device_id', $deviceId)->firstOrFail();
        }

        if ($device->device_type !== $deviceType) {
            $device->device_type = $deviceType;
            $device->save();
        }

        return $device;
    }

    protected function configError(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 500);
    }

    protected function lockKey(string $schoolId, string $deviceId): string
    {
        return "lms-sync:{$schoolId}:{$deviceId}";
    }
}