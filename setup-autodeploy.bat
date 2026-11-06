@echo off
rem First-time setup of AutoDeploy on this PC: dependencies, .env, SQLite database,
rem admin user (from AUTODEPLOY_ADMIN_EMAIL / _PASSWORD in .env), interface build. Safe to run again.
title AutoDeploy setup
cd /d "%~dp0"

set /p PHPV=<.php-version
set "PHP=C:\xampp\php_%PHPV%\php.exe"
if not exist "%PHP%" set "PHP=php"
set "COMPOSER=C:\xampp\php\composer.phar"

echo [1/5] Composer packages
"%PHP%" "%COMPOSER%" install --no-interaction || goto :error

echo [2/5] .env and application key
if not exist .env copy .env.example .env >nul
findstr /r /c:"^APP_KEY=base64" .env >nul || "%PHP%" artisan key:generate --force || goto :error

echo [3/5] Database (SQLite) and admin user
if not exist database\database.sqlite type nul > database\database.sqlite
"%PHP%" artisan migrate --force || goto :error
"%PHP%" artisan db:seed --force || goto :error

echo [4/5] npm packages
call npm install || goto :error

echo [5/5] Interface build
call npm run build || goto :error

echo.
echo Done. Start AutoDeploy with start-autodeploy.bat
pause
exit /b 0

:error
echo.
echo Setup stopped because of the error above.
pause
exit /b 1
