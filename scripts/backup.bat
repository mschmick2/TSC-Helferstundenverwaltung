@echo off
REM =============================================================================
REM VAES Backup - Wrapper für Windows Task Scheduler
REM =============================================================================

REM PowerShell-Script mit Admin-Rechten ausführen
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0backup.ps1"

exit /b %ERRORLEVEL%
