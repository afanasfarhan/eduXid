<?php
/**
 * eduXid V21R94 — school identity continuity vault.
 * Explicit, off-install, authenticated recovery; no Cloud/network writes.
 * Usage:
 *  php EDUXID_SCHOOL_IDENTITY_VAULT_V21R94.php backup --target=... --school=... --vault=... [--epoch-file=...]
 *  php EDUXID_SCHOOL_IDENTITY_VAULT_V21R94.php check  --target=... --school=... --vault=... --epoch-file=...
 *  php EDUXID_SCHOOL_IDENTITY_VAULT_V21R94.php restore --target=... --school=... --vault=... --epoch-file=...
 * A 256-bit one-time recovery code is displayed on BACKUP and read via STDIN
 * on CHECK/RESTORE. Do not use a NPSN or routine pairing ticket as this code.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
ini_set('display_errors', '0');
error_reporting(E_ALL);

final class EduXidSchoolIdentityVaultV21R94
{
    public static function option(array $argv, string $name): ?string
    {
        $found = null;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--'.$name.'=')) {
                if ($found !== null) throw new RuntimeException('Parameter ganda.');
                $found = substr($arg, strlen($name)+3);
            }
        }
        return $found;
    }

    public static function environment(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) throw new RuntimeException('File .env Admin tidak ditemukan.');
        $out=[];
        foreach (preg_split('/\r\n|\r|\n/', (string)file_get_contents($file)) ?: [] as $line) {
            if (!preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/D', $line, $m)) continue;
            if (array_key_exists($m[1], $out)) throw new RuntimeException('Konfigurasi .env ganda.');
            $v=trim($m[2]);
            if (strlen($v)>=2 && in_array($v[0], ['"',"'"], true) && substr($v,-1)===$v[0]) $v=substr($v,1,-1);
            $out[$m[1]]=$v;
        }
        return $out;
    }

    public static function keyDecode(string $text): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $text)) throw new RuntimeException('Kunci alias tidak valid.');
        $raw=base64_decode(strtr($text, '-_', '+/').'=', true);
        if (!is_string($raw) || strlen($raw)!==32 || self::keyEncode($raw)!==$text) {
            throw new RuntimeException('Kunci alias tidak valid.');
        }
        return $raw;
    }
    public static function keyEncode(string $raw): string { return rtrim(strtr(base64_encode($raw),'+/','-_'),'='); }
    public static function epoch(string $key, string $school): string
    {
        return hash_hmac('sha256', "eduxid-v21-directory-epoch:v1\0".$school, $key);
    }
    public static function cloudEpoch(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('File epoch Cloud belum tersedia.');
        $json=json_decode((string)file_get_contents($path),true);
        $found=[];
        $walk=static function ($node) use (&$walk, &$found): void {
            if (!is_array($node)) return;
            if (array_key_exists('identity_epoch',$node) && is_string($node['identity_epoch'])) {
                $found[]=$node['identity_epoch'];
            }
            foreach ($node as $part) if (is_array($part)) $walk($part);
        };
        $walk($json);
        if (count($found)!==1 || !preg_match('/^[a-f0-9]{64}$/D',$found[0])) {
            throw new RuntimeException('Nilai epoch Cloud tidak tunggal/valid; jangan melakukan pemulihan.');
        }
        return $found[0];
    }
    public static function project(string $target): string
    {
        $real=realpath($target);
        if ($real===false) throw new RuntimeException('Target tidak ditemukan.');
        $project=is_file($real.'/artisan')?$real:$real.'/eduXid';
        if (!is_file($project.'/artisan') || !is_file($project.'/.env')) {
            throw new RuntimeException('Target harus folder Admin Laravel atau root distribusi.');
        }
        // Do not use this utility on the build/source Master: Admin source is
        // not the school installation that owns the Cloud alias key.
        $distributionRoot=dirname($project);
        if (is_file($distributionRoot.'/eduxid-cloud/src/index.js')
            || is_file($real.'/eduxid-cloud/src/index.js')) {
            throw new RuntimeException('Master/build source bukan instalasi Admin sekolah; gunakan paket Admin operasional.');
        }
        return $project;
    }
    public static function assertSchoolMode(array $env): void
    {
        if (strtolower((string)($env['EDUXID_INSTALLATION_TYPE']??''))==='teacher'
            || strtolower((string)($env['EDUXID_EDITION']??''))==='teacher'
            || !in_array('school', [strtolower((string)($env['EDUXID_INSTALLATION_TYPE']??'')),strtolower((string)($env['EDUXID_EDITION']??''))],true)) {
            throw new RuntimeException('Khusus Admin Sekolah; tidak boleh diterapkan pada GTK/master.');
        }
        if (($env['EDUXID_IDENTITY_PROTOCOL']??'')!=='v21-opaque') {
            throw new RuntimeException('Hanya mendukung v21-opaque.');
        }
    }
    public static function databaseSchoolAndRoutes(string $project, array $env, string $school): void
    {
        if (!class_exists('PDO') || !in_array('sqlite',PDO::getAvailableDrivers(),true)) {
            throw new RuntimeException('PDO_SQLITE wajib untuk verifikasi database sebelum pemulihan.');
        }
        if (strtolower((string)($env['DB_CONNECTION']??'sqlite'))!=='sqlite' || trim((string)($env['DB_URL']??''))!=='') {
            throw new RuntimeException('Konfigurasi database bukan SQLite lokal yang dapat diverifikasi.');
        }
        $location=trim((string)($env['DB_DATABASE']??''));
        $location=$location===''?'database/database.sqlite':$location;
        if ($location===':memory:') throw new RuntimeException('Database sementara tidak dapat dipakai.');
        $absolute=(bool)preg_match('/^[A-Za-z]:[\\\\\/]/D',$location)||str_starts_with($location,'/')||str_starts_with($location,'\\\\');
        $file=$absolute?$location:$project.DIRECTORY_SEPARATOR.$location;
        if (!is_file($file)) throw new RuntimeException('Database aktif DB_DATABASE tidak tersedia.');
        $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>2]);
        $db->exec('PRAGMA query_only=ON');
        $tables=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('schools','cloud_identity_alias_routes')")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('schools',$tables,true)) throw new RuntimeException('Tabel sekolah belum tersedia; sekolah tidak dapat diverifikasi.');
        $rows=$db->query("SELECT DISTINCT TRIM(CAST(npsn AS TEXT)) FROM schools WHERE npsn IS NOT NULL AND TRIM(CAST(npsn AS TEXT))<>'' LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows)!==1 || !hash_equals($school,(string)$rows[0])) {
            throw new RuntimeException('NPSN tidak cocok dengan sekolah tunggal dalam database.');
        }
        if (in_array('cloud_identity_alias_routes',$tables,true)) {
            $stm=$db->prepare('SELECT COUNT(*) FROM cloud_identity_alias_routes WHERE school_id=?');
            $stm->execute([$school]);
            if ((int)$stm->fetchColumn()>0) {
                throw new RuntimeException('Registry alias sekolah telah terisi. Pemulihan otomatis diblokir; perlu rekonsiliasi registry sebelum ganti key.');
            }
        }
    }
    public static function deriveKey(string $code, string $salt): string
    {
        // Recovery code is 256-bit uniformly random; HKDF with per-vault salt,
        // not NPSN or a low-entropy pairing ticket.
        return hash_hkdf('sha256',$code,32,'eduxid-school-identity-vault-v1',$salt);
    }
    public static function readCode(): string
    {
        fwrite(STDERR, "Tempel KODE PEMULIHAN RAHASIA (64 karakter hex), lalu Enter. Jangan kirim kode ke chat: ");
        $line=fgets(STDIN);
        if ($line===false) throw new RuntimeException('Kode pemulihan tidak diberikan.');
        $text=trim($line);
        if (!preg_match('/^[0-9a-f]{64}$/D',$text)) throw new RuntimeException('Format kode pemulihan salah.');
        return hex2bin($text);
    }
    public static function encrypt(string $secret, string $school, string $epoch, string $code): array
    {
        $salt=random_bytes(32);$nonce=random_bytes(12);$tag='';
        $aad="EDUXID-V21R94-SCHOOL-VAULT\0".$school."\0".$epoch;
        $data=openssl_encrypt($secret,'aes-256-gcm',self::deriveKey($code,$salt),OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
        if (!is_string($data) || strlen($tag)!==16) throw new RuntimeException('Enkripsi vault gagal.');
        return ['format'=>'EDUXID_SCHOOL_ALIAS_VAULT_V1','school_id'=>$school,'identity_epoch'=>$epoch,
                'algorithm'=>'AES-256-GCM+HKDF-SHA256', 'salt'=>base64_encode($salt),
                'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($data)];
    }
    public static function decrypt(string $path, string $school, string $code): array
    {
        $row=json_decode((string)file_get_contents($path),true);
        if (!is_array($row)||($row['format']??'')!=='EDUXID_SCHOOL_ALIAS_VAULT_V1'
            ||($row['school_id']??'')!==$school || !preg_match('/^[a-f0-9]{64}$/D',(string)($row['identity_epoch']??''))) {
            throw new RuntimeException('Vault bukan milik NPSN yang dimaksud atau format tidak valid.');
        }
        foreach (['salt'=>32,'nonce'=>12,'tag'=>16] as $field=>$size) {
            $b=base64_decode((string)($row[$field]??''),true);
            if (!is_string($b)||strlen($b)!==$size)throw new RuntimeException('Metadata vault tidak valid.');
            $row[$field]=$b;
        }
        $cipher=base64_decode((string)($row['ciphertext']??''),true);
        if (!is_string($cipher))throw new RuntimeException('Ciphertext tidak valid.');
        $aad="EDUXID-V21R94-SCHOOL-VAULT\0".$school."\0".$row['identity_epoch'];
        $secret=openssl_decrypt($cipher,'aes-256-gcm',self::deriveKey($code,$row['salt']),OPENSSL_RAW_DATA,$row['nonce'],$row['tag'],$aad);
        if (!is_string($secret)||strlen($secret)!==32
            || !hash_equals(self::epoch($secret,$school),$row['identity_epoch'])) {
            throw new RuntimeException('Kode pemulihan salah atau vault tidak autentik.');
        }
        return [$secret,$row['identity_epoch']];
    }
    public static function backup(string $project,string $school,string $vault,?string $cloudEpoch): void
    {
        $env=self::environment($project.'/.env');self::assertSchoolMode($env);
        $secret=self::keyDecode((string)($env['EDUXID_SCHOOL_ALIAS_KEY']??''));
        $epoch=self::epoch($secret,$school);
        if ($cloudEpoch!==null && !hash_equals($cloudEpoch,$epoch)) {
            throw new RuntimeException('BLOCKED: key Admin tidak cocok dengan epoch Cloud. Vault untuk recovery Cloud tidak dibuat.');
        }
        if (is_file($vault)) throw new RuntimeException('Vault sudah ada; tidak boleh ditimpa.');
        // Verify NPSN on Admin local DB without blocking backup for a populated alias registry.
        self::verifySchoolOnly($project,$env,$school);
        $code=random_bytes(32);$record=self::encrypt($secret,$school,$epoch,$code);
        $dir=dirname($vault);
        if (!is_dir($dir)) throw new RuntimeException('Folder penyimpanan vault harus dibuat terlebih dahulu di luar aplikasi.');
        $realProject=realpath($project);
        $realDir=realpath($dir);
        $distRoot=basename($realProject)==='eduXid' ? dirname($realProject) : $realProject;
        $rootLower=strtolower(rtrim($distRoot,'/\\'));
        $vaultLower=strtolower(rtrim($realDir,'/\\'));
        if (!$realDir || !$realProject || $vaultLower===$rootLower
            || str_starts_with($vaultLower.DIRECTORY_SEPARATOR,$rootLower.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Simpan vault di luar instalasi eduXid dan paket builder.');
        }
        $tmp=$vault.'.tmp.'.bin2hex(random_bytes(6));
        $json=json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        try {
            if (file_put_contents($tmp,$json,LOCK_EX)!==strlen($json)) throw new RuntimeException('Gagal menulis vault.');
            @chmod($tmp,0600);
            if (!rename($tmp,$vault)) throw new RuntimeException('Gagal mengamankan vault.');
        } finally { if (is_file($tmp)) @unlink($tmp); }
        echo "HASIL: VAULT_DIBUAT\n";
        echo 'CLOUD_EPOCH: '.($cloudEpoch===null?'BELUM_DIBANDINGKAN':'COCOK')."\n";
        echo "KODE_PEMULIHAN_RAHASIA (SIMPAN TERPISAH, TIDAK AKAN DITAMPILKAN LAGI):\n";
        echo bin2hex($code)."\n";
    }
    public static function verifySchoolOnly(string $project,array $env,string $school): void
    {
        // Registry count check occurs only on restore; avoid exposing route rows.
        if (!class_exists('PDO')||!in_array('sqlite',PDO::getAvailableDrivers(),true)) throw new RuntimeException('PDO_SQLITE diperlukan.');
        $database=trim((string)($env['DB_DATABASE']??''));
        $database=$database===''?'database/database.sqlite':$database;
        $absolute=(bool)preg_match('/^[A-Za-z]:[\\\\\/]/D',$database)||str_starts_with($database,'/')||str_starts_with($database,'\\\\');
        $path=$absolute?$database:$project.DIRECTORY_SEPARATOR.$database;
        if (!is_file($path))throw new RuntimeException('Database aktif tidak ditemukan.');
        $pdo=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only=ON');
        $rows=$pdo->query("SELECT DISTINCT TRIM(CAST(npsn AS TEXT)) FROM schools WHERE npsn IS NOT NULL AND TRIM(CAST(npsn AS TEXT))<>'' LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows)!==1 || !hash_equals($school,(string)$rows[0])) throw new RuntimeException('NPSN aktif di database tidak sesuai.');
    }
    public static function restore(string $project,string $school,string $vault,string $cloudEpoch,bool $dryRun): void
    {
        $envFile=$project.'/.env';$env=self::environment($envFile);self::assertSchoolMode($env);
        if (!is_file($vault) || !is_readable($vault))throw new RuntimeException('Vault tidak tersedia.');
        $code=self::readCode();[$secret,$epoch]=self::decrypt($vault,$school,$code);
        if (!hash_equals($cloudEpoch,$epoch)) throw new RuntimeException('BLOCKED: identitas vault berbeda dengan Cloud D1.');
        $existing=(string)($env['EDUXID_SCHOOL_ALIAS_KEY']??'');
        if (!hash_equals(self::keyEncode($secret),$existing)) {
            self::databaseSchoolAndRoutes($project,$env,$school);
        } else {
            self::verifySchoolOnly($project,$env,$school);
        }
        if ($dryRun) { echo "HASIL: VALID_DAPAT_DIPULIHKAN\nSTATUS_KEAMANAN: TIDAK_ADA_DATA_YANG_DIUBAH\n";return; }
        $text=file_get_contents($envFile);
        if (!is_string($text))throw new RuntimeException('.env tidak dapat dibaca.');
        $count=0;
        $replace=preg_replace('/^EDUXID_SCHOOL_ALIAS_KEY[ \t]*=[^\r\n]*/m', 'EDUXID_SCHOOL_ALIAS_KEY='.self::keyEncode($secret),$text,-1,$count);
        if (!is_string($replace) || $count!==1) throw new RuntimeException('Entri key di .env harus tepat satu.');
        if ($replace===$text) {echo "HASIL: SUDAH_COCOK_TIDAK_DIUBAH\n";return;}
        $backup=$envFile.'.before-identity-restore-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        if (!copy($envFile,$backup))throw new RuntimeException('Backup .env gagal.');
        @chmod($backup,0600);
        $tmp=$envFile.'.identity-tmp-'.bin2hex(random_bytes(6));
        try {
            if (file_put_contents($tmp,$replace,LOCK_EX)!==strlen($replace)) throw new RuntimeException('Gagal menulis .env sementara.');
            @chmod($tmp,0600);
            if (!rename($tmp,$envFile))throw new RuntimeException('Gagal memasang .env terpulihkan.');
        } finally {if (is_file($tmp))@unlink($tmp);}
        echo "HASIL: KEY_SEKOLAH_DIPULIHKAN\nSTATUS_KEAMANAN: HANYA_ENV_DIPERBARUI_DENGAN_BACKUP\n";
    }

    public static function main(array $argv): int
    {
        try {
            $command=$argv[1]??'';
            if (!in_array($command,['backup','check','restore'],true)) throw new RuntimeException('Mode harus backup/check/restore.');
            $target=self::option($argv,'target');$school=self::option($argv,'school');$vault=self::option($argv,'vault');
            if (!$target || !$school || !$vault || !preg_match('/^[0-9]{8}$/D',$school)) throw new RuntimeException('Wajib --target, --school=NPSN 8 digit, --vault.');
            $project=self::project($target);
            $cloudFile=self::option($argv,'epoch-file');
            // All Cloud-published schools must match the existing immutable
            // directory epoch before a recovery vault is minted.
            if ($cloudFile===null) throw new RuntimeException('Wajib --epoch-file dari D1 resmi; tanpa kecocokan Cloud vault tidak boleh diterbitkan.');
            $epoch=self::cloudEpoch($cloudFile);
            if ($command==='backup') self::backup($project,$school,$vault,$epoch);
            else {
                if ($epoch===null)throw new RuntimeException('Pemulihan wajib menyertakan --epoch-file dari D1 resmi.');
                self::restore($project,$school,$vault,$epoch,$command==='check');
            }
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, 'HASIL: BLOCKED_V21R94'."\n".'ALASAN: '.$error->getMessage()."\n".'STATUS_KEAMANAN: TIDAK_ADA_PENGGANTIAN_KEY_OTOMATIS'."\n");
            return 2;
        }
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    exit(EduXidSchoolIdentityVaultV21R94::main($argv));
}
