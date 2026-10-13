@echo off
chcp 65001 >nul
title Oftob_Sohtumon - Laravel
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
  echo PHP topilmadi / PHP не найден. O'rnating / Установите PHP 8.3+: https://windows.php.net/download
  pause
  exit /b
)
where composer >nul 2>nul
if errorlevel 1 (
  echo Composer topilmadi / Composer не найден: https://getcomposer.org/download/
  pause
  exit /b
)
if not exist vendor (
  echo Kutubxonalar o'rnatilmoqda / Устанавливаю библиотеки...
  call composer install
)
if not exist .env copy .env.example .env >nul
findstr /r /c:"^APP_KEY=base64" .env >nul || php artisan key:generate
if not exist database\database.sqlite type nul > database\database.sqlite
php artisan migrate --force
rem Kunlik hisobotlar uchun rejalashtiruvchi / Планировщик ежедневных отчётов
start "Oftob - hisobotlar / отчёты" /min php artisan schedule:work
start "" "http://localhost:8000"
echo.
echo Dastur ishlayapti / Программа работает: http://localhost:8000
echo Oynani yopmang / Не закрывайте это окно.
php artisan serve --port=8000
pause
