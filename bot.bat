@echo off
chcp 65001 >nul
title Oftob - Telegram-bot / hisobotlar
cd /d "%~dp0"
rem Open Server: PHP uning papkasidan (eng yangisi), bo'lmasa PATH dagi php / PHP из папки Open Server, иначе из PATH
set "PHP=php"
for %%v in (8.2 8.3 8.4) do if exist "%~dp0..\..\modules\PHP-%%v\php.exe" set "PHP=%~dp0..\..\modules\PHP-%%v\php.exe"
"%PHP%" -v >nul 2>nul
if errorlevel 1 (
  echo PHP topilmadi / PHP не найден
  pause
  exit /b
)
echo.
echo Telegram-bot xabarlarni oladi, kunlik hisobotlar yuboriladi. Oynani yopmang.
echo Бот Telegram получает сообщения, ежедневные отчёты уходят. Не закрывайте это окно.
echo.
"%PHP%" artisan schedule:work
pause
