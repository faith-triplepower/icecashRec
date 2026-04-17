@echo off
REM ============================================================
REM Database backup script for IceCashRec
REM Run nightly via Windows Task Scheduler:
REM   Action: Start a program
REM   Program: c:\xampp\htdocs\icecashRec\scripts\backup_db.bat
REM
REM Retains daily backups for 30 days.
REM Monthly archives (1st of each month) are kept indefinitely.
REM
REM RESTORE PROCEDURE:
REM   1. Stop Apache (XAMPP Control Panel)
REM   2. Open CMD: c:\xampp\mysql\bin\mysql -u root icecash_recon
REM   3. Run: SOURCE c:\xampp\htdocs\icecashRec\backups\icecash_YYYY-MM-DD.sql
REM   4. Restart Apache
REM ============================================================

set MYSQL_BIN=c:\xampp\mysql\bin
set DB_NAME=icecash_recon
set DB_USER=root
set DB_PASS=
set BACKUP_DIR=c:\xampp\htdocs\icecashRec\backups
set ARCHIVE_DIR=c:\xampp\htdocs\icecashRec\backups\monthly
set RETENTION_DAYS=30

REM Create directories if missing
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"
if not exist "%ARCHIVE_DIR%" mkdir "%ARCHIVE_DIR%"

REM Generate filename with date
for /f "tokens=1-3 delims=-" %%a in ('powershell -command "Get-Date -Format yyyy-MM-dd"') do set DATESTR=%%a-%%b-%%c
set FILENAME=icecash_%DATESTR%.sql

REM Run mysqldump
"%MYSQL_BIN%\mysqldump" -u %DB_USER% --single-transaction --routines --triggers %DB_NAME% > "%BACKUP_DIR%\%FILENAME%"

if %ERRORLEVEL% EQU 0 (
    echo [%date% %time%] Backup OK: %FILENAME% >> "%BACKUP_DIR%\backup.log"
) else (
    echo [%date% %time%] BACKUP FAILED >> "%BACKUP_DIR%\backup.log"
    exit /b 1
)

REM Monthly archive: copy 1st-of-month backups to archive folder
for /f %%d in ('powershell -command "(Get-Date).Day"') do set DOM=%%d
if "%DOM%"=="1" (
    copy "%BACKUP_DIR%\%FILENAME%" "%ARCHIVE_DIR%\%FILENAME%" >nul
    echo [%date% %time%] Monthly archive: %FILENAME% >> "%BACKUP_DIR%\backup.log"
)

REM Delete daily backups older than 30 days
forfiles /p "%BACKUP_DIR%" /m "icecash_*.sql" /d -%RETENTION_DAYS% /c "cmd /c del @path" 2>nul

echo Backup complete: %FILENAME%
