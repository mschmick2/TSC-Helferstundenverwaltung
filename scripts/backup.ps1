# =============================================================================
# VAES Backup Script
# Sichert E:\TSC-Helferstundenverwaltung auf Y:\software_mondial\TSC-Helferstundenverwaltung
# =============================================================================

param(
    [switch]$DailySnapshot = $false
)

# Konfiguration
$SourcePath = "E:\TSC-Helferstundenverwaltung"
$BackupBasePath = "Y:\Software_Mondial\TSC-Helferstundenverwaltung"
$CurrentBackupPath = "$BackupBasePath\current"
$DailyBackupPath = "$BackupBasePath\daily"
$LogPath = "$BackupBasePath\logs"
$RetentionDays = 7

# Timestamp
$Timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
$DateOnly = Get-Date -Format "yyyy-MM-dd"
$LogFile = "$LogPath\backup_$DateOnly.log"

# Funktion: Log schreiben
function Write-Log {
    param([string]$Message)
    $LogEntry = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - $Message"
    Write-Host $LogEntry
    Add-Content -Path $LogFile -Value $LogEntry -ErrorAction SilentlyContinue
}

# Funktion: Verzeichnisse erstellen falls nicht vorhanden
function Ensure-Directory {
    param([string]$Path)
    if (!(Test-Path $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
        Write-Log "Verzeichnis erstellt: $Path"
    }
}

# =============================================================================
# Hauptprogramm
# =============================================================================

Write-Log "=========================================="
Write-Log "VAES Backup gestartet"
Write-Log "=========================================="

# Prüfen ob Quellverzeichnis existiert
if (!(Test-Path $SourcePath)) {
    Write-Log "FEHLER: Quellverzeichnis nicht gefunden: $SourcePath"
    exit 1
}

# Prüfen ob Netzlaufwerk erreichbar ist
if (!(Test-Path $BackupBasePath)) {
    Write-Log "FEHLER: Backup-Ziel nicht erreichbar: $BackupBasePath"
    Write-Log "Bitte pruefen Sie, ob Laufwerk Y: verbunden ist."
    Write-Log "Verbinden mit: net use Y: \\NAS-DS918_01\software_mondial /persistent:yes"
    exit 1
}

# Verzeichnisse sicherstellen
Ensure-Directory $LogPath
Ensure-Directory $CurrentBackupPath
Ensure-Directory $DailyBackupPath

# =============================================================================
# 1. Stündliches Backup (Spiegelung nach /current)
# =============================================================================

Write-Log "Starte Spiegelung nach $CurrentBackupPath ..."

try {
    # Robocopy für effiziente Spiegelung
    # /MIR = Mirror (inkl. Löschen nicht mehr vorhandener Dateien)
    # /XD = Exclude Directories (.git, node_modules, vendor werden trotzdem kopiert aber Logs ausgeschlossen)
    # /XF = Exclude Files
    # /R:3 = 3 Wiederholungsversuche
    # /W:5 = 5 Sekunden warten zwischen Versuchen
    # /NP = Keine Fortschrittsanzeige (für Logs)
    # /NDL = Keine Verzeichnisliste
    # /NFL = Keine Dateiliste (nur Zusammenfassung)
    
    $RobocopyArgs = @(
        $SourcePath,
        $CurrentBackupPath,
        "/MIR",
        "/XD", "$SourcePath\.git", "$SourcePath\node_modules",
        "/XF", "*.log", "*.tmp",
        "/R:3",
        "/W:5",
        "/NP",
        "/NDL"
    )
    
    $Result = & robocopy @RobocopyArgs
    
    # Robocopy Exit-Codes: 0-7 = Erfolg, 8+ = Fehler
    if ($LASTEXITCODE -lt 8) {
        Write-Log "Spiegelung erfolgreich abgeschlossen."
    } else {
        Write-Log "WARNUNG: Robocopy beendete mit Exit-Code $LASTEXITCODE"
    }
}
catch {
    Write-Log "FEHLER bei Spiegelung: $_"
}

# =============================================================================
# 2. Täglicher Snapshot (einmal pro Tag)
# =============================================================================

$TodaySnapshotPath = "$DailyBackupPath\$DateOnly"

# Prüfen ob heute bereits ein Snapshot existiert
if (!(Test-Path $TodaySnapshotPath)) {
    Write-Log "Erstelle täglichen Snapshot: $TodaySnapshotPath ..."
    
    try {
        # Kopiere current nach daily/YYYY-MM-DD
        $RobocopyArgs = @(
            $CurrentBackupPath,
            $TodaySnapshotPath,
            "/MIR",
            "/R:3",
            "/W:5",
            "/NP",
            "/NDL"
        )
        
        & robocopy @RobocopyArgs | Out-Null
        
        if ($LASTEXITCODE -lt 8) {
            Write-Log "Täglicher Snapshot erfolgreich erstellt."
        }
    }
    catch {
        Write-Log "FEHLER bei täglichem Snapshot: $_"
    }
}

# =============================================================================
# 3. Alte Snapshots aufräumen (älter als 7 Tage)
# =============================================================================

Write-Log "Prüfe alte Snapshots..."

try {
    $Cutoff = (Get-Date).AddDays(-$RetentionDays)
    $OldSnapshots = Get-ChildItem -Path $DailyBackupPath -Directory | Where-Object {
        # Parse Verzeichnisname als Datum
        $DirDate = $null
        if ([DateTime]::TryParseExact($_.Name, "yyyy-MM-dd", $null, [System.Globalization.DateTimeStyles]::None, [ref]$DirDate)) {
            return $DirDate -lt $Cutoff
        }
        return $false
    }
    
    foreach ($Snapshot in $OldSnapshots) {
        Write-Log "Lösche alten Snapshot: $($Snapshot.Name)"
        Remove-Item -Path $Snapshot.FullName -Recurse -Force
    }
    
    if ($OldSnapshots.Count -eq 0) {
        Write-Log "Keine alten Snapshots zum Löschen gefunden."
    } else {
        Write-Log "$($OldSnapshots.Count) alte Snapshot(s) gelöscht."
    }
}
catch {
    Write-Log "FEHLER beim Aufräumen: $_"
}

# =============================================================================
# Zusammenfassung
# =============================================================================

Write-Log "=========================================="
Write-Log "Backup abgeschlossen"
Write-Log "=========================================="

# Statistik
$CurrentSize = (Get-ChildItem -Path $CurrentBackupPath -Recurse -ErrorAction SilentlyContinue | Measure-Object -Property Length -Sum).Sum / 1MB
$SnapshotCount = (Get-ChildItem -Path $DailyBackupPath -Directory -ErrorAction SilentlyContinue).Count

Write-Log "Aktuelle Sicherung: $([math]::Round($CurrentSize, 2)) MB"
Write-Log "Vorhandene Snapshots: $SnapshotCount von max. $RetentionDays"
Write-Log ""

exit 0
