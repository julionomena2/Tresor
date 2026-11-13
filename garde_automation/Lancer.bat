@echo off
cd /d "%~dp0"
title Mon Application
setlocal

echo ========================================
echo   Demarrage de Mon Application
echo ========================================
echo.

REM === 1. Verifier Node.js ===
where node >nul 2>nul
if errorlevel 1 goto INSTALL_NODE
goto CHECK_DEPS

:INSTALL_NODE
echo [INFO] Node.js n'est pas installe. Installation automatique...
echo.

REM Telechargement du MSI officiel via PowerShell
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$url='https://nodejs.org/dist/v22.14.0/node-v22.14.0-x64.msi'; $out=\"$env:TEMP\node-installer.msi\"; Write-Host 'Telechargement...'; Invoke-WebRequest -Uri $url -OutFile $out -UseBasicParsing; Write-Host 'Installation silencieuse...'; Start-Process msiexec.exe -ArgumentList \"/i `\"$out`\" /qn /norestart\" -Wait; Remove-Item $out -Force -ErrorAction SilentlyContinue"

if errorlevel 1 (
    echo [ERREUR] L'installation de Node.js a echoue.
    echo Installez manuellement depuis https://nodejs.org
    pause
    exit /b 1
)

REM Rafraichir le PATH dans cette session
set "PATH=%PATH%;C:\Program Files\nodejs\"

where node >nul 2>nul
if errorlevel 1 (
    echo [ERREUR] Node.js installe mais non detecte. Redemarrez l'ordinateur.
    pause
    exit /b 1
)
echo [OK] Node.js installe.
echo.

:CHECK_DEPS
REM === 2. Verifier les dependances ===
if not exist "node_modules" (
    echo [INFO] Installation des dependances ^(premiere fois^)...
    call npm install
    if errorlevel 1 (
        echo [ERREUR] npm install a echoue.
        pause
        exit /b 1
    )
    echo.
)

REM === 3. Demarrer le serveur + ouvrir navigateur ===
echo [INFO] Demarrage du serveur...
start "" http://localhost:3000
node server.js

pause