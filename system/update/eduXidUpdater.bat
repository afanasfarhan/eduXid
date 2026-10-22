::[Bat To Exe Converter]
::
::YAwzoRdxOk+EWAnk
::fBw5plQjdG8=
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
::Zh4grVQjdCyDJFyA4Xo5LDZVXxCHLle/AqAk4ezO/+6GtkgPGucnfe8=
::YB416Ek+ZG8=
::
::
::978f952a14a936cc963da21a135fa983
@echo off
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul
title eduXid Updater

set "AUTO_MODE=0"
if /I "%~1"=="--auto" set "AUTO_MODE=1"

rem ============================================================
rem eduXidUpdater.bat
rem Implementasi internal berada di system\update. EXE manual tetap di root.
rem File ini dirancang untuk dikonversi menjadi eduXidUpdater.exe.
rem ============================================================

set "ROOT=%~dp0"
if not exist "%ROOT%eduXid.exe" for %%I in ("%ROOT%..") do if exist "%%~fI\eduXid.exe" set "ROOT=%%~fI\"
if not exist "%ROOT%eduXid.exe" for %%I in ("%ROOT%..\..") do if exist "%%~fI\eduXid.exe" set "ROOT=%%~fI\"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"

rem ============================================================
rem Jika sudah dikonversi menjadi EXE, sebagian converter menjalankan
rem batch internal dari folder TEMP. Urutan fallback root:
rem 1. EDUXID_INSTALL_ROOT dari launcher Laravel/eduXid.exe
rem 2. folder batch asli (%~dp0)
rem 3. current working directory jika berisi eduXid.exe
rem 4. lokasi ancestor process eduXidUpdater.exe
if defined EDUXID_INSTALL_ROOT set "ROOT=%EDUXID_INSTALL_ROOT%"

if not exist "%ROOT%\eduXid.exe" if exist "%CD%\eduXid.exe" set "ROOT=%CD%"
if not exist "%ROOT%\eduXid.exe" for %%I in ("%CD%\..") do if exist "%%~fI\eduXid.exe" set "ROOT=%%~fI"


if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"

if not exist "%ROOT%\eduXid.exe" (
    echo [ERROR] Root instalasi eduXid tidak dapat ditemukan. Pemasangan dibatalkan.
    exit /b 21
)
set "UPDATER_HOME=%ROOT%\.eduxid-updater"
set "STAGING_ROOT=%UPDATER_HOME%\staging"
set "BACKUP_ROOT=%UPDATER_HOME%\backup"
set "LOG_FILE=%UPDATER_HOME%\update.log"
set "LOCK_FILE=%UPDATER_HOME%\updater.lock"

if not exist "%UPDATER_HOME%" mkdir "%UPDATER_HOME%" >nul 2>&1
if not exist "%STAGING_ROOT%" mkdir "%STAGING_ROOT%" >nul 2>&1
if not exist "%BACKUP_ROOT%" mkdir "%BACKUP_ROOT%" >nul 2>&1

call :log "============================================================"
call :log "eduXidUpdater dimulai."
call :log "Install root: %ROOT%"

rem ============================================================
rem EXCLUSIVE LOCK: mkdir is atomic. Never steal a running updater's lock.
rem If a power outage leaves this directory behind, remove it manually
rem only after verifying no updater process is still running.
rem ============================================================
set "LOCK_DIR=%UPDATER_HOME%\updater.running"
set "LOCK_OWNED=0"
2>nul mkdir "%LOCK_DIR%"
if errorlevel 1 (
    call :log "Updater lain aktif atau lock basi: %LOCK_DIR%"
    goto :end_busy
)
set "LOCK_OWNED=1"
> "%LOCK_DIR%\started.txt" echo %DATE% %TIME%
call :log "Exclusive lock acquired."

rem ============================================================
rem DETEKSI LARAVEL
rem ============================================================
set "APP_DIR="

if exist "%ROOT%\eduXid\artisan" set "APP_DIR=%ROOT%\eduXid"
if not defined APP_DIR if exist "%ROOT%\artisan" set "APP_DIR=%ROOT%"

if not defined APP_DIR (
    echo.
    echo [ERROR] Folder aplikasi eduXid tidak ditemukan.
    echo Pastikan updater berada di root eduXidMaster.
    call :log "ERROR: artisan tidak ditemukan."
    goto :end_error
)

rem ============================================================
rem DETEKSI PHP BAWAAN
rem ============================================================
set "PHP_EXE="

for %%P in (
    "%ROOT%\runtime\php\php.exe"
    "%ROOT%\php\php.exe"
    "%ROOT%\bin\php\php.exe"
    "%APP_DIR%\runtime\php\php.exe"
    "%APP_DIR%\php\php.exe"
) do (
    if not defined PHP_EXE if exist "%%~fP" set "PHP_EXE=%%~fP"
)

if not defined PHP_EXE (
    for /f "delims=" %%P in ('where php.exe 2^>nul') do (
        if not defined PHP_EXE set "PHP_EXE=%%P"
    )
)

if not defined PHP_EXE (
    echo.
    echo [ERROR] Runtime PHP eduXid tidak ditemukan.
    call :log "ERROR: php.exe tidak ditemukan."
    goto :end_error
)

call :log "APP_DIR: %APP_DIR%"
call :log "PHP: %PHP_EXE%"
if not exist "%ROOT%\system\eduXidUpdateFiles.php" (
    call :log "ERROR: helper PHP eduXidUpdateFiles.php tidak ditemukan."
    goto :end_error
)

rem ============================================================
rem DETEKSI EDITION
rem 1. Baca EDUXID_EDITION dari .env
rem 2. Jika kosong, deteksi dari SQLite:
rem    ada akun admin = school, selain itu = teacher
rem 3. Simpan hasil ke .env agar permanen.
rem ============================================================
set "EDITION="

if exist "%APP_DIR%\.env" (
    for /f "usebackq tokens=1,* delims==" %%A in (`findstr /B /I /C:"EDUXID_EDITION=" "%APP_DIR%\.env" 2^>nul`) do (
        set "EDITION=%%B"
    )
)

if defined EDITION (
    set "EDITION=!EDITION:"=!"
    set "EDITION=!EDITION: =!"
)

if /I "!EDITION!"=="school" goto :edition_ok
if /I "!EDITION!"=="teacher" goto :edition_ok

set "EDITION_OUT=%UPDATER_HOME%\edition.txt"
if exist "!EDITION_OUT!" del /Q "!EDITION_OUT!" >nul 2>&1

"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 -r "$app=getenv('APP_DIR');$db=$app.DIRECTORY_SEPARATOR.'eduxid';if(!is_file($db)){$db=$app.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';}$edition='';try{if(is_file($db)){$pdo=new PDO('sqlite:'.$db);$n=(int)$pdo->query(\"SELECT COUNT(*) FROM users WHERE LOWER(role)='admin'\")->fetchColumn();$edition=$n>0?'school':'teacher';}}}catch(Throwable $e){$edition='';}echo $edition;" > "!EDITION_OUT!" 2>>"%LOG_FILE%"

if exist "!EDITION_OUT!" set /p EDITION=<"!EDITION_OUT!"
if exist "!EDITION_OUT!" del /Q "!EDITION_OUT!" >nul 2>&1

if /I "!EDITION!"=="school" goto :edition_persist
if /I "!EDITION!"=="teacher" goto :edition_persist

echo.
echo [ERROR] Jenis instalasi eduXid tidak dapat dideteksi.
echo Pastikan database SQLite dapat dibaca.
echo Atau tambahkan salah satu baris berikut ke:
echo %APP_DIR%\.env
echo.
echo EDUXID_EDITION=school
echo EDUXID_EDITION=teacher
call :log "ERROR: EDUXID_EDITION gagal dideteksi otomatis."
goto :end_error

:edition_persist
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 -r "$f=getenv('APP_DIR').DIRECTORY_SEPARATOR.'.env';$v=getenv('EDITION');$s=is_file($f)?file_get_contents($f):'';if(preg_match('/^EDUXID_EDITION=.*$/mi',$s)){$s=preg_replace('/^EDUXID_EDITION=.*$/mi','EDUXID_EDITION='.$v,$s);}else{$s=rtrim($s,\"\r\n\").PHP_EOL.'EDUXID_EDITION='.$v.PHP_EOL;}file_put_contents($f,$s);" >>"%LOG_FILE%" 2>&1

:edition_ok
call :log "Edition: !EDITION!"

rem ============================================================
rem CARI PATCH DI ROOT
rem Pemilihan versi tertinggi dilakukan PHP CLI.
rem ============================================================
set "PATCH_FILE="
set "PATCH_SELECT_OUT=%UPDATER_HOME%\selected-root-patch.txt"
set "SELECTOR_V21R154=%ROOT%\system\update\EDUXID_SELECT_PATCH_V21R154.php"
if exist "!PATCH_SELECT_OUT!" del /Q "!PATCH_SELECT_OUT!" >nul 2>&1
if not exist "!SELECTOR_V21R154!" (
    call :log "ERROR V21R154: helper seleksi ZIP tidak ada."
    goto :end_error
)
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "!SELECTOR_V21R154!" --root="%ROOT%" --edition="!EDITION!" > "!PATCH_SELECT_OUT!" 2>>"%LOG_FILE%"
set "SELECT_RC=!ERRORLEVEL!"
if "!SELECT_RC!"=="0" (
    if exist "!PATCH_SELECT_OUT!" set /p PATCH_FILE=<"!PATCH_SELECT_OUT!"
    if not defined PATCH_FILE (
        call :log "ERROR V21R154: selector status 0 tetapi path kosong."
        goto :end_error
    )
    if not exist "!PATCH_FILE!" (
        call :log "ERROR V21R154: file ZIP terpilih tidak ditemukan."
        goto :end_error
    )
    call :log "V21R154: file ZIP dipilih dan siap untuk verifikasi Ed25519."
) else (
    if "!SELECT_RC!"=="10" (
        call :log "V21R154: tidak ada ZIP RELEASE-V2 yang berlaku untuk edisi ini."
    ) else (
        call :log "ERROR V21R154: pemilih ZIP gagal. Exit=!SELECT_RC!"
        goto :end_error
    )
)
if exist "!PATCH_SELECT_OUT!" del /Q "!PATCH_SELECT_OUT!" >nul 2>&1
rem ============================================================
rem FALLBACK PEMILIHAN ZIP MELALUI CMD
rem ============================================================
if not defined PATCH_FILE if "%AUTO_MODE%"=="1" (
    call :log "Silent mode: tidak ada ZIP patch; tanpa Browse File."
    goto :end_ok
)
if not defined PATCH_FILE (
    echo.
    echo Tidak ada file patch pada root:
    echo %ROOT%
    echo.
    echo Pilih file ZIP patch dari Downloads, flashdisk, atau folder lain.
    call :log "Patch root tidak ditemukan; meminta lokasi ZIP di CMD."

    rem CMD-only: tempel lokasi ZIP, tidak ada prompt dalam --auto.
    set "PATCH_FILE="
    set /P "PATCH_FILE=Masukkan path lengkap ZIP patch (Enter untuk batal): "
    set "PATCH_FILE=!PATCH_FILE:"=!"
    if not defined PATCH_FILE goto :end_ok
)

if not exist "%PATCH_FILE%" (
    echo.
    echo [ERROR] File patch tidak ditemukan.
    call :log "ERROR: patch tidak ditemukan: %PATCH_FILE%"
    goto :end_error
)

call :log "Patch kandidat: %PATCH_FILE%"

rem ============================================================
rem INSPECT manifest.json DARI ZIP
rem Hanya bootstrap schema+version.
rem Validasi kriptografis dilakukan setelahnya oleh Laravel.
rem ============================================================
set "INSPECT_OUT=%UPDATER_HOME%\inspect-release.out"
if exist "%INSPECT_OUT%" del /Q "%INSPECT_OUT%" >nul 2>&1

"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "%ROOT%\system\eduXidUpdateFiles.php" inspect "%PATCH_FILE%" "%INSPECT_OUT%" >>"%LOG_FILE%" 2>&1

if errorlevel 1 (
    echo.
    echo [ERROR] ZIP bukan patch eduXid yang valid.
    echo Detail: %LOG_FILE%
    call :log "ERROR: Inspect manifest gagal."
    goto :end_error
)

set "PATCH_VERSION="
set /p PATCH_VERSION=<"%INSPECT_OUT%"
del /Q "%INSPECT_OUT%" >nul 2>&1

if not defined PATCH_VERSION (
    echo.
    echo [ERROR] Nomor versi patch tidak terbaca.
    call :log "ERROR: PATCH_VERSION kosong."
    goto :end_error
)

call :log "Versi release: %PATCH_VERSION%"

rem ============================================================
rem NORMALISASI LOKASI PATCH
rem Semua sumber (offline/LAN/cloud) akhirnya berada di ROOT.
rem ============================================================
set "CANONICAL_PATCH=%ROOT%\eduxid-patch-%PATCH_VERSION%.zip"

for %%A in ("%PATCH_FILE%") do set "PATCH_FULL=%%~fA"
for %%A in ("%CANONICAL_PATCH%") do set "CANON_FULL=%%~fA"

if /I not "%PATCH_FULL%"=="%CANON_FULL%" (
    echo Menyalin patch ke root eduXidMaster...
    copy /Y "%PATCH_FILE%" "%CANONICAL_PATCH%" >>"%LOG_FILE%" 2>&1
    if errorlevel 1 (
        echo.
        echo [ERROR] Patch tidak dapat disalin ke root eduXidMaster.
        call :log "ERROR: copy patch ke root gagal."
        goto :end_error
    )
)

set "PATCH_FILE=%CANONICAL_PATCH%"
call :log "Patch final: %PATCH_FILE%"

rem ============================================================
rem VERIFIKASI SHA-256 + Ed25519
rem Wajib disediakan modul Laravel:
rem   php artisan eduxid:update:verify
rem ============================================================
echo.
echo Memverifikasi patch...
call :log "Verifikasi kriptografis dimulai."

pushd "%APP_DIR%"
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan eduxid:update:verify --file="%PATCH_FILE%" --edition="!EDITION!" >>"%LOG_FILE%" 2>&1
set "VERIFY_RC=!ERRORLEVEL!"
popd

if "%VERIFY_RC%"=="20" (
    echo.
    echo eduXid sudah menggunakan versi ini atau versi yang lebih baru.
    call :log "Tidak ada update baru. Verify exit=20."
    goto :end_ok
)

if not "%VERIFY_RC%"=="0" (
    echo.
    echo [ERROR] Patch gagal diverifikasi dan TIDAK dipasang.
    echo Detail: %LOG_FILE%
    call :log "ERROR: verifikasi gagal. Exit=%VERIFY_RC%"
    goto :end_error
)

rem ============================================================
rem PREFLIGHT
rem exit 0  = aman
rem exit 10 = pending, misalnya CBT aktif
rem lainnya = gagal
rem ============================================================
echo Memeriksa kondisi sistem...
call :log "Preflight dimulai."

pushd "%APP_DIR%"
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan eduxid:update:preflight --file="%PATCH_FILE%" --edition="!EDITION!" >>"%LOG_FILE%" 2>&1
set "PREFLIGHT_RC=!ERRORLEVEL!"
popd

if "%PREFLIGHT_RC%"=="10" (
    >"%UPDATER_HOME%\pending.json" echo {"version":"%PATCH_VERSION%","file":"%PATCH_FILE%","status":"pending_safety"}
    echo.
    echo Update sudah diunduh tetapi belum dipasang karena eduXid sedang digunakan
    echo untuk proses yang tidak boleh dihentikan, misalnya CBT aktif.
    echo Patch akan tetap tersedia untuk pemasangan berikutnya.
    call :log "Update pending oleh preflight."
    goto :end_ok
)

if "%PREFLIGHT_RC%"=="20" (
    echo.
    echo eduXid sudah menggunakan versi yang sama atau lebih baru.
    call :log "Patch dilewati karena versi sudah terpasang."
    goto :end_ok
)

if not "%PREFLIGHT_RC%"=="0" (
    echo.
    echo [ERROR] Pemeriksaan sebelum update gagal.
    echo Detail: %LOG_FILE%
    call :log "ERROR: preflight gagal. Exit=%PREFLIGHT_RC%"
    goto :end_error
)

rem ============================================================
rem STAGING
rem ============================================================
set "STAGING=%STAGING_ROOT%\%PATCH_VERSION%"

if exist "%STAGING%" rmdir /S /Q "%STAGING%" >>"%LOG_FILE%" 2>&1
mkdir "%STAGING%" >nul 2>&1

echo Menyiapkan file update...
call :log "Extract ke staging: %STAGING%"

"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "%ROOT%\system\eduXidUpdateFiles.php" extract "%PATCH_FILE%" "%STAGING%" >>"%LOG_FILE%" 2>&1

if errorlevel 1 (
    echo.
    echo [ERROR] Patch gagal diekstrak ke staging.
    call :log "ERROR: extract staging gagal."
    goto :end_error
)

if not exist "%STAGING%\manifest.json" (
    call :log "ERROR: manifest.json hilang setelah extract."
    goto :end_error
)

if not exist "%STAGING%\payload\core" if not exist "%STAGING%\payload\!EDITION!" (
    echo.
    echo [ERROR] Patch tidak memiliki payload yang berlaku untuk edition !EDITION!.
    call :log "ERROR: payload core maupun !EDITION! tidak ditemukan."
    goto :end_error
)

if not exist "%STAGING%\payload\core" (
    call :log "INFO: payload core tidak ada. Hanya !EDITION! diterapkan."
)

if not exist "%STAGING%\payload\!EDITION!" (
    call :log "INFO: payload !EDITION! tidak ada. Hanya core diterapkan."
)

rem ============================================================
rem V21R156 - deterministic migration decision based on verified staging
rem AFTER eduxid:update:verify and BEFORE any production file is modified.
rem ============================================================
set "MIGRATION_MODE="
set "MIGRATION_POLICY_OUT=%UPDATER_HOME%\migration-policy-v21r156.txt"
if exist "!MIGRATION_POLICY_OUT!" del /Q "!MIGRATION_POLICY_OUT!" >nul 2>&1
if not exist "%ROOT%\system\update\EDUXID_MIGRATION_POLICY_V21R156.php" (
    call :log "ERROR V21R156: helper kebijakan migrasi hilang."
    goto :end_error
)
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "%ROOT%\system\update\EDUXID_MIGRATION_POLICY_V21R156.php" --staging="%STAGING%" --edition="!EDITION!" --version="%PATCH_VERSION%" > "!MIGRATION_POLICY_OUT!" 2>>"%LOG_FILE%"
set "MIGRATION_POLICY_RC=!ERRORLEVEL!"
if not "!MIGRATION_POLICY_RC!"=="0" (
    call :log "ERROR V21R156: manifest/staging tidak memenuhi kebijakan migrasi."
    goto :end_error
)
if exist "!MIGRATION_POLICY_OUT!" set /p MIGRATION_MODE=<"!MIGRATION_POLICY_OUT!"
if exist "!MIGRATION_POLICY_OUT!" del /Q "!MIGRATION_POLICY_OUT!" >nul 2>&1
if /I not "!MIGRATION_MODE!"=="SKIP_UI_ONLY_V21R150" if /I not "!MIGRATION_MODE!"=="RUN_NORMAL" (
    call :log "ERROR V21R156: hasil policy tidak valid."
    goto :end_error
)
call :log "V21R156 migration policy: !MIGRATION_MODE!"
rem ============================================================
rem BACKUP DIRECTORY
rem ============================================================
set "STAMP_OUT=%UPDATER_HOME%\stamp.txt"
if exist "!STAMP_OUT!" del /Q "!STAMP_OUT!" >nul 2>&1
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 -r "echo date('Ymd-His');" > "!STAMP_OUT!" 2>>"%LOG_FILE%"
set "STAMP="
if exist "!STAMP_OUT!" set /p STAMP=<"!STAMP_OUT!"
if exist "!STAMP_OUT!" del /Q "!STAMP_OUT!" >nul 2>&1
if not defined STAMP set "STAMP=%RANDOM%-%RANDOM%"

set "BACKUP_DIR=%BACKUP_ROOT%\%PATCH_VERSION%-!STAMP!"
mkdir "%BACKUP_DIR%\files" >nul 2>&1

if exist "%ROOT%\eduxid-version.json" (
    copy /Y "%ROOT%\eduxid-version.json" "%BACKUP_DIR%\eduxid-version.json" >>"%LOG_FILE%" 2>&1
)

call :log "Backup directory: %BACKUP_DIR%"

rem ============================================================
rem BACKUP FILE YANG AKAN DITIMPA
rem File terlindungi tidak boleh ada di payload.
rem ============================================================
echo Membuat backup otomatis...

"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "%ROOT%\system\eduXidUpdateFiles.php" backup "%STAGING%" "!EDITION!" "%APP_DIR%" "%BACKUP_DIR%" >>"%LOG_FILE%" 2>&1

if errorlevel 1 (
    echo.
    echo [ERROR] Backup file aplikasi gagal.
    call :log "ERROR: backup file aplikasi gagal."
    goto :end_error
)

rem ============================================================
rem MAINTENANCE + STOP eduXid
rem ============================================================
rem V21R157: tunggu bounded launcher exit, jangan mematikan proses.
rem process-check upstream tetap menjadi satu-satunya keputusan keamanan.
rem Maximum 8 checks, jeda 2 detik; CBT dan preflight tetap berlaku.
rem ============================================================
set "PROC_RC=10"
for /L %%N in (1,1,8) do (
    "%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "%ROOT%\system\eduXidUpdateFiles.php" process-check >>"%LOG_FILE%" 2>&1
    set "PROC_RC=!ERRORLEVEL!"
    if "!PROC_RC!"=="0" goto :process_ready_v21r157
    if not "!PROC_RC!"=="10" goto :process_error_v21r157
    if %%N LSS 8 (
        call :log "V21R157: launcher/eduXid/PHP masih aktif; menunggu normal, pemeriksaan %%N/8."
        "%PHP_EXE%" -n -r "usleep(2000000);" >nul 2>&1
    )
)
if "!PROC_RC!"=="10" (
    >"%UPDATER_HOME%\pending.json" echo {"version":"%PATCH_VERSION%","status":"pending_process","reason":"eduxid_or_php_active"}
    echo [UPDATE] Pemasangan ditunda: eduXid/PHP masih berjalan. Tutup secara normal dan coba pada startup berikutnya.
    call :log "V21R157: PENDING_PROCESS; tidak ada perubahan aplikasi/database, ZIP disimpan."
    goto :end_ok
)
:process_error_v21r157
call :log "ERROR V21R157: pemeriksaan proses gagal; tidak memasang rilis."
goto :end_error
:process_ready_v21r157
call :log "V21R157: semua proses terkait sudah berhenti; lanjut maintenance dan snapshot."
pushd "%APP_DIR%"
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan down --retry=10 >>"%LOG_FILE%" 2>&1
set "DOWN_RC=!ERRORLEVEL!"
popd
if not "%DOWN_RC%"=="0" (
    call :log "ERROR: gagal masuk maintenance mode; tidak mematikan aplikasi."
    goto :end_error
)

rem Proses eduXid.exe/php.exe yang masih aktif membuat updater menunda pemasangan.
rem Tidak ada penghentian paksa proses instalasi lain.
call :log "Pemeriksaan proses aman sebelumnya berhasil."


rem ============================================================
rem BACKUP DATABASE SETELAH APLIKASI DIHENTIKAN
rem ============================================================
rem SQLite VACUUM INTO produces a consistent snapshot in WAL mode.
rem Fail closed if PDO_SQLITE is unavailable or quick_check fails.
if not exist "%ROOT%\system\eduXidUpdateSnapshot.php" (
    call :log "ERROR: helper snapshot SQLite tidak tersedia; instalasi dihentikan."
    goto :rollback
)
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "%ROOT%\system\eduXidUpdateSnapshot.php" create "%APP_DIR%" "%BACKUP_DIR%" >>"%LOG_FILE%" 2>&1
if errorlevel 1 (
    call :log "ERROR: snapshot SQLite konsisten gagal dibuat; instalasi dibatalkan."
    goto :rollback
)
rem ============================================================
rem APPLY PAYLOAD core + edition
rem ============================================================
echo Memasang update...
call :log "Apply payload dimulai."

"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "%ROOT%\system\eduXidUpdateFiles.php" apply "%STAGING%" "!EDITION!" "%APP_DIR%" "%BACKUP_DIR%" >>"%LOG_FILE%" 2>&1

if errorlevel 1 (
    call :log "ERROR: apply payload gagal."
    goto :rollback
)

rem ============================================================
rem MIGRATION POLICY V21R156
rem Signature verification, preflight, snapshot SQLite, backup and rollback
rem remain mandatory. Only the EXACT V21R150 UI-only payload can skip
rem old pending migrations that are unrelated to this release.
rem ============================================================
if /I "!MIGRATION_MODE!"=="SKIP_UI_ONLY_V21R150" (
    call :log "V21R156: rilis UI-only bertanda tangan 21.150.0; migrasi lama tidak dipaksakan."
) else (
    if /I not "!MIGRATION_MODE!"=="RUN_NORMAL" (
        call :log "ERROR V21R156: kebijakan migrasi tidak dikenal."
        goto :rollback
    )
    pushd "%APP_DIR%"
    "%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan migrate --force >>"%LOG_FILE%" 2>&1
    set "MIGRATION_RC=!ERRORLEVEL!"
    popd
    if not "!MIGRATION_RC!"=="0" (
        call :log "ERROR: migration gagal."
        goto :rollback
    )
)

pushd "%APP_DIR%"
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan optimize:clear >>"%LOG_FILE%" 2>&1
if errorlevel 1 (
    popd
    call :log "ERROR: optimize clear gagal."
    goto :rollback
)
rem ============================================================
rem AKTIVASI VERSI
rem ============================================================
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan eduxid:update:activate --version="%PATCH_VERSION%" --source="updater" >>"%LOG_FILE%" 2>&1
if errorlevel 1 (
    popd
    call :log "ERROR: aktivasi versi gagal."
    goto :rollback
)

rem ============================================================
rem HEALTH CHECK
rem ============================================================
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan eduxid:update:health >>"%LOG_FILE%" 2>&1
if errorlevel 1 (
    popd
    call :log "ERROR: health check gagal."
    goto :rollback
)

rem Tulis manifest versi SEBELUM melaporkan sukses ke Cloud.
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "%ROOT%\system\eduXidUpdateFiles.php" version "%ROOT%" "%PATCH_VERSION%" "!EDITION!" >>"%LOG_FILE%" 2>&1
if errorlevel 1 (
    popd
    call :log "ERROR: gagal menyimpan versi rilis."
    goto :rollback
)
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan up >>"%LOG_FILE%" 2>&1
if errorlevel 1 (
    popd
    call :log "ERROR: gagal keluar maintenance mode."
    goto :rollback
)
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan eduxid:update:report --state=installed --version="%PATCH_VERSION%" >>"%LOG_FILE%" 2>&1
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan eduxid:update:cleanup-download --version="%PATCH_VERSION%" >>"%LOG_FILE%" 2>&1
popd

rem ============================================================
rem SUKSES
rem ============================================================
if exist "%UPDATER_HOME%\pending.json" del /Q "%UPDATER_HOME%\pending.json" >nul 2>&1

>"%UPDATER_HOME%\last-success.json" echo {"version":"%PATCH_VERSION%","edition":"!EDITION!","installed_at":"%DATE% %TIME%"}

echo.
echo ============================================================
echo  UPDATE BERHASIL
echo  eduXid versi %PATCH_VERSION%
echo ============================================================
echo.
call :log "UPDATE BERHASIL ke %PATCH_VERSION%."
rem Mode startup-only: jangan mendaftarkan Task Scheduler kembali.


goto :restart_ok

rem ============================================================
rem ROLLBACK
rem ============================================================
:rollback
echo.
echo Update gagal. Mengembalikan eduXid ke kondisi sebelumnya...
call :log "ROLLBACK dimulai."

"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "%ROOT%\system\eduXidUpdateFiles.php" rollback "%APP_DIR%" "%BACKUP_DIR%" >>"%LOG_FILE%" 2>&1
if errorlevel 1 (
    call :log "CRITICAL: rollback file gagal. Instalasi tetap maintenance."
    goto :rollback_unrecoverable
)

if exist "%BACKUP_DIR%\sqlite-snapshots.json" (
    "%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 "%ROOT%\system\eduXidUpdateSnapshot.php" restore "%APP_DIR%" "%BACKUP_DIR%" >>"%LOG_FILE%" 2>&1
    if errorlevel 1 (
        call :log "CRITICAL: SQLite rollback gagal! Instalasi tetap maintenance."
        goto :rollback_unrecoverable
    )
) else (
    call :log "INFO: tidak ada snapshot karena pemasangan belum mengubah database."
)

if exist "%BACKUP_DIR%\eduxid-version.json" (
    copy /Y "%BACKUP_DIR%\eduxid-version.json" "%ROOT%\eduxid-version.json" >>"%LOG_FILE%" 2>&1
)

pushd "%APP_DIR%"
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan optimize:clear >>"%LOG_FILE%" 2>&1
"%PHP_EXE%" -d opcache.enable_cli=0 -d opcache.file_cache= -d opcache.file_cache_only=0 artisan up >>"%LOG_FILE%" 2>&1
popd

>"%UPDATER_HOME%\last-failure.json" echo {"version":"%PATCH_VERSION%","failed_at":"%DATE% %TIME%","status":"rolled_back"}

call :log "ROLLBACK selesai."

echo.
echo ============================================================
echo  UPDATE GAGAL
echo  eduXid sudah dikembalikan ke versi sebelumnya.
echo ============================================================
echo Detail:
echo %LOG_FILE%
echo.

goto :restart_error

:rollback_unrecoverable
call :log "CRITICAL: Pemulihan tidak tuntas. Jangan restart otomatis; periksa file backup."
>"%UPDATER_HOME%\RECOVERY_REQUIRED.txt" echo Database belum berhasil dipulihkan. Jangan jalankan aplikasi sebelum perbaikan manual dari backup yang valid.
goto :end_error

rem ============================================================
rem RESTART
rem ============================================================
:restart_ok
set "UPDATER_RESTARTED=1"
if exist "%ROOT%\eduXid.exe" (
    call :log "Restart eduXid.exe."
    start "" "%ROOT%\eduXid.exe"
)
goto :end_ok

:restart_error
set "UPDATER_RESTARTED=1"
rem Hindari loop gagal: launcher yang dibuka sesudah rollback melewati check sekali.
set "EDUXID_SKIP_STARTUP_UPDATE=1"
if exist "%ROOT%\eduXid.exe" (
    call :log "Restart eduXid.exe setelah rollback."
    start "" "%ROOT%\eduXid.exe"
)
goto :end_error

rem ============================================================
rem LOGGING
rem ============================================================
:log
>>"%LOG_FILE%" echo [%DATE% %TIME%] %~1
exit /b 0

rem ============================================================
rem EXIT
rem ============================================================
:end_ok
rem Ketika pemasangan ditunda (CBT aktif), buka eduXid normal.
if "%AUTO_MODE%"=="1" if not "%UPDATER_RESTARTED%"=="1" if exist "%ROOT%\eduXid.exe" (
    set "EDUXID_SKIP_STARTUP_UPDATE=1"
    start "" "%ROOT%\eduXid.exe"
)
if "%LOCK_OWNED%"=="1" if exist "%LOCK_DIR%" rmdir /S /Q "%LOCK_DIR%" >nul 2>&1
endlocal
exit /b 0

:end_error
rem Jika pemeriksaan/pemasangan gagal sebelum sistem rusak, jangan tutup akses lokal.
rem Kegagalan pemulihan database tetap fail-closed (RECOVERY_REQUIRED.txt).
if "%AUTO_MODE%"=="1" if not "%UPDATER_RESTARTED%"=="1" if not exist "%UPDATER_HOME%\RECOVERY_REQUIRED.txt" if exist "%ROOT%\eduXid.exe" (
    set "EDUXID_SKIP_STARTUP_UPDATE=1"
    start "" "%ROOT%\eduXid.exe"
)
if "%LOCK_OWNED%"=="1" if exist "%LOCK_DIR%" rmdir /S /Q "%LOCK_DIR%" >nul 2>&1
if "%AUTO_MODE%"=="0" (
    echo.
    echo Tekan tombol apa saja untuk menutup updater.
    pause >nul
)
endlocal
exit /b 1

:end_busy
endlocal
exit /b 10
