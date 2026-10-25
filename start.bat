@echo off
cd /d "%~dp0"
echo Starting FarmWise at http://127.0.0.1:8081
start http://127.0.0.1:8081
"C:\xampp\php\php.exe" -S 127.0.0.1:8081 router.php
