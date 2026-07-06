param(
    [Parameter(Mandatory = $true)]
    [string]$ImagePath,

    [Parameter(Mandatory = $true)]
    [string]$PrinterName,

    [int]$PaperWidthHundredths = 300,
    [int]$PaperHeightHundredths = 300
)

Add-Type -AssemblyName System.Drawing

if (-not (Test-Path -LiteralPath $ImagePath)) {
    throw "Image file was not found: $ImagePath"
}

$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$visiblePrinters = [System.Drawing.Printing.PrinterSettings]::InstalledPrinters
Write-Output "Computer: $env:COMPUTERNAME"
Write-Output "Windows user: $identity"
Write-Output "Target printer: $PrinterName"
Write-Output ("Visible printers: " + (($visiblePrinters | ForEach-Object { $_ }) -join " | "))

$resolvedPrinterName = $PrinterName

if ($PrinterName.StartsWith("\\")) {
    $shareQueueName = ($PrinterName -split "\\")[-1]
    $localQueue = $visiblePrinters | Where-Object { $_ -ieq $shareQueueName } | Select-Object -First 1

    if ($localQueue) {
        $resolvedPrinterName = [string]$localQueue
        Write-Output "Resolved shared printer to local queue: $resolvedPrinterName"
    }
}

$image = [System.Drawing.Image]::FromFile($ImagePath)
$doc = New-Object System.Drawing.Printing.PrintDocument

try {
    $doc.PrinterSettings.PrinterName = $resolvedPrinterName
    Write-Output "Printer valid for this user: $($doc.PrinterSettings.IsValid)"

    if (-not $doc.PrinterSettings.IsValid) {
        throw "Printer queue is not valid or not available: $resolvedPrinterName"
    }

    $doc.DocumentName = "NBC Picker Tag"
    $doc.OriginAtMargins = $false
    $doc.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(0, 0, 0, 0)
    $doc.DefaultPageSettings.PaperSize = New-Object System.Drawing.Printing.PaperSize("Picker Tag", $PaperWidthHundredths, $PaperHeightHundredths)

    $doc.add_PrintPage({
        param($sender, $eventArgs)

        $eventArgs.Graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $eventArgs.Graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
        $eventArgs.Graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $eventArgs.Graphics.DrawImage($image, $eventArgs.PageBounds)
        $eventArgs.HasMorePages = $false
    })

    $doc.Print()
    Write-Output "Printed image to $resolvedPrinterName"
} finally {
    $image.Dispose()
    $doc.Dispose()
}
