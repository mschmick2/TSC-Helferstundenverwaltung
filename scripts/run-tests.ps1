#Requires -Version 5.1
<#
.SYNOPSIS
    Einziger Eintrittspunkt fuer die VAES-Testumgebung.

.DESCRIPTION
    Orchestriert:
      - MailPit-Binary Download (gepinnt v1.29.7, SHA256-Verifikation via checksums.txt)
      - MailPit SMTP-Server (Port 1025) + Web-UI (8025)
      - PHP-Dev-Server (localhost:8000) aus src/public/
      - Playwright E2E-Tests (e2e-tests/)
      - UX-Analyzer (tools/ux-analyzer/) mit RTX-GPU

.PARAMETER Action
    all      (Default) Full-Run: Services -> E2E -> UX -> Stop
    services Startet nur Services und bleibt (Ctrl+C beendet)
    e2e      Nur Playwright (Services muessen laufen)
    ux       Nur UX-Analyse auf vorhandenen Screenshots
    stop     Background-Jobs stoppen
    verify   Nur Umgebungspruefung (Tools, GPU)

.PARAMETER SkipDownload
    MailPit-Download ueberspringen (Offline-Modus).

.PARAMETER Force
    Vorhandene Dev-Server-Prozesse ignorieren.

.EXAMPLE
    .\scripts\run-tests.ps1
    .\scripts\run-tests.ps1 -Action services
    .\scripts\run-tests.ps1 -Action stop

.NOTES
    Voraussetzungen:
      - Windows 10/11, PowerShell 5.1+
      - WAMP/XAMPP laeuft (MySQL)
      - Node 18+, Python 3.13, NVIDIA RTX-Treiber 550+
#>

[CmdletBinding()]
param(
    [ValidateSet('all', 'services', 'e2e', 'ux', 'stop', 'verify')]
    [string]$Action = 'all',

    [switch]$SkipDownload,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
$ProjectRoot   = Split-Path -Parent $PSScriptRoot
$StorageDir    = Join-Path $ProjectRoot 'storage'
$PidFile       = Join-Path $StorageDir 'run-tests.pids'
$MailPitDir    = Join-Path $ProjectRoot 'tools\mailpit'
$MailPitExe    = Join-Path $MailPitDir 'mailpit.exe'
$MailPitVersion = 'v1.29.7'
$MailPitUrl     = "https://github.com/axllent/mailpit/releases/download/$MailPitVersion/mailpit-windows-amd64.zip"
$MailPitChecksumsUrl = "https://github.com/axllent/mailpit/releases/download/$MailPitVersion/checksums.txt"
$MailPitZipName = 'mailpit-windows-amd64.zip'

New-Item -ItemType Directory -Force -Path $StorageDir | Out-Null

function Write-Step {
    param([string]$Text)
    Write-Host ""
    Write-Host "==> $Text" -ForegroundColor Cyan
}

function Write-Ok {
    param([string]$Text)
    Write-Host "  [OK] $Text" -ForegroundColor Green
}

function Write-Warn {
    param([string]$Text)
    Write-Host "  [WARN] $Text" -ForegroundColor Yellow
}

function Write-Err {
    param([string]$Text)
    Write-Host "  [ERR] $Text" -ForegroundColor Red
}

function Test-PortOpen {
    param([string]$Hostname = '127.0.0.1', [int]$Port)
    try {
        $tcp = New-Object System.Net.Sockets.TcpClient
        $async = $tcp.BeginConnect($Hostname, $Port, $null, $null)
        $wait = $async.AsyncWaitHandle.WaitOne(500)
        if (-not $wait) { $tcp.Close(); return $false }
        $tcp.EndConnect($async)
        $tcp.Close()
        return $true
    } catch { return $false }
}

function Wait-ForPort {
    param([int]$Port, [int]$TimeoutSec = 15)
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ((Get-Date) -lt $deadline) {
        if (Test-PortOpen -Port $Port) { return $true }
        Start-Sleep -Milliseconds 400
    }
    return $false
}

function Save-Pid {
    param([string]$Name, [int]$ProcessId)
    "$Name=$ProcessId" | Add-Content -Path $PidFile -Encoding UTF8
}

function Get-SavedPids {
    if (-not (Test-Path $PidFile)) { return @() }
    Get-Content $PidFile | Where-Object { $_ -match '^.+=\d+$' } | ForEach-Object {
        $parts = $_ -split '=', 2
        [pscustomobject]@{ Name = $parts[0]; ProcessId = [int]$parts[1] }
    }
}

function Stop-SavedPids {
    $entries = Get-SavedPids
    if (-not $entries) {
        Write-Warn "Keine gespeicherten PIDs gefunden."
        return
    }
    foreach ($p in $entries) {
        try {
            $proc = Get-Process -Id $p.ProcessId -ErrorAction SilentlyContinue
            if ($proc) {
                Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
                Write-Ok "$($p.Name) (PID $($p.ProcessId)) gestoppt."
            } else {
                Write-Warn "$($p.Name) (PID $($p.ProcessId)) bereits weg."
            }
        } catch {
            Write-Warn "$($p.Name) stoppen fehlgeschlagen: $_"
        }
    }
    Remove-Item $PidFile -ErrorAction SilentlyContinue
}

# ---------------------------------------------------------------------------
# Umgebungspruefungen
# ---------------------------------------------------------------------------
function Invoke-VerifyEnvironment {
    Write-Step "Umgebung pruefen"

    # PHP
    try {
        $phpVer = & php --version 2>&1 | Select-Object -First 1
        Write-Ok "PHP: $phpVer"
    } catch {
        Write-Err "PHP nicht im PATH"
        return $false
    }

    # Node
    try {
        $nodeVer = & node --version 2>&1
        Write-Ok "Node: $nodeVer"
    } catch {
        Write-Err "Node nicht im PATH"
        return $false
    }

    # MySQL
    $mysqlFound = $false
    foreach ($p in @(
        'mysql',
        'C:\wamp64\bin\mysql\mysql*\bin\mysql.exe',
        'C:\xampp\mysql\bin\mysql.exe'
    )) {
        $resolved = Get-Command $p -ErrorAction SilentlyContinue
        if (-not $resolved) {
            $glob = Get-Item $p -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($glob) { $resolved = $glob }
        }
        if ($resolved) {
            $path = if ($resolved.Source) { $resolved.Source } else { $resolved.FullName }
            Write-Ok "MySQL CLI: $path"
            $mysqlFound = $true
            break
        }
    }
    if (-not $mysqlFound) { Write-Warn "mysql.exe nicht gefunden - Import wird scheitern" }

    # MySQL-Port
    if (Test-PortOpen -Port 3306) {
        Write-Ok "MySQL Port 3306 erreichbar"
    } else {
        Write-Err "MySQL Port 3306 geschlossen - WAMP/XAMPP starten"
    }

    # Python-venv
    $pythonVenv = Join-Path $ProjectRoot 'tools\ux-analyzer\venv\Scripts\python.exe'
    if (Test-Path $pythonVenv) {
        Write-Ok "Python venv: $pythonVenv"
    } else {
        Write-Warn "Python venv fehlt - setup.bat in tools/ux-analyzer ausfuehren"
    }

    return $true
}

# ---------------------------------------------------------------------------
# MailPit-Download
# ---------------------------------------------------------------------------
function Install-MailPit {
    [CmdletBinding()]
    param()
    if (Test-Path $MailPitExe) {
        try {
            $existingVer = & $MailPitExe --version 2>&1 | Out-String
            if ($existingVer -match [regex]::Escape($MailPitVersion)) {
                Write-Ok "MailPit $MailPitVersion bereits vorhanden."
                return
            } else {
                Write-Warn "MailPit vorhanden, aber andere Version: $($existingVer.Trim())"
            }
        } catch {
            # fallthrough -> erneut herunterladen
        }
    }

    if ($SkipDownload) {
        Write-Warn "-SkipDownload aktiv: MailPit wird NICHT installiert."
        Write-Warn "Tests mit MailPit-Abhaengigkeit werden scheitern/geskippt."
        return
    }

    Write-Step "MailPit $MailPitVersion downloaden"
    $zipPath = Join-Path $MailPitDir $MailPitZipName
    $checksumsPath = Join-Path $MailPitDir 'checksums.txt'

    New-Item -ItemType Directory -Force -Path $MailPitDir | Out-Null

    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -Uri $MailPitUrl -OutFile $zipPath -UseBasicParsing
        Invoke-WebRequest -Uri $MailPitChecksumsUrl -OutFile $checksumsPath -UseBasicParsing
    } catch {
        Write-Err "Download fehlgeschlagen: $_"
        Write-Host ""
        Write-Warn "Moegliche Ursachen:"
        Write-Warn "  - MailPit-Version '$MailPitVersion' existiert nicht (mehr)"
        Write-Warn "  - Keine Internetverbindung / Proxy blockiert github.com"
        Write-Warn "  - GitHub-Release umbenannt"
        Write-Host ""
        Write-Warn "Loesungsschritte:"
        Write-Warn "  1. Aktuelle Version pruefen: https://github.com/axllent/mailpit/releases"
        Write-Warn "  2. In dieser Datei `$MailPitVersion anpassen (Zeile ~40)"
        Write-Warn "  3. Oder manuell herunterladen:"
        Write-Warn "       Asset:     $MailPitUrl"
        Write-Warn "       Checksums: $MailPitChecksumsUrl"
        Write-Warn "       Ziel:      $MailPitExe"
        Write-Warn "  4. Alternativ: .\scripts\run-tests.ps1 -SkipDownload"
        throw
    }

    # SHA256 verifizieren
    $expected = (Get-Content $checksumsPath | Where-Object { $_ -match [regex]::Escape($MailPitZipName) }) -split '\s+' | Select-Object -First 1
    if (-not $expected) {
        Write-Err "SHA256 fuer $MailPitZipName nicht in checksums.txt gefunden."
        throw "Checksum-Verifikation fehlgeschlagen."
    }
    $actual = (Get-FileHash -Path $zipPath -Algorithm SHA256).Hash.ToLower()
    if ($actual -ne $expected.ToLower()) {
        Write-Err "SHA256-Mismatch!"
        Write-Err "  erwartet: $expected"
        Write-Err "  gefunden: $actual"
        Remove-Item $zipPath -Force
        throw "Integritaetspruefung fehlgeschlagen."
    }
    Write-Ok "SHA256 verifiziert."

    Expand-Archive -Path $zipPath -DestinationPath $MailPitDir -Force
    Remove-Item $zipPath -Force
    Remove-Item $checksumsPath -Force
    if (Test-Path $MailPitExe) {
        Write-Ok "MailPit installiert: $MailPitExe"
    } else {
        throw "MailPit-Binary nicht gefunden nach Extraktion."
    }
}

# ---------------------------------------------------------------------------
# Services
# ---------------------------------------------------------------------------
function Start-Services {
    Install-MailPit

    # Dev-Server-Check
    if ((Test-PortOpen -Port 8000) -and -not $Force) {
        Write-Err "Port 8000 ist belegt. -Force fuer Override."
        exit 2
    }

    # MailPit starten - Bind explizit auf 127.0.0.1 (nicht 0.0.0.0!) -
    # verhindert LAN-Exposure waehrend Tests laufen.
    if (Test-Path $MailPitExe) {
        Write-Step "MailPit starten (SMTP 127.0.0.1:1025, UI 127.0.0.1:8025)"
        $logMpOut = Join-Path $StorageDir 'mailpit.out.log'
        $logMpErr = Join-Path $StorageDir 'mailpit.err.log'
        $mpProc = Start-Process -FilePath $MailPitExe `
            -ArgumentList '--listen', '127.0.0.1:8025', '--smtp', '127.0.0.1:1025' `
            -PassThru -WindowStyle Hidden `
            -RedirectStandardOutput $logMpOut -RedirectStandardError $logMpErr
        Save-Pid -Name 'mailpit' -ProcessId $mpProc.Id
        if (Wait-ForPort -Port 8025 -TimeoutSec 10) {
            Write-Ok "MailPit laeuft (PID $($mpProc.Id)) - UI http://127.0.0.1:8025/"
        } else {
            Write-Err "MailPit antwortet nicht auf 8025 (siehe $logMpErr)"
        }
    } else {
        Write-Warn "MailPit nicht installiert - E-Mail-Tests werden geskippt."
    }

    # PHP-Dev-Server
    Write-Step "PHP-Dev-Server starten (http://localhost:8000)"
    $publicDir = Join-Path $ProjectRoot 'src\public'
    $logPhpOut = Join-Path $StorageDir 'php-server.out.log'
    $logPhpErr = Join-Path $StorageDir 'php-server.err.log'
    $phpProc = Start-Process -FilePath 'php' `
        -ArgumentList '-S', '127.0.0.1:8000', '-t', $publicDir `
        -PassThru -WindowStyle Hidden `
        -RedirectStandardOutput $logPhpOut -RedirectStandardError $logPhpErr
    Save-Pid -Name 'php-server' -ProcessId $phpProc.Id
    if (Wait-ForPort -Port 8000 -TimeoutSec 10) {
        Write-Ok "PHP-Server laeuft (PID $($phpProc.Id))"
    } else {
        Write-Err "PHP-Server antwortet nicht (siehe $logPhpErr)"
    }
}

# ---------------------------------------------------------------------------
# E2E
# ---------------------------------------------------------------------------
function Invoke-E2E {
    Write-Step "Playwright E2E ausfuehren"
    $e2eDir = Join-Path $ProjectRoot 'e2e-tests'
    if (-not (Test-Path (Join-Path $e2eDir 'node_modules'))) {
        Write-Warn "node_modules fehlt - npm install ..."
        Push-Location $e2eDir
        try { & npm install --no-audit --no-fund }
        finally { Pop-Location }
    }
    Push-Location $e2eDir
    try {
        & npx playwright test
        $script:E2EExitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }
    if ($script:E2EExitCode -eq 0) {
        Write-Ok "E2E gruen."
    } else {
        Write-Warn "E2E Exit-Code $script:E2EExitCode"
    }
}

# ---------------------------------------------------------------------------
# UX
# ---------------------------------------------------------------------------
function Invoke-UX {
    Write-Step "UX-Analyzer ausfuehren"
    $uxDir = Join-Path $ProjectRoot 'tools\ux-analyzer'
    $venvPy = Join-Path $uxDir 'venv\Scripts\python.exe'
    if (-not (Test-Path $venvPy)) {
        Write-Err "Python venv fehlt. In $uxDir : setup.bat ausfuehren."
        return
    }
    $shots = Join-Path $ProjectRoot 'e2e-tests\screenshots'
    if (-not (Test-Path $shots) -or -not (Get-ChildItem $shots -Filter '*.png' -ErrorAction SilentlyContinue)) {
        Write-Warn "Keine Screenshots in $shots - UX-Analyse uebersprungen."
        return
    }
    $out = Join-Path $uxDir 'baseline_report.json'
    & $venvPy (Join-Path $uxDir 'ux_analyzer.py') $shots --batch -o $out --json-only
    if (Test-Path $out) {
        Write-Ok "UX-Report geschrieben: $out"
    }
}

# ---------------------------------------------------------------------------
# Action-Dispatch
# ---------------------------------------------------------------------------
switch ($Action) {
    'verify' {
        if (-not (Invoke-VerifyEnvironment)) { exit 1 }
        exit 0
    }
    'stop' {
        Write-Step "Background-Jobs stoppen"
        Stop-SavedPids
        exit 0
    }
    'services' {
        if (-not (Invoke-VerifyEnvironment)) { exit 1 }
        Start-Services
        Write-Host ""
        Write-Host "Services laufen. Zum Stoppen: .\scripts\run-tests.ps1 -Action stop" -ForegroundColor Green
        exit 0
    }
    'e2e' {
        Invoke-E2E
        exit $script:E2EExitCode
    }
    'ux' {
        Invoke-UX
        exit 0
    }
    'all' {
        if (-not (Invoke-VerifyEnvironment)) { exit 1 }
        Start-Services
        try {
            Invoke-E2E
            Invoke-UX
        } finally {
            Write-Step "Services stoppen (all-Modus)"
            Stop-SavedPids
        }
        $rc = if ($null -ne $script:E2EExitCode) { $script:E2EExitCode } else { 0 }
        exit $rc
    }
}
