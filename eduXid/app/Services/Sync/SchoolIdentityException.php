<?php

namespace App\Services\Sync;

use RuntimeException;

/**
 * Exception identitas sekolah.
 *
 * Dibedakan dari RuntimeException umum agar pemanggil (daemon,
 * controller, job) bisa memisahkan "identitas belum siap/ambigu"
 * dari kegagalan teknis lain (jaringan, DB, dsb.).
 */
class SchoolIdentityException extends RuntimeException
{
}