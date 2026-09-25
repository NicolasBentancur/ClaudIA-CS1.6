@echo off
rem Inicia el servicio de Claudia en Windows (dejar esta ventana abierta).
cd /d "%~dp0"
set PHP=php
if exist "..\.tools\php\php.exe" set PHP=..\.tools\php\php.exe
if not exist "vendor\autoload.php" (
    echo Faltan las dependencias. Ejecuta primero: composer install --no-dev
    pause
    exit /b 1
)
%PHP% bin\claudia.php start
pause
