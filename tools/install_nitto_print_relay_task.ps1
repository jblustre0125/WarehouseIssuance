param(
    [string]$RelayRoot = "C:\NittoPrintRelay",
    [string]$PrinterName = "NITTO DURA-SL-400",
    [string]$TaskName = "Warehouse Nitto Print Relay",
    [string]$ScriptPath = "C:\Xampp\htdocs\WarehouseIssuance\tools\run_nitto_print_relay.ps1"
)

$inboxPath = Join-Path $RelayRoot "inbox"

if (-not (Test-Path -LiteralPath $inboxPath)) {
    New-Item -ItemType Directory -Path $inboxPath -Force | Out-Null
}

$share = Get-SmbShare -Name "NittoPrintRelay" -ErrorAction SilentlyContinue

if (-not $share) {
    New-SmbShare -Name "NittoPrintRelay" -Path $RelayRoot -ChangeAccess "Everyone" | Out-Null
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$ScriptPath`" -InboxPath `"$inboxPath`" -PrinterName `"$PrinterName`""

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
Write-Output "Printer: $PrinterName"
Write-Output "Task: $TaskName"
