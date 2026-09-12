@echo off
cd /d "C:\Xampp\htdocs\WarehouseIssuance"
"C:\Xampp\php\php.exe" "C:\Xampp\htdocs\WarehouseIssuance\tools\sync_scanplus_cache.php" 3 100 5000 >> "C:\Xampp\htdocs\WarehouseIssuance\storage\logs\scanplus_cache_sync.log" 2>&1
exit /b %errorlevel%