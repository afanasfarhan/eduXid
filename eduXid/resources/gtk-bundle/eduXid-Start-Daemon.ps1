$ErrorActionPreference = 'Stop'

try {
    $daemonDirectory = [System.IO.Path]::GetFullPath($PSScriptRoot)
    $daemonScript = Join-Path -Path $daemonDirectory -ChildPath 'eduXid-Daemon.bat'
    if (-not (Test-Path -LiteralPath $daemonScript -PathType Leaf)) {
        throw ('File daemon tidak ditemukan: ' + $daemonScript)
    }

    $systemDirectory = [System.Environment]::SystemDirectory
    $cmd = Join-Path -Path $systemDirectory -ChildPath 'cmd.exe'
    if (-not (Test-Path -LiteralPath $cmd -PathType Leaf)) {
        $candidate = ([string]$env:ComSpec).Trim().Trim([char]34)
        if ([string]::IsNullOrWhiteSpace($candidate) -or -not (Test-Path -LiteralPath $candidate -PathType Leaf) -or [System.IO.Path]::GetFileName($candidate) -ine 'cmd.exe') {
            throw ('Command Prompt tidak ditemukan pada direktori sistem: ' + $systemDirectory)
        }
        $cmd = [System.IO.Path]::GetFullPath($candidate)
    }

    $env:ComSpec = $cmd
    Write-Host ('[BOOT] Command Prompt: ' + $cmd)
    $process = Start-Process -FilePath $cmd -WorkingDirectory $daemonDirectory -ArgumentList '/D /V:OFF /K eduXid-Daemon.bat' -WindowStyle Normal -PassThru -ErrorAction Stop
    Start-Sleep -Milliseconds 250
    $process.Refresh()
    if ($process.HasExited) {
        throw ('Command Prompt berhenti sebelum jendela daemon siap. Kode: ' + $process.ExitCode)
    }
    Write-Host ('[OK] Jendela Sync Daemon dibuka. PID: ' + $process.Id)
    Write-Host 'Periksa keluaran Laravel pada jendela daemon.'
    exit 0
} catch {
    [Console]::Error.WriteLine('[ERROR] Pembuka Sync Daemon gagal: ' + $_.Exception.Message)
    exit 1
}
