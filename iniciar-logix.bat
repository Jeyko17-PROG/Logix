@echo off
REM ============================================
REM   Logix ERP - Iniciar todos los servidores
REM   Doble clic para arrancar MySQL, backend y frontend.
REM ============================================
title Logix ERP - Lanzador

echo Iniciando Logix ERP...
echo.

REM --- 1) MySQL (si Laragon ya lo tiene encendido, esta ventana mostrara un error y puedes cerrarla) ---
start "Logix - MySQL" "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe" --datadir="C:\laragon\data\mysql-8.4" --port=3306

REM Espera unos segundos a que MySQL levante
timeout /t 4 /nobreak >nul

REM --- 2) Backend (Laravel) en http://localhost:8000 ---
start "Logix - Backend (Laravel)" cmd /k "cd /d C:\laragon\www\Logix.MD\backend && php artisan serve"

REM --- 3) Frontend (React/Vite) en http://localhost:5173 ---
start "Logix - Frontend (Vite)" cmd /k "cd /d C:\laragon\www\Logix.MD\frontend && npm run dev"

REM Espera y abre el navegador
timeout /t 5 /nobreak >nul
start "" "http://localhost:5173"

echo.
echo Listo. Se abrieron 3 ventanas (MySQL, Backend, Frontend) y el navegador.
echo Para APAGAR Logix, simplemente cierra esas ventanas.
echo.
pause
