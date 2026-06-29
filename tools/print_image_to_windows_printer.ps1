param(
    [Parameter(Mandatory = $true)]
    [string]$ImagePath,

    [Parameter(Mandatory = $true)]
    [string]$PrinterName,

    [int]$PaperWidthHundredths = 400,
    [int]$PaperHeightHundredths = 400
)

Add-Type -AssemblyName System.Drawing

if (-not (Test-Path -LiteralPath $ImagePath)) {
    throw "Image file was not found: $ImagePath"
}

$image = [System.Drawing.Image]::FromFile($ImagePath)
$doc = New-Object System.Drawing.Printing.PrintDocument

try {
    $doc.PrinterSettings.PrinterName = $PrinterName

    if (-not $doc.PrinterSettings.IsValid) {
        throw "Printer queue is not valid or not available: $PrinterName"
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
    Write-Output "Printed image to $PrinterName"
} finally {
    $image.Dispose()
    $doc.Dispose()
}
