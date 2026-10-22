<?php
namespace App\Services\Update;
use Illuminate\Support\Facades\Http;
class UpdateCheckGate {
    private $lock = null;
    private string $dir;
    public function __construct() {
        $this->dir=rtrim((string)config('eduxid.install_root'), '\\/').DIRECTORY_SEPARATOR.'.eduxid-updater';
        if(!is_dir($this->dir))@mkdir($this->dir,0770,true);
    }
    public function hasLock(): bool { return is_resource($this->lock); }
    public function begin(): bool {
        $f=@fopen($this->dir.DIRECTORY_SEPARATOR.'daily-check.lock','c+');
        if(!$f||!flock($f,LOCK_EX|LOCK_NB)){if($f)fclose($f);return false;}
        $this->lock=$f;
        $state=$this->state();
        return time()-(int)($state['last_successful_check']??0)>=86400;
    }
    public function cloudReachable(): bool {
        try {
            $base=rtrim((string)(config('eduxid.update_server_url')?:config('eduxid.sync_server')),'/');
            $request=Http::timeout(8);
            $ca=(string)config('eduxid.ca_cert');
            if($ca!==''&&is_file($ca))$request=$request->withOptions(['verify'=>$ca]);
            return $request->get($base.'/health')->successful();
        }catch(\Throwable){return false;}
    }
    public function markSuccessful(): void {
        $file=$this->dir.DIRECTORY_SEPARATOR.'daily-state.json';
        $tmp=$file.'.tmp';
        if(file_put_contents($tmp,json_encode(['last_successful_check'=>time(),'interval_seconds'=>86400]))!==false)@rename($tmp,$file);
    }
    private function state(): array {
        $raw=@file_get_contents($this->dir.DIRECTORY_SEPARATOR.'daily-state.json');
        $value=is_string($raw)?json_decode($raw,true):null;
        return is_array($value)?$value:[];
    }
    public function __destruct(){if(is_resource($this->lock)){flock($this->lock,LOCK_UN);fclose($this->lock);}}
}
