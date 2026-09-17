@echo off
setlocal
title PWA Nailul Muna - Salin kredensial Google SA (produksi)
echo.
echo ============================================================
echo  Salin JSON Service Account ke config/ (TIDAK ikut GitHub)
echo ============================================================
echo.

set "SRC=%USERPROFILE%\Downloads\pwa-nailul-muna-keuangan-2-01c91dbb7fe0.json"
set "DEST=%~dp0config\google_service_account.json"

if not exist "%SRC%" (
    echo [ERROR] File sumber tidak ditemukan:
    echo   %SRC%
    echo Unduh ulang key dari Google Cloud Console jika perlu.
    pause
    exit /b 1
)

copy /Y "%SRC%" "%DEST%" >nul
if errorlevel 1 (
    echo [GAGAL] Tidak bisa menyalin ke %DEST%
    pause
    exit /b 1
)

echo [OK] Lokal: %DEST%
echo.
echo --- Langkah hosting live (FTP / File Manager) ---
echo 1. Upload file yang sama ke folder config/ di server
echo    (sejajar dengan database.local.php), rename:
echo    google_service_account.json
echo 2. Jangan commit file ini ke GitHub ^(sudah di .gitignore^).
echo 3. Deploy config/.htaccess dari repo ^(blokir akses HTTP ke JSON^).
echo 4. Di server jalankan:
echo    php scripts/laporan_snapshot_bootstrap.php apply
echo    php scripts/laporan_snapshot_bootstrap.php push
echo    ^(atau tes dari Pengaturan - Snapshot Laporan Google Sheet^)
echo.
pause
