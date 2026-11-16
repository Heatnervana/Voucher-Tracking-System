#Requires -RunAsAdministrator
param(
    [string]$BackupDestination = (Join-Path $PSScriptRoot '.project-backups\daily')
)

$ErrorActionPreference = 'Stop'

# SECURITY-FIX: 2026-11-12 replace broad explicit grants with an allowlisted backup ACL.
function New-OfficeBackupAcl([Security.Principal.SecurityIdentifier]$AccountSid) {
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner((New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')))
    foreach ($identity in @($AccountSid, (New-Object Security.Principal.SecurityIdentifier('S-1-5-18')), (New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')))) {
        $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
    }
    return $acl
}

# SECURITY-FIX: 2026-11-12 validate every existing ancestor before creating files or changing ACLs.
function Assert-OfficeBackupDestination([string]$Path, [Security.Principal.SecurityIdentifier]$AccountSid) {
    $resolved = [IO.Path]::GetFullPath($Path)
    $current = $resolved
    while ($current) {
        if (Test-Path -LiteralPath $current) {
            $item = Get-Item -LiteralPath $current -Force
            if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Backup directories must not contain a junction or symbolic link.' }
            if (-not $item.PSIsContainer) { throw 'Backup destination ancestors must be directories.' }
        }
        $parent = Split-Path $current -Parent
        if ($parent -eq $current) { break }
        $current = $parent
    }
    # SECURITY-FIX: 2026-11-12 reject unsafe historical ACLs instead of recursively changing backup permissions.
    if ($null -ne $AccountSid -and (Test-Path -LiteralPath $resolved)) {
        $allowed = @($AccountSid.Value, 'S-1-5-18', 'S-1-5-32-544')
        $boundary = $resolved.TrimEnd('\') + '\'
        $pending = New-Object 'System.Collections.Generic.Stack[string]'
        $pending.Push($resolved)
        while ($pending.Count) {
            foreach ($child in @(Get-ChildItem -LiteralPath $pending.Pop() -Force -ErrorAction Stop)) {
                $childPath = [IO.Path]::GetFullPath($child.FullName)
                if (-not $childPath.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase)) { throw 'A backup entry is outside its destination boundary.' }
                if ($child.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Backup contents must not contain a junction or symbolic link.' }
                $childAcl = Get-Acl -LiteralPath $childPath -ErrorAction Stop
                $rules = @($childAcl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
                if ($rules.Count -eq 0 -or $childAcl.GetOwner([Security.Principal.SecurityIdentifier]).Value -notin $allowed -or
                    @($rules | Where-Object { $_.IdentityReference.Value -notin $allowed -or $_.AccessControlType -ne 'Allow' }).Count) {
                    throw 'Existing backup contents have unsafe permissions. Review them manually and use a new empty protected destination.'
                }
                if ($child.PSIsContainer) { $pending.Push($childPath) }
            }
        }
    }
    return $resolved
}

$root = $PSScriptRoot
$apache = 'C:\xampp\apache\bin\httpd.exe'
$account = "$env:COMPUTERNAME\Stat"
$interface = 'Wi-Fi 2'
$subnet = '192.168.1.0/24'
$taskNames = @('Voucher Tracker - Apache', 'Voucher Tracker - Amount reader', 'Voucher Tracker - Backup')
$configPaths = @('C:\xampp\apache\conf\httpd.conf', 'C:\xampp\apache\conf\extra\httpd-vhosts.conf', 'C:\xampp\apache\conf\extra\httpd-ssl.conf')
$accountSid = (New-Object Security.Principal.NTAccount($account)).Translate([Security.Principal.SecurityIdentifier])
$BackupDestination = Assert-OfficeBackupDestination -Path $BackupDestination -AccountSid $accountSid
$archive = Assert-OfficeBackupDestination -Path (Join-Path $root ('.project-backups\office-setup-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))) -AccountSid $accountSid
$changedConfiguration = $false
$changedTasks = @()
$disabledRules = @()
$replacedOfficeRule = $false
$createdPublicRule = $false
$stoppedProcesses = $false
New-Item -ItemType Directory -Path $archive | Out-Null
Set-Acl -LiteralPath $archive -AclObject (New-OfficeBackupAcl -AccountSid $accountSid)
Start-Transcript -Path (Join-Path $archive 'setup.log') | Out-Null

try {
    $network = Get-NetConnectionProfile -InterfaceAlias $interface
    if (Get-CimInstance Win32_Service | Where-Object { $_.PathName -like '*httpd.exe*' }) {
        throw 'Apache already has a Windows service; review that service before replacing startup.'
    }
    if ($network.Name -ne 'Finance Division 2' -or $network.NetworkCategory -ne 'Private') {
        throw 'This setup is restricted to the Finance Division 2 private office network.'
    }
    if (-not (Get-NetIPAddress -InterfaceAlias $interface -AddressFamily IPv4 | Where-Object { $_.IPAddress -like '192.168.1.*' -and $_.PrefixLength -eq 24 })) {
        throw 'The office subnet changed; review the network settings first.'
    }
    foreach ($path in $configPaths) {
        Copy-Item -LiteralPath $path -Destination (Join-Path $archive (Split-Path $path -Leaf))
    }
    Copy-Item -LiteralPath (Join-Path $root '.env') -Destination (Join-Path $archive '.env')
    foreach ($name in $taskNames) {
        if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
            Export-ScheduledTask -TaskName $name | Set-Content -LiteralPath (Join-Path $archive ($name.Replace(' ', '-') + '.xml'))
        }
    }
    $previousOfficeRule = Get-NetFirewallRule -Name 'VoucherTracker-Office-8080' -ErrorAction SilentlyContinue
    if ($previousOfficeRule) {
        throw 'Office firewall setup already exists; inspect it rather than overwriting it.'
    }
    $allRules = @(Get-NetFirewallRule -Direction Inbound -Enabled True)
    $apacheRules = @($allRules | Where-Object {
        $application = $_ | Get-NetFirewallApplicationFilter
        $application.Program -eq $apache -and $_.Action -eq 'Allow'
    })
    $apacheRules | Select-Object Name,DisplayName,Enabled,Profile | Export-Clixml -LiteralPath (Join-Path $archive 'previous-apache-firewall.xml')

    $main = [IO.File]::ReadAllText($configPaths[0])
    if ($main -notmatch '(?m)^Listen (127\.0\.0\.1:|0\.0\.0\.0:)?8080[ \t]*\r?$') {
        throw 'Expected Apache port 8080 listener was not found.'
    }
    $main = [regex]::Replace($main, '(?m)^Listen (127\.0\.0\.1:|0\.0\.0\.0:)?8080[ \t]*\r?$', 'Listen 0.0.0.0:8080')
    $vhosts = [IO.File]::ReadAllText($configPaths[1])
    $officeHost = @"
<VirtualHost *:8080>
    ServerName $($env:COMPUTERNAME.ToLowerInvariant())
    ServerAlias localhost
    DocumentRoot "C:/xampp/htdocs/voucher-tracker"
    DirectoryIndex index.php
    <Directory "C:/xampp/htdocs/voucher-tracker">
        Options -Indexes -Includes -ExecCGI
        AllowOverride All
        <RequireAny>
            Require local
            Require ip $subnet
        </RequireAny>
    </Directory>
    <LocationMatch "^/(?:phpmyadmin|php-cgi|cgi-bin|webalizer|licenses|server-status|server-info|examples|icons|error)(?:/|$)|/\.|^/~">
        Require all denied
    </LocationMatch>
    ErrorLog "logs/voucher-tracker-error.log"
    CustomLog "logs/voucher-tracker-access.log" common
</VirtualHost>
"@
    $hostPattern = '(?s)<VirtualHost (?:127\.0\.0\.1|\*):8080>.*?</VirtualHost>'
    if ([regex]::Matches($vhosts, $hostPattern).Count -ne 1) {
        throw 'Expected exactly one voucher virtual host on port 8080.'
    }
    $vhosts = [regex]::Replace($vhosts, $hostPattern, [Text.RegularExpressions.MatchEvaluator]{ param($match) $officeHost })
    $ssl = [regex]::Replace([IO.File]::ReadAllText($configPaths[2]), '(?m)^Listen (?:0\.0\.0\.0:)?443[ \t]*\r?$', 'Listen 127.0.0.1:443')
    $changedConfiguration = $true
    [IO.File]::WriteAllText($configPaths[0], $main)
    [IO.File]::WriteAllText($configPaths[1], $vhosts)
    [IO.File]::WriteAllText($configPaths[2], $ssl)
    & $apache -t
    if ($LASTEXITCODE -ne 0) {
        foreach ($path in $configPaths) {
            Copy-Item -LiteralPath (Join-Path $archive (Split-Path $path -Leaf)) -Destination $path -Force
        }
        throw 'Apache configuration check failed; original configuration restored.'
    }

    foreach ($rule in $apacheRules) {
        Disable-NetFirewallRule -Name $rule.Name | Out-Null
        $disabledRules += $rule.Name
    }
    New-NetFirewallRule -Name 'VoucherTracker-Office-8080' -DisplayName 'Voucher Tracker - office subnet only' -Direction Inbound -Action Allow -Enabled True -Profile Private -Protocol TCP -LocalPort 8080 -RemoteAddress $subnet -InterfaceAlias $interface -Program $apache -EdgeTraversalPolicy Block | Out-Null
    $replacedOfficeRule = $true
    if (-not (Get-NetFirewallRule -Name 'VoucherTracker-Block-Public' -ErrorAction SilentlyContinue)) {
        New-NetFirewallRule -Name 'VoucherTracker-Block-Public' -DisplayName 'Voucher Tracker - block public networks' -Direction Inbound -Action Block -Enabled True -Profile Public -Program $apache | Out-Null
        $createdPublicRule = $true
    }

    $BackupDestination = Assert-OfficeBackupDestination -Path $BackupDestination -AccountSid $accountSid
    if (-not (Test-Path -LiteralPath $BackupDestination)) { New-Item -ItemType Directory -Path $BackupDestination | Out-Null }
    $backupAcl = Get-Acl -LiteralPath $BackupDestination
    $backupAcl | Export-Clixml -LiteralPath (Join-Path $archive 'previous-backup-acl.xml')
    Set-Acl -LiteralPath $BackupDestination -AclObject (New-OfficeBackupAcl -AccountSid $accountSid)

    $principal = New-ScheduledTaskPrincipal -UserId $account -LogonType S4U -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
    $startup = New-ScheduledTaskTrigger -AtStartup
    $recovery = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
    $daily = New-ScheduledTaskTrigger -Daily -At '18:00'
    $runner = Join-Path $root 'Run office task.ps1'
    $powershell = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
    foreach ($entry in @(@{Name=$taskNames[0]; Mode='Apache'}, @{Name=$taskNames[1]; Mode='AmountReader'}, @{Name=$taskNames[2]; Mode='Backup'})) {
        $executable = $powershell
        $arguments = '-NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -WindowStyle Hidden -File "' + $runner + '" -Task ' + $entry.Mode
        $triggers = @($startup, $recovery)
        if ($entry.Mode -eq 'Apache') {
            $executable = $apache
            $arguments = '-d C:/xampp/apache -f conf/httpd.conf'
        }
        if ($entry.Mode -eq 'AmountReader') {
            $executable = 'C:\xampp\php\php.exe'
            $arguments = 'artisan queue:work database --queue=voucher-ocr --sleep=1 --tries=1 --timeout=60 --memory=256 --max-time=3600 --no-interaction'
        }
        if ($entry.Mode -eq 'Backup') {
            $arguments += ' -BackupDestination "' + $BackupDestination + '"'
            $triggers = @($daily)
        }
        $action = New-ScheduledTaskAction -Execute $executable -Argument $arguments -WorkingDirectory $root
        Register-ScheduledTask -TaskName $entry.Name -Action $action -Trigger $triggers -Settings $settings -Principal $principal -Force | Out-Null
        $changedTasks += $entry.Name
    }
    $envPath = Join-Path $root '.env'
    $environment = [IO.File]::ReadAllText($envPath)
    $environment = [regex]::Replace($environment, '(?m)^APP_URL=.*$', 'APP_URL=http://' + $env:COMPUTERNAME.ToLowerInvariant() + ':8080')
    $environment = [regex]::Replace($environment, '(?m)^APP_ENV=.*$', 'APP_ENV=production')
    [IO.File]::WriteAllText($envPath, $environment)
    Set-Location -LiteralPath $root
    & 'C:\xampp\php\php.exe' artisan config:clear --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Could not clear application configuration.' }

    $processes = @(Get-CimInstance Win32_Process)
    $stoppedProcesses = $true
    $manualLaunchers = @($processes | Where-Object { $_.Name -eq 'cmd.exe' -and $_.CommandLine -like ('*' + $root + '\Start amount reader.cmd*') })
    foreach ($launcher in $manualLaunchers) {
        Stop-Process -Id $launcher.ProcessId -Force
        $processes | Where-Object { $_.ParentProcessId -eq $launcher.ProcessId -and $_.Name -eq 'php.exe' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
    }
    $processes | Where-Object { $_.Name -eq 'httpd.exe' -and $_.ExecutablePath -eq $apache } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Seconds 3
    foreach ($name in $taskNames) { Start-ScheduledTask -TaskName $name }
    $healthy = $false
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        Start-Sleep -Seconds 1
        try {
            $response = Invoke-WebRequest -Uri 'http://127.0.0.1:8080/login' -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -eq 200 -and (Get-ScheduledTask -TaskName $taskNames[0]).State -eq 'Running' -and (Get-ScheduledTask -TaskName $taskNames[1]).State -eq 'Running') {
                $healthy = $true
                break
            }
        } catch { }
    }
    if (-not $healthy) { throw 'Startup tasks did not become healthy; restoring the previous local setup.' }
    [ordered]@{status='configured'; host=$env:COMPUTERNAME.ToLowerInvariant(); subnet=$subnet; backup=$BackupDestination; archive=$archive; tasks=$taskNames} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $root 'storage\logs\office-setup-result.json')
} catch {
    $failure = $_
    $ErrorActionPreference = 'Continue'
    $restoredTasks = @()
    foreach ($name in $changedTasks) {
        Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $name -Confirm:$false
        $previousTask = Join-Path $archive ($name.Replace(' ', '-') + '.xml')
        if (Test-Path -LiteralPath $previousTask) {
            Register-ScheduledTask -TaskName $name -Xml ([IO.File]::ReadAllText($previousTask)) | Out-Null
            $restoredTasks += $name
        }
    }
    if ($replacedOfficeRule) { Remove-NetFirewallRule -Name 'VoucherTracker-Office-8080' }
    if ($createdPublicRule) { Remove-NetFirewallRule -Name 'VoucherTracker-Block-Public' }
    foreach ($name in $disabledRules) { Enable-NetFirewallRule -Name $name | Out-Null }
    if ($changedConfiguration) {
        foreach ($path in $configPaths) { Copy-Item -LiteralPath (Join-Path $archive (Split-Path $path -Leaf)) -Destination $path -Force }
        Copy-Item -LiteralPath (Join-Path $archive '.env') -Destination (Join-Path $root '.env') -Force
        & 'C:\xampp\php\php.exe' (Join-Path $root 'artisan') config:clear --no-interaction
    }
    if ($stoppedProcesses) {
        foreach ($name in $restoredTasks) { Start-ScheduledTask -TaskName $name }
    }
    [ordered]@{status='failed'; message=$failure.Exception.Message; needs_local_restart=$stoppedProcesses; archive=$archive} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $root 'storage\logs\office-setup-result.json')
    throw $failure
} finally {
    Stop-Transcript | Out-Null
}
