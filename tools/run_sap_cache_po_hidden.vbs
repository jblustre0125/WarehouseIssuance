Option Explicit

Dim shell, command

Set shell = CreateObject("WScript.Shell")

' Refresh only Open PO cache.
command = """C:\Xampp\php\php.exe"" ""C:\Xampp\htdocs\WarehouseIssuance\tools\sync_sap_cache.php"" po"

shell.Run command, 0, False
