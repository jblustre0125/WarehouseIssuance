Option Explicit

Dim shell, command

Set shell = CreateObject("WScript.Shell")

command = """C:\Xampp\php\php.exe"" ""C:\Xampp\htdocs\WarehouseIssuance\tools\sync_sap_cache.php"""

' Second parameter 0 keeps the window hidden. Third parameter False runs in background.
shell.Run command, 0, False

