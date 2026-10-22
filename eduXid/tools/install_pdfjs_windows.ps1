$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
if (!(Test-Path (Join-Path $root 'artisan'))) { throw 'Root eduXid tidak ditemukan. Pastikan tools berada di dalam root proyek yang berisi artisan.' }
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}
$dest = Join-Path $root 'public\vendor\pdfjs'
New-Item -ItemType Directory -Force -Path $dest | Out-Null

$version = '3.11.174'
$base = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/$version"
$files = @('pdf.min.js', 'pdf.worker.min.js')

Write-Host "[eduXid LJK] Memasang PDF.js $version untuk impor PDF offline..."
foreach ($name in $files) {
    $url = "$base/$name"
    $out = Join-Path $dest $name
    Write-Host "  Mengunduh $name"
    Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $out
    if (!(Test-Path $out) -or (Get-Item $out).Length -lt 10000) {
        throw "Unduhan $name tidak valid."
    }
}

Write-Host "[OK] PDF.js tersimpan di public\vendor\pdfjs."
Write-Host "     Impor PDF LJK dapat digunakan secara offline setelah ini."
