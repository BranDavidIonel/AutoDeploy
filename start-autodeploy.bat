@echo off
rem Double-click: starts AutoDeploy on http://127.0.0.1:8077 (web server + queue worker)
rem and opens it in the browser. Close this window to stop everything.
rem First time only: run setup-autodeploy.bat.
title AutoDeploy
cd /d "%~dp0"

rem PHP from XAMPP: C:\xampp\php_<version> for the version in .php-version,
rem otherwise the active XAMPP PHP in C:\xampp\php, otherwise php from the PATH.
set /p PHPV=<.php-version
set "PHP=C:\xampp\php_%PHPV%\php.exe"
if not exist "%PHP%" set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"

if not exist vendor\autoload.php (
    echo Dependencies are missing. Run setup-autodeploy.bat first.
    pause
    exit /b 1
)

if not exist public\build\manifest.json (
    echo Building the interface once ^(npm run build^)...
    call npm run build || (pause & exit /b 1)
)

rem The queue worker runs the deployments in the background (one hour max per deploy).
start "AutoDeploy queue worker" /min "%PHP%" artisan queue:work --timeout=3600 --tries=1 --sleep=1

start "" http://127.0.0.1:8077
echo AutoDeploy runs on http://127.0.0.1:8077  (login: AUTODEPLOY_ADMIN_EMAIL from .env)
echo Close this window to stop the web server; close "AutoDeploy queue worker" to stop deployments.
"%PHP%" artisan serve --host=127.0.0.1 --port=8077
