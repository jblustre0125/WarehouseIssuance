Option Explicit

Dim shell
Dim command
Dim exitCode

Set shell = CreateObject("WScript.Shell")

' Refresh only the Open PO cache.
command = """C:\Xampp\php\php.exe"" " & _
          """C:\Xampp\htdocs\WarehouseIssuance\tools\sync_sap_cache.php"" po"

' 0 = hidden window
' True = wait until PHP finishes
exitCode = shell.Run(command, 0, True)

Set shell = Nothing

' Return the PHP result to Windows Task Scheduler.
WScript.Quit exitCode