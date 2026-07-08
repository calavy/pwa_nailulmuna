@echo off
setlocal
title PWA Nailul Muna - Jadwalkan Cron WA Otomatis
echo.
echo ============================================================
echo  Jadwalkan cron WA otomatis (setiap 1 menit)
echo  Proyek: pwa_nailulmuna
echo ============================================================
echo.

set "PHP_EXE=C:\xampp\php\php.exe"
set "CRON_SCRIPT=C:\xampp\htdocs\pwa_nailulmuna\cron\wa_auto.php"
set "TASK_NAME=PWA_NailulMuna_WA_Auto"

if not exist "%PHP_EXE%" (
    echo [ERROR] PHP tidak ditemukan: %PHP_EXE%
    echo Sesuaikan path PHP_EXE di file ini jika XAMPP di folder lain.
    pause
    exit /b 1
)
if not exist "%CRON_SCRIPT%" (
    echo [ERROR] Skrip cron tidak ditemukan: %CRON_SCRIPT%
    pause
    exit /b 1
)

echo Membuat/mengganti task Windows Scheduler: %TASK_NAME%
echo Perintah: "%PHP_EXE%" "%CRON_SCRIPT%"
echo.

schtasks /Create /TN "%TASK_NAME%" /TR "\"%PHP_EXE%\" \"%CRON_SCRIPT%\"" /SC MINUTE /MO 1 /F
if errorlevel 1 (
    echo.
    echo [GAGAL] Tidak bisa membuat task. Jalankan sebagai Administrator.
    pause
    exit /b 1
)

echo.
echo [OK] Task terjadwal setiap 1 menit.
echo.
echo Cek status:
schtasks /Query /TN "%TASK_NAME%" /FO LIST /V | findstr /I "Status Next"
echo.
echo Uji manual sekarang:
"%PHP_EXE%" "%CRON_SCRIPT%"
echo.
echo Setelah jalan, cek Pengaturan -^> WA Otomatis -^> Ringkasan:
echo   "Terakhir jalan" harus terupdate setiap ~1 menit.
echo.
echo Hapus task (jika perlu): schtasks /Delete /TN "%TASK_NAME%" /F
echo.
pause
