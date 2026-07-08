@echo off
title Setup Git — siap push GitHub
cd /d "%~dp0"
set "PATH=C:\Program Files\Git\bin;%PATH%"

echo.
echo === Setup Git (hanya repo ini) ===
echo Repo : https://github.com/calavy/pwa_nailulmuna
echo.

git config user.name "calavy"
git config user.email "chahcalavy@gmail.com"

echo Identitas commit (lokal):
git config user.name
git config user.email
echo.

echo Remote:
git remote -v
echo.

echo File sensitif (TIDAK ikut push):
git check-ignore -v config/database.local.php config/app.local.php 2>nul
echo.

echo Perubahan belum di-commit:
git status -sb
echo.

echo Selesai setup.
echo.
echo Langkah berikutnya:
echo   1. Double-click  upload-github.bat
echo   2. Ketik pesan commit
echo   3. Login GitHub jika diminta (browser / token)
echo.
pause
