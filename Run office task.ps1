param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Backup')]
    [string]$Task,
    [string]$BackupDestination
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$php = 'C:\xampp\php\php.exe'

switch ($Task) {
    'Backup' {
        if ([string]::IsNullOrWhiteSpace($BackupDestination)) {
            throw 'A private backup destination is required.'
        }
        $ErrorActionPreference = 'Continue'
        & $php artisan vouchers:backup $BackupDestination --no-interaction 2>&1 | Out-File -FilePath 'storage\logs\voucher-backup-task.log' -Append -Encoding utf8
        exit $LASTEXITCODE
    }
}
