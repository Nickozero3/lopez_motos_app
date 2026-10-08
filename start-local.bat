@echo off
setlocal
echo [1/3] Deteniendo contenedores...
docker compose down
if errorlevel 1 goto :error

echo [2/3] Construyendo la aplicacion...
docker compose build --no-cache
if errorlevel 1 goto :error

echo [3/3] Iniciando Lopez Motos...
docker compose up -d
if errorlevel 1 goto :error

echo.
echo Aplicacion:  http://localhost:8080
echo phpMyAdmin:  http://localhost:8081
echo.
docker compose ps
goto :eof

:error
echo.
echo ERROR. Revisa el mensaje anterior.
exit /b 1
