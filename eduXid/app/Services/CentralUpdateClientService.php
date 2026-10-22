<?php
namespace App\Services;

use App\Services\Sync\EduxidCloudConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use ZipArchive;

class CentralUpdateClientService
{
    public function __construct(
        protected UpdateSignatureService $signatures,
        protected PatchPackageValidator $packageValidator
    ) {}

    public function configured(): bool { return $this->serverUrl() !== ''; }

    public function check(): array
    {
        $this->assertSchoolEdition();
        $server = $this->serverUrl();
        if ($server === '') return [
            'configured'=>false,'connected'=>false,'channel'=>$this->channel(),
            'current_version'=>$this->currentVersion(),'update_available'=>false,
            'latest_version'=>null,'release'=>null,
        ];

        try {
            $response = Http::timeout($this->timeout())->connectTimeout(min(5,$this->timeout()))
                ->acceptJson()->withHeaders($this->authHeaders())->withOptions($this->httpOptions())
                ->get($server.'/api/update/check',[
                    'application'=>(string)config('eduxid.name','eduXid'),'edition'=>'school',
                    'channel'=>$this->channel(),'current_version'=>$this->currentVersion(),
                ]);
            if (!$response->successful()) throw new RuntimeException('Central update server HTTP '.$response->status().'.');

            $payload=$response->json();
            if (!is_array($payload)) throw new RuntimeException('Respons central update server tidak valid.');
            $release=$payload['release']??null;
            if ($release===null) return [
                'configured'=>true,'connected'=>true,'channel'=>$this->channel(),
                'current_version'=>$this->currentVersion(),'update_available'=>false,
                'latest_version'=>null,'release'=>null,
            ];
            if (!is_array($release)) throw new RuntimeException('Manifest release central tidak valid.');
            $manifest=$this->validateManifest($release);
            return [
                'configured'=>true,'connected'=>true,'channel'=>$this->channel(),
                'current_version'=>$this->currentVersion(),
                'update_available'=>version_compare($manifest['version'],$this->currentVersion(),'>'),
                'latest_version'=>$manifest['version'],'release'=>$manifest,
            ];
        } catch(Throwable $e) {
            throw new RuntimeException('Gagal memeriksa update pusat: '.$e->getMessage(),0,$e);
        }
    }

    public function download(array $manifest): array
    {
        $this->assertSchoolEdition();
        if (!$this->configured()) throw new RuntimeException('EDUXID_UPDATE_SERVER_URL belum dikonfigurasi.');
        $manifest=$this->validateManifest($manifest);
        if (version_compare($manifest['version'],$this->currentVersion(),'<='))
            throw new RuntimeException('Release central bukan versi yang lebih baru.');

        $dir=(string)config('eduxid.release_path',storage_path('app/releases'));
        File::ensureDirectoryExists($dir);
        $version=$manifest['version']; $target=$manifest['target'];
        $zipName="patch_{$version}_{$target}.zip"; $manifestName="release_{$version}_{$target}.json";
        if ($manifest['file']!==$zipName) throw new RuntimeException('Nama ZIP pada manifest central tidak sesuai.');

        $finalZip=$dir.DIRECTORY_SEPARATOR.$zipName;
        $finalManifest=$dir.DIRECTORY_SEPARATOR.$manifestName;
        $nonce=bin2hex(random_bytes(8));
        $tempZip=$dir.DIRECTORY_SEPARATOR.".download_{$version}_{$nonce}.zip.tmp";
        $tempManifest=$dir.DIRECTORY_SEPARATOR.".manifest_{$version}_{$nonce}.json.tmp";

        try {
            $options=$this->httpOptions(); $options['sink']=$tempZip;
            $response=Http::timeout(max($this->timeout(),60))->connectTimeout(min(10,$this->timeout()))
                ->acceptJson()->withHeaders($this->authHeaders())->withOptions($options)
                ->get($this->serverUrl().'/api/update/download',[
                    'version'=>$version,'target'=>$target,'channel'=>$this->channel(),
                ]);
            if (!$response->successful()) { @unlink($tempZip); throw new RuntimeException('Download central HTTP '.$response->status().'.'); }
            if (!is_file($tempZip)||filesize($tempZip)<=0) throw new RuntimeException('ZIP release central kosong atau tidak ditemukan.');

            $sha=strtolower((string)hash_file('sha256',$tempZip));
            if (!hash_equals($manifest['sha256'],$sha)) throw new RuntimeException('SHA-256 ZIP release central tidak cocok.');
            if (!$this->signatures->verify($version,$target,$sha,$manifest['signature'])) throw new RuntimeException('Signature Ed25519 release central tidak valid.');
            $this->assertZipReadable($tempZip);
            $this->packageValidator->inspect($tempZip,$version);

            $json=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (file_put_contents($tempManifest,$json.PHP_EOL,LOCK_EX)===false) throw new RuntimeException('Gagal membuat manifest temporary.');
            $this->promotePair($tempZip,$tempManifest,$finalZip,$finalManifest);
            return ['version'=>$version,'target'=>$target,'sha256'=>$sha,'zip'=>$finalZip,'manifest'=>$finalManifest];
        } catch(Throwable $e) {
            @unlink($tempZip); @unlink($tempManifest);
            throw new RuntimeException('Gagal mengambil signed release dari pusat: '.$e->getMessage(),0,$e);
        }
    }

    protected function authHeaders(): array
    {
        $token=trim(EduxidCloudConfig::adminToken()); $school=$this->schoolId();
        if ($token==='') throw new RuntimeException('Admin token sekolah belum dikonfigurasi. Jalankan Setup Cloud terlebih dahulu.');
        if ($school==='') throw new RuntimeException('NPSN sekolah belum tersedia.');
        return ['Authorization'=>'Admin '.$token,'X-School-Id'=>$school,'User-Agent'=>'eduXid-School-Updater'];
    }

    protected function schoolId(): string
    {
        foreach (['school_id','npsn'] as $key) {
            $v=trim((string)config('eduxid.'.$key,''));
            if ($v!=='') return $v;
        }
        try {
            foreach (['npsn','school_id'] as $col) {
                if (Schema::hasColumn('schools',$col)) {
                    $v=trim((string)DB::table('schools')->value($col));
                    if ($v!=='') return $v;
                }
            }
        } catch(Throwable) {}
        return '';
    }

    protected function httpOptions(): array
    {
        $ca=trim((string)config('eduxid.ca_cert',''));
        return $ca!==''&&is_file($ca)?['verify'=>$ca]:[];
    }

    protected function validateManifest(array $m): array
    {
        $schema=trim((string)($m['schema']??'')); $app=trim((string)($m['application']??''));
        $version=trim((string)($m['version']??'')); $target=strtolower(trim((string)($m['target']??'')));
        $sha=strtolower(trim((string)($m['sha256']??''))); $alg=trim((string)($m['signature_algorithm']??''));
        $sig=trim((string)($m['signature']??'')); $file=basename(trim((string)($m['file']??'')));
        if ($schema!=='EDUXID-RELEASE-V1') throw new RuntimeException('Schema signed release central tidak didukung.');
        if ($app!==(string)config('eduxid.name','eduXid')) throw new RuntimeException('Application release central tidak sesuai.');
        if (preg_match('/^\\d+\\.\\d+\\.\\d+(-[A-Za-z0-9._-]+)?$/',$version)!==1) throw new RuntimeException('Versi release central tidak valid.');
        if (!in_array($target,['core','school','teacher'],true)) throw new RuntimeException('Target release central tidak valid.');
        if (!$this->signatures->targetApplies($target,'school')) throw new RuntimeException("Target signed release {$target} tidak berlaku untuk edition school.");
        if (preg_match('/^[a-f0-9]{64}$/',$sha)!==1) throw new RuntimeException('SHA-256 release central tidak valid.');
        if ($alg!=='Ed25519'||$sig==='') throw new RuntimeException('Signature release central tidak valid.');
        if ($file!=="patch_{$version}_{$target}.zip") throw new RuntimeException('Nama file signed release central tidak sesuai.');
        if (!$this->signatures->verify($version,$target,$sha,$sig)) throw new RuntimeException('Signature Ed25519 manifest central tidak valid.');
        $m['schema']=$schema;$m['application']=$app;$m['version']=$version;$m['target']=$target;
        $m['sha256']=$sha;$m['signature_algorithm']=$alg;$m['signature']=$sig;$m['file']=$file;
        return $m;
    }

    protected function promotePair(string $tz,string $tm,string $fz,string $fm): void
    {
        $n=bin2hex(random_bytes(6)); $bz=null;$bm=null;
        try {
            if (is_file($fz)) { $bz=$fz.'.old.'.$n; if(!rename($fz,$bz)) throw new RuntimeException('Gagal backup ZIP lama.'); }
            if (is_file($fm)) { $bm=$fm.'.old.'.$n; if(!rename($fm,$bm)) throw new RuntimeException('Gagal backup manifest lama.'); }
            if(!rename($tz,$fz)) throw new RuntimeException('Gagal mempromosikan ZIP signed release.');
            if(!rename($tm,$fm)) throw new RuntimeException('Gagal mempromosikan manifest signed release.');
            if($bz)@unlink($bz); if($bm)@unlink($bm);
        } catch(Throwable $e) {
            @unlink($fz);@unlink($fm);
            if($bz&&is_file($bz))@rename($bz,$fz);
            if($bm&&is_file($bm))@rename($bm,$fm);
            throw $e;
        }
    }

    protected function assertZipReadable(string $p): void
    {
        $z=new ZipArchive();$r=$z->open($p);
        if($r!==true) throw new RuntimeException('ZIP release central tidak dapat dibuka.');
        try { if($z->numFiles<1) throw new RuntimeException('ZIP release central tidak memiliki isi.'); } finally {$z->close();}
    }
    protected function assertSchoolEdition(): void
    {
        if(strtolower(trim((string)config('eduxid.edition','school')))!=='school')
            throw new RuntimeException('Central update client hanya boleh digunakan oleh edition school.');
    }
    protected function serverUrl(): string { return rtrim(trim((string)config('eduxid.update_server_url','')),'/'); }
    protected function channel(): string
    {
        $c=strtolower(trim((string)config('eduxid.update_channel','stable')));
        if(!in_array($c,['stable','beta','dev'],true)) throw new RuntimeException('EDUXID_UPDATE_CHANNEL tidak valid.');
        return $c;
    }
    protected function currentVersion(): string
    {
        try{return app(UpdateService::class)->version();}catch(Throwable){return trim((string)config('eduxid.version','1.0.0'));}
    }
    protected function timeout(): int { return max(5,(int)config('eduxid.timeout',15)); }
}
