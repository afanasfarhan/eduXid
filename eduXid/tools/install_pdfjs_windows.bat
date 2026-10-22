@echo off
setlocal EnableExtensions DisableDelayedExpansion
set "ROOT=%~dp0.."
if not exist "%ROOT%\artisan" (
    echo [ERROR] Folder Laravel eduXid tidak ditemukan.
    exit /b 2
)
where curl.exe >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Windows curl.exe tidak tersedia; PDF.js tidak diubah.
    exit /b 3
)
set "DEST=%ROOT%\public\vendor\pdfjs"
if not exist "%DEST%" mkdir "%DEST%" >nul 2>&1
if not exist "%DEST%" exit /b 3
set "BASE=https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174"
echo [eduXid LJK] Mengunduh PDF.js 3.11.174 via curl.exe.
for %%F in (pdf.min.js pdf.worker.min.js) do (
    curl.exe --fail --location --silent --show-error --retry 2 --connect-timeout 20 --max-time 120 "%BASE%/%%F" --output "%DEST%\%%F.tmp"
    if errorlevel 1 goto :ERROR
    if not exist "%DEST%\%%F.tmp" goto :ERROR
    for %%S in ("%DEST%\%%F.tmp") do if %%~zS LSS 10000 goto :ERROR
    move /Y "%DEST%\%%F.tmp" "%DEST%\%%F" >nul
    if errorlevel 1 goto :ERROR
)
echo [OK] PDF.js tersedia untuk penampil PDF offline.
exit /b 0
:ERROR
del /Q "%DEST%\*.tmp" >nul 2>&1
echo [GAGAL] PDF.js belum lengkap. File lama dipertahankan jika masih ada.
exit /b 1
