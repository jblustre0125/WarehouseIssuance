Option Explicit

Dim shell, command, jobFile

Set shell = CreateObject("WScript.Shell")

jobFile = ""

If WScript.Arguments.Count > 0 Then
    jobFile = WScript.Arguments(0)
End If

command = """C:\Xampp\php\php.exe"" ""C:\Xampp\htdocs\WarehouseIssuance\workers\print_picker_worker.php"""

If jobFile <> "" Then
    command = command & " """ & Replace(jobFile, """", """""") & """"
End If

' Second parameter 0 keeps the window hidden. Third parameter False runs in background.
shell.Run command, 0, False
