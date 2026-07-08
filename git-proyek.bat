@echo off
rem Jalankan git dari folder proyek ini (PATH + cwd otomatis)
cd /d "%~dp0"
set "PATH=C:\Program Files\Git\bin;C:\Program Files\Git\cmd;%PATH%"
git %*
