@echo off
setlocal EnableExtensions DisableDelayedExpansion
rem Bootstrap otomatis dipanggil oleh launcher eduXid dan GTK.
rem Aman dipanggil berkali-kali; tidak membutuhkan PowerShell.
set "ROOT=%~dp0"
rem Ketika berjalan sebagai skrip internal, root dua level di atas.
if not exist "%ROOT%eduXid\artisan" for %%R in ("%ROOT%..") do if exist "%%~fR\eduXid\artisan" set "ROOT=%%~fR\"
if not exist "%ROOT%eduXid\artisan" for %%R in ("%ROOT%..\..") do if exist "%%~fR\eduXid\artisan" set "ROOT=%%~fR\"
if defined EDUXID_INSTALL_ROOT if exist "%EDUXID_INSTALL_ROOT%\eduXid\artisan" set "ROOT=%EDUXID_INSTALL_ROOT%\"
rem Jika compiler BAT menggunakan direktori TEMP, CWD dari launcher menjadi cadangan.
if not exist "%ROOT%eduXid\artisan" if exist "%CD%\eduXid\artisan" set "ROOT=%CD%\"
if not exist "%ROOT%eduXid\artisan" for %%R in ("%CD%\..") do if exist "%%~fR\eduXid\artisan" set "ROOT=%%~fR\"
set "BOOTSTRAP=%ROOT%system\eduXidAutoUpdateBootstrap.php"
set "PHP="
for %%P in (
    "%ROOT%php\php.exe"
    "%ROOT%runtime\php\php.exe"
    "%ROOT%bin\php\php.exe"
    "%ROOT%eduXid\runtime\php\php.exe"
    "%ROOT%eduXid\php\php.exe"
) do if not defined PHP if exist "%%~fP" set "PHP=%%~fP"
if not defined PHP for %%P in (php.exe) do set "PHP=%%~$PATH:P"
if not exist "%BOOTSTRAP%" (
    echo [ERROR] eduXidAutoUpdateBootstrap.php tidak ditemukan.
    exit /b 2
)
if not defined PHP (
    echo [ERROR] PHP portable belum tersedia. Auto-Update akan dicoba pada pembukaan berikutnya.
    exit /b 3
)
"%PHP%" -n "%BOOTSTRAP%" --disable
exit /b %ERRORLEVEL%
