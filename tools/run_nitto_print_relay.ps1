param(
    [string]$InboxPath = "C:\NittoPrintRelay\inbox",
    [string]$PrinterName = "NITTO DURA-SL-400",
    [int]$PaperWidthHundredths = 300,
    [int]$PaperHeightHundredths = 300,
    [int]$PollSeconds = 2,
    [switch]$Once
)

Add-Type -AssemblyName System.Drawing

function Ensure-Folder {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Write-RelayLog {
    param([string]$Message)

    Ensure-Folder $script:LogDir
    Add-Content -LiteralPath $script:LogFile -Value ("[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message)
}

function Print-RelayImage {
    param(
        [string]$ImagePath,
        [string]$TargetPrinter,
        [int]$WidthHundredths,
        [int]$HeightHundredths
    )

    $settings = New-Object System.Drawing.Printing.PrinterSettings
    $settings.PrinterName = $TargetPrinter

    if (-not $settings.IsValid) {
        throw "Printer queue is not valid on this PC: $TargetPrinter"
    }

    $image = [System.Drawing.Image]::FromFile($ImagePath)
    $doc = New-Object System.Drawing.Printing.PrintDocument

    try {
        $doc.PrinterSettings.PrinterName = $TargetPrinter
        $doc.DocumentName = "NBC Picker Tag Relay"
        $doc.OriginAtMargins = $false
        $doc.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(0, 0, 0, 0)
        $doc.DefaultPageSettings.PaperSize = New-Object System.Drawing.Printing.PaperSize("Picker Tag", $WidthHundredths, $HeightHundredths)
        $doc.PrintController = New-Object System.Drawing.Printing.StandardPrintController

        $doc.add_PrintPage({
            param($sender, $eventArgs)

            $eventArgs.Graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
            $eventArgs.Graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
            $eventArgs.Graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
            $eventArgs.Graphics.DrawImage($image, $eventArgs.PageBounds)
            $eventArgs.HasMorePages = $false
        })

        $doc.Print()
    } finally {
        $image.Dispose()
        $doc.Dispose()
    }
}

function Process-RelayJob {
    param([System.IO.FileInfo]$ImageFile)

    $baseName = [System.IO.Path]::GetFileNameWithoutExtension($ImageFile.Name)
    $metaPath = Join-Path $script:InboxPath ($baseName + ".json")
    $processingPath = Join-Path $script:ProcessingDir $ImageFile.Name
    $donePath = Join-Path $script:DoneDir $ImageFile.Name
    $errorPath = Join-Path $script:ErrorDir $ImageFile.Name
    $targetPrinter = $script:PrinterName
    $width = $script:PaperWidthHundredths
    $height = $script:PaperHeightHundredths

    if (Test-Path -LiteralPath $metaPath) {
        try {
            $meta = Get-Content -Raw -LiteralPath $metaPath | ConvertFrom-Json

            if ($meta.printer_name) {
                $targetPrinter = [string]$meta.printer_name
            }

            if ($meta.paper_width_hundredths) {
                $width = [int]$meta.paper_width_hundredths
            }

            if ($meta.paper_height_hundredths) {
                $height = [int]$meta.paper_height_hundredths
            }
        } catch {
            Write-RelayLog "WARN Metadata unreadable for $($ImageFile.Name): $($_.Exception.Message)"
        }
    }

    Move-Item -LiteralPath $ImageFile.FullName -Destination $processingPath -Force

    try {
        $started = Get-Date
        Print-RelayImage -ImagePath $processingPath -TargetPrinter $targetPrinter -WidthHundredths $width -HeightHundredths $height
        $elapsed = [math]::Round(((Get-Date) - $started).TotalSeconds, 3)
        Move-Item -LiteralPath $processingPath -Destination $donePath -Force

        if (Test-Path -LiteralPath $metaPath) {
            Move-Item -LiteralPath $metaPath -Destination (Join-Path $script:DoneDir ([System.IO.Path]::GetFileName($metaPath))) -Force
        }

        Write-RelayLog "PRINTED $($ImageFile.Name) to $targetPrinter in ${elapsed}s"
    } catch {
        if (Test-Path -LiteralPath $processingPath) {
            Move-Item -LiteralPath $processingPath -Destination $errorPath -Force
        }

        if (Test-Path -LiteralPath $metaPath) {
            Move-Item -LiteralPath $metaPath -Destination (Join-Path $script:ErrorDir ([System.IO.Path]::GetFileName($metaPath))) -Force
        }

        Write-RelayLog "ERROR $($ImageFile.Name): $($_.Exception.Message)"
    }
}

$script:InboxPath = $InboxPath
$script:PrinterName = $PrinterName
$script:PaperWidthHundredths = $PaperWidthHundredths
$script:PaperHeightHundredths = $PaperHeightHundredths
$script:ProcessingDir = Join-Path $InboxPath "processing"
$script:DoneDir = Join-Path $InboxPath "done"
$script:ErrorDir = Join-Path $InboxPath "errors"
$script:LogDir = Join-Path $InboxPath "logs"
$script:LogFile = Join-Path $script:LogDir ("nitto_relay_{0}.log" -f (Get-Date -Format "yyyyMMdd"))

Ensure-Folder $script:InboxPath
Ensure-Folder $script:ProcessingDir
Ensure-Folder $script:DoneDir
Ensure-Folder $script:ErrorDir
Ensure-Folder $script:LogDir

Write-RelayLog "Relay started. Inbox: $InboxPath | Printer: $PrinterName | User: $([System.Security.Principal.WindowsIdentity]::GetCurrent().Name)"

do {
    Get-ChildItem -LiteralPath $script:InboxPath -Filter "*.png" -File |
        Sort-Object LastWriteTime |
        ForEach-Object { Process-RelayJob $_ }

    if (-not $Once) {
        Start-Sleep -Seconds ([math]::Max(1, $PollSeconds))
    }
} while (-not $Once)
