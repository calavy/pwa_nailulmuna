@echo off
title PWA Nailul Muna - Preview Lokal
echo.
echo Pastikan XAMPP Apache sudah RUNNING (hijau).
echo.
start "" "http://localhost/pwa_nailulmuna/cek-server.php"
timeout /t 2 >nul
start "" "http://localhost/pwa_nailulmuna/login.php"
echo Browser dibuka. Tutup jendela ini jika sudah.
pause
