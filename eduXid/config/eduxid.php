<?php

declare(strict_types=1);

$installRoot = realpath(base_path('..')) ?: dirname(base_path());
$bundledCaCert = $installRoot . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'cacert.pem';

return [
    'sync_server' => env('EDUXID_SYNC_SERVER', 'https://eduxid-cloud.eduxid-sync-anas.workers.dev'),

    'school_id' => env('EDUXID_SCHOOL_ID', ''),
    'npsn' => env('EDUXID_NPSN', ''),
    'device_id' => env('EDUXID_DEVICE_ID', ''),

    'ca_cert' => env('EDUXID_CA_CERT', $bundledCaCert),

    // V21R2: staging/preflight only; keep secret on trusted Admin PC (NEVER in PWA or Cloud payload).
    'identity_school_alias_key' => env('EDUXID_SCHOOL_ALIAS_KEY', ''),
    'identity_protocol' => env('EDUXID_IDENTITY_PROTOCOL', 'v21-opaque'),

    'sync_token' => env('EDUXID_SYNC_TOKEN'),

    // Verified HTTPS endpoint of Admin school's LAN/Tunnel gateway.
    'lan_master_url' => env('EDUXID_LAN_MASTER_URL', ''),
    // Explicit legacy override only; NEVER enable on untrusted LAN.
    'allow_insecure_lan_master' => filter_var(env('EDUXID_ALLOW_INSECURE_LAN_MASTER', false), FILTER_VALIDATE_BOOLEAN),
    'enrollment_token' => env('EDUXID_ENROLLMENT_TOKEN'),
    'admin_key' => env('EDUXID_ADMIN_KEY'),
    'admin_token' => env('EDUXID_ADMIN_TOKEN'),

    'timeout' => (int) env('EDUXID_SYNC_TIMEOUT', 15),
    'retry_times' => (int) env('EDUXID_SYNC_RETRY', 2),

    'name' => env('EDUXID_NAME', 'eduXid'),
    'version' => env('EDUXID_VERSION', '1.0.0'),
    'edition' => env('EDUXID_EDITION', 'school'),

    'install_root' => env('EDUXID_INSTALL_ROOT', $installRoot),

    'update_server_url' => env('EDUXID_UPDATE_SERVER_URL'),
    'update_channel' => env('EDUXID_UPDATE_CHANNEL', 'stable'),
    'admin_url' => env('EDUXID_ADMIN_URL'),

    'update_public_key' => env('EDUXID_UPDATE_PUBLIC_KEY'),
    'update_private_key' => env('EDUXID_UPDATE_PRIVATE_KEY'),

    'update_check_timeout' => (int) env('EDUXID_UPDATE_CHECK_TIMEOUT', 10),
    'update_download_timeout' => (int) env('EDUXID_UPDATE_DOWNLOAD_TIMEOUT', 300),

    /*
     * PATCH ROOT V2
     * File patch lokal/download wajib berada satu folder dengan eduXid.exe.
     * Key lama patch_cache_path dipertahankan agar kode lama tidak langsung rusak,
     * tetapi nilainya sekarang menunjuk ke root instalasi, BUKAN storage/app/patches.
     */
    'patch_cache_path' => env('EDUXID_PATCH_ROOT', $installRoot),

    /* Release publisher tetap terpisah dari cache/install lokal. */
    'release_path' => storage_path('app/releases'),

    'updater_exe' => $installRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'eduXidUpdater.exe',
    'updater_bat' => $installRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'eduXidUpdater.bat',
];
