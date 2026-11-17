@echo off
rem Run the BPlus B18 sync now (same as the 12:00/15:00/18:00/00:00 tasks)
chcp 65001 >nul
"C:\Program Files\Python312\python.exe" "C:\BplusSync\bplus-auto-sync.py"
echo.
pause
