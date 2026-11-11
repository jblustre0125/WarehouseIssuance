param(
    [Parameter(Mandatory = $true)]
    [string]$ZplPath,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^COM[1-9][0-9]{0,4}$')]
    [string]$ComPort,

    [ValidateRange(1, 4000000)]
    [int]$BaudRate = 115200,

    [ValidateRange(1, 120)]
    [int]$TimeoutSeconds = 20
)

$ErrorActionPreference = 'Stop'
$serial = $null
$exitCode = 0

try {
    $payload = [System.IO.File]::ReadAllBytes($ZplPath)
    if ($payload.Length -eq 0) {
        throw 'Label data is empty.'
    }
    if ([System.IO.Ports.SerialPort]::GetPortNames() -notcontains $ComPort) {
        throw "Outgoing Bluetooth port $ComPort was not found on this computer."
    }

    $serial = New-Object System.IO.Ports.SerialPort
    $serial.PortName = $ComPort
    $serial.BaudRate = $BaudRate
    $serial.Parity = [System.IO.Ports.Parity]::None
    $serial.DataBits = 8
    $serial.StopBits = [System.IO.Ports.StopBits]::One
    $serial.Handshake = [System.IO.Ports.Handshake]::None
    $serial.WriteTimeout = $TimeoutSeconds * 1000
    $serial.Open()

    # Use bytes so UTF-8 ZPL is not converted to the SerialPort ASCII encoding.
    $serial.Write($payload, 0, $payload.Length)
    $drainTimer = [System.Diagnostics.Stopwatch]::StartNew()
    while ($serial.BytesToWrite -gt 0) {
        if ($drainTimer.Elapsed.TotalSeconds -ge $TimeoutSeconds) {
            throw 'Timed out waiting for Bluetooth label data to drain.'
        }
        Start-Sleep -Milliseconds 50
    }
    Write-Output "Sent $($payload.Length) bytes via Bluetooth $ComPort."
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    $exitCode = 1
} finally {
    if ($null -ne $serial) {
        try {
            if ($serial.IsOpen) {
                $serial.Close()
            }
        } catch {
            [Console]::Error.WriteLine($_.Exception.Message)
            $exitCode = 1
        } finally {
            $serial.Dispose()
        }
    }
}

exit $exitCode
