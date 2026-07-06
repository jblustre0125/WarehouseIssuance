param(
    [Parameter(Mandatory = $true)]
    [string]$ImagePath,

    [Parameter(Mandatory = $true)]
    [string]$PrinterName,

    [string]$FallbackPrinterName = "",

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
Write-Output "Fallback printer: $FallbackPrinterName"
Write-Output ("Visible printers: " + (($visiblePrinters | ForEach-Object { $_ }) -join " | "))

function Add-PrinterCandidate {
    param(
        [System.Collections.ArrayList]$Candidates,
        [string]$Name
    )

    $candidate = $Name.Trim()

    if ($candidate -ne "" -and -not $Candidates.Contains($candidate)) {
        [void]$Candidates.Add($candidate)
    }
}

function Test-PrinterCandidate {
    param([string]$Name)

    $settings = New-Object System.Drawing.Printing.PrinterSettings
    $settings.PrinterName = $Name

    return $settings.IsValid
}

$printerCandidates = New-Object System.Collections.ArrayList
Add-PrinterCandidate $printerCandidates $PrinterName

$fallbackShareQueueName = ""
$fallbackServerName = ""

if ($FallbackPrinterName.StartsWith("\\")) {
    $fallbackParts = $FallbackPrinterName -split "\\"
    $fallbackServerName = $fallbackParts[-2]
    $fallbackShareQueueName = $fallbackParts[-1]
}

if ($PrinterName.StartsWith("\\")) {
    $shareQueueName = ($PrinterName -split "\\")[-1]
} else {
    $shareQueueName = $PrinterName
}

if ($fallbackShareQueueName -ne "" -and $shareQueueName -eq "") {
    $shareQueueName = $fallbackShareQueueName
}

$visibleShareQueue = $visiblePrinters | Where-Object { $_ -like "\\*\$shareQueueName" } | Select-Object -First 1

$visibleNamedQueue = $visiblePrinters | Where-Object { $_ -ieq $shareQueueName } | Select-Object -First 1
Add-PrinterCandidate $printerCandidates ([string]$visibleNamedQueue)

$visiblePrinters |
    Where-Object { $_ -like "$shareQueueName*" -and $_ -notlike "*(redirected*)" } |
    ForEach-Object { Add-PrinterCandidate $printerCandidates ([string]$_) }

if ($fallbackServerName -ne "" -and $fallbackShareQueueName -ne "") {
    $visiblePrinters |
        Where-Object { $_ -like "$fallbackShareQueueName on $fallbackServerName*" -and $_ -notlike "*(redirected*)" } |
        ForEach-Object { Add-PrinterCandidate $printerCandidates ([string]$_) }
}

Add-PrinterCandidate $printerCandidates ([string]$visibleShareQueue)
Add-PrinterCandidate $printerCandidates $FallbackPrinterName

if ($PrinterName.StartsWith("\\")) {
    Add-PrinterCandidate $printerCandidates $shareQueueName
}

$resolvedPrinterName = ""

foreach ($candidate in $printerCandidates) {
    $isValid = Test-PrinterCandidate $candidate
    Write-Output "Candidate printer valid [$candidate]: $isValid"

    if ($isValid) {
        $resolvedPrinterName = $candidate
        break
    }
}

if ($resolvedPrinterName -eq "") {
    throw "No valid printer queue found. Tried: $($printerCandidates -join " | ")"
}

Write-Output "Resolved printer: $resolvedPrinterName"

$image = [System.Drawing.Image]::FromFile($ImagePath)
$doc = New-Object System.Drawing.Printing.PrintDocument

try {
    $doc.PrinterSettings.PrinterName = $resolvedPrinterName

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
