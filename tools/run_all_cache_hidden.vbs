Option Explicit

Dim shell, sapCommand, http

Set shell = CreateObject("WScript.Shell")

' Refresh the PHP SAP cache in the background.
sapCommand = """C:\Xampp\php\php.exe"" ""C:\Xampp\htdocs\WarehouseIssuance\tools\sync_sap_cache.php"""
shell.Run sapCommand, 0, False

' Refresh the dashboard ScanPlus cache without opening PowerShell or a browser window.
Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
http.open "GET", "http://192.168.21.144/warehouseIssuance/pages/dashboard/sync_scanplus_cache.php", False
http.setRequestHeader "Cache-Control", "no-cache"
http.send

