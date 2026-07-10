@echo off
if not exist "C:\Xampp\htdocs\WarehouseIssuance\storage\logs" mkdir "C:\Xampp\htdocs\WarehouseIssuance\storage\logs"
"C:\Xampp\php\php.exe" "C:\Xampp\htdocs\WarehouseIssuance\tools\sync_picker_grpo_cache.php" 30 >> "C:\Xampp\htdocs\WarehouseIssuance\storage\logs\picker_grpo_cache_sync.log" 2>&1
