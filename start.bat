@echo off
cd /d "%~dp0"
echo Starting DragoraPanel (Laravel) on http://127.0.0.1:8050
start "DragoraPanel" cmd /k "php artisan serve --port 8050"
