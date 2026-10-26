@echo off
REM Starts 4 copies of the Laravel backend so the dashboards' requests are answered side by side
REM (php artisan serve answers only one request at a time on Windows).
REM The React .env needs:  VITE_API_PORTS=8000,8001,8002,8003
REM Close the 4 windows to stop them.
cd /d "%~dp0"
php artisan config:clear
start "MYTRACK API 8000" cmd /k php artisan serve --port=8000
start "MYTRACK API 8001" cmd /k php artisan serve --port=8001
start "MYTRACK API 8002" cmd /k php artisan serve --port=8002
start "MYTRACK API 8003" cmd /k php artisan serve --port=8003