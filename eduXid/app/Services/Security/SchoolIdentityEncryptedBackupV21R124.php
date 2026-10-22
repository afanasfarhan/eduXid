<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Sync\EduxidCloudConfig;
use EduXid\Bootstrap\SchoolAliasSealV21R110;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * V21R124 — password-encrypted, local-console-only backup of pairing + immutable school key.
 * Neither the key nor the pairing token ever leaves this application unencrypted.
 * Backups are AES-256-GCM with PBKDF2-SHA256 and a per-file random salt.
 * Cloud immutable identity_epoch is authoritative, and neither secret is logged.
 */
final class SchoolIdentityEncryptedBackupV21R124
{
    // Password is never persisted. For an offline backup, a strong passphrase is essential.
    private const KDF_ITERATIONS = 600000;
    private const ENCRYPTED_FORMAT = 'EDUXID-SCHOOL-IDENTITY-PAIRING-ENC-V1';

    private static function validatePassword(string $password): void
    {
        if (strlen($password) < 14 || strlen($password) > 256 || str_contains($password, "\0")) {
            throw new RuntimeException('Kata sandi pemulihan minimal 14 karakter dan maksimal 256 byte.');
        }
    }

    private static function url64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode64($val, int $bytes): string
    {
        if (!is_string($val) || preg_match('/^[A-Za-z0-9_-]+$/D', $val) !== 1) {
            throw new RuntimeException('Format cadangan terenkripsi tidak sah.');
        }
        $raw = base64_decode(strtr($val, '-_', '+/') . str_repeat('=', (4 - strlen($val) % 4) % 4), true);
        if (!is_string($raw) || strlen($raw) !== $bytes || self::url64($raw) !== $val) {
            throw new RuntimeException('Format cadangan terenkripsi tidak sah.');
        }
        return $raw;
    }

    private static function cipherReady(): void
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt') ||
            !function_exists('hash_pbkdf2') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('Ekstensi OpenSSL/PBKDF2 untuk cadangan terenkripsi belum tersedia.');
        }
    }

    public static function exportEncrypted(string $password): array
    {
        self::validatePassword($password);
        self::cipherReady();
        $plain = self::buildPlaintext();
        // A local seal alone is insufficient to export school credentials.
        $parsed = self::parse($plain['txt']);
        $cloud = self::verifyCloud($parsed);
        if ($cloud === 'new' && $plain['local_seal_verified']) {
            throw new RuntimeException('Cloud tidak memiliki epoch sedangkan segel lokal sudah terikat; periksa Worker/D1.');
        }
        // When Cloud is pinned and its epoch matches, the *key* is verified even if
        // this installation's local seal is still initialized. Backing up this
        // verified key must not depend on a separate local-seal reconciliation.
        $salt = random_bytes(16);
        $nonce = random_bytes(12);
        $key = hash_pbkdf2('sha256', $password, $salt, self::KDF_ITERATIONS, 32, true);
        $aad = self::ENCRYPTED_FORMAT . "\0" . $plain['school_id'];
        $tag = '';
        $ciphertext = openssl_encrypt($plain['txt'], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Gagal mengenkripsi paket identitas.');
        }
        $envelope = [
            'format' => self::ENCRYPTED_FORMAT,
            'version' => 1,
            'school_id' => $plain['school_id'],
            'kdf' => ['algorithm' => 'PBKDF2-HMAC-SHA256', 'iterations' => self::KDF_ITERATIONS, 'salt' => self::url64($salt)],
            'encryption' => ['algorithm' => 'AES-256-GCM', 'nonce' => self::url64($nonce), 'tag' => self::url64($tag), 'ciphertext' => self::url64($ciphertext)],
        ];
        return [
            'school_id' => $plain['school_id'],
            'filename' => 'eduXid-Identitas-dan-Pairing-' . $plain['school_id'] . '.enc.json',
            'encrypted_file' => json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'cloud_state' => $cloud,
        ];
    }

    public static function restoreEncrypted(string $file, string $password, ?string $currentPairingToken = null): array
    {
        self::validatePassword($password);
        self::cipherReady();
        if (strlen($file) > 16384 || strlen($file) < 160) {
            throw new RuntimeException('Ukuran file cadangan terenkripsi tidak valid.');
        }
        try { $obj = json_decode($file, true, 16, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new RuntimeException('File cadangan bukan JSON valid.'); }
        if (!is_array($obj) || array_keys($obj) !== ['format','version','school_id','kdf','encryption'] ||
            ($obj['format'] ?? '') !== self::ENCRYPTED_FORMAT || ($obj['version'] ?? null) !== 1 ||
            preg_match('/^[0-9]{8}$/D', (string)($obj['school_id'] ?? '')) !== 1 ||
            !is_array($obj['kdf']) || array_keys($obj['kdf']) !== ['algorithm','iterations','salt'] ||
            ($obj['kdf']['algorithm'] ?? '') !== 'PBKDF2-HMAC-SHA256' || ($obj['kdf']['iterations'] ?? null) !== self::KDF_ITERATIONS ||
            !is_array($obj['encryption']) || array_keys($obj['encryption']) !== ['algorithm','nonce','tag','ciphertext'] ||
            ($obj['encryption']['algorithm'] ?? '') !== 'AES-256-GCM') {
            throw new RuntimeException('Format atau algoritma paket cadangan tidak dikenal.');
        }
        $salt = self::decode64($obj['kdf']['salt'], 16);
        $nonce = self::decode64($obj['encryption']['nonce'], 12);
        $tag = self::decode64($obj['encryption']['tag'], 16);
        $enc = $obj['encryption']['ciphertext'];
        if (!is_string($enc) || strlen($enc) > 10000 || strlen($enc) < 32 || preg_match('/^[A-Za-z0-9_-]+$/D',$enc)!==1) {
            throw new RuntimeException('Ciphertext cadangan tidak valid.');
        }
        $ciphertext = base64_decode(strtr($enc, '-_', '+/').str_repeat('=',(4-strlen($enc)%4)%4),true);
        if (!is_string($ciphertext) || self::url64($ciphertext) !== $enc) {
            throw new RuntimeException('Ciphertext cadangan tidak valid.');
        }
        $key = hash_pbkdf2('sha256', $password, $salt, self::KDF_ITERATIONS, 32, true);
        $aad = self::ENCRYPTED_FORMAT . "\0" . $obj['school_id'];
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if (!is_string($plain)) {
            throw new RuntimeException('Kata sandi salah atau file cadangan telah berubah.');
        }
        $parsed = self::parse($plain);
        if ($parsed['school_id'] !== $obj['school_id']) {
            throw new RuntimeException('NPSN dalam paket tidak sama dengan NPSN file.');
        }
        return self::restorePlaintext($plain, $currentPairingToken);
    }

    private const FORMAT = 'EDUXID-IDENTITAS-PAIRING-INNER-V1';

    public static function localConsole(Request $request): void
    {
        if (!in_array((string) $request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Buka Admin Sekolah melalui localhost pada komputer server untuk operasi identitas.');
        }
    }

    private static function seal(): void
    {
        require_once base_path('bootstrap/SchoolAliasSealV21R110.php');
    }

    private static function schoolFromDatabase(): string
    {
        self::seal();
        $env = SchoolAliasSealV21R110::env(base_path());
        $school = trim((string)($env['EDUXID_SCHOOL_ID'] ?? $env['EDUXID_NPSN'] ?? ''));
        if ($school !== '' && preg_match('/^[0-9]{8}$/D', $school) !== 1) {
            throw new RuntimeException('NPSN pada .env tidak valid.');
        }
        if (Schema::hasTable('schools')) {
            $rows = DB::table('schools')->whereNotNull('npsn')->distinct()->pluck('npsn')->map(fn ($x) => trim((string) $x))->filter()->unique()->values()->all();
            if (count($rows) > 1 || (count($rows) === 1 && $school !== '' && (string)$rows[0] !== $school)) {
                throw new RuntimeException('NPSN sekolah lokal tidak konsisten.');
            }
            if ($school === '' && count($rows) === 1) $school = (string)$rows[0];
        }
        if (preg_match('/^[0-9]{8}$/D', $school) !== 1) {
            throw new RuntimeException('NPSN sekolah harus diisi sebelum cadangan identitas.');
        }
        return $school;
    }

    private static function tokenValid(string $token): bool
    {
        return preg_match('/^edx_sch_[A-Za-z0-9_-]{43}$/D', $token) === 1;
    }

    private static function buildPlaintext(): array
    {
        self::seal();
        $school = self::schoolFromDatabase();
        $secret = SchoolAliasSealV21R110::adminSecret(base_path());
        $env = SchoolAliasSealV21R110::env(base_path());
        foreach (['EDUXID_SCHOOL_ID', 'EDUXID_NPSN'] as $field) {
            if (isset($env[$field]) && $env[$field] !== '' && $env[$field] !== $school) {
                throw new RuntimeException('NPSN .env tidak sesuai dengan database lokal.');
            }
        }
        $state = SchoolAliasSealV21R110::state(base_path());
        if (!in_array($state, ['COCOK_DENGAN_SEGEL_CLOUD', 'SEGEL_AWAL_PERLU_EPOCH_CLOUD'], true)) {
            throw new RuntimeException('Segel identitas lokal belum valid. Cadangan terenkripsi tidak diterbitkan.');
        }
        $token = EduxidCloudConfig::adminToken();
        if (!self::tokenValid($token)) throw new RuntimeException('Kode pairing Admin edx_sch belum tersedia. Simpan kode pairing dahulu.');
        $epoch = SchoolAliasSealV21R110::epoch($secret, $school);
        $encoded = rtrim(strtr(base64_encode($secret), '+/', '-_'), '=');
        $verified = $state === 'COCOK_DENGAN_SEGEL_CLOUD';
        if ($verified) SchoolAliasSealV21R110::assertSchool(base_path(), $secret, $school);
        $lines = [
            '# EDUXID - CADANGAN RAHASIA ADMIN SEKOLAH',
            '# HANYA UNTUK ADMIN SEKOLAH. BERISI DUA KREDENSIAL AKTIF.',
            '# Simpan OFFLINE (USB aman/brankas). Jangan unggah ke Drive publik, email, grup, atau paket GTK.',
            self::FORMAT,
            'NPSN='.$school,
            'KODE_PAIRING_CLOUD='.$token,
            'KUNCI_IDENTITAS_SEKOLAH='.$encoded,
            'IDENTITY_EPOCH='.$epoch,
            'STATUS_SEGEL_LOKAL='.($verified ? 'TERVERIFIKASI_LOKAL' : 'BELUM_TERIKAT_CLOUD'),
            'DIBUAT_UTC='.gmdate('Y-m-d\TH:i:s\Z'),
            '# Jika kode pairing dicabut oleh Admin Pusat, dapat diganti; kunci identitas TIDAK boleh diganti.',
        ];
        return ['school_id'=>$school, 'filename'=>'eduXid-Identitas-dan-Pairing-'.$school.'.txt',
            'txt'=>implode("\r\n", $lines)."\r\n", 'local_seal_verified'=>$verified];
    }

    public static function parse(string $txt): array
    {
        if (strlen($txt) > 4096 || strlen($txt) < 160 || str_contains($txt, "\0")) throw new RuntimeException('Ukuran atau isi payload pemulihan tidak valid.');
        $rows = preg_split('/\r\n|\n|\r/', $txt) ?: [];
        $found=[];$format=false;
        foreach ($rows as $row) {
            $row=trim($row);
            if ($row === '' || str_starts_with($row, '#')) continue;
            if ($row === self::FORMAT) { if ($format) throw new RuntimeException('Format payload ganda.'); $format=true; continue; }
            if (preg_match('/^([A-Z_]+)=(.*)$/D', $row, $m) !== 1 || isset($found[$m[1]])) throw new RuntimeException('Format atau nilai payload tidak valid.');
            if (!in_array($m[1], ['NPSN','KODE_PAIRING_CLOUD','KUNCI_IDENTITAS_SEKOLAH','IDENTITY_EPOCH','STATUS_SEGEL_LOKAL','DIBUAT_UTC'], true)) {
                throw new RuntimeException('Field payload tidak dikenal.');
            }
            $found[$m[1]]=$m[2];
        }
        if (!$format || preg_match('/^[0-9]{8}$/D',(string)($found['NPSN']??'')) !== 1 ||
            !self::tokenValid((string)($found['KODE_PAIRING_CLOUD']??'')) ||
            preg_match('/^[A-Za-z0-9_-]{43}$/D',(string)($found['KUNCI_IDENTITAS_SEKOLAH']??'')) !== 1 ||
            preg_match('/^[a-f0-9]{64}$/D',(string)($found['IDENTITY_EPOCH']??'')) !== 1) {
            throw new RuntimeException('NPSN, kode pairing, kunci, atau epoch dalam payload tidak valid.');
        }
        $encoded=$found['KUNCI_IDENTITAS_SEKOLAH'];
        $secret=base64_decode(strtr($encoded, '-_', '+/').'=', true);
        if (!is_string($secret) || strlen($secret)!==32 || rtrim(strtr(base64_encode($secret), '+/', '-_'),'=')!==$encoded) {
            throw new RuntimeException('Kunci identitas cadangan tidak valid.');
        }
        self::seal();
        $epoch=SchoolAliasSealV21R110::epoch($secret,$found['NPSN']);
        if (!hash_equals($epoch,$found['IDENTITY_EPOCH'])) throw new RuntimeException('Sidik identitas payload tidak cocok. File mungkin berubah.');
        return ['school_id'=>$found['NPSN'], 'token'=>$found['KODE_PAIRING_CLOUD'], 'key'=>$encoded, 'epoch'=>$epoch, 'raw'=>$secret];
    }

    private static function verifyCloud(array $bundle): string
    {
        $server=EduxidCloudConfig::syncServer();
        if ($server === '') $server=trim((string)config('eduxid.sync_server', env('EDUXID_SYNC_SERVER', '')));
        if (strtolower((string)parse_url($server, PHP_URL_SCHEME)) !== 'https') throw new RuntimeException('HTTPS Cloud belum tersedia; pemulihan diblokir.');
        try {
            $http=Http::acceptJson()->timeout(12)->withHeaders([
                'Authorization'=>'Admin '.$bundle['token'], 'X-School-Id'=>$bundle['school_id'],
            ]);
            $ca=trim((string)config('eduxid.ca_cert',''));
            if ($ca!=='' && is_file($ca)) $http=$http->withOptions(['verify'=>$ca]);
            $r=$http->get(rtrim($server,'/').'/api/admin/directory/identity-status');
        } catch (\Throwable) { throw new RuntimeException('Tidak dapat menghubungi Cloud untuk verifikasi identitas.'); }
        if (!$r->successful()) throw new RuntimeException('Kode pairing tidak diakui Cloud atau koneksi ditolak (HTTP '.$r->status().').');
        $d=$r->json('data');
        if (!is_array($d) || ($d['school_id']??null)!==$bundle['school_id']) throw new RuntimeException('Respons Cloud belum kompatibel dengan pemulihan identitas.');
        if (($d['state']??'')==='pinned') {
            if (!hash_equals((string)($d['identity_epoch']??''), $bundle['epoch'])) throw new RuntimeException('BLOCKED: kunci payload tidak cocok dengan identitas sekolah Cloud.');
            return 'pinned';
        }
        if (($d['state']??'')==='new' && ($d['identity_epoch']??null)===null) return 'new';
        throw new RuntimeException('Status identitas Cloud tidak dapat diverifikasi, pemulihan ditolak.');
    }

    /**
     * Restore only an empty unbound school installation. Existing cloud-verified
     * identity, local devices, GTK, student records and aliases are protected.
     */
    private static function restorePlaintext(string $txt, ?string $currentPairingToken = null): array
    {
        self::seal();
        $b=self::parse($txt);
        if ($currentPairingToken !== null && $currentPairingToken !== '') {
            if (!self::tokenValid($currentPairingToken)) throw new RuntimeException('Kode pairing pengganti tidak valid.');
            $b['token'] = $currentPairingToken;
        }
        $env=SchoolAliasSealV21R110::env(base_path());
        // Only Admin school, not MASTER or GTK; refuse unknown legacy installs.
        SchoolAliasSealV21R110::adminSecret(base_path());
        foreach (['EDUXID_SCHOOL_ID','EDUXID_NPSN'] as $field) {
            if (isset($env[$field]) && $env[$field]!=='' && $env[$field]!==$b['school_id']) throw new RuntimeException('NPSN instalasi tidak sama dengan payload.');
        }
        if (Schema::hasTable('schools')) {
            $schools=DB::table('schools')->whereNotNull('npsn')->distinct()->pluck('npsn')->map(fn($v)=>trim((string)$v))->filter()->unique()->values()->all();
            if (count($schools)>1 || (count($schools)===1 && $schools[0]!==$b['school_id'])) throw new RuntimeException('Sekolah lokal berbeda; pemulihan ditolak.');
        }
        $state=SchoolAliasSealV21R110::state(base_path());
        if (!in_array($state,['COCOK_DENGAN_SEGEL_CLOUD','SEGEL_AWAL_PERLU_EPOCH_CLOUD'],true)) throw new RuntimeException('Segel lokal belum aman untuk pemulihan otomatis.');
        $current=(string)($env['EDUXID_SCHOOL_ALIAS_KEY']??'');
        // Existing, already-verified identity may recover a still-active pairing
        // code WITHOUT touching the key, seal, database records, or alias mapping.
        if ($state==='COCOK_DENGAN_SEGEL_CLOUD') {
            if (!hash_equals($current,$b['key'])) throw new RuntimeException('Identitas sudah terverifikasi dengan kunci lain; tidak boleh diganti.');
            if (self::verifyCloud($b)!=='pinned') throw new RuntimeException('Segel Cloud wajib pinned untuk pemulihan pairing pada instalasi aktif.');
            EduxidCloudConfig::set('admin_token',$b['token']);
            return ['school_id'=>$b['school_id'], 'message'=>'Kunci identitas tetap sama. Kode pairing telah diverifikasi dan diperbarui.'];
        }
        foreach (['teachers','students','teaching_assignments','sync_devices','cloud_identity_alias_routes','lms_sync_records'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) throw new RuntimeException('Instalasi berisi data '.$table.'; gunakan pemulihan manual, tidak menimpa identitas.');
        }
        $cloud=self::verifyCloud($b);
        $newSecret=$b['key'];
        $envFile=base_path('.env');$sealFile=SchoolAliasSealV21R110::path(base_path());
        if (is_link($envFile) || !is_file($envFile) || is_link($sealFile) || !is_file($sealFile)) throw new RuntimeException('File lokal tidak aman untuk diperbarui.');
        $oldEnv=file_get_contents($envFile);$oldSeal=file_get_contents($sealFile);
        if (!is_string($oldEnv)||!is_string($oldSeal)||strlen($oldEnv)>1048576||strlen($oldSeal)>4096) throw new RuntimeException('Konfigurasi lokal tidak dapat dibaca.');
        $changed=0;
        $updated=preg_replace('/^EDUXID_SCHOOL_ALIAS_KEY[ \t]*=[^\r\n]*/m', 'EDUXID_SCHOOL_ALIAS_KEY='.$newSecret,$oldEnv,-1,$changed);
        if (!is_string($updated)||$changed!==1) throw new RuntimeException('Entry kunci identitas pada .env harus tepat satu.');
        $seal=['format'=>'EDUXID_SCHOOL_ALIAS_SEAL_V21R110_V1',
            'state'=>$cloud==='pinned'?'cloud_verified':'initialized',
            'key_commitment'=>SchoolAliasSealV21R110::fingerprint($b['raw']),
            'school_id'=>$cloud==='pinned'?$b['school_id']:'',
            'identity_epoch'=>$cloud==='pinned'?$b['epoch']:''];
        $newSeal=json_encode($seal,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $backupDir=base_path('storage/app/identity/backup-before-enc-restore-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4)));
        if (!mkdir($backupDir,0700,true)) throw new RuntimeException('Folder cadangan identitas tidak dapat dibuat.');
        if (file_put_contents($backupDir.'/.env.before',$oldEnv,LOCK_EX)!==strlen($oldEnv) ||
            file_put_contents($backupDir.'/seal.before.json',$oldSeal,LOCK_EX)!==strlen($oldSeal)) throw new RuntimeException('Backup sebelum pemulihan gagal.');
        @chmod($backupDir.'/.env.before',0600);@chmod($backupDir.'/seal.before.json',0600);
        $tempEnv=$envFile.'.v21r124-'.bin2hex(random_bytes(4));
        $tempSeal=$sealFile.'.v21r124-'.bin2hex(random_bytes(4));
        try {
            if (file_put_contents($tempEnv,$updated,LOCK_EX)!==strlen($updated)||file_put_contents($tempSeal,$newSeal,LOCK_EX)!==strlen($newSeal)) {
                throw new RuntimeException('Penulisan file sementara gagal.');
            }
            @chmod($tempEnv,0600);@chmod($tempSeal,0600);
            DB::transaction(function () use ($tempEnv,$tempSeal,$envFile,$sealFile,$b): void {
                if (!rename($tempEnv,$envFile)||!rename($tempSeal,$sealFile)) throw new RuntimeException('Pemulihan konfigurasi identitas gagal.');
                EduxidCloudConfig::set('admin_token',$b['token']);
            });
        } catch (\Throwable $e) {
            // Best-effort file rollback. Keep verified backup for manual recovery.
            @file_put_contents($envFile,$oldEnv,LOCK_EX);@file_put_contents($sealFile,$oldSeal,LOCK_EX);
            throw new RuntimeException('Pemulihan gagal. Config lama dipertahankan; cadangan tersedia.');
        } finally {if(is_file($tempEnv))@unlink($tempEnv);if(is_file($tempSeal))@unlink($tempSeal);}
        return ['school_id'=>$b['school_id'], 'message'=>'Kunci sekolah asli dan kode pairing dipulihkan. Tutup aplikasi, lalu jalankan kembali eduXid.exe sebelum Push Cloud.'];
    }
}
