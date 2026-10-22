<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
class EduxidUpdateCleanupCommand extends Command {
    protected $signature='eduxid:update:cleanup-download {--version=}';
    protected $description='Bersihkan ZIP hasil unduhan otomatis yang sudah sukses dipasang';
    public function handle(): int {
        $root=rtrim((string)config('eduxid.install_root'), '\\/');
        $file=$root.DIRECTORY_SEPARATOR.'.eduxid-updater'.DIRECTORY_SEPARATOR.'cloud-download.json';
        $data=json_decode((string)@file_get_contents($file),true);
        if(!is_array($data))return self::SUCCESS;
        $name=(string)($data['basename']??'');
        $version=(string)$this->option('version');
        if($version!==($data['version']??'')||!preg_match('/^eduxid-patch-\d+\.\d+\.\d+(?:-[\w.-]+)?\.zip$/',$name))return self::FAILURE;
        $target=$root.DIRECTORY_SEPARATOR.$name;
        if(is_file($target)&&hash_equals((string)($data['sha256']??''),(string)hash_file('sha256',$target)))@unlink($target);
        @unlink($file);
        return self::SUCCESS;
    }
}
