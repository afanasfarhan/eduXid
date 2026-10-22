<?php

return [
    // Root bundle Windows yang berisi eduXid/ dan php/.
    'template_root' => env('EDUXID_GTK_TEMPLATE_ROOT', dirname(base_path())),
    'project_directory' => 'eduXid',
    'runtime_directory' => 'php',
    'output_directory' => storage_path('app/gtk-bundles'),
];
