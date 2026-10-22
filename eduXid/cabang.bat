@echo off
title Buat Daftar Struktur Folder (Tree)
set "NAMA_FILE=Daftar_Struktur_Folder.txt"

echo Sedang membuat daftar struktur folder...
echo Mohon tunggu sebentar, proses ini bisa memakan waktu jika foldernya sangat besar.
echo -----------------------------------------------

:: Perintah tree /f untuk menampilkan folder dan file, /a agar karakter cabang aman dibuka di Notepad
tree /f /a > "%NAMA_FILE%"

echo -----------------------------------------------
echo SELESAI!
echo File telah disimpan dengan nama: %NAMA_FILE%
echo Lokasi file: %CD%\%NAMA_FILE%
echo.
pause