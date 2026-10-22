<?php
/** eduXid V21R118: activate existing immutable school identity, never generate a key. */
declare(strict_types=1);
require_once __DIR__.'/EDUXID_SCHOOL_IDENTITY_VAULT_V21R94.php';
require_once dirname(__DIR__).'/bootstrap/SchoolAliasSealV21R110.php';
use EduXid\Bootstrap\SchoolAliasSealV21R110 as Seal;
final class IdentityActivate118 {
  private const CLOUD='https://eduxid-cloud.eduxid-sync-anas.workers.dev';
  private static function ask(string $prompt):string {fwrite(STDERR,$prompt);$s=fgets(STDIN);if($s===false)throw new RuntimeException('Input tidak tersedia.');return trim($s);}
  private static function cloudEpoch(string $school,string $token,string $project):string {
    if(preg_match('/^edx_sch_[A-Za-z0-9_-]{43}$/D',$token)!==1)throw new RuntimeException('Kode pairing Admin tidak valid.');
    if(!extension_loaded('curl'))throw new RuntimeException('cURL dengan HTTPS wajib aktif.');
    $ch=curl_init(self::CLOUD.'/api/admin/identity/verify?school_id='.$school);
    if($ch===false)throw new RuntimeException('Gagal membuat koneksi Cloud.');
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,
      CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Admin '.$token]];
    foreach([dirname($project).'/php/cacert.pem',dirname(dirname($project)).'/php/cacert.pem',getenv('CURL_CA_BUNDLE')?:'',ini_get('curl.cainfo')?:''] as $ca) {
      if($ca!==''&&is_file($ca)&&filesize($ca)>1024){$options[CURLOPT_CAINFO]=$ca;break;}
    }
    curl_setopt_array($ch,$options);
    try {$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);} finally {curl_close($ch);}
    if(!is_string($raw)||$status!==200)throw new RuntimeException('Verifikasi pasangan Admin/Cloud gagal (HTTP '.$status.').');
    $r=json_decode($raw,true);
    if(!is_array($r)||($r['success']??null)!==true||($r['data']['school_id']??null)!==$school||($r['data']['state']??null)!=='pinned'
       ||preg_match('/^[a-f0-9]{64}$/D',(string)($r['data']['identity_epoch']??''))!==1)throw new RuntimeException('Respons identitas Cloud tidak sah.');
    return $r['data']['identity_epoch'];
  }
  private static function pairedTokenFromVault(string $vault,string $school,string $epoch,string $code):?string {
    $record=json_decode((string)file_get_contents($vault),true);
    if(!is_array($record)||!array_key_exists('pairing',$record))return null;
    $pair=$record['pairing'];
    if(!is_array($pair)||($pair['format']??'')!=='EDUXID_ADMIN_PAIRING_ENCRYPTED_V21R118')throw new RuntimeException('Format cadangan pairing tidak valid.');
    $salt=base64_decode((string)($record['salt']??''),true);
    $nonce=base64_decode((string)($pair['nonce']??''),true);
    $tag=base64_decode((string)($pair['tag']??''),true);
    $cipher=base64_decode((string)($pair['ciphertext']??''),true);
    if(!is_string($salt)||strlen($salt)!==32||!is_string($nonce)||strlen($nonce)!==12||
       !is_string($tag)||strlen($tag)!==16||!is_string($cipher))throw new RuntimeException('Metadata pairing tidak valid.');
    $aad="EDUXID-V21R118-PAIRING\0".$school."\0".$epoch;
    $token=openssl_decrypt($cipher,'aes-256-gcm',EduXidSchoolIdentityVaultV21R94::deriveKey($code,$salt),OPENSSL_RAW_DATA,$nonce,$tag,$aad);
    if(!is_string($token)||preg_match('/^edx_sch_[A-Za-z0-9_-]{43}$/D',$token)!==1)throw new RuntimeException('Pairing dalam vault tidak autentik.');
    return $token;
  }
  private static function inspect(string $project,string $school,string $secret):void {
    $env=EduXidSchoolIdentityVaultV21R94::environment($project.'/.env');
    EduXidSchoolIdentityVaultV21R94::assertSchoolMode($env);
    foreach(['EDUXID_SCHOOL_ID','EDUXID_NPSN'] as $key){$value=trim((string)($env[$key]??''));if($value!==''&&!hash_equals($school,$value))throw new RuntimeException('NPSN .env berbeda dari vault.');}
    $old=(string)($env['EDUXID_SCHOOL_ALIAS_KEY']??'');$path=Seal::path($project);
    if($old!==''&&!hash_equals($secret,$old)&&!is_file($path))throw new RuntimeException('Kunci lokal berbeda tanpa segel; perlu rekonsiliasi, bukan menimpa.');
    if(is_file($path)) {
      if(is_link($path))throw new RuntimeException('Segel identitas tidak sah.');
      $seal=json_decode((string)file_get_contents($path),true);
      $commit=Seal::fingerprint(EduXidSchoolIdentityVaultV21R94::keyDecode($secret));
      if(!is_array($seal)||!hash_equals((string)($seal['key_commitment']??''),$commit)||
         (($seal['state']??'')==='cloud_verified'&&($seal['school_id']??'')!==$school))
        throw new RuntimeException('Kunci pemulihan tidak cocok dengan segel Admin lama.');
    }
    $db=trim((string)($env['DB_DATABASE']??'database/database.sqlite'));
    if($db==='')$db='database/database.sqlite';
    if($db===':memory:'||strtolower((string)($env['DB_CONNECTION']??'sqlite'))!=='sqlite')throw new RuntimeException('Database bukan SQLite lokal.');
    $absolute=(bool)preg_match('/^[A-Za-z]:[\\\\\/]/D',$db)||str_starts_with($db,'/')||str_starts_with($db,'\\\\');
    $file=$absolute?$db:$project.DIRECTORY_SEPARATOR.$db;
    if(is_file($file)){
      if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('PDO_SQLITE diperlukan.');
      $pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
      $pdo->exec('PRAGMA query_only=ON');
      $present=$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='schools'")->fetchColumn();
      if($present){$ids=$pdo->query("SELECT DISTINCT TRIM(CAST(npsn AS TEXT)) FROM schools WHERE npsn IS NOT NULL AND TRIM(CAST(npsn AS TEXT))<>'' LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)>1||(count($ids)===1&&!hash_equals($school,(string)$ids[0])))throw new RuntimeException('SQLite berisi NPSN berbeda.');}
    }
  }
  private static function apply(string $project,string $school,string $secret,string $epoch):void {
    $path=$project.'/.env';$orig=file_get_contents($path);if(!is_string($orig))throw new RuntimeException('.env tidak dapat dibaca.');
    $count=0;$new=preg_replace('/^EDUXID_SCHOOL_ALIAS_KEY[ \t]*=[^\r\n]*/m','EDUXID_SCHOOL_ALIAS_KEY='.$secret,$orig,-1,$count);
    if(!is_string($new)||$count!==1)throw new RuntimeException('EDUXID_SCHOOL_ALIAS_KEY .env harus tepat satu.');
    $backup=$path.'.v21r118.before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
    if(!copy($path,$backup))throw new RuntimeException('Backup .env gagal.');@chmod($backup,0600);
    $temp=$path.'.v21r118-'.bin2hex(random_bytes(4));
    try {
      if($new!==$orig){if(file_put_contents($temp,$new,LOCK_EX)!==strlen($new)||!rename($temp,$path))throw new RuntimeException('Penyimpanan .env gagal.');@chmod($path,0600);}
      Seal::bindFromCloud($project,$school,$epoch);
      if(Seal::state($project)!=='COCOK_DENGAN_SEGEL_CLOUD')throw new RuntimeException('Pemeriksaan akhir segel gagal.');
    } catch(Throwable $e){@copy($backup,$path);throw $e;}
    finally {if(is_file($temp))@unlink($temp);}
    echo "HASIL: V21R118_AKTIVASI_IDENTITAS_PASS\nSEGEL_CLOUD: COCOK\nNPSN: ".$school."\n";
  }
  /** V21R120: find an existing, school-edition Admin key on THIS machine.
   * Never probe the build/master tree, display keys, or accept a non-matching epoch.
   * No data is mutated before the scoped Cloud pairing and epoch are verified.
   */
  private static function localOriginal(string $project,string $school,string $epoch): ?string {
    $roots=[];
    foreach (['SystemDrive','HOMEDRIVE'] as $k) {
      $v=trim((string)getenv($k));
      if(preg_match('/^[A-Za-z]:$/D',$v))$roots[]=$v.DIRECTORY_SEPARATOR;
    }
    foreach (['C:'.DIRECTORY_SEPARATOR,'D:'.DIRECTORY_SEPARATOR,dirname($project),dirname(dirname($project))] as $root) $roots[]=$root;
    $roots=array_values(array_unique($roots));
    $found=[];
    foreach($roots as $root){
      if(!is_dir($root)||is_link($root))continue;
      $names=@scandir($root);if(!is_array($names))continue;
      foreach(array_slice($names,0,800) as $name){
        if(!preg_match('/^eduXid[-_]ADMIN(?:[-_].+)?$/iD',$name))continue;
        $dir=rtrim($root,'\\/').DIRECTORY_SEPARATOR.$name;
        if(!is_dir($dir)||is_link($dir))continue;
        foreach([$dir,$dir.DIRECTORY_SEPARATOR.'eduXid'] as $candidate){
          $real=realpath($candidate);
          if($real===false||$real===$project||!is_file($real.DIRECTORY_SEPARATOR.'artisan')||
             is_link($real.DIRECTORY_SEPARATOR.'.env')||!is_file($real.DIRECTORY_SEPARATOR.'.env')||
             is_file(dirname($real).DIRECTORY_SEPARATOR.'eduxid-cloud'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'index.js'))continue;
          try {
            $env=EduXidSchoolIdentityVaultV21R94::environment($real.DIRECTORY_SEPARATOR.'.env');
            EduXidSchoolIdentityVaultV21R94::assertSchoolMode($env);
            $npsn=trim((string)($env['EDUXID_SCHOOL_ID']??''));
            if($npsn==='')$npsn=trim((string)($env['EDUXID_NPSN']??''));
            if($npsn!==$school)continue;
            $secret=EduXidSchoolIdentityVaultV21R94::keyDecode((string)($env['EDUXID_SCHOOL_ALIAS_KEY']??''));
            if(hash_equals($epoch,EduXidSchoolIdentityVaultV21R94::epoch($secret,$school)))
              $found[EduXidSchoolIdentityVaultV21R94::keyEncode($secret)]=true;
          }catch(Throwable $ignored){ /* unreadable/foreign candidate is not accepted */ }
        }
      }
    }
    if(count($found)>1)throw new RuntimeException('Ditemukan identitas lokal yang tidak konsisten; pemulihan otomatis diblokir.');
    return $found?array_key_first($found):null;
  }
  /** Look only for a vault tagged with THIS pinned epoch; recovery code is still required. */
  private static function knownVault(string $project,string $school,string $epoch):?string {
    $home=trim((string)getenv('USERPROFILE'));
    if($home==='')$home=trim((string)getenv('HOME'));
    $dirs=[dirname($project),$home.DIRECTORY_SEPARATOR.'Downloads',$home.DIRECTORY_SEPARATOR.'Documents',
      $home.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'EDUXID_IDENTITAS_BACKUP',
      $home.DIRECTORY_SEPARATOR.'OneDrive'.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'EDUXID_IDENTITAS_BACKUP'];
    $matches=[];
    foreach($dirs as $dir){
      if(!is_dir($dir)||is_link($dir))continue;
      $names=@scandir($dir);if(!is_array($names))continue;
      foreach(array_slice($names,0,800) as $n){
        if(!preg_match('/^eduXid-Identitas-'.preg_quote($school,'/').'.*\\.enc\\.json$/iD',$n))continue;
        $path=$dir.DIRECTORY_SEPARATOR.$n;
        if(!is_file($path)||is_link($path)||filesize($path)>32768)continue;
        $data=json_decode((string)@file_get_contents($path),true);
        if(is_array($data)&&($data['format']??'')==='EDUXID_SCHOOL_ALIAS_VAULT_V1'&&
           ($data['school_id']??'')===$school&&hash_equals($epoch,(string)($data['identity_epoch']??'')))$matches[]=$path;
      }
    }
    return count($matches)===1?$matches[0]:null;
  }
  public static function main(array $argv):int {
    try {
      $opts=[];foreach(array_slice($argv,1) as $arg){if(preg_match('/^--(target|school|vault|mode)=(.*)$/D',$arg,$m)!==1||isset($opts[$m[1]]))throw new RuntimeException('Argumen tidak diizinkan.');$opts[$m[1]]=$m[2];}
      if(!in_array($opts['mode']??'interactive',['interactive','check','apply'],true))throw new RuntimeException('Mode tidak valid.');
      $mode=$opts['mode']??'interactive';$project=EduXidSchoolIdentityVaultV21R94::project((string)($opts['target']??''));
      $env=EduXidSchoolIdentityVaultV21R94::environment($project.'/.env');EduXidSchoolIdentityVaultV21R94::assertSchoolMode($env);
      // A malformed/missing key must NOT prevent the original vault from being supplied.
      try {
        if(trim((string)($env['EDUXID_SCHOOL_ALIAS_KEY']??''))!=='' && Seal::state($project)==='COCOK_DENGAN_SEGEL_CLOUD') {
          echo "HASIL: V21R118_IDENTITAS_SUDAH_COCOK\n";return 0;
        }
      } catch(Throwable $ignored) { /* recover only after independent Cloud + vault proof below */ }
      // School admin user-facing flow: NPSN + active activation code first.
      // Vault file path is a fallback, not the default prompt.
      $school=trim((string)($opts['school']??self::ask('NPSN sekolah (8 digit): ')));
      if(preg_match('/^[0-9]{8}$/D',$school)!==1)throw new RuntimeException('NPSN harus 8 digit.');
      $token=self::ask('Kode aktivasi sekolah (edx_sch_...): ');
      $epoch=self::cloudEpoch($school,$token,$project);
      unset($token);
      if(!isset($opts['vault'])) {
        $local=self::localOriginal($project,$school,$epoch);
        if($local!==null){
          echo "[IDENTITAS] Kunci sekolah ASLI ditemukan pada instalasi Admin lama dan cocok dengan Cloud.\n";
          self::inspect($project,$school,$local);
          if($mode==='check'){echo "HASIL: V21R120_PREFLIGHT_LOKAL_PASS_READONLY\n";return 0;}
          self::apply($project,$school,$local,$epoch);return 0;
        }
      }
      $vault=trim((string)($opts['vault']??self::knownVault($project,$school,$epoch)??''),'"\'');
      if($vault===''){
        echo "[IDENTITAS] Tidak ada kunci lama yang cocok pada instalasi lokal, dan vault belum ditemukan.\n";
        $vault=trim(self::ask('Jika memiliki cadangan terenkripsi, ketik path .enc.json (atau ENTER untuk berhenti): '),'"\'');
      }else echo "[IDENTITAS] Cadangan terenkripsi sekolah ditemukan.\n";
      if($vault===''||!is_file($vault)||is_link($vault))throw new RuntimeException('Kunci asli belum tersedia. Jangan membuat kunci baru. Hubungi Admin Pusat untuk pemulihan terotorisasi.');
      $code=EduXidSchoolIdentityVaultV21R94::readCode();
      [$raw,$claimedEpoch]=EduXidSchoolIdentityVaultV21R94::decrypt($vault,$school,$code);
      unset($code);
      if(!hash_equals($epoch,$claimedEpoch)||!hash_equals(Seal::epoch($raw,$school),$epoch))
        throw new RuntimeException('Vault tidak cocok dengan kunci sekolah yang dikunci Cloud.');
      $secret=EduXidSchoolIdentityVaultV21R94::keyEncode($raw);unset($raw);
      self::inspect($project,$school,$secret);
      if($mode==='check'){echo "HASIL: V21R120_PREFLIGHT_VAULT_PASS_READONLY\n";return 0;}
      self::apply($project,$school,$secret,$epoch);return 0;
    }catch(Throwable $e){fwrite(STDERR,'[BLOCKED V21R118] '.$e->getMessage()."\n");return 2;}
  }
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(IdentityActivate118::main($argv));
