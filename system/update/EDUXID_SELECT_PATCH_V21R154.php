<?php
/**
 * eduXid V21R154 — deterministic, read-only ZIP selector for Windows CMD updater.
 * Output: exactly one full file path on stdout (exit 0); no match: exit 10;
 * config/runtime error: exit 2. All diagnostics on stderr.
 * This helper DOES NOT authenticate a release: existing artisan
 * eduxid:update:verify performs Ed25519/SHA-256 checks after selection.
 */
declare(strict_types=1);
function err154(string $message): void {fwrite(STDERR, '[V21R154 selector] '.$message.PHP_EOL);}
function arg154(array $argv,string $key): ?string {
    foreach(array_slice($argv,1) as $part){if(str_starts_with($part,$key.'='))return substr($part,strlen($key)+1);}
    return null;
}
$rootArg=arg154($argv,'--root');
$edition=strtolower((string)arg154($argv,'--edition'));
if(!$rootArg || !in_array($edition,['school','teacher'],true)){err154('Argumen root/edition tidak sah.');exit(2);}
$root=realpath($rootArg);
if($root===false || !is_dir($root) || !is_file($root.DIRECTORY_SEPARATOR.'eduXid.exe')){err154('Root instalasi tidak sah.');exit(2);}
if(!class_exists(ZipArchive::class)){err154('Ekstensi zip PHP tidak tersedia.');exit(2);}
// V21R159: teacher ZIP selection is restricted to a previously verified Cloud download.
// The local marker is NOT a substitute for Ed25519: artisan verify remains mandatory.
if($edition==='teacher'){
    $markerFile=$root.DIRECTORY_SEPARATOR.'.eduxid-updater'.DIRECTORY_SEPARATOR.'cloud-download.json';
    if(!is_file($markerFile)||is_link($markerFile)){err154('GTK: belum ada ZIP unduhan Cloud; abaikan ZIP manual/LAN.');exit(10);}
    try {$marker=json_decode((string)file_get_contents($markerFile),true,512,JSON_THROW_ON_ERROR);}
    catch(Throwable $e){err154('GTK: marker cache Cloud rusak.');exit(2);}
    if(!is_array($marker)){err154('GTK: marker cache Cloud tidak sah.');exit(2);}
    $v=(string)($marker['version']??'');
    $base=(string)($marker['basename']??'');
    $sha=strtolower((string)($marker['sha256']??''));
    if(!preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/D',$v)
        || $base!=='eduxid-patch-'.$v.'.zip'
        || !preg_match('/^[a-f0-9]{64}$/D',$sha)){
        err154('GTK: metadata cache Cloud tidak sesuai format.');exit(2);
    }
    $cached=$root.DIRECTORY_SEPARATOR.$base;
    if(!is_file($cached)||is_link($cached)||!hash_equals($sha,strtolower((string)hash_file('sha256',$cached)))){
        err154('GTK: file cache Cloud tidak ada/hash berubah.');exit(2);
    }
    $z=new ZipArchive();
    if($z->open($cached,ZipArchive::RDONLY)!==true){err154('GTK: ZIP cache Cloud tidak dapat dibuka.');exit(2);}
    try {$data=$z->getFromName('manifest.json');$manifest=is_string($data)?json_decode($data,true):null;}
    finally {$z->close();}
    if(!is_array($manifest)||($manifest['schema']??null)!=='EDUXID-RELEASE-V2'||($manifest['version']??null)!==$v){
        err154('GTK: manifest cache Cloud tidak sesuai.');exit(2);
    }
    $comps=$manifest['components']??null;
    $ok=is_array($comps) && (array_is_list($comps)
        ? (in_array('core',$comps,true)||in_array('teacher',$comps,true))
        : (!empty($comps['core'])||!empty($comps['teacher'])));
    if(!$ok){err154('GTK: release Cloud bukan untuk teacher.');exit(2);}
    err154('GTK: ZIP dari cache unduhan Cloud dipilih. Ed25519 tetap diperiksa oleh artisan.');
    echo $cached,PHP_EOL;exit(0);
}
$best=null; $bestVersion=null; $seen=0; $eligible=0;
$items=@scandir($root);
if(!is_array($items)){err154('Gagal membaca daftar file pada root.');exit(2);}
foreach($items as $base){
    if(!is_string($base) || !preg_match('/^eduxid-patch-(\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?)\.zip$/iD',$base,$m))continue;
    $seen++; $f=$root.DIRECTORY_SEPARATOR.$base;
    if(!is_file($f) || is_link($f) || !is_readable($f))continue;
    $z=new ZipArchive();$rc=$z->open($f,ZipArchive::RDONLY);
    if($rc!==true){err154('Lewati ZIP tidak dapat dibaca: '.$base);continue;}
    $manifest=null;
    try{
        $stat=$z->statName('manifest.json');
        if(is_array($stat) && ($stat['size']??0)>0 && ($stat['size']??0)<=1048576){
            $body=$z->getFromName('manifest.json');
            if(is_string($body))$manifest=json_decode($body,true);
        }
    }finally{$z->close();}
    if(!is_array($manifest) || ($manifest['schema']??null)!=='EDUXID-RELEASE-V2' || ($manifest['version']??null)!==$m[1]){
        err154('Lewati ZIP format/versi tidak sesuai: '.$base);continue;
    }
    $components=$manifest['components']??null;
    if(!is_array($components))continue;
    $applies=array_is_list($components)
       ? (in_array('core',$components,true) || in_array($edition,$components,true))
       : (!empty($components['core']) || !empty($components[$edition]));
    if(!$applies)continue;
    $eligible++;
    if($bestVersion===null || version_compare($m[1],$bestVersion,'>')){
        $best=$f;$bestVersion=$m[1];
    }
}
if($best===null){err154('Tidak ada ZIP RELEASE-V2 untuk '.$edition.' (ditemukan '.$seen.', cocok 0).');exit(10);}
err154('ZIP kompatibel: '.$eligible.'; versi dipilih: '.$bestVersion.'. Verifikasi Ed25519 tetap wajib.');
echo $best,PHP_EOL;
exit(0);
