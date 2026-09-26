@echo off
cd /d "%~dp0"
echo Los Magueyes - http://127.0.0.1:8000
"C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe" artisan serve --host=127.0.0.1 --port=8000 --no-reload
pause
