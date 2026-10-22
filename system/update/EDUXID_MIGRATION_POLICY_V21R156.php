<?php
/**
 * eduXid V21R156: conservative, read-only migration policy after signed-release verification.
 * Usage: php EDUXID_MIGRATION_POLICY_V21R156.php --staging="..." --edition=school --version=21.150.0
 * stdout: SKIP_UI_ONLY_V21R150 or RUN_NORMAL. Nonzero means STOP.
 * This helper is not a replacement for eduxid:update:verify; call only AFTER signature+SHA verification.
 */
declare(strict_types=1);
function fail156(string $message): never { fwrite(STDERR, '[V21R156 POLICY BLOCKED] '.$message.PHP_EOL); exit(2); }
function opt156(array $args,string $key): ?string {
    foreach(array_slice($args,1) as $v) { if(str_starts_with($v, '--'.$key.'=')) return substr($v,strlen($key)+3); }
    return null;
}
$stage=opt156($argv,'staging');$edition=opt156($argv,'edition');$version=opt156($argv,'version');
if(!$stage||!in_array($edition,['school','teacher'],true)||!is_string($version)||!preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/D',$version)) fail156('Argumen instalasi tidak valid.');
$staging=realpath($stage);
if($staging===false||!is_dir($staging)||!is_file($staging.DIRECTORY_SEPARATOR.'manifest.json'))fail156('Staging/manifest tidak ditemukan.');
try {$m=json_decode((string)file_get_contents($staging.DIRECTORY_SEPARATOR.'manifest.json'),true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){fail156('Manifest tidak dapat dibaca.');}
if(!is_array($m)||($m['schema']??null)!=='EDUXID-RELEASE-V2'||($m['version']??null)!==$version||!is_array($m['files']??null)||!is_array($m['components']??null))fail156('Skema/versi/daftar file manifest tidak sesuai.');
if(!($m['components']['core']??false) && !($m['components'][$edition]??false))fail156('Release tidak berlaku untuk edisi target.');
// All other signed releases retain the updater's historical migrate --force behavior.
if($version!=='21.150.0'){echo 'RUN_NORMAL',PHP_EOL;exit(0);}
// Only this precisely scoped, already-signed UI release may skip historical pending migrations.
if(($m['components']['core']??null)!==true || ($m['components']['school']??null)!==false || ($m['components']['teacher']??null)!==false)fail156('Komponen V21R150 berbeda dari kontrak UI-only.');
$expected=[
 'payload/core/app/Http/Controllers/DonationController.php',
 'payload/core/resources/views/about/index.blade.php',
 'payload/core/resources/views/layouts/partials/donation-reminder.blade.php',
 'payload/core/security/donation-integrity-v1.json',
];
$keys=array_keys($m['files']);sort($keys,SORT_STRING);sort($expected,SORT_STRING);
if($keys!==$expected)fail156('Daftar file V21R150 berbeda; tidak aman melewati migrasi.');
foreach($expected as $rel){
    $h=$m['files'][$rel];
    if(!is_string($h)||!preg_match('/^[0-9a-f]{64}$/D',$h))fail156('SHA-256 file tidak valid.');
    $p=$staging.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);
    $canonical=realpath($p);
    if(!is_file($p)||is_link($p)||$canonical===false||!str_starts_with($canonical,$staging.DIRECTORY_SEPARATOR))fail156('File staging di luar root atau hilang.');
    if(!hash_equals($h,(string)hash_file('sha256',$p)))fail156('Hash staging tidak cocok dengan manifest.');
}
// Extra files not in manifest must not be installed; the original updater extract+apply also checks these.
// Avoid recursive reading of unrelated files in staging created by the updater (e.g. manifest.json).
echo 'SKIP_UI_ONLY_V21R150',PHP_EOL;
