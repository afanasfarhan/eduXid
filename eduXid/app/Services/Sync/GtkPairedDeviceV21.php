<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\SyncDevice;
use App\Models\User;
use App\Services\AcademicPeriodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only, account-bound Cloud PC resolver. Never uses a token belonging
 * to another GTK or a raw personal identifier as a V21 device address.
 */
final class GtkPairedDeviceV21
{
    public function resolve(User $user): ?SyncDevice
    {
        if (!AcademicPeriodService::isGtkRole($user->roleName())) {
            return null;
        }
        $loginId = trim((string) $user->login_id);
        if ($loginId === '') {
            return null;
        }

        try {
            if (!Schema::hasTable('schools') || !Schema::hasTable('sync_devices')) {
                return null;
            }
            $schools = DB::table('schools')->whereNotNull('npsn')->distinct()->limit(2)->pluck('npsn');
            if ($schools->count() !== 1) {
                return null;
            }
            $schoolId = trim((string) $schools->first());
            if ($schoolId === '') {
                return null;
            }
            $deviceId = OpaqueRouteRegistryV21::enabled()
                ? app(OpaqueRouteRegistryV21::class)->deviceId($schoolId, 'teacher', $loginId, 'PC')
                : $schoolId . '-' . $loginId . '-PC';

            $device = SyncDevice::query()
                ->where('school_id', $schoolId)
                ->where('device_type', 'teacher')
                ->where('device_id', $deviceId)
                ->where('is_active', true)
                ->first();

            if (!$device) {
                return null;
            }
            // Prevent borrowing the token of a different user even when a
            // restored SQLite database has inconsistent user/device links.
            if ($device->user_id !== null && (int) $device->user_id !== (int) $user->id) {
                return null;
            }
            return trim((string) $device->sync_token) !== '' ? $device : null;
        } catch (Throwable) {
            // For UI status this is an unconfirmed pairing, not an excuse to
            // relax identity checks or leak secret exception details.
            return null;
        }
    }

    public function isPaired(User $user): bool
    {
        return $this->resolve($user) !== null;
    }
}
