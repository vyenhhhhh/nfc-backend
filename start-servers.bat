   @echo off
   REM Starts 4 copies of the Laravel backend so the dashboards' requests are answered side by side.
   REM --host=0.0.0.0 lets phones on the same Wi-Fi reach the servers.
   REM The React .env needs:  VITE_API_PORTS=8000,8001,8002,8003
   REM Close the 4 windows to stop them. Run this file again after you change .env.
   cd /d "%~dp0"
   php artisan optimize:clear
   php artisan optimize
   start "API 8000" cmd /k php artisan serve --host=0.0.0.0 --port=8000
   start "API 8001" cmd /k php artisan serve --host=0.0.0.0 --port=8001
   start "API 8002" cmd /k php artisan serve --host=0.0.0.0 --port=8002
   start "API 8003" cmd /k php artisan serve --host=0.0.0.0 --port=8003