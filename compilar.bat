@echo off
rem Compila los plugins de Claudia y arma build\cstrike con todo lo que va en el servidor de CS.
rem Usa el compilador de .tools\amxx o el de la variable AMXXPC (ruta a amxxpc.exe).
setlocal
cd /d "%~dp0"

if "%AMXXPC%"=="" set AMXXPC=.tools\amxx\addons\amxmodx\scripting\amxxpc.exe
if not exist "%AMXXPC%" (
    echo No encuentro amxxpc.exe. Descarga AMX Mod X 1.10 base o define AMXXPC.
    exit /b 1
)
for %%I in ("%AMXXPC%") do set AMXXINC=%%~dpIinclude

set OUT=build\cstrike
if exist "%OUT%" rmdir /s /q "%OUT%"
mkdir "%OUT%\addons\amxmodx\plugins" "%OUT%\addons\amxmodx\configs\claudia" "%OUT%\addons\amxmodx\data\lang" "%OUT%\addons\amxmodx\scripting\include" "%OUT%\sound\claudia"

set FAIL=0
for %%P in (claudia_core claudia_auth claudia_stats claudia_admin) do (
    echo == %%P
    "%AMXXPC%" "amxmodx\scripting\%%P.sma" -i"%AMXXINC%" -i"amxmodx\scripting\include" -o"%OUT%\addons\amxmodx\plugins\%%P.amxx" || set FAIL=1
)

copy /y amxmodx\configs\plugins-claudia.ini "%OUT%\addons\amxmodx\configs\" >nul
copy /y amxmodx\configs\claudia\*.* "%OUT%\addons\amxmodx\configs\claudia\" >nul
copy /y amxmodx\data\lang\claudia.txt "%OUT%\addons\amxmodx\data\lang\" >nul
copy /y amxmodx\scripting\*.sma "%OUT%\addons\amxmodx\scripting\" >nul
copy /y amxmodx\scripting\include\claudia.inc "%OUT%\addons\amxmodx\scripting\include\" >nul
copy /y sound\claudia\*.wav "%OUT%\sound\claudia\" >nul

if "%FAIL%"=="1" (
    echo Hubo errores de compilacion.
    exit /b 1
)
echo Listo: copia el contenido de %OUT% dentro de la carpeta cstrike del servidor.
