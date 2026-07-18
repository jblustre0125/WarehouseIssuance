param(
    [string]$ProjectRoot = 'C:\Xampp\htdocs\WarehouseIssuance',
    [string]$PhpExe = 'C:\Xampp\php\php.exe',
    [string]$TaskName = 'WarehouseIssuance - ScanPlus Cache Sync',
    [int]$LookbackDays = 7,
    [int]$RepeatMinutes = 10,
    [int]$BatchSize = 50,
    [int]$MaxReferences = 1000
)

$ErrorActionPreference = 'Stop'

$SyncScript = Join-Path $ProjectRoot 'tools\sync_scanplus_cache.php'
$ToolsDirectory = Join-Path $ProjectRoot 'tools'
$LogDirectory = Join-Path $ProjectRoot 'storage\logs'
$LogPath = Join-Path $LogDirectory 'scanplus_cache_sync.log'
$RunnerPath = Join-Path $ToolsDirectory 'run_scanplus_cache_sync.cmd'
$SchtasksPath = Join-Path $env:SystemRoot 'System32\schtasks.exe'

foreach ($RequiredPath in @($PhpExe, $SyncScript, $SchtasksPath)) {
    if (-not (Test-Path -LiteralPath $RequiredPath)) {
        throw "Required file was not found: $RequiredPath"
    }
}

if ($RepeatMinutes -lt 1) {
    throw 'RepeatMinutes must be at least 1.'
}

New-Item -Path $ToolsDirectory -ItemType Directory -Force | Out-Null
New-Item -Path $LogDirectory -ItemType Directory -Force | Out-Null

$RunnerContent = @"
@echo off
cd /d "$ProjectRoot"
"$PhpExe" "$SyncScript" $LookbackDays $BatchSize $MaxReferences >> "$LogPath" 2>&1
exit /b %errorlevel%
"@
Set-Content -LiteralPath $RunnerPath -Value $RunnerContent -Encoding ASCII

# /Create with /F already replaces an existing task. Do not call /Delete first,
# because schtasks returns "The system cannot find the file specified" when
# the task does not exist and PowerShell may treat that stderr output as fatal.
# Create or replace the scheduled task.
# Direct invocation preserves the task name as one argument.
& $SchtasksPath `
    /Create `
    /TN $TaskName `
    /TR $RunnerPath `
    /SC MINUTE `
    /MO ([string]$RepeatMinutes) `
    /RU SYSTEM `
    /RL HIGHEST `
    /F

if ($LASTEXITCODE -ne 0) {
    throw "Unable to create scheduled task. schtasks.exe exit code: $LASTEXITCODE"
}

# Run it immediately.
& $SchtasksPath /Run /TN $TaskName

if ($LASTEXITCODE -ne 0) {
    Write-Warning "Task was installed but could not start immediately. Exit code: $LASTEXITCODE"
}

Start-Sleep -Seconds 3

Write-Host ''
Write-Host "Installed task: $TaskName" -ForegroundColor Green
Write-Host "Schedule: every $RepeatMinutes minute(s)"
Write-Host "Lookback days: $LookbackDays"
Write-Host "Batch size: $BatchSize"
Write-Host "Maximum references per run: $MaxReferences"
Write-Host "Runner: $RunnerPath"
Write-Host "Log: $LogPath"
Write-Host ''
Write-Host 'Task status:' -ForegroundColor Cyan
& $SchtasksPath /Query /TN $TaskName /V /FO LIST
Write-Host ''
Write-Host 'Latest synchronization log:' -ForegroundColor Cyan
if (Test-Path -LiteralPath $LogPath) {
    Get-Content -LiteralPath $LogPath -Tail 40
} else {
    Write-Warning 'The log file has not been created yet.'
}