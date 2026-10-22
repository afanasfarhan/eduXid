<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

final class GtkCloudPairingRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Akun GTK ini belum memiliki pairing Cloud aktif. Hubungi Admin Sekolah untuk aktivasi perangkat yang sesuai.');
    }
}
