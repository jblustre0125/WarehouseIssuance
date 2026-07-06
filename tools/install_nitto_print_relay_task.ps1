param(
    [string]$RelayRoot = "C:\NittoPrintRelay",
    [string]$PrinterName = "NITTO DURA-SL-400",
    [string]$TaskName = "Warehouse Nitto Print Relay"
)

$inboxPath = Join-Path $RelayRoot "inbox"
$sourceWorkerPath = Join-Path $PSScriptRoot "run_nitto_print_relay.ps1"
$installedWorkerPath = Join-Path $RelayRoot "run_nitto_print_relay.ps1"

if (-not (Test-Path -LiteralPath $sourceWorkerPath)) {
    throw "Relay worker not found beside installer: $sourceWorkerPath"
}

if (-not (Test-Path -LiteralPath $RelayRoot)) {
    New-Item -ItemType Directory -Path $RelayRoot -Force | Out-Null
}

Copy-Item -LiteralPath $sourceWorkerPath -Destination $installedWorkerPath -Force

if (-not (Test-Path -LiteralPath $inboxPath)) {
    New-Item -ItemType Directory -Path $inboxPath -Force | Out-Null
}

$acl = Get-Acl -LiteralPath $RelayRoot
$rule = New-Object System.Security.AccessControl.FileSystemAccessRule("Everyone", "Modify", "ContainerInherit,ObjectInherit", "None", "Allow")
$acl.SetAccessRule($rule)
Set-Acl -LiteralPath $RelayRoot -AclObject $acl

$share = Get-SmbShare -Name "NittoPrintRelay" -ErrorAction SilentlyContinue

if (-not $share) {
    New-SmbShare -Name "NittoPrintRelay" -Path $RelayRoot -ChangeAccess "Everyone" | Out-Null
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$installedWorkerPath`" -InboxPath `"$inboxPath`" -PrinterName `"$PrinterName`""

$trigger = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Force | Out-Null

Start-ScheduledTask -TaskName $TaskName

Write-Output "Nitto relay installed."
Write-Output "Inbox: $inboxPath"
Write-Output "Share: \\$env:COMPUTERNAME\NittoPrintRelay\inbox"
Write-Output "Worker: $installedWorkerPath"
Write-Output "Printer: $PrinterName"
Write-Output "Task: $TaskName"
