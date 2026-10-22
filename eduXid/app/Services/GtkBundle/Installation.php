<?php

namespace App\Services\GtkBundle;

use App\Services\Sync\OpaqueRouteRegistryV21;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class Installation
{
    public static function owner(): ?array
    {
        if (!Schema::hasTable('app_settings')) return null;
        $settings = DB::table('app_settings')->whereIn('key', [
            'installation::type', 'installation::school_id',
            'installation::owner_login_id', 'installation::owner_device_id',
        ])->pluck('value', 'key');
        if (($settings['installation::type'] ?? '') !== 'teacher') return null;
        $school = trim((string) ($settings['installation::school_id'] ?? ''));
        $login = trim((string) ($settings['installation::owner_login_id'] ?? ''));
        $device = trim((string) ($settings['installation::owner_device_id'] ?? ''));
        if ($school === '' || $login === '' || !preg_match('/^[0-9]{8}$/D',$school)) {
            throw new RuntimeException('Identitas pemilik paket GTK tidak lengkap atau tidak sesuai.');
        }
        if (OpaqueRouteRegistryV21::enabled()) {
            // A new GTK bundle is launched before its BND1 pairing populates
            // the teacher registry. Accept only a canonical opaque PC address;
            // when a local mapping exists it must match that exact address.
            if (!preg_match('/^[0-9]{8}-t_[a-z2-7]{26}-PC$/D', $device)
                || !str_starts_with($device,$school.'-')) {
                throw new RuntimeException('BLOCKED V21: device_id pemilik GTK harus beralias PC.');
            }
            if (Schema::hasTable('cloud_identity_alias_routes')) {
                $digest=OpaqueRouteRegistryV21::lookupDigest($school,'teacher',$login);
                $stored=DB::table('cloud_identity_alias_routes')
                    ->where('school_id',$school)->where('role','teacher')
                    ->where('local_digest',$digest)->value('cloud_alias');
                if ($stored !== null && !hash_equals($school.'-'.$stored.'-PC',$device)) {
                    throw new RuntimeException('BLOCKED V21: pemilik dan alias lokal Paket GTK tidak cocok.');
                }
            }
        } elseif ($device !== $school.'-'.$login.'-PC') {
            throw new RuntimeException('Identitas pemilik paket GTK tidak lengkap atau tidak sesuai.');
        }
        return ['school_id'=>$school, 'login_id'=>$login, 'device_id'=>$device];
    }

    public static function scopeDevices($query, string $schoolId)
    {
        $owner = self::owner();
        if ($owner === null) return $query;
        if ($owner['school_id'] !== $schoolId) {
            throw new RuntimeException('NPSN data pokok berbeda dari identitas paket GTK.');
        }
        return $query->where('device_id', $owner['device_id']);
    }
}
