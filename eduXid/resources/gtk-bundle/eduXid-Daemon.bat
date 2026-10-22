@echo off
setlocal EnableExtensions DisableDelayedExpansion
chcp 65001 >NUL

for %%I in ("%~dp0..\..\..") do set "BUNDLE_ROOT=%%~fI"
set "PROJ=%BUNDLE_ROOT%\eduXid"
set "PHP_DIR=%BUNDLE_ROOT%\php"
set "PHP_EXE=%PHP_DIR%\php.exe"
set "PHP_INI=%PHP_DIR%\php.ini"
set "OPCACHE_DIR=%PROJ%\storage\framework\opcache"
set "EDUXID_CA_CERT=%PHP_DIR%\extras\ssl\cacert.pem"
set "PHPRC="
set "PHP_INI_SCAN_DIR=%PHP_DIR%\eduxid-ini-scan"
set "SSL_CERT_FILE="
set "CURL_CA_BUNDLE="
title eduXid Sync Daemon - %PROJ%

if not exist "%PHP_EXE%" goto :BAD_BUNDLE
if not exist "%PHP_INI%" goto :BAD_BUNDLE
if not exist "%PROJ%\artisan" goto :BAD_BUNDLE

rem V21R99: Pakai PHP yang sudah dipilih launcher jika tersedia.
if defined EDUXID_RUNTIME_PHP if exist "%EDUXID_RUNTIME_PHP%" set "PHP_EXE=%EDUXID_RUNTIME_PHP%"
"%PHP_EXE%" -n -v >NUL 2>&1
if errorlevel 1 (
    echo [PERINGATAN] PHP portabel daemon tidak dapat dijalankan.
    if not exist "D:\php\php.exe" goto :PHP_DENIED
    "D:\php\php.exe" -n -v >NUL 2>&1
    if errorlevel 1 goto :PHP_DENIED
    set "PHP_EXE=D:\php\php.exe"
)
rem Gunakan php.ini dan CA milik instalasi, bukan mengganti konfigurasi diam-diam.
"%PHP_EXE%" -c "%PHP_INI%" -r "foreach(['curl','openssl','mbstring','fileinfo','PDO','pdo_sqlite'] as $e){if(!extension_loaded($e)){fwrite(STDERR,'Ekstensi hilang: '.$e.PHP_EOL);exit(1);}}"
if errorlevel 1 goto :PHP_CONFIG_FAIL

cd /d "%PROJ%"
if errorlevel 1 goto :BAD_BUNDLE

echo ========================================
echo             eduXid Sync Daemon
echo ========================================
echo Salinan: "%PROJ%"
echo PHP: "%PHP_EXE%"
echo.

"%PHP_EXE%" -c "%PHP_INI%" -d memory_limit=256M -d opcache.enable=1 -d opcache.enable_cli=1 -d "opcache.file_cache=%OPCACHE_DIR%" -d opcache.file_cache_fallback=1 -d opcache.file_cache_only=0 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=0 "%PROJ%\artisan" eduxid:lms-sync --interval=5

set "DAEMON_EXIT=%ERRORLEVEL%"
echo.
echo [INFO] Proses daemon berhenti. Kode keluar: %DAEMON_EXIT%
echo Baca pesan di atas, termasuk bila daemon lain masih hidup.
echo Jendela ini tetap terbuka untuk pemeriksaan.
exit /b %DAEMON_EXIT%

:BAD_BUNDLE
echo.
echo [ERROR] Folder PHP atau proyek eduXid belum lengkap.
echo PHP: "%PHP_EXE%"
echo Project: "%PROJ%"
echo Pastikan file ini ada di eduXid\resources\gtk-bundle.
exit /b 1

:PHP_DENIED
echo [ERROR] PHP daemon ditolak Windows. Uji D:\php\php.exe -n -v.
exit /b 5

:PHP_CONFIG_FAIL
echo [ERROR] PHP alternatif berjalan tetapi tidak kompatibel dengan php.ini/ext instalasi.
echo [INFO] Periksa PHP dan ekstensi tanpa menonaktifkan verifikasi keamanan.
exit /b 6
