@echo off
cd /d "%~dp0"
echo Se imprimira un ticket de prueba por area en EC-PM-5890X.
"C:\laragon\bin\nodejs\node-v22\node.exe" agent\print-agent.mjs --test
pause
