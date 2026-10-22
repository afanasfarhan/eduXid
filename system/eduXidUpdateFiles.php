<?php
/** Compatibility bridge for existing compiled eduXidUpdater.exe. */
declare(strict_types=1);
$target = __DIR__ . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'eduXidUpdateFiles.php';
if (!is_file($target)) { fwrite(STDERR, 'Komponen updater tidak ditemukan: eduXidUpdateFiles.php\n'); exit(2); }
require $target;
