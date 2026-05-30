@echo off
title PWA Nailul Muna - Akses via Ngrok
echo.
echo Pastikan XAMPP Apache RUNNING dan ngrok sudah dijalankan:  ngrok http 80
echo.
echo Di HP, buka URL ngrok ANDA dengan path aplikasi, contoh:
echo   https://SUBDOMAIN.ngrok-free.dev/pwa_nailulmuna/login.php
echo.
echo Root ngrok (tanpa path) juga boleh — akan dialihkan ke beranda.
echo.
start "" "http://localhost/pwa_nailulmuna/cek-server.php"
echo.
echo Salin URL ngrok, lalu buka di HP salah satu:
echo   https://SUBDOMAIN.ngrok-free.dev/pwa_nailulmuna/ping.php
echo   https://SUBDOMAIN.ngrok-free.dev/pwa_nailulmuna/login.php
echo.
echo Jika halaman "Server aplikasi aktif" muncul, ngrok sudah benar.
pause
