Option Explicit

Dim http

Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")

http.open "GET", "http://192.168.21.144/warehouseIssuance/pages/dashboard/sync_scanplus_cache.php", False
http.setRequestHeader "Cache-Control", "no-cache"
http.send

