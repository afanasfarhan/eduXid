::[Bat To Exe Converter]
::
::YAwzoRdxOk+EWAjk
::fBw5plQjdCuDJFyA4Xo5LDZVXxCHLle/AqAk4ezH//+FrkQeaOQ+bobY1brAKeMcig==
::YAwzuBVtJxjWCl3EqQJgSA==
::ZR4luwNxJguZRRnk
::Yhs/ulQjdF+5
::cxAkpRVqdFKZSDk=
::cBs/ulQjdF+5
::ZR41oxFsdFKZSTk=
::eBoioBt6dFKZSDk=
::cRo6pxp7LAbNWATEpCI=
::egkzugNsPRvcWATEpCI=
::dAsiuh18IRvcCxnZtBJQ
::cRYluBh/LU+EWAnk
::YxY4rhs+aU+JeA==
::cxY6rQJ7JhzQF1fEqQJQ
::ZQ05rAF9IBncCkqN+0xwdVs0
::ZQ05rAF9IAHYFVzEqQJQ
::eg0/rx1wNQPfEVWB+kM9LVsJDGQ=
::fBEirQZwNQPfEVWB+kM9LVsJDGQ=
::cRolqwZ3JBvQF1fEqQJQ
::dhA7uBVwLU+EWDk=
::YQ03rBFzNR3SWATElA==
::dhAmsQZ3MwfNWATElA==
::ZQ0/vhVqMQ3MEVWAtB9wSA==
::Zg8zqx1/OA3MEVWAtB9wSA==
::dhA7pRFwIByZRRnk
::Zh4grVQjdCuDJFyA4Xo5LDZVXxCHLle/AqAk4ey16vKCwg==
::YB416Ek+ZG8=
::
::
::978f952a14a936cc963da21a135fa983
@echo off
setlocal EnableExtensions DisableDelayedExpansion
title eduXid System Engine
color 0A
chcp 65001 >NUL

echo ========================================
echo                 eduXid
echo    Platform Manajemen Sekolah Pintar
echo           ©2026. Anas Farhan
echo ========================================
echo.

timeout /t 3 /nobreak > NUL

ping -n 3 127.0.0.1 >NUL


rem ============================================================
rem  EDUXID PORTABLE PATH
rem ============================================================

set "ROOT=%~dp0"
if not exist "%ROOT%eduXid\artisan" (
    if exist "%CD%\eduXid\artisan" if exist "%CD%\php\php.exe" set "ROOT=%CD%\"
)
if not exist "%ROOT%eduXid\artisan" (
    if exist "%CD%\artisan" if exist "%CD%\..\php\php.exe" (
        for %%I in ("%CD%\..") do set "ROOT=%%~fI\"
    )
)
set "PROJ=%ROOT%eduXid"
rem Auto-update kini hanya diperiksa sekali di tahap INSTALL_READY.
rem Tidak memanggil EXE installer Task Scheduler pada startup.

set "DAEMON_TITLE=eduXid Sync Daemon - %PROJ%"
set "DAEMON_DIR=%PROJ%\resources\gtk-bundle"
set "EDUXID_EXIT=0"
if exist "%SystemRoot%\System32\cmd.exe" set "ComSpec=%SystemRoot%\System32\cmd.exe"

set "PHP_DIR=%ROOT%php"
set "PHP_EXE=%PHP_DIR%\php.exe"
set "PHP_INI=%PHP_DIR%\php.ini"
set "INI_SCAN_DIR=%PHP_DIR%\eduxid-ini-scan"

set "CACERT=%PHP_DIR%\extras\ssl\cacert.pem"
set "OPCACHE_DIR=%PROJ%\storage\framework\opcache"

set "CLOUD_URL=https://eduxid-cloud.eduxid-sync-anas.workers.dev/api/device/register"
set "LOCAL_URL=http://127.0.0.1:8000"

rem ============================================================
rem  INSTALLATION IDENTITY
rem ============================================================

set "INSTALL_GUARD=%PROJ%\bootstrap\LauncherInstallGuardV21.php"
set "ENV_FILE=%PROJ%\.env"
set "SCHOOL_INSTALL_MARKER=%PROJ%\storage\app\school-installation.json"
set "GTK_INSTALL_MARKER=%PROJ%\storage\app\installation.json"



rem ============================================================
rem  BERSIHKAN ENVIRONMENT LAMA
rem ============================================================

set "PHPRC="
set "PHP_INI_SCAN_DIR=%INI_SCAN_DIR%"
set "SSL_CERT_FILE="
set "CURL_CA_BUNDLE="


rem ============================================================
rem  PREFLIGHT
rem ============================================================

if not exist "%PHP_EXE%" goto :NO_PHP
if not exist "%PHP_INI%" goto :NO_PHP_INI
if not exist "%CACERT%" goto :NO_CACERT
if not exist "%PROJ%\artisan" goto :NO_PROJECT
if not exist "%INSTALL_GUARD%" goto :NO_LAUNCHER_GUARD
if not exist "%DAEMON_DIR%\eduXid-Daemon.bat" goto :NO_DAEMON_SCRIPT

rem V21R99: Uji executable sebelum menyalahkan OPcache.
rem PHP portabel tetap prioritas; alternatif hanya saat eksekusinya gagal.
"%PHP_EXE%" -n -v >NUL 2>&1
if errorlevel 1 (
    echo [PERINGATAN] php.exe portabel ditolak/tidak dapat dijalankan.
    echo [INFO] Menguji PHP alternatif D:\php\php.exe...
    if not exist "D:\php\php.exe" goto :PHP_EXECUTION_DENIED
    "D:\php\php.exe" -n -v >NUL 2>&1
    if errorlevel 1 goto :PHP_EXECUTION_DENIED
    set "PHP_EXE=D:\php\php.exe"
    echo [OK] PHP alternatif aktif. Konfigurasi dan ekstensi tetap akan diverifikasi.
)
set "EDUXID_RUNTIME_PHP=%PHP_EXE%"


if not exist "%INI_SCAN_DIR%" mkdir "%INI_SCAN_DIR%" >NUL 2>&1
if not exist "%INI_SCAN_DIR%" goto :PHP_CONFIG_ERROR
if exist "%INI_SCAN_DIR%\*.ini" goto :PHP_CONFIG_ERROR

if not exist "%PROJ%\storage\app\public" mkdir "%PROJ%\storage\app\public" >NUL 2>&1
if not exist "%PROJ%\public\storage" (
    mklink /J "%PROJ%\public\storage" "%PROJ%\storage\app\public" >NUL 2>&1
    if errorlevel 1 echo [INFO] Folder penyimpanan publik belum dapat ditautkan. Gunakan folder lokal pada drive NTFS.
)


rem ============================================================
rem  PASTIKAN STRUKTUR RUNTIME LARAVEL TERSEDIA
rem ============================================================

echo [BOOT] Memastikan folder runtime Laravel...

for %%D in (
    "%PROJ%\storage\framework"
    "%PROJ%\storage\framework\views"
    "%PROJ%\storage\framework\sessions"
    "%PROJ%\storage\framework\cache"
    "%PROJ%\storage\framework\cache\data"
    "%PROJ%\storage\logs"
    "%PROJ%\bootstrap\cache"
) do (
    if not exist "%%~fD" mkdir "%%~fD" >NUL 2>&1
    if not exist "%%~fD" goto :RUNTIME_DIR_ERROR
)

echo [OK] Folder runtime Laravel siap.
echo.

if not exist "%OPCACHE_DIR%" (
    mkdir "%OPCACHE_DIR%" >NUL 2>&1
)

if not exist "%OPCACHE_DIR%" goto :OPCACHE_ERROR

"%PHP_EXE%" -n -r "$d=$argv[1];$f=$d.DIRECTORY_SEPARATOR.'.eduxid-write-test';if(!is_dir($d)){exit(1);}if(file_put_contents($f,'ok',LOCK_EX)===false){exit(2);}@unlink($f);" "%OPCACHE_DIR%"

if errorlevel 1 goto :OPCACHE_ERROR


rem ============================================================
rem  PORTABLE SSL + OPCACHE CONFIGURATION
rem ============================================================

echo [BOOT] Menyiapkan konfigurasi PHP portable...

"%PHP_EXE%" -n -r "$f=$argv[1];$ca=$argv[2];$oc=$argv[3];$lines=file($f);if($lines===false){exit(10);}$keys=['curl.cainfo','openssl.cafile','opcache.enable','opcache.enable_cli','opcache.file_cache','opcache.file_cache_fallback','opcache.file_cache_only'];$out=[];foreach($lines as $line){$trim=trim($line);$skip=false;if($trim!=='' && $trim[0]!==';' && $trim[0]!=='#'){$p=strpos($trim,'=');$name=strtolower(trim($p===false?$trim:substr($trim,0,$p)));if(in_array($name,$keys,true)){$skip=true;}}if(!$skip){$out[]=$line;}}$q=chr(34);$ca=str_replace('\\','/',$ca);$oc=str_replace('\\','/',$oc);$s=rtrim(implode('',$out)).PHP_EOL.PHP_EOL.'curl.cainfo='.$q.$ca.$q.PHP_EOL.'openssl.cafile='.$q.$ca.$q.PHP_EOL.'opcache.enable=1'.PHP_EOL.'opcache.enable_cli=1'.PHP_EOL.'opcache.file_cache='.$q.$oc.$q.PHP_EOL.'opcache.file_cache_fallback=1'.PHP_EOL.'opcache.file_cache_only=0'.PHP_EOL;if(file_put_contents($f,$s,LOCK_EX)===false){exit(11);}" "%PHP_INI%" "%CACERT%" "%OPCACHE_DIR%"

if errorlevel 1 goto :PHP_INI_WRITE_ERROR

echo [OK] Konfigurasi PHP portable diperbarui.
echo.

timeout /t 1 /nobreak > NUL

rem ============================================================
rem  EDUXID LARAVEL CA
rem ============================================================

set "EDUXID_CA_CERT=%CACERT%"


rem ============================================================
rem  VERIFIKASI PHP.INI
rem ============================================================

echo [BOOT] Memeriksa PHP...

"%PHP_EXE%" -c "%PHP_INI%" -r "echo 'PHP executable    : '.PHP_BINARY.PHP_EOL;echo 'PHP configuration : '.php_ini_loaded_file().PHP_EOL;"

if errorlevel 1 goto :PHP_CONFIG_ERROR

echo.

timeout /t 1 /nobreak > NUL

rem ============================================================
rem  PASTIKAN PHP.INI YANG BENAR
rem ============================================================

"%PHP_EXE%" -c "%PHP_INI%" -r "$a=realpath(php_ini_loaded_file());$b=realpath($argv[1]);if($a===false){exit(1);}if($b===false){exit(1);}if(strcasecmp($a,$b)!==0){fwrite(STDERR,'PHP menggunakan php.ini yang salah.'.PHP_EOL);exit(1);}echo '[OK] php.ini portable aktif.'.PHP_EOL;" "%PHP_INI%"

if errorlevel 1 goto :PHP_CONFIG_ERROR

echo.

timeout /t 1 /nobreak > NUL

rem ============================================================
rem  CEK EKSTENSI PHP
rem ============================================================

echo [BOOT] Memeriksa ekstensi PHP...

"%PHP_EXE%" -c "%PHP_INI%" -r "foreach(['curl','openssl','mbstring','fileinfo','PDO','pdo_sqlite'] as $e){if(!extension_loaded($e)){fwrite(STDERR,'Extension tidak tersedia: '.$e.PHP_EOL);exit(1);}}echo '[OK] Extension PHP lengkap.'.PHP_EOL;"

if errorlevel 1 goto :NO_EXT

echo.

timeout /t 1 /nobreak > NUL

rem ============================================================
rem  VERIFIKASI CA
rem ============================================================

echo [SSL] Memeriksa konfigurasi CA...

"%PHP_EXE%" -c "%PHP_INI%" -r "$expected=realpath($argv[1]);$curl=ini_get('curl.cainfo');$openssl=ini_get('openssl.cafile');echo 'curl.cainfo    : '.$curl.PHP_EOL;echo 'openssl.cafile : '.$openssl.PHP_EOL;if($expected===false){exit(1);}$curlReal=realpath($curl);$opensslReal=realpath($openssl);if($curlReal===false){exit(2);}if($opensslReal===false){exit(3);}if(strcasecmp($expected,$curlReal)!==0){exit(4);}if(strcasecmp($expected,$opensslReal)!==0){exit(5);}if(!is_readable($curlReal)){exit(6);}echo '[OK] CA certificate valid.'.PHP_EOL;" "%CACERT%"

if errorlevel 1 goto :SSL_ERROR

echo.


rem ============================================================
rem  VERIFIKASI OPCACHE PORTABLE
rem ============================================================

echo [OPCACHE] Memeriksa konfigurasi portable...

"%PHP_EXE%" -c "%PHP_INI%" -r "if(!extension_loaded('Zend OPcache')){echo '[INFO] Zend OPcache tidak aktif. Server tetap dapat berjalan.'.PHP_EOL;exit(0);}$expected=realpath($argv[1]);$cache=ini_get('opcache.file_cache');$fallback=ini_get('opcache.file_cache_fallback');$cli=ini_get('opcache.enable_cli');echo 'file_cache      : '.$cache.PHP_EOL;echo 'fallback        : '.$fallback.PHP_EOL;echo 'enable_cli      : '.$cli.PHP_EOL;if($expected===false){exit(1);}$cacheReal=realpath($cache);if($cacheReal===false){exit(2);}if(strcasecmp($expected,$cacheReal)!==0){exit(3);}if(!is_writable($cacheReal)){exit(4);}if((string)$fallback!=='1'){exit(5);}if((string)$cli!=='1'){exit(6);}echo '[OK] OPcache portable siap - fallback ASLR aktif.'.PHP_EOL;" "%OPCACHE_DIR%"

if errorlevel 1 goto :OPCACHE_ERROR

echo.


rem ============================================================
rem  DETEKSI INSTALASI SEKOLAH / PAKET GTK
rem ============================================================

echo [INSTALL] Memeriksa jenis dan status instalasi...

rem Penanda status per proses menghindari hasil ERRORLEVEL yang tidak konsisten
rem pada launcher BAT yang telah dikompilasi ke EXE.
set "INSTALL_STATE="
set "INSTALL_PROBE_EXIT="
set "INSTALL_STATE_FILE=%PROJ%\storage\framework\eduxid-install-%RANDOM%-%RANDOM%.state"
if exist "%INSTALL_STATE_FILE%" del /f /q "%INSTALL_STATE_FILE%" >NUL 2>&1
if exist "%INSTALL_STATE_FILE%" goto :INSTALL_CHECK_ERROR

"%PHP_EXE%" -c "%PHP_INI%" "%INSTALL_GUARD%" probe "%PROJ%" "%INSTALL_STATE_FILE%"
set "INSTALL_PROBE_EXIT=%ERRORLEVEL%"

rem Hanya menerima status yang benar-benar ditulis oleh pemeriksaan PHP ini.
if not exist "%INSTALL_STATE_FILE%" goto :INSTALL_CHECK_ERROR
set /p "INSTALL_STATE="<"%INSTALL_STATE_FILE%"
del /f /q "%INSTALL_STATE_FILE%" >NUL 2>&1
if not defined INSTALL_STATE goto :INSTALL_CHECK_ERROR
if not "%INSTALL_PROBE_EXIT%"=="0" echo [INFO] Hasil PHP: %INSTALL_PROBE_EXIT%. Status instalasi valid dibaca dari pemeriksaan terpisah.
echo [INSTALL] Kode status: %INSTALL_STATE%

if "%INSTALL_STATE%"=="0" goto :INSTALL_READY
if "%INSTALL_STATE%"=="10" goto :FIRST_INSTALL_SCHOOL
if "%INSTALL_STATE%"=="11" goto :GTK_INSTALL_READY
if "%INSTALL_STATE%"=="12" goto :RESUME_SCHOOL_INSTALL
if "%INSTALL_STATE%"=="41" goto :GTK_INSTALL_INVALID
if "%INSTALL_STATE%"=="42" goto :SCHOOL_INSTALL_INVALID
if "%INSTALL_STATE%"=="43" goto :SCHOOL_INSTALL_STALE
goto :INSTALL_CHECK_ERROR


rem ============================================================
rem  FIRST INSTALL - SEKOLAH BARU
rem ============================================================

:FIRST_INSTALL_SCHOOL

echo.
echo ========================================
echo   INSTALASI PERTAMA EDUXID SEKOLAH
echo ========================================
echo.
goto :SETUP_SCHOOL_INSTALL

:RESUME_SCHOOL_INSTALL

echo [INSTALL] Melanjutkan instalasi Sekolah yang sempat terputus.
echo [INSTALL] APP_KEY, alias sekolah, dan token lokal tetap dipertahankan.
goto :SETUP_SCHOOL_INSTALL

:SETUP_SCHOOL_INSTALL

rem Guard memeriksa database yang sama dengan DB_DATABASE Laravel,
rem 3 akun bootstrap, alias kosong, dan menulis marker initializing.
rem Tidak membuat ulang key saat melanjutkan instalasi parsial.
"%PHP_EXE%" -c "%PHP_INI%" "%INSTALL_GUARD%" prepare "%PROJ%"
if errorlevel 1 goto :FIRST_INSTALL_ERROR

rem V21R121: no activation/vault prompt before local Admin login.
rem A one-time local alias key is sealed by the guarded clean first install.
rem Cloud pairing is entered from Admin after successful login.
echo [INSTALL] Siap login Admin. Pairing Cloud dilakukan di aplikasi.

cd /d "%PROJ%"
if errorlevel 1 goto :NO_PROJECT

echo [INSTALL] Membersihkan cache Laravel...
"%PHP_EXE%" -c "%PHP_INI%" artisan optimize:clear
if errorlevel 1 goto :FIRST_INSTALL_ERROR

echo [INSTALL] Menjalankan migrasi database terpilih...
"%PHP_EXE%" -c "%PHP_INI%" artisan migrate --force
if errorlevel 1 goto :FIRST_INSTALL_ERROR

echo [INSTALL] Memfinalisasi instalasi Sekolah...
"%PHP_EXE%" -c "%PHP_INI%" "%INSTALL_GUARD%" finalize "%PROJ%"
if errorlevel 1 goto :FIRST_INSTALL_ERROR

echo.
echo [OK] eduXid siap. Login Admin, lalu masukkan kode pairing Cloud.
echo.
goto :INSTALL_READY


rem ============================================================
rem  PAKET GTK
rem  DILARANG RESET APP_KEY / NPSN / DEVICE / PAIRING
rem ============================================================

:GTK_INSTALL_READY

echo [INSTALL] Mode Paket GTK aktif.
echo [INSTALL] APP_KEY, NPSN, device_id, database, dan pairing dipertahankan.
echo.
goto :INSTALL_READY


:INSTALL_READY

rem Gate V21 tidak menulis .env atau token dan berlaku untuk Sekolah + GTK.
echo [INSTALL] Memvalidasi SQLite dan identitas V21 sebelum startup...
"%PHP_EXE%" -c "%PHP_INI%" "%INSTALL_GUARD%" verify "%PROJ%"
if errorlevel 1 goto :SCHOOL_IDENTITY_ERROR
echo.


rem ============================================================
rem  TEST HTTPS
rem ============================================================

echo [SSL] Menguji HTTPS eduXid Cloud...

"%PHP_EXE%" -c "%PHP_INI%" -r "$c=curl_init($argv[1]);curl_setopt($c,CURLOPT_RETURNTRANSFER,true);curl_setopt($c,CURLOPT_CONNECTTIMEOUT,5);curl_setopt($c,CURLOPT_TIMEOUT,10);curl_exec($c);$e=curl_errno($c);$m=curl_error($c);$h=curl_getinfo($c,CURLINFO_HTTP_CODE);unset($c);echo 'cURL : '.$e.PHP_EOL;echo 'HTTP : '.$h.PHP_EOL;if($e){echo $m.PHP_EOL;exit(1);}" "%CLOUD_URL%"

if errorlevel 1 (
    echo [WARNING] Koneksi HTTPS Cloud belum berhasil.
    echo eduXid lokal tetap dijalankan. Periksa pesan koneksi/TLS di atas.
    echo Sinkronisasi akan mencoba lagi; pairing tidak diubah.
) else (
    echo [OK] Koneksi HTTPS berhasil; status pairing diperiksa oleh aplikasi.
)
echo.


rem ============================================================
rem  MASUK PROJECT
rem ============================================================

cd /d "%PROJ%"

if errorlevel 1 goto :NO_PROJECT


rem ============================================================
rem  STEP 1 - CLEANUP INSTANCE
rem ============================================================

echo [1/4] Memeriksa instance eduXid...

"%PHP_EXE%" -c "%PHP_INI%" artisan eduxid:cleanup-instances "%PROJ%"

if errorlevel 1 (
    echo.
    echo [WARNING] Pemeriksaan instance menghasilkan peringatan.
    echo Startup tetap dilanjutkan.
    echo.
)

timeout /t 1 /nobreak > NUL


rem ============================================================
rem  STEP 2 - CLEAR CACHE
rem ============================================================

echo [2/4] Membersihkan cache Laravel...

"%PHP_EXE%" -c "%PHP_INI%" artisan optimize:clear

if errorlevel 1 goto :BOOT_ERROR

echo.

timeout /t 1 /nobreak > NUL

rem ============================================================
rem  VERIFIKASI CONFIG EDUXID
rem ============================================================

echo [BOOT] Memeriksa konfigurasi CA Laravel...

"%PHP_EXE%" -c "%PHP_INI%" "%INSTALL_GUARD%" verify-ca "%PROJ%"
if errorlevel 1 goto :BOOT_ERROR

echo.

timeout /t 1 /nobreak > NUL

rem ============================================================
rem  AUTO-UPDATE SAAT LAUNCHER DIBUKA (TANPA SCHEDULE)
rem ============================================================
echo [UPDATE] Memeriksa pembaruan Cloud saat startup...
rem Hapus tugas periodik warisan V1 milik folder instalasi ini saja.
if exist "%ROOT%system\eduXidAutoUpdateBootstrap.php" (
  "%PHP_EXE%" -n "%ROOT%system\eduXidAutoUpdateBootstrap.php" --disable >NUL 2>&1
)
rem Command ini mengunduh patch tepercaya dan membuka updater tanpa dialog.
"%PHP_EXE%" -c "%PHP_INI%" artisan eduxid:update:auto --startup
set "UPDATE_STARTUP_RC=%ERRORLEVEL%"
if "%UPDATE_STARTUP_RC%"=="20" (
  echo [UPDATE] Pembaruan ditemukan. Launcher menyerahkan kendali ke updater.
  goto :EDUXID_END
)
if not "%UPDATE_STARTUP_RC%"=="0" echo [INFO] Update tidak tersedia atau pemeriksaan tertunda; eduXid tetap dibuka.
echo.

rem ============================================================
rem  SERVER BERSAMA: INSTALASI SEKOLAH DAN GTK BOLEH SE-PC
rem ============================================================

rem Periksa port segera sebelum membuka daemon/server.
rem Tidak menulis APP_URL atau port ke .env permanen.
set "LOCAL_PORT="
set "PORT_PROBE_FILE=%PROJ%\storage\framework\eduxid-port-%RANDOM%-%RANDOM%.state"
if exist "%PORT_PROBE_FILE%" del /f /q "%PORT_PROBE_FILE%" >NUL 2>&1
if exist "%PORT_PROBE_FILE%" goto :PORT_IN_USE_ERROR
"%PHP_EXE%" -n -r "$file=$argv[1];$preferred=(int)$argv[2];if($preferred<1024||$preferred>65535){$preferred=8000;}$ports=array_unique(array_merge([$preferred],range(8000,8010)));foreach($ports as $port){$sock=@stream_socket_server('tcp://0.0.0.0:'.$port,$errno,$errstr,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);if($sock!==false){fclose($sock);$temp=$file.'.'.getmypid().'.tmp';if(file_put_contents($temp,(string)$port.PHP_EOL,LOCK_EX)===false||!@rename($temp,$file)){@unlink($temp);exit(2);}exit(0);}}fwrite(STDERR,'Semua port lokal 8000-8010 sedang digunakan.'.PHP_EOL);exit(3);" "%PORT_PROBE_FILE%" "8000"
if not exist "%PORT_PROBE_FILE%" goto :PORT_IN_USE_ERROR
set /p "LOCAL_PORT="<"%PORT_PROBE_FILE%"
del /f /q "%PORT_PROBE_FILE%" >NUL 2>&1
if not defined LOCAL_PORT goto :PORT_IN_USE_ERROR
set "LOCAL_URL=http://127.0.0.1:%LOCAL_PORT%"
if not "%LOCAL_PORT%"=="8000" (
    echo [INFO] Port 8000 digunakan oleh aplikasi lain.
    echo [INFO] eduXid ini akan berjalan pada port %LOCAL_PORT%.
    rem APP_URL proses Laravel diselaraskan tanpa menimpa .env.
    set "APP_URL=http://127.0.0.1:%LOCAL_PORT%"
)
echo [SERVER] Alamat: %LOCAL_URL%
echo.

rem ============================================================
rem  STEP 3 - FIREWALL
rem ============================================================

echo [3/4] Memastikan aturan firewall eduXid...

netsh advfirewall firewall show rule name="eduXid Server (HTTP) %LOCAL_PORT%" >NUL 2>&1

if errorlevel 1 (

    netsh advfirewall firewall add rule name="eduXid Server (HTTP) %LOCAL_PORT%" dir=in action=allow protocol=TCP localport=%LOCAL_PORT% remoteip=192.168.0.0/16,10.0.0.0/8,172.16.0.0/12 >NUL 2>&1

    if errorlevel 1 (
        echo [INFO] Rule firewall belum dapat dibuat.
        echo        Jika HP/LAN tidak dapat mengakses eduXid,
        echo        jalankan launcher sekali sebagai Administrator.
    ) else (
        echo [OK] Rule firewall Port %LOCAL_PORT% dibuat.
    )

) else (

    echo [OK] Rule firewall Port %LOCAL_PORT% sudah tersedia.

)

echo.

timeout /t 1 /nobreak > NUL

rem ============================================================
rem  GTK AUTO-PAIR - pemulihan aman sebelum daemon; offline tetap jalan
rem ============================================================
if exist "%PROJ%\storage\app\gtk-autopair.json" (
    echo [PAIR] Mencoba auto-pair Paket GTK melalui Cloud...
    "%PHP_EXE%" -c "%PHP_INI%" "%PROJ%\artisan" eduxid:gtk-auto-pair
    if errorlevel 1 (
        echo [WARNING] Auto-pair tertunda. eduXid lokal tetap dijalankan.
        echo           Percobaan berikutnya dilakukan saat startup selanjutnya.
    ) else (
        echo [OK] Auto-pair GTK berhasil.
    )
    echo.
)

rem ============================================================
rem  STEP 4 - SYNC DAEMON
rem ============================================================

echo [4/4] Menjalankan eduXid Sync Daemon...

start "%DAEMON_TITLE%" "%ComSpec%" /D /K ""%DAEMON_DIR%\eduXid-Daemon.bat""
set "DAEMON_START_EXIT=%ERRORLEVEL%"
if not "%DAEMON_START_EXIT%"=="0" goto :DAEMON_START_ERROR

echo.

timeout /t 1 /nobreak > NUL

rem ============================================================
rem  READY
rem ============================================================

cls

echo.
echo ========================================
echo       Sistem eduXid siap digunakan!
echo ========================================
echo.
echo Salinan aktif :
echo %PROJ%
echo.
echo PHP Portable :
echo %PHP_EXE%
echo.
echo PHP Configuration :
echo %PHP_INI%
echo.
echo SSL CA :
echo %CACERT%
echo.
echo OPcache File Cache :
echo %OPCACHE_DIR%
echo.
echo Server lokal :
echo %LOCAL_URL%
echo.
echo Jendela Sync Daemon dibuka terpisah; periksa status di sana.
echo Jangan tutup jendela ini selama eduXid digunakan.
echo.


rem ============================================================
rem  BUKA BROWSER
rem ============================================================

start "" "%LOCAL_URL%"


rem ============================================================
rem  LARAVEL SERVER
rem ============================================================

"%PHP_EXE%" -c "%PHP_INI%" -d memory_limit=512M -d opcache.enable=1 -d opcache.enable_cli=1 -d "opcache.file_cache=%OPCACHE_DIR%" -d opcache.file_cache_fallback=1 -d opcache.file_cache_only=0 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=0 -d opcache.memory_consumption=128 -d opcache.max_accelerated_files=10000 artisan serve --host=0.0.0.0 --port=%LOCAL_PORT%


rem ============================================================
rem  SERVER BERHENTI
rem ============================================================

set "SERVER_EXIT=%ERRORLEVEL%"

taskkill /FI "WINDOWTITLE eq %DAEMON_TITLE%" /T /F >NUL 2>&1

echo.
echo ========================================
echo Server eduXid telah berhenti.
echo ========================================
echo.

pause
set "EDUXID_EXIT=%SERVER_EXIT%"
goto :EDUXID_END


rem ============================================================
rem  ERROR - GAGAL MENULIS PHP.INI
rem ============================================================

:GTK_INSTALL_INVALID

echo.
echo ========================================
echo [ERROR] Identitas Paket GTK tidak lengkap atau bukan alias V21.
echo ========================================
echo.
echo Launcher mendeteksi edition/type teacher, tetapi salah satu
echo dari APP_KEY, NPSN, protokol, atau device_id alias tidak valid.
echo.
echo Paket GTK harus dibuat ulang dari Admin. Launcher tidak akan
echo membuat identitas baru karena dapat merusak pairing Guru.
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


:SCHOOL_INSTALL_INVALID

echo.
echo ========================================
echo [ERROR] Instalasi Sekolah tidak konsisten.
echo ========================================
echo.
echo Marker, key, dan atau alias sekolah tidak konsisten.
echo Kunci yang sudah ada tidak akan ditimpa secara otomatis.
echo Buka salinan eduXid yang lengkap beserta folder data sebelumnya.
echo Jika data lama hilang, hubungi Admin Pusat untuk prosedur pemulihan.
echo Periksa .env dan marker instalasi dengan aman.
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


:SCHOOL_INSTALL_STALE

echo.
echo ========================================
echo [ERROR] Paket distribusi belum bersih.
echo ========================================
echo.
echo APP_KEY masih kosong tetapi masih ditemukan identitas
echo Sekolah/Cloud lama pada .env.
echo Bersihkan paket distribusi lalu buat ZIP ulang.
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


:INSTALL_CHECK_ERROR

echo.
echo [ERROR] Pemeriksaan jenis/status instalasi gagal.
if defined INSTALL_PROBE_EXIT echo [DIAG] Proses PHP: %INSTALL_PROBE_EXIT%
if defined INSTALL_STATE echo [DIAG] Status ditemukan: %INSTALL_STATE%
if defined INSTALL_STATE_FILE echo [DIAG] File status: %INSTALL_STATE_FILE%
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END



:NO_LAUNCHER_GUARD

echo.
echo [ERROR] Helper instalasi V21 tidak ditemukan.
echo Diperlukan file:
echo "%INSTALL_GUARD%"
echo Perbaiki paket distribusi, jangan buat key secara manual.
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END

:SCHOOL_IDENTITY_ERROR

echo.
echo [ERROR] Pemeriksaan identitas V21 atau SQLite gagal.
echo Startup server dan daemon dibatalkan untuk mencegah routing salah.
echo Pulihkan konfigurasi dari instalasi yang benar; jangan reset key.
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


:FIRST_INSTALL_ERROR

echo.
echo ========================================
echo [ERROR] Instalasi Sekolah belum selesai.
echo ========================================
echo.
echo Perbaiki error di atas lalu jalankan eduXid.exe kembali.
echo Key yang sudah ditulis tetap dipertahankan untuk resume.
echo Launcher tidak menghapus database atau pairing.
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


:DAEMON_START_ERROR

echo.
echo [ERROR] Jendela Sync Daemon tidak dapat dibuka. Kode: %DAEMON_START_EXIT%
echo Periksa jendela Command Prompt Sync Daemon.
echo Jalankan perintah berikut dari folder proyek untuk melihat error:
echo "%PHP_EXE%" -c "%PHP_INI%" artisan eduxid:lms-sync --interval=5
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END

:PORT_IN_USE_ERROR

echo.
echo [ERROR] Tidak menemukan port lokal yang tersedia untuk eduXid.
echo Pastikan server lain berhenti atau gunakan port berbeda.
echo Tidak ada database, pairing, atau konfigurasi yang diubah.
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


:NO_DAEMON_SCRIPT

echo.
echo [ERROR] File pembuka daemon belum terpasang.
echo Salin eduXid-Daemon.bat ke:
echo "%DAEMON_DIR%\eduXid-Daemon.bat"
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END

:PHP_INI_WRITE_ERROR

echo.
echo ========================================
echo [ERROR] php.ini tidak dapat diperbarui.
echo ========================================
echo.
echo File:
echo %PHP_INI%
echo.
echo Pastikan file tidak Read-only dan folder eduXid
echo memiliki izin tulis.
echo.

pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


rem ============================================================
rem  ERROR - PHP CONFIG
rem ============================================================

:PHP_CONFIG_ERROR

echo.
echo ========================================
echo [ERROR] Konfigurasi PHP portable gagal.
echo ========================================
echo.
echo PHP:
echo %PHP_EXE%
echo.
echo PHP INI:
echo %PHP_INI%
echo.

pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


rem ============================================================
rem  ERROR - EXTENSION
rem ============================================================

:NO_EXT

echo.
echo ========================================
echo [ERROR] Extension PHP tidak lengkap.
echo ========================================
echo.
echo Periksa:
echo %PHP_INI%
echo.

pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


rem ============================================================
rem  ERROR - SSL
rem ============================================================

:SSL_ERROR

echo.
echo ========================================
echo [ERROR] CA certificate tidak valid.
echo ========================================
echo.
echo CA:
echo %CACERT%
echo.
echo PHP INI:
echo %PHP_INI%
echo.

pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


rem ============================================================
rem  ERROR - TLS
rem ============================================================

:TLS_ERROR

echo.
echo ========================================
echo [ERROR] HTTPS EDUXID Cloud gagal.
echo ========================================
echo.
echo PHP:
echo %PHP_EXE%
echo.
echo PHP INI:
echo %PHP_INI%
echo.
echo CA:
echo %CACERT%
echo.
echo Jangan menonaktifkan SSL verification.
echo.

pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


rem ============================================================
rem  ERROR - OPCACHE
rem ============================================================

:RUNTIME_DIR_ERROR

echo.
echo ========================================
echo [ERROR] Folder runtime Laravel tidak dapat dibuat.
echo ========================================
echo.
echo Pastikan folder instalasi eduXid memiliki izin tulis.
echo Folder yang wajib tersedia:
echo   storage\framework\views
echo   storage\framework\sessions
echo   storage\framework\cache\data
echo   storage\logs
echo   bootstrap\cache
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


:OPCACHE_ERROR

echo.
echo ========================================
echo [ERROR] Konfigurasi OPcache portable gagal.
echo ========================================
echo.
echo Folder OPcache:
echo %OPCACHE_DIR%
echo.
echo Pastikan folder eduXid memiliki izin tulis.
echo Jangan mematikan ASLR Windows.
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


rem ============================================================
rem  ERROR - BOOT
rem ============================================================

:BOOT_ERROR

echo.
echo ========================================
echo [ERROR] Persiapan eduXid gagal.
echo ========================================
echo.

pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


rem ============================================================
rem  ERROR - PHP
rem ============================================================
:PHP_EXECUTION_DENIED

echo.
echo ========================================
echo [ERROR] Windows menolak PHP portabel dan alternatif tidak dapat dijalankan.
echo ========================================
echo PHP portabel : "%ROOT%php\php.exe"
echo PHP alternatif: "D:\php\php.exe"
echo Periksa Windows Security / Protection history dan keutuhan file.
echo Jangan menonaktifkan antivirus, ASLR, atau OPcache untuk mengatasi penolakan executable.
echo.
pause
set "EDUXID_EXIT=1"
goto :EDUXID_END

:NO_PHP

echo.
echo ========================================
echo [ERROR] PHP portable tidak ditemukan.
echo ========================================
echo.
echo Lokasi:
echo %PHP_EXE%
echo.

pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


rem ============================================================
rem  ERROR - PHP.INI
rem ============================================================

:NO_PHP_INI

echo.
echo ========================================
echo [ERROR] php.ini tidak ditemukan.
echo ========================================
echo.
echo Lokasi:
echo %PHP_INI%
echo.

pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


rem ============================================================
rem  ERROR - CACERT
rem ============================================================

:NO_CACERT

echo.
echo ========================================
echo [ERROR] CA certificate tidak ditemukan.
echo ========================================
echo.
echo Lokasi:
echo %CACERT%
echo.
echo Bundle eduXid harus memiliki:
echo php\extras\ssl\cacert.pem
echo.

pause
set "EDUXID_EXIT=1"
goto :EDUXID_END


rem ============================================================
rem  ERROR - PROJECT
rem ============================================================

:NO_PROJECT

echo.
echo ========================================
echo [ERROR] Project eduXid tidak ditemukan.
echo ========================================
echo.
echo Lokasi:
echo %PROJ%
echo.

pause
set "EDUXID_EXIT=1"
goto :EDUXID_END

:EDUXID_END
exit /b %EDUXID_EXIT%
