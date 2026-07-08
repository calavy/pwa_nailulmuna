@echo off
title Push GitHub — pwa_nailulmuna
cd /d "%~dp0"
set "PATH=C:\Program Files\Git\bin;C:\Program Files\Git\cmd;%PATH%"

echo.
echo === Push GitHub (semua langkah otomatis) ===
echo Folder: %CD%
echo Repo  : https://github.com/calavy/pwa_nailulmuna
echo.

if not exist ".git" (
    echo [ERROR] Bukan folder git. Jalankan file ini dari:
    echo   C:\xampp\htdocs\pwa_nailulmuna
    pause
    exit /b 1
)

git config user.name "calavy" 2>nul
git config user.email "chahcalavy@gmail.com" 2>nul

echo Identitas Git:
git config user.name
git config user.email
echo.

git status -sb
echo.

set /p MSG="Pesan commit (Enter = default): "
if "%MSG%"=="" set MSG=Impor DB lokal, riwayat hapus perbaikan kas, PKPPS, dan skrip Git.

git add .
git status -sb
echo.
git commit -m "%MSG%"
if errorlevel 1 (
    echo.
    echo Tidak ada commit baru ^(mungkin sudah commit^) — lanjut pull/push...
)

echo.
echo Mengambil update dari GitHub...
git pull origin main --no-rebase
if errorlevel 1 (
    echo [ERROR] Pull gagal. Selesaikan konflik lalu jalankan lagi.
    pause
    exit /b 1
)

echo.
echo Mengirim ke GitHub...
git push origin main
if errorlevel 1 (
    echo.
    echo [ERROR] Push gagal.
    echo - Pastikan sudah login GitHub ^(browser / Personal Access Token^)
    echo - Buat token: GitHub - Settings - Developer settings - Personal access tokens
    pause
    exit /b 1
)

echo.
echo BERHASIL ^^! Cek: https://github.com/calavy/pwa_nailulmuna
pause
