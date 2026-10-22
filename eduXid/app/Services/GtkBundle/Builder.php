<?php

namespace App\Services\GtkBundle;

use App\Services\Sync\{EduxidCloudConfig, MasterRegistry, MasterSnapshotService, SchoolIdentity};
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\{Cache, Crypt, DB, File, Hash, Schema};
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

class Builder
{
    public function __construct(protected Provisioner $provisioner) {}

    public function fingerprint(): string
    {
        $tables=[];
        foreach (MasterSnapshotService::TABLE_MAP as $key=>$table) {
            $tables[$key]=DB::table($table)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
        }
        return MasterSnapshotService::canonicalHash($tables);
    }

    public function preflight(): array
    {
        if (DB::connection()->getDriverName()!=='sqlite') throw new RuntimeException('Pembuatan paket GTK v1 memerlukan database SQLite. Database sumber tidak diubah.');
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Aktifkan ekstensi ZIP pada PHP admin sebelum membuat paket.');
        foreach (array_merge(array_values(MasterSnapshotService::TABLE_MAP),['users','sync_devices','app_settings','eduxid_settings','gtk_bundle_exports']) as $table) {
            if (!Schema::hasTable($table)) throw new RuntimeException('Tabel '.$table.' belum tersedia; selesaikan migration aplikasi admin.');
        }
        app(SchoolIdentity::class)->schoolId();
        $root=realpath((string)config('gtk_bundle.template_root'));
        $projectName=(string)config('gtk_bundle.project_directory','eduXid');
        $runtimeName=(string)config('gtk_bundle.runtime_directory','php');
        foreach ([$projectName,$runtimeName] as $name) if (!preg_match('/^[A-Za-z0-9_-]+$/D',$name)) throw new RuntimeException('Nama folder template paket tidak valid.');
        if (!$root||!is_dir($root)) throw new RuntimeException('Folder template bundle belum ditemukan.');
        $project=realpath($root.DIRECTORY_SEPARATOR.$projectName);
        $runtime=realpath($root.DIRECTORY_SEPARATOR.$runtimeName);
        if (!$project||!$runtime||!$this->inside($project,$root)||!$this->inside($runtime,$root)) throw new RuntimeException('Folder Laravel/PHP harus berada dalam template bundle.');
        foreach (['artisan','vendor/autoload.php','public/index.php','bootstrap/app.php'] as $file) if (!is_file($project.'/'.$file)) throw new RuntimeException('Template Laravel belum lengkap: '.$file.'. Gunakan bundle aplikasi terpasang, termasuk vendor.');
        foreach (['php.exe','php.ini','extras/ssl/cacert.pem'] as $file) if (!is_file($runtime.'/'.$file)) throw new RuntimeException('PHP portable belum lengkap: '.$file.'.');
        // Paket GTK menggunakan eduXid.exe sebagai satu-satunya launcher di root.
        $launcher=$root.'/eduXid.exe';
        $launcherName='eduXid.exe';
        $launcherReal=realpath($launcher);
        if (!$launcherReal || is_link($launcher) || !$this->inside($launcherReal,$root)
            || !is_readable($launcher) || filesize($launcher)===0) {
            throw new RuntimeException('eduXid.exe asli wajib tersedia di root Paket GTK.');
        }
        $this->validateExecutable($launcherReal);
        $launcher=$launcherReal;
        // Bundel GTK harus membawa executable asli yang sama seperti distribusi sekolah.
        // Jangan silently mengeluarkan ZIP tanpa komponen yang diwajibkan.
        foreach (['eduXid.exe', 'PETUNJUK PENGGUNAAN.txt', 'system/eduXidAutoUpdate.exe', 'system/eduXidUpdater.exe'] as $required) {
            $candidate = $root.DIRECTORY_SEPARATOR.$required;
            $real = realpath($candidate);
            if (!$real || !is_file($candidate) || is_link($candidate) || !$this->inside($real, $root) || !is_readable($candidate) || filesize($candidate) === 0) {
                throw new RuntimeException('File wajib paket GTK tidak ditemukan atau tidak valid: '.$required.'. Periksa root template distribusi.');
            }
            if (str_ends_with(strtolower($required), '.exe')) {
                $this->validateExecutable($real);
            }
        }
        $paths=$this->templateFiles($root,$project,$runtime,$projectName,$runtimeName,$launcher,$launcherName);
        $output=(string)config('gtk_bundle.output_directory');
        File::ensureDirectoryExists($output,0700,true);
        if (!is_writable($output)) throw new RuntimeException('Folder keluaran paket tidak dapat ditulis.');
        return compact('root','project','runtime','projectName','runtimeName','launcher','launcherName','paths','output');
    }

    /**
     * Download name only. Keep Cloud V21 alias, device_id, export UUID and
     * storage filenames untouched; NIP/NIK must never enter Cloud routing.
     */
    public static function archiveNameForTeacher(string $schoolId, int $teacherId): string
    {
        if (preg_match('/^[0-9]{8}$/D', $schoolId) !== 1) {
            throw new RuntimeException('NPSN sekolah tidak valid untuk nama paket GTK.');
        }
        $teacher = DB::table('teachers')->where('id', $teacherId)->first(['nip', 'nik']);
        if (!$teacher) {
            throw new RuntimeException('Data GTK tidak tersedia untuk penamaan paket.');
        }
        // Use authoritative Dapodik fields, never cloud_login_id or export id.
        foreach (['nip', 'nik'] as $field) {
            $number = trim((string) ($teacher->{$field} ?? ''));
            if (preg_match('/^[0-9]{8,24}$/D', $number) === 1) {
                return 'eduXid-GTK-' . $schoolId . '-' . $number . '.zip';
            }
        }
        throw new RuntimeException('GTK belum memiliki NIP/NIK numerik valid pada Data Pokok. Lengkapi data sebelum membuat atau mengunduh paket.');
    }

    public function create(int $teacherId, ?string $serverIp=null): array
    {
        $spec=$this->preflight();
        $v53r2Donation = $this->v53r2DonationPreflight($spec);
        $profile=$this->provisioner->profile($teacherId, true);
        $filename=self::archiveNameForTeacher((string)$profile['school_id'], $teacherId);
        $snapshotHash=$this->fingerprint();
        $cloudUrl=EduxidCloudConfig::syncServer();
        $ready=json_decode((string)DB::table('app_settings')->where('key','gtk_bundle::cloud_ready')->value('value'),true);

        if (!is_array($ready)
            ||($ready['school_id']??'')!==$profile['school_id']
            ||($ready['hash']??'')!==$snapshotHash
            ||($ready['url']??'')!==$cloudUrl) {
            throw new RuntimeException(
                'Siapkan Cloud kembali; Data Pokok dan koneksi paket harus sesuai persiapan terakhir.'
            );
        }

        $lock=Cache::lock('gtk-bundle:'.$profile['device_id'],900);

        if (!$lock->get()) {
            throw new RuntimeException(
                'Paket GTK ini sedang dibuat oleh proses lain. Tunggu proses sebelumnya selesai lalu coba lagi.'
            );
        }

        $work=null;
        $part=null;
        $previous=null;
        $target=null;
        $record=null;
        $oldState=null;

        try {
            $record=DB::table('gtk_bundle_exports')
                ->where('school_id',$profile['school_id'])
                ->where('device_id',$profile['device_id'])
                ->first();

            if (!$record) {
                $id=(string)Str::uuid();

                DB::table('gtk_bundle_exports')->insert([
                    'id'=>$id,
                    'school_id'=>$profile['school_id'],
                    'teacher_id'=>$teacherId,
                    'login_id'=>$profile['login_id'],
                    'device_id'=>$profile['device_id'],
                    'status'=>'pending',
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);

                $record=DB::table('gtk_bundle_exports')->where('id',$id)->first();
            }

            if (!$record) {
                throw new RuntimeException('Record Paket GTK tidak dapat dibuat.');
            }

            $target=$spec['output'].'/'.$record->id.'.zip';
            $part=$spec['output'].'/'.$record->id.'.part.zip';
            $previous=$target.'.previous';

            $oldState=[
                'status'=>(string)($record->status??'pending'),
                'archive_name'=>$record->archive_name??null,
                'sha256'=>$record->sha256??null,
                'size_bytes'=>$record->size_bytes??null,
                'prepared_at'=>$record->prepared_at??null,
                'has_archive'=>is_file($target),
            ];

            /*
             * Rebuild memakai record dan device_id yang sama.
             * Metadata identitas diperbarui bila Data Pokok berubah,
             * tetapi token pairing lama tetap dipakai bila masih valid.
             */
            DB::table('gtk_bundle_exports')
                ->where('id',$record->id)
                ->update([
                    'teacher_id'=>$teacherId,
                    'login_id'=>$profile['login_id'],
                    'device_id'=>$profile['device_id'],
                    'status'=>'building',
                    'updated_at'=>now(),
                ]);

            $token=$this->provisioner->token($profile,$record);

            DB::table('gtk_bundle_exports')
                ->where('id',$record->id)
                ->update([
                    'device_token'=>Crypt::encryptString($token),
                    'updated_at'=>now(),
                ]);

            $work=$spec['output'].'/work-'.Str::uuid();
            File::ensureDirectoryExists($work,0700,true);

            $appKey=random_bytes(32);
            $encrypter=new Encrypter($appKey,'AES-256-CBC');
            $dbPath=$work.'/database.sqlite';

            $metadata=DB::transaction(
                function()use($dbPath,$profile,$encrypter,$token,$serverIp,$snapshotHash,$cloudUrl){
                    if ($snapshotHash!==$this->fingerprint()
                        ||$cloudUrl!==EduxidCloudConfig::syncServer()) {
                        throw new RuntimeException(
                            'Data Pokok atau koneksi Cloud berubah saat persiapan. '
                            .'Siapkan Cloud kembali sebelum membuat paket.'
                        );
                    }

                    return $this->makeDatabase(
                        $dbPath,
                        $profile,
                        $encrypter->encryptString($token),
                        $serverIp
                    );
                }
            );

            $env=$this->environment(
                base64_encode($appKey),
                $profile,
                $spec['runtimeName'],
                $v53r2Donation['public_key']
            );
            $env = $this->v53r2DonationEnv($env, $v53r2Donation["public_key"]);


            if (is_file($part)) {
                File::delete($part);
            }

            if (is_file($previous)) {
                File::delete($previous);
            }

            $zip=new ZipArchive();

            if ($zip->open($part,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) {
                throw new RuntimeException('Arsip paket baru tidak dapat dibuat.');
            }

            try {
                foreach ($spec['paths'] as $relative=>$absolute) {
                    if ($relative==='eduXid-Launcher.bat'
                        &&$spec['launcherName']==='eduXid-Launcher.bat') {
                        continue;
                    }

                    if (!$zip->addFile($absolute,$relative)) {
                        throw new RuntimeException(
                            'Salah satu file template tidak dapat dimasukkan ke paket.'
                        );
                    }
                }

                $base=$spec['projectName'];
                if (!$zip->addFromString($base."/security/donation-integrity-v1.json", $v53r2Donation["manifest_raw"])) {
                    throw new \RuntimeException("Manifest Donasi resmi gagal dimasukkan ke ZIP GTK.");
                }

                if ($spec['launcherName']==='eduXid-Launcher.bat'
                    &&!$zip->addFromString(
                        'eduXid-Launcher.bat',
                        $this->launcher($spec['launcher'],$base)
                    )) {
                    throw new RuntimeException('Launcher paket gagal dimasukkan.');
                }

                if (
                    !$zip->addFile($dbPath,$base.'/database/database.sqlite')
                    ||!$zip->addFromString($base.'/.env',$env)
                    ||!$zip->addFromString(
                        $base.'/storage/app/installation.json',
                        json_encode(
                            $metadata,
                            JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR
                        )
                    )
                    ||!$zip->addFromString(
                        'MULAI_GTK.txt',
                        $this->instructions($profile,$spec['launcherName'])
                    )
                ) {
                    throw new RuntimeException(
                        'Data konfigurasi paket gagal dimasukkan.'
                    );
                }

                foreach ([
                    'storage/app/private',
                    'storage/app/public',
                    'storage/framework/cache/data',
                    'storage/framework/sessions',
                    'storage/framework/views',
                    'storage/logs',
                    'bootstrap/cache',
                ] as $dir) {
                    $zip->addEmptyDir($base.'/'.$dir);
                }

                if (!$zip->close()) {
                    throw new RuntimeException('Arsip paket baru belum selesai ditulis.');
                }
            } catch (Throwable $e) {
                try { $zip->close(); } catch (Throwable) {}
                throw $e;
            }

            // Only finalize a completely closed ZIP. Previously this call was
            // mistakenly inside catch, so successful bundles had no BND1 ticket.
            // Fail BEFORE publishing a READY bundle if auto-pair cannot finish.
            app(AutoPairBundleService::class)->finalizeZip((string) $part);
            $this->v53r2DonationAuditZip($part, $spec["projectName"], $v53r2Donation);

            $check=new ZipArchive();

            if ($check->open($part,ZipArchive::CHECKCONS)!==true) {
                throw new RuntimeException(
                    'Arsip Paket GTK baru tidak lolos pemeriksaan ZIP.'
                );
            }

            // Fail closed before an archive replaces the previous GTK release.
            $this->assertGtkZipDonationInventoryV21R53($check, $spec['projectName'], $v53r2Donation['public_key']);
            $check->close();

            /*
             * Atomic-like replacement:
             * paket lama baru dipindahkan setelah paket baru selesai
             * dan lolos CHECKCONS.
             */
            if (is_file($target)) {
                if (!@rename($target,$previous)) {
                    throw new RuntimeException(
                        'Paket GTK lama sedang digunakan atau dikunci Windows. '
                        .'Tutup proses download/Explorer yang sedang memakai ZIP lalu coba Buat Ulang lagi.'
                    );
                }
            }

            if (!@rename($part,$target)) {
                if (is_file($previous) && !is_file($target)) {
                    @rename($previous,$target);
                }

                throw new RuntimeException(
                    'Paket GTK baru tidak dapat menggantikan arsip lama.'
                );
            }

            try {
                DB::transaction(
                    function()use($record,$profile,$filename,$target):void {
                        DB::table('gtk_bundle_exports')
                            ->where('id',$record->id)
                            ->update([
                                'teacher_id'=>$profile['teacher_id'],
                                'login_id'=>$profile['login_id'],
                                'device_id'=>$profile['device_id'],
                                'status'=>'ready',
                                'archive_name'=>$filename,
                                'sha256'=>hash_file('sha256',$target),
                                'size_bytes'=>filesize($target),
                                'prepared_at'=>now(),
                                'updated_at'=>now(),
                            ]);

                        DB::table('sync_devices')
                            ->where('school_id',$profile['school_id'])
                            ->where('device_id',$profile['device_id'])
                            ->update([
                                'is_active'=>false,
                                'updated_at'=>now(),
                            ]);
                    }
                );
            } catch (Throwable $e) {
                /*
                 * Jika metadata gagal, pulihkan ZIP lama dan metadata lama.
                 */
                if (is_file($previous)) {
                    if (is_file($target)) {
                        @unlink($target);
                    }

                    @rename($previous,$target);
                }

                throw $e;
            }

            if (is_file($previous)) {
                File::delete($previous);
            }

            return [
                'id'=>$record->id,
                'name'=>$profile['name'],
                'archive_name'=>$filename,
                'device_id'=>$profile['device_id'],
                'size_bytes'=>filesize($target),
                'sha256'=>hash_file('sha256',$target),
                'rebuilt'=>$oldState['has_archive'],
            ];
        } catch (Throwable $e) {
            /*
             * Build gagal:
             * - jangan hilangkan Paket GTK lama yang sebelumnya READY;
             * - pulihkan .previous bila replacement sempat dimulai;
             * - status menjadi READY kembali jika arsip lama masih ada,
             *   sehingga tombol Download/Buat Ulang tetap tersedia.
             */
            if ($target && $previous && is_file($previous) && !is_file($target)) {
                @rename($previous,$target);
            }

            if ($record) {
                $hasUsableArchive=$target && is_file($target);

                $restore=[
                    'status'=>$hasUsableArchive && ($oldState['has_archive']??false)
                        ? 'ready'
                        : 'failed',
                    'updated_at'=>now(),
                ];

                if ($hasUsableArchive && is_array($oldState)) {
                    $restore['archive_name']=$oldState['archive_name'];
                    $restore['sha256']=$oldState['sha256'];
                    $restore['size_bytes']=$oldState['size_bytes'];
                    $restore['prepared_at']=$oldState['prepared_at'];
                }

                try {
                    DB::table('gtk_bundle_exports')
                        ->where('id',$record->id)
                        ->update($restore);
                } catch (Throwable) {
                }
            }

            throw $e;
        } finally {
            if ($work&&is_dir($work)) {
                File::deleteDirectory($work);
            }

            if ($part&&is_file($part)) {
                File::delete($part);
            }

            if ($previous&&is_file($previous) && $target && is_file($target)) {
                File::delete($previous);
            }

            $lock->release();
        }
    }

    private function makeDatabase(string $path,array $p,string $encryptedToken,?string $serverIp): array
    {
        $pdo=new PDO('sqlite:'.$path); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $schema=DB::select("SELECT type,name,sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 WHEN 'view' THEN 2 ELSE 3 END,name");
        $pdo->beginTransaction();
        foreach ($schema as $s) if ($s->type==='table') $pdo->exec($s->sql);
        $now=now()->format('Y-m-d H:i:s');
        $owner=$p['source_user_id']? (array)DB::table('users')->where('id',$p['source_user_id'])->first():[];
        if ($owner===[]) {
            $owner=['id'=>(int)DB::table('users')->max('id')+$p['teacher_id']+1,'name'=>$p['name'],
                'email'=>$p['login_id'].'@eduxid.id','password'=>Hash::make($p['school_id']),'must_change_password'=>true,'created_at'=>$now];
        }
        $owner=array_merge($owner,['login_id'=>$p['login_id'],'role'=>$p['role'],'remember_token'=>null,'email_verified_at'=>$now,'updated_at'=>$now]);
        foreach (['two_factor_secret','two_factor_recovery_codes','api_token'] as $key) if (array_key_exists($key,$owner)) $owner[$key]=null;
        $this->insert($pdo,'users',$owner);
        $userId=(int)$owner['id'];
        // Siswa LAN tetap dapat login pada server guru; akun GTK/admin lain tidak disalin.
        DB::table('users')->whereIn('role',['siswa','student'])->orderBy('id')->chunk(200,function($rows)use($pdo){
            foreach($rows as $row) { $r=(array)$row;$r['remember_token']=null;
                foreach(['two_factor_secret','two_factor_recovery_codes','api_token'] as $key) if(array_key_exists($key,$r))$r[$key]=null;
                $this->insert($pdo,'users',$r);
            }
        });
        foreach (MasterSnapshotService::TABLE_MAP as $table) {
            DB::table($table)->orderBy('id')->chunk(200,function($rows)use($pdo,$p,$userId,$table){
                foreach($rows as $row) { $r=(array)$row;
                    if($table==='teachers'&&array_key_exists('user_id',$r)) $r['user_id']=(int)$r['id']===$p['teacher_id']?$userId:null;
                    if($table==='students'&&array_key_exists('user_id',$r)&&$r['user_id']!==null) {
                        $s=DB::table('users')->where('id',$r['user_id'])->whereIn('role',['siswa','student'])->exists();
                        if(!$s)$r['user_id']=null;
                    }
                    if($table==='enrollments') {
                        $wali=(int)DB::table('homerooms')->where('id',$r['homeroom_id'])->value('homeroom_teacher_id');
                        if($wali!==$p['teacher_id']) {
                            if(array_key_exists('catatan_wali',$r))$r['catatan_wali']=null;
                            if(array_key_exists('catatan_wali_manual',$r))$r['catatan_wali_manual']=0;
                            foreach(['rapor_sakit','rapor_izin','rapor_alpa'] as $f)if(array_key_exists($f,$r))$r[$f]=0;
                        }
                    }
                    $this->insert($pdo,$table,$r);
                }
            });
        }
        foreach(['migrations','app_versions','bk_rules','kartu_pelajar_settings'] as $table)if(Schema::hasTable($table)) {
            foreach(DB::table($table)->get() as $r)$this->insert($pdo,$table,(array)$r);
        }
        $settings=[
            'installation::type'=>'teacher','installation::school_id'=>$p['school_id'],
            'installation::owner_login_id'=>$p['login_id'],'installation::owner_device_id'=>$p['device_id'],
            'installation::channels'=>'core,teacher','gtk_bundle::prepared_at'=>$now,
            MasterRegistry::KEY_APPLIED_VERSION=>MasterRegistry::get(MasterRegistry::KEY_PUSHED_VERSION)??'0',
            MasterRegistry::KEY_APPLIED_HASH=>MasterRegistry::get(MasterRegistry::KEY_PUSHED_HASH)??'',
            MasterRegistry::KEY_APPLIED_AT=>$now,'master::owner_device'=>'school::'.$p['school_id'],
        ];
        if($serverIp)$settings['server_ip']=$serverIp;
        foreach($settings as $key=>$value)$this->insert($pdo,'app_settings',['key'=>$key,'value'=>$value,'created_at'=>$now,'updated_at'=>$now]);
        $this->insert($pdo,'eduxid_settings',['key'=>'sync_server','value'=>EduxidCloudConfig::syncServer(),'updated_at'=>$now]);
        $this->insert($pdo,'sync_devices',['school_id'=>$p['school_id'],'device_id'=>$p['device_id'],'device_type'=>'teacher',
            'user_id'=>$userId,'device_name'=>'eduXid '.$p['name'],'sync_token'=>$encryptedToken,
            'is_active'=>true,'sync_cursor'=>0,'last_synced_at'=>null,'created_at'=>$now,'updated_at'=>$now]);
        foreach($schema as $s)if($s->type!=='table')$pdo->exec($s->sql);
        $pdo->commit();
        if($pdo->query('PRAGMA foreign_key_check')->fetchAll()!==[])throw new RuntimeException('Data paket memiliki referensi yang belum lengkap; perbaiki Data Pokok sumber.');
        $pdo=null;
        return ['format'=>1,'installation_type'=>'teacher','school_id'=>$p['school_id'],'owner_teacher_id'=>$p['teacher_id'],
            'owner_login_id'=>$p['login_id'],'owner_device_id'=>$p['device_id'],'channels'=>['core','teacher'],
            'prepared_at'=>$now,'master_hash'=>$this->fingerprint()];
    }

    private function insert(PDO $pdo,string $table,array $row): void
    {
        $info=$pdo->query('PRAGMA table_info('.$this->quote($table).')')->fetchAll(PDO::FETCH_ASSOC);
        $columns=array_column($info,'name'); $row=array_intersect_key($row,array_flip($columns));
        if($row===[])return;
        $sql='INSERT INTO '.$this->quote($table).' ('.implode(',',array_map($this->quote(...),array_keys($row))).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')';
        $pdo->prepare($sql)->execute(array_values($row));
    }

    private function quote(string $name): string { return '"'.str_replace('"','""',$name).'"'; }

    private function environment(string $key,array $p,string $runtime, string $publisherPublic): string
    {
        $url=EduxidCloudConfig::syncServer();
        if(preg_match('/[\r\n"\\\\]/',$url))throw new RuntimeException('URL Cloud tidak valid untuk konfigurasi paket.');
        if (\App\Services\Security\DonationIntegrityV21::decodePublic($publisherPublic) === null) {
            throw new RuntimeException('Public key Ed25519 resmi belum tersedia untuk Paket GTK.');
        }
        return "APP_NAME=eduXid\nAPP_ENV=production\nAPP_KEY=base64:$key\nAPP_DEBUG=false\nAPP_URL=http://127.0.0.1:8000\nAPP_TIMEZONE=Asia/Jakarta\nAPP_LOCALE=id\nDB_CONNECTION=sqlite\nDB_FOREIGN_KEYS=true\nSESSION_DRIVER=file\nCACHE_STORE=file\nQUEUE_CONNECTION=sync\nLOG_CHANNEL=single\nEDUXID_SYNC_SERVER=\"$url\"\nEDUXID_SYNC_URL=\"$url\"\nEDUXID_SCHOOL_ID={$p['school_id']}\nEDUXID_NPSN={$p['school_id']}\nEDUXID_INSTALLATION_TYPE=teacher\nEDUXID_EDITION=teacher\nEDUXID_IDENTITY_PROTOCOL=v21-opaque\nEDUXID_SCHOOL_ALIAS_KEY=\nEDUXID_DEVICE_ID={$p['device_id']}\nEDUXID_UPDATE_PUBLIC_KEY={$publisherPublic}\nEDUXID_UPDATE_CHANNELS=core,teacher\n";
    }

    private function instructions(array $p,string $launcherName): string
    {
        $maskedLogin = \App\Support\SensitiveDataProtector::maskIdentifier($p['login_id']);
        return "EDUXID — PAKET GTK SIAP PAKAI\r\nPemilik: {$p['name']}\r\nSekolah: {$p['school_id']}\r\nLogin: {$maskedLogin}\r\n\r\nEkstrak seluruh paket ke folder baru di laptop pemilik, lalu jalankan $launcherName.\r\nLogin memakai password akun yang disiapkan admin. Jika akun baru, password awal adalah NPSN sekolah; ganti melalui Profil.\r\nData Pokok dan pairing PC sudah disiapkan. Tidak perlu tarik master atau memasukkan kode pairing untuk memulai.\r\nKoneksi internet diperlukan untuk sinkronisasi berikutnya; administrasi lokal tetap dapat dipakai offline.\r\nPaket ini untuk satu GTK. Gunakan folder baru; jangan menimpa pekerjaan lama.\r\nPWA HP memakai login PWA masing-masing dan identitas -HP yang terpisah.\r\nAbsensi rapor berasal dari input wali kelas; absensi Buku Nilai tetap terpisah.\r\n";
    }

    private function launcher(string $source,string $project): string
    {
        // Junction dibuat pada laptop tujuan; jangan membawa public/storage milik admin.
        $setup="@echo off\r\nif not exist \"%~dp0$project\\public\\storage\" (\r\n    mklink /J \"%~dp0$project\\public\\storage\" \"%~dp0$project\\storage\\app\\public\" >NUL 2>&1\r\n    if errorlevel 1 echo [INFO] Folder penyimpanan publik belum dapat ditautkan. Gunakan folder lokal pada drive NTFS.\r\n)\r\n";
        $original=file_get_contents($source);
        if ($original===false) throw new RuntimeException('Launcher template tidak dapat dibaca.');
        return $setup.$original;
    }

    private function inside(string $path,string $root): bool
    {
        return str_starts_with(str_replace('\\','/',$path).'/',rtrim(str_replace('\\','/',$root),'/').'/');
    }

    private function validateExecutable(string $path): void
    {
        $handle=fopen($path,'rb');
        if ($handle===false) throw new RuntimeException(basename($path).' tidak dapat dibaca.');
        try {
            $header=fread($handle,64);
            $offset=is_string($header)&&strlen($header)===64?unpack('Voffset',substr($header,60,4))['offset']:0;
            if (!is_string($header)||substr($header,0,2)!=='MZ'||$offset<64||$offset>filesize($path)-4||fseek($handle,$offset)!==0||fread($handle,4)!=="PE\0\0") {
                throw new RuntimeException(basename($path).' harus executable Windows asli, bukan BAT yang diganti ekstensinya.');
            }
        } finally { fclose($handle); }
    }

    private function templateFiles(string $root,string $project,string $runtime,string $projectName,string $runtimeName,string $launcher,string $launcherName): array
    {
        $files=[$launcherName=>$launcher];
        foreach (['eduXid.exe','PETUNJUK PENGGUNAAN.txt','system/eduXidAutoUpdate.exe','system/eduXidUpdater.exe'] as $required) {
            $files[$required] = $root.'/'.$required;
        }

        // Ketiga PHP runtime berada di system, tidak ada bridge di root.
        foreach (['eduXidAutoUpdateBootstrap.php','eduXidUpdateFiles.php','eduXidUpdateSnapshot.php'] as $bridge) {
            $candidate=$root.'/system/'.$bridge;
            $resolved=realpath($candidate);
            if (!$resolved || is_link($candidate) || !$this->inside($resolved,$root)
                || !is_readable($candidate) || filesize($candidate)===0) {
                throw new RuntimeException('Bridge PHP updater tidak ditemukan: '.$bridge);
            }
            $files['system/'.$bridge]=$resolved;
        }
        $requiredUpdateFiles=[
            'PASANG_AUTO_UPDATE.bat','eduXidUpdateAgent.bat','eduXidUpdateRun.cmd',
            'eduXidUpdater.bat','eduXidUpdateFiles.php','eduXidUpdateSnapshot.php',
            // V21R159: new GTK bundles must contain the cloud-only ZIP selector and migration policy.
            'EDUXID_SELECT_PATCH_V21R154.php','EDUXID_MIGRATION_POLICY_V21R156.php',
        ];
        foreach ($requiredUpdateFiles as $helper) {
            $candidate=$root.'/system/update/'.$helper;
            $resolved=realpath($candidate);
            if (!$resolved || is_link($candidate) || !$this->inside($resolved,$root)
                || !is_readable($candidate) || filesize($candidate)===0) {
                throw new RuntimeException('Helper Auto-Update internal hilang: system/update/'.$helper);
            }
            $files['system/update/'.$helper]=$resolved;
        }
        if (is_file($root.'/eduxid-version.json')) $files['eduxid-version.json']=$root.'/eduxid-version.json';

        // [V21R53] Only the publisher-signed donation inventory may travel to GTK.
        // Never export publisher private keys or the whole security directory.
        $this->assertPublisherDonationInventoryV21R53($project);
        $signedManifest = $project.'/security/donation-integrity-v1.json';
        $realManifest = realpath($signedManifest);
        if (!$realManifest || is_link($signedManifest) || !$this->inside($realManifest, $project)) {
            throw new RuntimeException('Manifest integritas Donasi resmi tidak aman untuk dipaketkan.');
        }
        $files[$projectName.'/security/donation-integrity-v1.json'] = $realManifest;

        $dirs=['app','bootstrap','config','database','lang','public','resources','routes','vendor'];
        foreach($dirs as $dir)if(is_dir($project.'/'.$dir))$this->collect($project.'/'.$dir,$project,$projectName,$files);
        foreach(['artisan','composer.json','composer.lock'] as $f)if(is_file($project.'/'.$f))$files[$projectName.'/'.$f]=$project.'/'.$f;
        $this->collect($runtime,$runtime,$runtimeName,$files,true);
        // Certificat public bawaan PHP ikut disalin; material kunci privat tidak.
        // Hanya aset publik sekolah, tanpa file LMS/jawaban/laporan atau kunci release.
        foreach(['foto_siswa','foto_guru','logos','logo','kop_surat','tanda_tangan'] as $dir)if(is_dir($project.'/storage/app/public/'.$dir))
            $this->collect($project.'/storage/app/public/'.$dir,$project,$projectName,$files);
        ksort($files); return $files;
    }

    /**
     * [V21R53] Periksa tanda tangan penerbit dan semua file terlindungi pada
     * template SEKOLAH sebelum menerbitkan Paket GTK. Jangan pernah menonaktifkan
     * audit Donasi untuk menutupi mismatch.
     */
    private function assertPublisherDonationInventoryV21R53(string $project): void
    {
        $verifier = \App\Services\Security\DonationIntegrityV21::class;
        $public = trim((string) config('eduxid.update_public_key', ''));
        $manifestPath = $project.'/security/donation-integrity-v1.json';
        if (!is_file($manifestPath) || is_link($manifestPath) || !is_readable($manifestPath)) {
            throw new RuntimeException('Manifest Donasi Ed25519 tidak ada. Terbitkan rilis resmi pada Master sebelum membuat Paket GTK.');
        }
        $raw = @file_get_contents($manifestPath);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || !$verifier::verifySignedManifest($manifest, $public)) {
            throw new RuntimeException('Tanda tangan manifest Donasi atau public key penerbit tidak valid.');
        }
        foreach ($manifest['files'] as $relative => $digest) {
            $file = $project.'/'.str_replace('\\', '/', $relative);
            $resolved = realpath($file);
            if (!$resolved || !is_file($resolved) || is_link($file)
                || !$this->inside($resolved, $project)
                || !hash_equals($digest, (string) @hash_file('sha256', $resolved))) {
                throw new RuntimeException('Source Master tidak cocok dengan manifest Donasi: '.$relative);
            }
        }
    }

    /**
     * [V21R53] Paket GTK tak boleh diumumkan siap bila salah satu file dalam ZIP
     * tidak sesuai inventaris Ed25519, bahkan jika ZIP strukturalnya valid.
     */
    private function assertGtkZipDonationInventoryV21R53(ZipArchive $zip, string $projectName, string $public): void
    {
        $verifier = \App\Services\Security\DonationIntegrityV21::class;
        $raw = $zip->getFromName($projectName.'/security/donation-integrity-v1.json');
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || !$verifier::verifySignedManifest($manifest, $public)) {
            throw new RuntimeException('Paket GTK tidak membawa manifest Donasi Ed25519 yang sah.');
        }
        foreach ($manifest['files'] as $relative => $digest) {
            $data = $zip->getFromName($projectName.'/'.$relative);
            if (!is_string($data) || !hash_equals($digest, hash('sha256', $data))) {
                throw new RuntimeException('File Paket GTK berbeda dari manifest Donasi: '.$relative);
            }
        }
        $env = $zip->getFromName($projectName.'/.env');
        $matches = [];
        if (!is_string($env)
            || preg_match_all('/^EDUXID_UPDATE_PUBLIC_KEY=([^\r\n]*)\r?$/m', $env, $matches) !== 1
            || !hash_equals($public, trim((string) ($matches[1][0] ?? '')))) {
            throw new RuntimeException('Paket GTK tidak membawa public key Ed25519 yang sesuai.');
        }
    }

    private function collect(string $dir,string $root,string $prefix,array &$files,bool $runtime=false): void
    {
        if(is_link($dir))throw new RuntimeException('Template mengandung symlink; gunakan file asli di dalam bundle.');
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)) as $f) {
            if($f->isLink())continue;
            if(!$f->isFile())continue;
            $relative=str_replace('\\','/',substr($f->getPathname(),strlen($root)+1));
            $parts=explode('/',$relative);
            if(array_filter($parts,fn($v)=>str_starts_with($v,'.')||in_array(strtolower($v),['node_modules','tests','logs','backups','releases','patches','gtk-bundles','eduxid-ini-scan'],true)))continue;
            // Cache runtime dibuang; vendor/psr/cache dan kelas Illuminate/Cache wajib ikut.
            if($runtime&&in_array('cache',array_map('strtolower',$parts),true))continue;
            if(preg_match('/\.(sqlite[0-9]*|db|log|sql|bak|key|p12|pfx)$/i',$relative)||str_contains(strtolower($relative),'private-key')||str_contains(strtolower($relative),'signing-key'))continue;
            if(str_ends_with(strtolower($relative),'.pem')&&!in_array(strtolower(basename($relative)),['cacert.pem','public.pem','public-key.pem'],true))continue;
            if(str_starts_with($relative,'database/')&&!str_starts_with($relative,'database/migrations/')&&!str_starts_with($relative,'database/seeders/')&&!str_starts_with($relative,'database/factories/'))continue;
            if(str_starts_with($relative,'bootstrap/cache/'))continue;
            if($relative==='public/storage'||str_starts_with($relative,'public/storage/'))continue;
            if(is_link($f->getPathname())||!is_readable($f->getPathname()))throw new RuntimeException('Template mempunyai file yang tidak dapat dibaca.');
            $files[$prefix.'/'.$relative]=$f->getPathname();
        }
    }

    /** Verify publisher-signed Master inventory before building any GTK archive. */
    private function v53r2DonationPreflight(array $spec): array
    {
        $app = rtrim((string) ($spec['project'] ?? ''), '/\\');
        $projectName = (string) ($spec['projectName'] ?? '');
        if ($app === '' || !is_dir($app) || !preg_match('/^[A-Za-z0-9_-]+$/D', $projectName)) {
            throw new \RuntimeException('Template GTK Donasi belum valid.');
        }
        $guardFile = $app.'/app/Services/Security/DonationIntegrityV21.php';
        if (!is_file($guardFile) || is_link($guardFile)
            || !hash_equals('49e9ce0f096f562b20aa42109d7656ec0738a12c8182daa1cb14f0e0c39586ce', (string) hash_file('sha256', $guardFile))) {
            throw new \RuntimeException('Verifier integritas Donasi pada Master tidak sesuai baseline resmi.');
        }
        $guard = \App\Services\Security\DonationIntegrityV21::class;
        $envFile = $app.'/.env';
        if (!is_file($envFile) || is_link($envFile)) throw new \RuntimeException('Konfigurasi Master tidak tersedia.');
        $masterEnv = file_get_contents($envFile);
        if (!is_string($masterEnv)) throw new \RuntimeException('Konfigurasi Master tidak terbaca.');
        // Cache konfigurasi Laravel yang sudah memuat kunci resmi dapat menjadi
        // fallback bila .env templat kehilangan barisnya. Kunci palsu/duplikat
        // tetap ditolak dan manifest Ed25519 tetap HARUS diverifikasi.
        $pub = PublisherKeyResolver::resolve($masterEnv, (string) config('eduxid.update_public_key', ''));
        $manifestFile = $app.'/security/donation-integrity-v1.json';
        if (!is_file($manifestFile) || is_link($manifestFile)) {
            throw new \RuntimeException('Manifest Donasi Ed25519 resmi belum tersedia di Master.');
        }
        $raw = file_get_contents($manifestFile);
        $manifest = is_string($raw) && strlen($raw) <= 262144 ? json_decode($raw, true) : null;
        if (!is_array($manifest) || !$guard::verifySignedManifest($manifest, $pub)) {
            throw new \RuntimeException('Tanda tangan manifest Donasi Master tidak valid.');
        }
        foreach ($manifest['files'] as $relative => $hash) {
            $file = $app.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $zipName = $projectName.'/'.$relative;
            $templateFile = $spec['paths'][$zipName] ?? null;
            if (!is_string($templateFile) || !is_file($file) || is_link($file)
                || !is_file($templateFile) || is_link($templateFile)
                || !hash_equals($hash, (string) hash_file('sha256', $file))
                || !hash_equals($hash, (string) hash_file('sha256', $templateFile))) {
                throw new \RuntimeException('Inventaris Donasi GTK tidak cocok: '.$relative);
            }
        }
        $guardInZip=$spec['paths'][$projectName.'/app/Services/Security/DonationIntegrityV21.php']??null;
        if (!is_string($guardInZip) || !is_file($guardInZip) || !hash_equals(
            '49e9ce0f096f562b20aa42109d7656ec0738a12c8182daa1cb14f0e0c39586ce',
            (string) hash_file('sha256',$guardInZip)
        )) throw new \RuntimeException('Verifier Donasi tidak ikut dalam template GTK.');
        return ['public_key' => $pub, 'manifest_raw' => $raw, 'manifest' => $manifest];
    }

    /** Only a publisher PUBLIC key is transmitted to GTK. */
    private function v53r2DonationEnv(string $env, string $pub): string
    {
        $env = preg_replace('/^EDUXID_UPDATE_PUBLIC_KEY[ \t]*=[^\r\n]*(?:\r?\n|\z)/m', '', $env);
        if (!is_string($env) || preg_match('/^EDUXID_UPDATE_PRIVATE_KEY[ \t]*=[ \t]*\S/m', $env)) {
            throw new \RuntimeException('Template .env GTK berisi konfigurasi penerbit privat yang tidak aman.');
        }
        return rtrim($env, "\r\n")."\nEDUXID_UPDATE_PUBLIC_KEY=".$pub."\n";
    }

    /** Re-audit ZIP after AutoPairBundleService has finished modifying it. */
    private function v53r2DonationAuditZip(string $zipPath, string $base, array $source): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('ZIP GTK gagal dibuka untuk audit Donasi.');
        }
        try {
            $manifestBytes=$zip->getFromName($base.'/security/donation-integrity-v1.json');
            if (!is_string($manifestBytes) || !hash_equals(hash('sha256',$source['manifest_raw']),hash('sha256',$manifestBytes))) {
                throw new \RuntimeException('ZIP GTK kehilangan manifest Donasi resmi.');
            }
            $envBytes=$zip->getFromName($base.'/.env');
            if (!is_string($envBytes) || preg_match_all('/^EDUXID_UPDATE_PUBLIC_KEY[ \t]*=[ \t]*([^\r\n]*)\r?$/m',$envBytes,$matches)!==1
                || trim(trim($matches[1][0])," \t\"'")!==$source['public_key']
                || preg_match('/^EDUXID_UPDATE_PRIVATE_KEY[ \t]*=[ \t]*\S/m',$envBytes)) {
                throw new \RuntimeException('ZIP GTK tidak memiliki public key Ed25519 yang benar.');
            }
            $guard=\App\Services\Security\DonationIntegrityV21::class;
            $manifest=json_decode($manifestBytes,true);
            if (!is_array($manifest) || !$guard::verifySignedManifest($manifest,$source['public_key'])) {
                throw new \RuntimeException('Signature Ed25519 pada ZIP GTK tidak valid.');
            }
            $verifier=$zip->getFromName($base.'/app/Services/Security/DonationIntegrityV21.php');
            if (!is_string($verifier) || !hash_equals(
                '49e9ce0f096f562b20aa42109d7656ec0738a12c8182daa1cb14f0e0c39586ce',hash('sha256',$verifier))) {
                throw new \RuntimeException('Verifier integritas GTK tidak cocok.');
            }
            foreach ($manifest['files'] as $relative=>$expected) {
                $bytes=$zip->getFromName($base.'/'.$relative);
                if (!is_string($bytes) || !hash_equals($expected,hash('sha256',$bytes))) {
                    throw new \RuntimeException('ZIP GTK memiliki file Donasi tidak cocok: '.$relative);
                }
            }
        } finally {
            $zip->close();
        }
    }
}
