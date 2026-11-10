@echo off
schtasks /Query /TN "Voucher Tracker - Amount reader" >nul 2>&1
if not errorlevel 1 (
    schtasks /Run /TN "Voucher Tracker - Amount reader" >nul 2>&1
    exit /b
)
if "%~1"=="--run" goto run
start "Voucher amount reader" /min "%ComSpec%" /c ""%~f0" --run"
exit /b

:run
cd /d "%~dp0"
set "VOUCHER_WORKER_PHP=php"
if exist "C:\xampp\php\php.exe" set "VOUCHER_WORKER_PHP=C:\xampp\php\php.exe"

:work
"%VOUCHER_WORKER_PHP%" artisan queue:work database --queue=voucher-ocr --sleep=1 --tries=1 --timeout=60 --memory=256 --max-time=3600 --no-interaction >> "storage\logs\voucher-worker.log" 2>&1
timeout /t 5 /nobreak >nul
goto work
