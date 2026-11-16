param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Backup', 'Uptime')]
    [string]$Task,
    [string]$BackupDestination,
    [uri]$HealthUrl,
    [string]$PhpPath = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot

switch ($Task) {
    'Backup' {
        if ([string]::IsNullOrWhiteSpace($BackupDestination)) {
            throw 'A private backup destination is required.'
        }
        if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
            throw 'The selected PHP executable does not exist.'
        }
        $ErrorActionPreference = 'Continue'
        & $PhpPath artisan vouchers:backup $BackupDestination --no-interaction 2>&1 | Out-File -FilePath 'storage\logs\voucher-backup-task.log' -Append -Encoding utf8
        exit $LASTEXITCODE
    }
    'Uptime' {
        if (!$HealthUrl -or !$HealthUrl.IsAbsoluteUri -or $HealthUrl.Scheme -ne 'https') {
            throw 'An absolute HTTPS health URL is required.'
        }

        $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
        $status = & $curl --noproxy '*' --ssl-revoke-best-effort --connect-timeout 5 --max-time 15 --silent --output NUL --write-out '%{http_code}' $HealthUrl.AbsoluteUri
        $curlExit = $LASTEXITCODE
        $healthy = $curlExit -eq 0 -and $status -eq '200'
        $state = if ($healthy) { 'UP' } else { 'DOWN' }
        $log = 'storage\logs\voucher-uptime.log'
        if ((Test-Path -LiteralPath $log) -and (Get-Item -LiteralPath $log).Length -ge 1MB) {
            Move-Item -LiteralPath $log -Destination 'storage\logs\voucher-uptime-previous.log' -Force
        }
        Add-Content -LiteralPath $log -Value "$((Get-Date).ToString('o')) $state HTTP $status (curl exit $curlExit)" -Encoding utf8

        if ($healthy) {
            exit 0
        }
        exit 1
    }
}
