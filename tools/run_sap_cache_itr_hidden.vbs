Option Explicit

Dim shell, command

Set shell = CreateObject("WScript.Shell")

' Refresh only SAP ITR caches.
command = """C:\Xampp\php\php.exe"" ""C:\Xampp\htdocs\WarehouseIssuance\tools\sync_sap_cache.php"" itr"

shell.Run command, 0, False
