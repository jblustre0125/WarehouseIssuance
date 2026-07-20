Option Explicit

Dim shell, sapCommand, scanplusCommand

Set shell = CreateObject("WScript.Shell")

' Refresh the SAP cache through the controlled CLI job.
sapCommand = """C:\Xampp\php\php.exe"" ""C:\Xampp\htdocs\WarehouseIssuance\tools\sync_sap_cache.php"""
shell.Run sapCommand, 0, True

' Refresh the local ScanPlus/SAP receive cache used by reports.
scanplusCommand = """C:\Xampp\htdocs\WarehouseIssuance\tools\run_scanplus_cache_sync.cmd"""
shell.Run scanplusCommand, 0, True
