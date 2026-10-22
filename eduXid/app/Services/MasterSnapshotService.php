<?php

namespace App\Services;

use App\Services\Sync\MasterSnapshotService as VersionedSnapshot;

/*
 * JEMBATAN KOMPATIBILITAS (delegasi).
 * Referensi lama (eksporMaster, eksporMasterApi, alur m2g) memakai
 * kelas namespace ini; implementasi sesungguhnya di namespace Sync.
 */
class MasterSnapshotService
{
    protected VersionedSnapshot $versioned;

    public function __construct()
    {
        $this->versioned = app(VersionedSnapshot::class);
    }

    public function __call(string $method, array $args)
    {
        return $this->versioned->{$method}(...$args);
    }

    public static function __callStatic(string $method, array $args)
    {
        return VersionedSnapshot::{$method}(...$args);
    }
}