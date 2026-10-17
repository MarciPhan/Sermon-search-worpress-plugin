@echo off
setlocal
cd /d "%~dp0"
echo Stazeni ceskych titulku bez videa - pro Bible Search WordPress
echo Pro beh je treba Python 3.10+ a internet.
py -3 -m pip install --upgrade yt-dlp
if errorlevel 1 (
 echo Instalace yt-dlp selhala. Zkontrolujte Python.
 pause
 exit /b 1
)
set /p YTURL=Vlozte URL kanalu (/videos) nebo playlistu: 
if "%YTURL%"=="" exit /b 1
py -3 "%~dp0backfill.py" "%YTURL%" --out "%~dp0biblesearch-export"
echo.
echo ZIPy najdete ve slozce tools\biblesearch-export\zip-k-nahrani.
pause
