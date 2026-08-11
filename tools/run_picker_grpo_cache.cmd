@echo off
set "APP_DIR=C:\Xampp\htdocs\WarehouseIssuance"
set "PHP_EXE=C:\Xampp\php\php.exe"
set "SCRIPT=%APP_DIR%\tools\sync_picker_grpo_cache.php"
set "LOG_DIR=%APP_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\picker_grpo_cache_sync.log"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

>> "%LOG_FILE%" echo [%date% %time%] Task wrapper starting.

cd /d "%APP_DIR%"
"%PHP_EXE%" "%SCRIPT%" 7 >> "%LOG_FILE%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"

>> "%LOG_FILE%" echo [%date% %time%] Task wrapper finished with exit code %EXIT_CODE%.
exit /b %EXIT_CODE%
