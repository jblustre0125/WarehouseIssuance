Option Explicit

Dim shell, command

Set shell = CreateObject("WScript.Shell")

' Refresh heavier SAP-backed caches, such as Open PO, on a less frequent schedule.
command = """C:\Xampp\php\php.exe"" ""C:\Xampp\htdocs\WarehouseIssuance\tools\sync_sap_cache.php"" heavy"

shell.Run command, 0, False
