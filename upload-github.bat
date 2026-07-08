@echo off
title Upload ke GitHub - pwa_nailulmuna
cd /d "%~dp0"
set "PATH=C:\Program Files\Git\bin;%PATH%"

echo.
echo === Upload ke GitHub ===
echo Repo: https://github.com/calavy/pwa_nailulmuna
echo.

for /f "delims=" %%A in ('git config user.email 2^>nul') do set GIT_EMAIL=%%A
if "%GIT_EMAIL%"=="" (
    echo [PERINGATAN] Email Git belum di-set untuk repo ini.
    echo Jalankan dulu: setup-git-push.bat
    echo.
    pause
)

set /p MSG="Pesan commit (contoh: Update fitur keuangan): "
if "%MSG%"=="" (
    echo Pesan commit tidak boleh kosong.
    pause
    exit /b 1
)

git add .
git status -sb
echo.
git commit -m "%MSG%"
if errorlevel 1 (
    echo.
    echo Commit gagal atau tidak ada perubahan.
    pause
    exit /b 1
)

echo.
echo Mengambil update dari GitHub...
git pull origin main
if errorlevel 1 (
    echo Pull gagal. Selesaikan konflik lalu jalankan lagi.
    pause
    exit /b 1
)

echo.
echo Mengirim ke GitHub...
git push origin main
if errorlevel 1 (
    echo Push gagal. Periksa login GitHub.
    pause
    exit /b 1
)

echo.
echo BERHASIL. Cek: https://github.com/calavy/pwa_nailulmuna
pause
