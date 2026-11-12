# TRUST-BOUNDARY: Queued OCR supplies server-owned PDF paths and a generated temporary image path.
param(
    [Parameter(Mandatory = $true)][string] $PdfPath,
    [Parameter(Mandatory = $true)][string] $ImagePath
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Runtime.WindowsRuntime

$asyncMethod = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 -and
    $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation' + [char]96 + '1'
} | Select-Object -First 1

function Wait-WinRtResult($Operation, [Type] $ResultType) {
    $task = $asyncMethod.MakeGenericMethod($ResultType).Invoke($null, @($Operation))
    $task.GetAwaiter().GetResult()
}

$source = Wait-WinRtResult (
    [Windows.Storage.StorageFile,Windows.Storage,ContentType=WindowsRuntime]::GetFileFromPathAsync(
        # SECURITY: Literal path lookup keeps PDF names from becoming PowerShell wildcard expressions.
        (Get-Item -LiteralPath $PdfPath).FullName
    )
) ([Windows.Storage.StorageFile,Windows.Storage,ContentType=WindowsRuntime])
$pdf = Wait-WinRtResult (
    [Windows.Data.Pdf.PdfDocument,Windows.Data.Pdf,ContentType=WindowsRuntime]::LoadFromFileAsync($source)
) ([Windows.Data.Pdf.PdfDocument,Windows.Data.Pdf,ContentType=WindowsRuntime])

$page = $null
$stream = $null
$reader = $null
try {
    # SECURITY: Only the first page is rendered; the caller enforces the subprocess timeout.
    $page = $pdf.GetPage(0)
    $stream = [Windows.Storage.Streams.InMemoryRandomAccessStream,Windows.Storage.Streams,ContentType=WindowsRuntime]::new()
    $options = [Windows.Data.Pdf.PdfPageRenderOptions,Windows.Data.Pdf,ContentType=WindowsRuntime]::new()
    $options.DestinationWidth = 1700
    $actionMethod = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
        $_.Name -eq 'AsTask' -and -not $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 -and
        $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction'
    } | Select-Object -First 1
    $task = $actionMethod.Invoke($null, @($page.RenderToStreamAsync($stream, $options)))
    $null = $task.GetAwaiter().GetResult()

    $reader = [Windows.Storage.Streams.DataReader,Windows.Storage.Streams,ContentType=WindowsRuntime]::new($stream.GetInputStreamAt(0))
    $loaded = Wait-WinRtResult ($reader.LoadAsync([uint32] $stream.Size)) ([uint32])
    $bytes = [byte[]]::new($loaded)
    $reader.ReadBytes($bytes)
    [System.IO.File]::WriteAllBytes([System.IO.Path]::GetFullPath($ImagePath), $bytes)
} finally {
    if ($null -ne $reader) { $reader.Dispose() }
    if ($null -ne $page) { $page.Dispose() }
    if ($null -ne $stream) { $stream.Dispose() }
}
