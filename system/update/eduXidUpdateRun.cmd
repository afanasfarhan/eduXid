@echo off
setlocal EnableExtensions DisableDelayedExpansion
rem CMD-only background launcher. Updater BAT is authoritative, not legacy compiled EXE.
set "ROOT=%~dp0..\..\"
timeout /T 3 /NOBREAK >nul
if exist "%ROOT%system\update\eduXidUpdater.bat" goto :BAT
if exist "%ROOT%system\eduXidUpdater.exe" goto :EXE
exit /b 2
:BAT
call "%ROOT%system\update\eduXidUpdater.bat" --auto
exit /b %ERRORLEVEL%
:EXE
"%ROOT%system\eduXidUpdater.exe" --auto
exit /b %ERRORLEVEL%
