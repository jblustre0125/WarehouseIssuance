Option Explicit

Dim shell, sapCommand

Set shell = CreateObject("WScript.Shell")

' Refresh the SAP cache through the controlled CLI job.
sapCommand = """C:\Xampp\php\php.exe"" ""C:\Xampp\htdocs\WarehouseIssuance\tools\sync_sap_cache.php"""
shell.Run sapCommand, 0, False
