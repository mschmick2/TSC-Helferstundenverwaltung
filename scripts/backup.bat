@echo off
REM VAES Backup Script Starter
REM Startet das PowerShell Backup-Script

cd /d "%~dp0"
@echo off
"C:\Program Files\PowerShell\7\pwsh.exe" -ExecutionPolicy Bypass -File "%~dp0backup.ps1" %*
pause