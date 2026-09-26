@echo off
cd /d "%~dp0"
echo Agente de impresion - EC-PM-5890X para Cocina, Barra y Caja
"C:\laragon\bin\nodejs\node-v22\node.exe" agent\print-agent.mjs
pause
