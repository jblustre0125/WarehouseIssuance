$ErrorActionPreference = 'Stop'

$TaskName = 'WarehouseIssuance - Picker GRPO Cache Sync'
$PhpPath = 'C:\Xampp\php\php.exe'
$ScriptPath = 'C:\Xampp\htdocs\WarehouseIssuance\tools\sync_picker_grpo_cache.php'
$LogDirectory = 'C:\Xampp\htdocs\WarehouseIssuance\storage\logs'
$LogPath = Join-Path $LogDirectory 'picker_grpo_cache_sync.log'

if (-not (Test-Path $PhpPath)) {
    throw "PHP executable was not found: $PhpPath"
}

if (-not (Test-Path $ScriptPath)) {
    throw "Synchronization script was not found: $ScriptPath"
}

if (-not (Test-Path $LogDirectory)) {
    New-Item -Path $LogDirectory -ItemType Directory -Force | Out-Null
}

$TaskCommand = 'cmd.exe /c ""' + $PhpPath + '" "' + $ScriptPath + '" 14 >> "' + $LogPath + '" 2>&1"'

# Remove the old task if it already exists.
& schtasks.exe /Delete /TN $TaskName /F 2>$null | Out-Null

# Create a task that repeats every 5 minutes and runs as SYSTEM.
& schtasks.exe /Create /TN $TaskName /TR $TaskCommand /SC MINUTE /MO 5 /RU SYSTEM /RL HIGHEST /F

if ($LASTEXITCODE -ne 0) {
    throw "Unable to create scheduled task. schtasks.exe returned exit code $LASTEXITCODE."
}

# Start it immediately for testing.
& schtasks.exe /Run /TN $TaskName

if ($LASTEXITCODE -ne 0) {
    Write-Warning 'The task was created, but it could not be started immediately.'
}

Write-Host ''
Write-Host "Installed scheduled task: $TaskName" -ForegroundColor Green
Write-Host 'Schedule: Every 5 minutes'
Write-Host "PHP: $PhpPath"
Write-Host "Script: $ScriptPath"
Write-Host "Log: $LogPath"
Write-Host ''
Write-Host 'Verify with:'
Write-Host ('schtasks.exe /Query /TN "' + $TaskName + '" /V /FO LIST')
