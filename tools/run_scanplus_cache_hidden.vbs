Option Explicit

Dim shell, command

Set shell = CreateObject("WScript.Shell")

command = """C:\Xampp\htdocs\WarehouseIssuance\tools\run_scanplus_cache_sync.cmd"""
shell.Run command, 0, True
