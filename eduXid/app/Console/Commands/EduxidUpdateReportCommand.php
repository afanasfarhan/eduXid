<?php
namespace App\Console\Commands;
use App\Services\Sync\EduxidCloudConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Throwable;
class EduxidUpdateReportCommand extends Command {
    protected $signature='eduxid:update:report {--version=} {--state=}';
    protected $description='Laporkan instalasi silent update ke eduXid Cloud';
    public function handle(): int {
        try {
            $state=(string)$this->option('state');$version=(string)$this->option('version');
            if(!preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/',$version)||!in_array($state,['downloaded','installed','failed','rollback','waiting_safe'],true))return 1;
            $edition=strtolower((string)config('eduxid.edition','school'));
            if($edition==='teacher') {
                $token=(string)DB::table('sync_devices')->where('device_id',(string)config('eduxid.device_id'))->value('sync_token');
                $authorization='Bearer '.$token;
            }else $authorization='Admin '.EduxidCloudConfig::adminToken();
            if(trim($authorization)==='Admin'||trim($authorization)==='Bearer')return 1;
            $url=rtrim((string)config('eduxid.sync_server'),'/').'/api/update/report';
            $req=Http::timeout(8)->withHeaders(['Authorization'=>$authorization])->acceptJson();
            $ca=(string)config('eduxid.ca_cert');if($ca!==''&&is_file($ca))$req=$req->withOptions(['verify'=>$ca]);
            // The Cloud uses a release UUID; the ZIP manifest uses a semantic
            // version. Prefer the UUID recorded when the ZIP was downloaded.
            $root=rtrim((string)config('eduxid.install_root'), '\\/');
            $marker=$root.DIRECTORY_SEPARATOR.'.eduxid-updater'.DIRECTORY_SEPARATOR.'cloud-download.json';
            $download=json_decode((string)@file_get_contents($marker),true);
            $releaseId=$version;
            if(is_array($download)&&($download['version']??'')===$version
                && preg_match('/^[a-zA-Z0-9_-]{3,100}$/',(string)($download['release_id']??''))) {
                $releaseId=(string)$download['release_id'];
            }
            $r=$req->post($url,['release_id'=>$releaseId,'state'=>$state]);
            return $r->successful()?0:1;
        }catch(Throwable){return 1;}
    }
}
