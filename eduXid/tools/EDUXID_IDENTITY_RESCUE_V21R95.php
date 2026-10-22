<?php
/**
 * EDUXID V21R95 -- school alias rescue (operator-initiated, no Cloud writes).
 * PHP 8.3 CLI. No external packages, no raw secrets in logs, no network calls.
 * --scan, --plan or --restore. School scope always checked against D1 epoch export.
 * --restore refuses an existing alias registry and any mismatch of the active DB.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
ini_set('display_errors', '0');
error_reporting(E_ALL);
final class SchoolIdentityRescueV21R95 {
    private const MAX_ENV = 131072;
    private const MAX_FILES = 40000;
    private const MAX_ZIP = 104857600;
    private const MAX_ARCHIVE_MEMBERS = 12000;

    public static function args(array $args): array {
        $result=[];
        foreach ($args as $arg) {
            if (in_array($arg,['--scan','--plan','--restore'],true)) {
                if (isset($result['action'])) throw new RuntimeException('Aksi lebih dari satu.');
                $result['action']=substr($arg,2); continue;
            }
            if (!preg_match('/^--(target|school|cloud-epoch-file|search-root|candidate-env|confirm)=(.+)$/D', $arg, $m))
                throw new RuntimeException('Parameter tidak dikenal. Lihat panduan.');
            $k=$m[1]; if ($k==='search-root') { $result[$k][]=$m[2]; continue; }
            if (isset($result[$k])) throw new RuntimeException('Parameter ganda: '.$k);
            $result[$k]=$m[2];
        }
        if (!isset($result['action'],$result['target'],$result['school'],$result['cloud-epoch-file']))
            throw new RuntimeException('Wajib --scan|--plan|--restore --target=ROOT_ADMIN --school=NPSN --cloud-epoch-file=FILE.');
        if (preg_match('/^[0-9]{8}$/D',$result['school'])!==1) throw new RuntimeException('NPSN harus delapan digit.');
        if ($result['action']==='restore' && ($result['confirm']??'')!=='RESTORE_IDENTITY_AFTER_BACKUP')
            throw new RuntimeException('Restore membutuhkan --confirm=RESTORE_IDENTITY_AFTER_BACKUP dan penghentian aplikasi.');
        if (in_array($result['action'],['plan','restore'],true) &&
            (!isset($result['candidate-env']) || isset($result['candidate-zip']) || isset($result['search-root'])))
            throw new RuntimeException('Plan/restore mewajibkan tepat satu --candidate-env=FILE .env lama.');
        return $result;
    }
    public static function resolveProject(string $path): string {
        $root=realpath($path);
        if ($root===false) throw new RuntimeException('Folder instalasi tidak ditemukan.');
        $root=rtrim($root,'/\\');
        if (is_file($root.'/eduXid/artisan')) $root.='/eduXid';
        if (!is_file($root.'/artisan') || !is_file($root.'/.env'))
            throw new RuntimeException('Target wajib instalasi Admin dengan eduXid/artisan dan .env.');
        if (is_file(dirname($root).'/eduxid-cloud/src/index.js') ||
            is_file(dirname($root).'/BUAT_PAKET_DISTRIBUSI_EDUXID_FINAL_V3_1.bat'))
            throw new RuntimeException('Master/builder D: bukan instalasi Admin Sekolah; gunakan distribusi di C:.');
        return $root;
    }
    public static function parseEnv(string $source): array {
        $out=[];
        foreach (preg_split('/\r\n|\n|\r/',$source) ?: [] as $line) {
            if (!preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/D',$line,$m))continue;
            if (array_key_exists($m[1],$out))throw new RuntimeException('Entri .env ganda.');
            $val=$m[2];
            if (strlen($val)>1 && in_array($val[0],['"',"'"],true) && substr($val,-1)===$val[0])$val=substr($val,1,-1);
            $out[$m[1]]=$val;
        }
        return $out;
    }
    public static function readEnv(string $file): array {
        if (!is_file($file) || !is_readable($file) || filesize($file)>self::MAX_ENV)
            throw new RuntimeException('File .env tidak tersedia/terlalu besar.');
        $s=file_get_contents($file); if (!is_string($s))throw new RuntimeException('Tidak dapat membaca konfigurasi.');
        return self::parseEnv($s);
    }
    public static function secret(array $env): ?string {
        $raw=trim((string)($env['EDUXID_SCHOOL_ALIAS_KEY']??''));
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$raw))return null;
        $bin=base64_decode(strtr($raw,'-_','+/').'=',true);
        if (!is_string($bin) || strlen($bin)!==32 || rtrim(strtr(base64_encode($bin),'+/','-_'),'=')!==$raw)return null;
        return $bin;
    }
    public static function epoch(string $secret,string $school): string {
        return hash_hmac('sha256',"eduxid-v21-directory-epoch:v1\0".$school,$secret);
    }
    public static function readCloudEpoch(string $file,string $school): string {
        if (!is_file($file)||!is_readable($file)||filesize($file)>1048576)throw new RuntimeException('File ekspor epoch D1 tidak tersedia/terlalu besar.');
        $data=file_get_contents($file);
        if (!is_string($data))throw new RuntimeException('File epoch tidak terbaca.');
        $json=json_decode(trim($data),true);
        if (!is_array($json))throw new RuntimeException('Gunakan JSON Wrangler berisi school_id dan identity_epoch, bukan string epoch saja.');
        $rows=[];
        $visit=static function ($item) use (&$visit,&$rows) {
            if (!is_array($item))return;
            if (array_key_exists('identity_epoch',$item)) {
                // Refuse an epoch without a school identifier: no cross-school confusion.
                if (!array_key_exists('school_id',$item))throw new RuntimeException('Query D1 harus SELECT school_id, identity_epoch.');
                if (preg_match('/^[0-9]{8}$/D',(string)$item['school_id'])!==1 ||
                    !is_string($item['identity_epoch']) ||
                    preg_match('/^[a-f0-9]{64}$/D',$item['identity_epoch'])!==1)
                    throw new RuntimeException('Baris D1 identitas tidak valid.');
                $rows[]=$item;return;
            }
            foreach($item as $value)if (is_array($value))$visit($value);
        };
        $visit($json);
        if (count($rows)!==1 || !hash_equals($school,(string)$rows[0]['school_id']))
            throw new RuntimeException('D1 harus memberi tepat satu epoch untuk NPSN yang diminta.');
        return $rows[0]['identity_epoch'];
    }
    private static function sameSchool(array $env,string $school): bool {
        if (in_array(strtolower(trim((string)($env['EDUXID_EDITION']??''))),['teacher','gtk'],true) ||
            in_array(strtolower(trim((string)($env['EDUXID_INSTALLATION_TYPE']??''))),['teacher','gtk'],true))return false;
        foreach (['EDUXID_SCHOOL_ID','EDUXID_NPSN'] as $field) {
            if (!empty($env[$field]) && trim((string)$env[$field])!==$school)return false;
        }
        return true;
    }
    private static function cloudMatch(array $env,string $school,string $cloud): bool {
        if (!self::sameSchool($env,$school))return false;
        $key=self::secret($env);
        return $key!==null && hash_equals($cloud,self::epoch($key,$school));
    }
    public static function scan(array $opts,string $cloud): void {
        $roots=$opts['search-root']??[];
        if (!$roots)throw new RuntimeException('Scan membutuhkan minimal satu --search-root=LOKASI_BACKUP; jangan scan drive acak.');
        $visited=0;$candidates=0;$matched=[];$zipOK=class_exists('ZipArchive');$limited=false;
        foreach ($roots as $root) {
            $path=realpath($root);
            if (!$path)throw new RuntimeException('Salah satu search-root tidak ditemukan.');
            $queue=[$path];
            while ($queue) {
                $dir=array_pop($queue);
                if (!is_dir($dir) || is_link($dir))continue;
                try { $iter=new DirectoryIterator($dir); } catch(Throwable) {continue;}
                foreach ($iter as $info) {
                    if ($info->isDot() || $info->isLink())continue;
                    if (++$visited>self::MAX_FILES){$limited=true; break 3;}
                    $name=$info->getFilename();$full=$info->getPathname();
                    if ($info->isDir()) {
                        if (in_array(strtolower($name),['vendor','node_modules','.git','.svn','cache','logs','tmp','temp','php','system volume information','$recycle.bin','windows','program files','program files (x86)'],true))continue;
                        $queue[]=$full; continue;
                    }
                    if (!$info->isFile() || !$info->isReadable())continue;
                    $isEnv=(bool)preg_match('/^\.env(?:[._-].+)?$/i',$name);
                    if ($isEnv && $info->getSize()<=self::MAX_ENV) {
                        $candidates++;
                        try {if (self::cloudMatch(self::readEnv($full),$opts['school'],$cloud))$matched[]=$full;}
                        catch(Throwable){};
                    } elseif ($zipOK && preg_match('/\.zip$/i',$name) && $info->getSize()<=self::MAX_ZIP) {
                        $z=new ZipArchive(); if ($z->open($full)!==true)continue;
                        try {
                            if ($z->numFiles>self::MAX_ARCHIVE_MEMBERS)continue;
                            for($j=0;$j<$z->numFiles;$j++){
                                $meta=$z->statIndex($j);$inside=$meta['name']??'';
                                if (!preg_match('~(?:^|/)(?:\.env(?:[._-][^/]*)?)$~i',$inside))continue;
                                if (($meta['size']??self::MAX_ENV+1)>self::MAX_ENV)continue;
                                $candidates++;$data=$z->getFromIndex($j,self::MAX_ENV+1);
                                if (is_string($data) && strlen($data)<=self::MAX_ENV) {
                                    try { if (self::cloudMatch(self::parseEnv($data),$opts['school'],$cloud))$matched[]=$full.' :: '.$inside; }
                                    catch(Throwable){}
                                }
                            }
                        } finally { $z->close(); }
                    }
                }
            }
        }
        echo "MODE: SCAN_READONLY\n";
        echo 'ZIP_SUPPORT: '.($zipOK?'TERSEDIA':'TIDAK_TERSEDIA')."\n";
        echo "KANDIDAT_DIPERIKSA: $candidates\n";
        echo 'BATAS_SCAN: '.($limited?'TERCAPAI_LANJUT_PER_FOLDER':'TIDAK')."\n";
        echo 'KUNCI_ASLI_COCOK_CLOUD: '.($matched?'DITEMUKAN':'BELUM_DITEMUKAN')."\n";
        echo 'JUMLAH_LOKASI_COCOK: '.count($matched)."\n";
        foreach (array_slice($matched,0,10) as $path)echo 'LOKASI_COCOK (BUKAN KUNCI): '.$path."\n";
        if (count($matched)>10) echo "LOKASI_COCOK_LAIN: ADA\n";
        echo "STATUS_KEAMANAN: TIDAK_ADA_DATA_YANG_DIUBAH\n";
    }
    private static function validateDb(string $project,array $env,string $school): int {
        if (!class_exists('PDO') || !in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('Driver PDO_SQLITE wajib tersedia.');
        $name=trim((string)($env['DB_DATABASE']??''));if ($name==='')$name='database/database.sqlite';
        if ($name===':memory:' || trim((string)($env['DB_URL']??''))!=='')throw new RuntimeException('Konfigurasi database bukan SQLite berkas.');
        $absolute=(bool)preg_match('/^[A-Za-z]:[\\\/]/D',$name)||str_starts_with($name,'/')||str_starts_with($name,'\\\\');
        $file=$absolute?$name:$project.DIRECTORY_SEPARATOR.$name;
        if (!is_file($file))throw new RuntimeException('DB_DATABASE tidak menunjuk file yang ada (termasuk eduxid tanpa ekstensi).');
        $pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>2]);
        $pdo->exec('PRAGMA query_only = ON');
        $tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('schools',$tables,true))throw new RuntimeException('Tabel sekolah tidak ada.');
        $schools=$pdo->query("SELECT DISTINCT TRIM(CAST(npsn AS TEXT)) FROM schools WHERE npsn IS NOT NULL AND TRIM(CAST(npsn AS TEXT)) <> '' LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        if (count($schools)!==1 || !hash_equals($school,(string)$schools[0]))throw new RuntimeException('NPSN DB bukan satu sekolah yang sama: BLOCKED.');
        $active = 0;
        foreach (['cloud_identity_alias_routes','cloud_alias_activation_credentials','cloud_identity_alias_registry','sync_devices'] as $table) {
            if (!in_array($table,$tables,true))continue;
            $cols=$pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
            $hasSchool=in_array('school_id',array_column($cols,'name'),true);
            if ($hasSchool) {
                $stmt=$pdo->prepare('SELECT COUNT(*) FROM "'.$table.'" WHERE school_id=?');$stmt->execute([$school]);
                $active+=(int)$stmt->fetchColumn();
            } else {
                $active+=(int)$pdo->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();
            }
        }
        return $active;
    }
    private static function atomicWrite(string $dest,string $source): void {
        $temp=$dest.'.v21r95-'.bin2hex(random_bytes(6)).'.tmp';
        try {
            if (@file_put_contents($temp,$source,LOCK_EX)!==strlen($source))throw new RuntimeException('Gagal menulis file sementara.');
            @chmod($temp,0600);
            if (!@rename($temp,$dest))throw new RuntimeException('Gagal mengganti file .env secara atomik.');
        } finally { if (is_file($temp))@unlink($temp); }
    }
    public static function planOrRestore(array $opts,string $cloud,string $project): void {
        $orig=self::readEnv($project.'/.env');
        $candidate=self::readEnv($opts['candidate-env']);
        if (!self::sameSchool($orig,$opts['school']))throw new RuntimeException('Instalasi target bukan sekolah yang benar.');
        if (!self::cloudMatch($candidate,$opts['school'],$cloud))
            throw new RuntimeException('KANDIDAT_TIDAK_COCOK_CLOUD: tidak boleh mengubah konfigurasi.');
        if (strtolower((string)($orig['EDUXID_INSTALLATION_TYPE']??''))!=='school')
            throw new RuntimeException('Hanya paket Admin Sekolah dapat dipulihkan.');
        $active=self::secret($orig);
        $matches=$active!==null && hash_equals(self::epoch($active,$opts['school']),$cloud);
        $registry=self::validateDb($project,$orig,$opts['school']);
        echo "KANDIDAT_VS_CLOUD: COCOK\n";
        echo 'ADMIN_SAAT_INI_VS_CLOUD: '.($matches?'COCOK':'TIDAK_COCOK')."\n";
        echo 'MAPPING_ATAU_PERANGKAT_LOKAL_BERISI: '.($registry?'YA':'TIDAK')."\n";
        if ($matches) {echo "HASIL: IDENTITAS_SUDAH_COCOK_TIDAK_DIUBAH\n";return;}
        if ($registry>0)throw new RuntimeException('BLOCKED: mapping/perangkat lokal sudah berisi data. Perlu rekonsiliasi DB dan perangkat secara terpisah.');
        if ($opts['action']==='plan') {echo "HASIL: LAYAK_PEMULIHAN_TANPA_UBAH\n";return;}
        // Restore requires an independently backed up target .env. Never copy entire candidate .env.
        $source=file_get_contents($project.'/.env');
        if (!is_string($source))throw new RuntimeException('Tidak dapat membaca .env Admin.');
        $encoded=rtrim(strtr(base64_encode(self::secret($candidate)),'+/','-_'),'=');
        $count=0;
        $next=preg_replace('/^EDUXID_SCHOOL_ALIAS_KEY\s*=[^\r\n]*/m','EDUXID_SCHOOL_ALIAS_KEY='.$encoded,$source,-1,$count);
        if (!is_string($next) || $count!==1)throw new RuntimeException('EDUXID_SCHOOL_ALIAS_KEY pada .env target harus tepat satu.');
        $backup=$project.'/.env.before-V21R95-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3));
        if (@file_put_contents($backup,$source,LOCK_EX)!==strlen($source))throw new RuntimeException('Backup .env gagal; tidak ada perubahan.');
        @chmod($backup,0600);
        if (!hash_equals(hash('sha256',$source),(string)hash_file('sha256',$backup)))throw new RuntimeException('Verifikasi backup gagal.');
        try { self::atomicWrite($project.'/.env',$next); }
        catch (Throwable $e) { @copy($backup,$project.'/.env');throw $e; }
        echo "HASIL: ENV_DIPULIHKAN_BERDASARKAN_KUNCI_CLOUD_SAH\n";
        echo "BACKUP_OTOMATIS: TERSEDIA (di folder .env target, simpan aman)\n";
        echo "LANJUTAN: HENTIKAN_APLIKASI_KEMUDIAN_BERSIHKAN_CACHE_KONFIGURASI_LARAVEL_SAJA\n";
    }
    public static function run(array $argv): void {
        $opts=self::args(array_slice($argv,1));
        $project=self::resolveProject($opts['target']);
        $cloud=self::readCloudEpoch($opts['cloud-epoch-file'],$opts['school']);
        if ($opts['action']==='scan') self::scan($opts,$cloud);
        else self::planOrRestore($opts,$cloud,$project);
    }
}
try { SchoolIdentityRescueV21R95::run($argv); }
catch(Throwable $e) {fwrite(STDERR,'[BLOCKED V21R95] '.$e->getMessage()."\nSTATUS_KEAMANAN: TIDAK_ADA_PERUBAHAN_YANG_DIRENCANAKAN\n");exit(2);}
