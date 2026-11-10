param(
    [ValidateSet('Preview', 'Apply', 'Rollback')]
    [string]$Mode = 'Preview',
    [string]$Archive
)

$ErrorActionPreference = 'Stop'
$apache = 'C:\xampp\apache\bin\httpd.exe'
$openssl = 'C:\xampp\apache\bin\openssl.exe'
$vhostsPath = 'C:\xampp\apache\conf\extra\httpd-vhosts.conf'
$mainPath = 'C:\xampp\apache\conf\httpd.conf'
$sslPath = 'C:\xampp\apache\conf\extra\httpd-ssl.conf'
$privateRoot = Join-Path $env:ProgramData 'VoucherTrackerHttps'
$taskName = 'Voucher Tracker - Apache'
$hostName = 'pmo-moz-asststa'
$environmentPath = Join-Path $PSScriptRoot '.env'

function Get-OfficeHostPattern([string]$HostName, [string[]]$Addresses, [int]$Port) {
    $names = @($HostName, 'localhost', '127.0.0.1') + $Addresses
    return '^(?:' + (($names | Select-Object -Unique | ForEach-Object { [regex]::Escape($_) }) -join '|') + ')(?::' + $Port + ')?$'
}

function New-OfficeHttpsConfiguration([string]$Original, [string]$HostName, [string[]]$Addresses, [string]$Certificate, [string]$Key) {
    if ($HostName -notmatch '^[a-z0-9-]+$' -or @($Addresses | Where-Object { $_ -notmatch '^\d{1,3}(?:\.\d{1,3}){3}$' }).Count) {
        throw 'Unsafe hostname or IPv4 address.'
    }
    $pattern = '(?s)<VirtualHost \*:8080>.*?</VirtualHost>'
    if ([regex]::Matches($Original, $pattern).Count -ne 1 -or $Original -match '(?im)^\s*Listen\s+.*8443|<VirtualHost[^>]*:8443>') {
        throw 'Expected exactly one existing office virtual host and no HTTPS 8443 listener.'
    }
    foreach ($path in @($Certificate, $Key)) {
        if ($path -match '["\r\n]') { throw 'Unsafe certificate path.' }
    }
    $deniedPaths = '^/(?:phpmyadmin|php-cgi|cgi-bin|webalizer|licenses|server-status|server-info|examples|icons|error)(?:/|$)|/\.|^/~'
    $hosts = @('localhost', '127.0.0.1') + $Addresses
    $blocks = foreach ($port in @(8080, 8443)) {
        $hostPattern = Get-OfficeHostPattern -HostName $HostName -Addresses $Addresses -Port $port
        $capturePattern = $hostPattern.Replace('^(?:', '^(')
        $transport = if ($port -eq 8443) {
            @"
    SSLEngine on
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCipherSuite HIGH:!aNULL:!eNULL:!MD5:!RC4:!3DES:!kRSA
    SSLCompression off
    SSLCertificateFile "$($Certificate.Replace('\', '/'))"
    SSLCertificateKeyFile "$($Key.Replace('\', '/'))"
"@
        } else {
            @"
    RewriteCond %{REQUEST_METHOD} !^(GET|HEAD)$
    RewriteRule ^ - [F,L]
    RewriteCond %{HTTP_HOST} $capturePattern [NC]
    RewriteRule ^ https://%1:8443%{REQUEST_URI} [R=302,L]
"@
        }
        @"
<VirtualHost *:$port>
    ServerName $HostName
    ServerAlias $($hosts -join ' ')
    DocumentRoot "C:/xampp/htdocs/voucher-tracker"
    DirectoryIndex index.php
    RewriteEngine On
    RewriteCond expr "!(-R '127.0.0.0/8' || -R '::1' || -R '192.168.1.0/24' || -R '100.64.0.0/10')"
    RewriteRule ^ - [F,L]
    RewriteCond %{HTTP_HOST} !$hostPattern [NC]
    RewriteRule ^ - [F,L]
    RewriteRule "$deniedPaths" - [F,L,NC]
$transport
    <Directory "C:/xampp/htdocs/voucher-tracker">
        Options -Indexes -Includes -ExecCGI
        AllowOverride All
        <RequireAny>
            Require local
            Require ip 192.168.1.0/24
            Require ip 100.64.0.0/10
        </RequireAny>
    </Directory>
    <LocationMatch "(?i)$deniedPaths">
        Require all denied
    </LocationMatch>
    ErrorDocument 403 "Access denied. Use https://${HostName}:8443 on the office network or Tailscale."
    ErrorLog "logs/voucher-tracker-error.log"
    CustomLog "logs/voucher-tracker-access.log" common
</VirtualHost>
"@
    }
    $http = $blocks[0]
    $replaced = [regex]::Replace($Original, $pattern, [Text.RegularExpressions.MatchEvaluator]{ param($match) $http })
    return $replaced.TrimEnd() + "`r`n`r`nListen 0.0.0.0:8443 https`r`n" + $blocks[1] + "`r`n"
}

function Invoke-OfficeOpenSsl([string[]]$Arguments) {
    $ErrorActionPreference = 'Continue'
    $output = & $openssl @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) { throw "OpenSSL failed: $output" }
}

function New-OfficeHttpsEnvironment([string]$Original, [string]$HostName) {
    if ($HostName -notmatch '^[a-z0-9-]+$') { throw 'Unsafe hostname.' }
    $updated = $Original
    foreach ($setting in @(@{Key='APP_URL'; Value="https://${HostName}:8443"}, @{Key='SESSION_SECURE_COOKIE'; Value='true'})) {
        $pattern = '(?m)^' + $setting.Key + '=[^\r\n]*'
        $count = [regex]::Matches($updated, $pattern).Count
        if ($count -gt 1 -or ($setting.Key -eq 'APP_URL' -and $count -ne 1)) { throw "Expected one unambiguous $($setting.Key) dotenv setting." }
        $replacement = $setting.Key + '=' + $setting.Value
        if ($count -eq 0) { $updated = $updated.TrimEnd("`r", "`n") + "`r`n$replacement`r`n" } else { $updated = [regex]::Replace($updated, $pattern, $replacement) }
    }
    return $updated
}

function Update-OfficeConfigurationCache([bool]$WasCached) {
    $command = if ($WasCached) { 'config:cache' } else { 'config:clear' }
    Push-Location -LiteralPath $PSScriptRoot
    try {
        & 'C:\xampp\php\php.exe' artisan $command --no-interaction --quiet
        if ($LASTEXITCODE -ne 0) { throw 'Laravel configuration refresh failed.' }
    } finally {
        Pop-Location
    }
}

function Set-OfficePrivateAcl([string]$Path, [string]$Reader) {
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner((New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')))
    foreach ($sid in @('S-1-5-18', 'S-1-5-32-544')) {
        $identity = New-Object Security.Principal.SecurityIdentifier($sid)
        $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
    }
    if ($Reader) {
        $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($Reader, 'ReadAndExecute', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Set-OfficeAdministratorsOwner([string]$Path) {
    $acl = Get-Acl -LiteralPath $Path
    $acl.SetOwner((New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')))
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function New-OfficePrivateFile([string]$Path) {
    New-Item -ItemType File -Path $Path | Out-Null
    Set-OfficeAdministratorsOwner -Path $Path
}

function Assert-OfficePrivateAcl([string]$Path, [string]$Reader) {
    $acl = Get-Acl -LiteralPath $Path
    if ($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne 'S-1-5-32-544') { throw "Private path is not owned by Administrators: $Path" }
    $allowed = @('S-1-5-18', 'S-1-5-32-544')
    $readerSid = if ($Reader) { (New-Object Security.Principal.NTAccount($Reader)).Translate([Security.Principal.SecurityIdentifier]).Value } else { $null }
    if ($readerSid) { $allowed += $readerSid }
    foreach ($rule in $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
        if ($rule.AccessControlType -ne 'Allow') { continue }
        if ($rule.IdentityReference.Value -notin $allowed) { throw "Unexpected account has access to private path: $Path" }
        if ($rule.IdentityReference.Value -eq $readerSid -and $readerSid -notin @('S-1-5-18', 'S-1-5-32-544')) {
            $writeRights = [Security.AccessControl.FileSystemRights]::Write -bor [Security.AccessControl.FileSystemRights]::Delete -bor [Security.AccessControl.FileSystemRights]::ChangePermissions -bor [Security.AccessControl.FileSystemRights]::TakeOwnership
            if ($rule.FileSystemRights -band $writeRights) { throw "Apache has write access to private server material: $Path" }
        }
    }
}

function Write-OfficeHttpsResult([string]$Status, [string]$Message) {
    $result = [ordered]@{Status=$Status; Message=$Message; UpdatedAt=(Get-Date -Format o); Deployment=$deployment; ClientZip=$zipDestination; CaSha256=$fingerprint; RollbackSucceeded=$rollbackSucceeded}
    [IO.File]::WriteAllText((Join-Path $PSScriptRoot 'storage\logs\office-https-result.json'), ($result | ConvertTo-Json))
}

function Restart-OfficeApache {
    Stop-ScheduledTask -TaskName $taskName
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if (-not (Get-NetTCPConnection -State Listen -LocalPort 8080 -ErrorAction SilentlyContinue)) { break }
        Start-Sleep -Milliseconds 500
    }
    if (Get-NetTCPConnection -State Listen -LocalPort 8080 -ErrorAction SilentlyContinue) { throw 'Apache did not stop; no processes were force-killed.' }
    Start-ScheduledTask -TaskName $taskName
}

function Restore-OfficeHttps([string]$Directory) {
    $resolved = [IO.Path]::GetFullPath($Directory).TrimEnd('\')
    if ((Split-Path $resolved -Parent) -ne $privateRoot -or (Split-Path $resolved -Leaf) -notmatch '^deployment-\d{8}-\d{6}-[a-f0-9]{8}$') {
        throw 'Rollback archive must be an HTTPS deployment immediately inside the protected private root.'
    }
    $state = Get-Content -LiteralPath (Join-Path $resolved 'state.json') -Raw | ConvertFrom-Json
    if ($state.ConfigChanged) {
        Copy-Item -LiteralPath (Join-Path $resolved 'httpd-vhosts.conf') -Destination $vhostsPath -Force
        & $apache -t
        if ($LASTEXITCODE -ne 0) { throw 'Restored Apache configuration failed syntax validation. Do not restart it.' }
    }
    if ($state.EnvironmentChanged) {
        Copy-Item -LiteralPath (Join-Path $resolved '.env') -Destination $environmentPath -Force
        Update-OfficeConfigurationCache -WasCached $state.WasConfigCached
    }
    foreach ($rule in @($state.FirewallRules)) {
        if (Get-NetFirewallRule -Name $rule -ErrorAction SilentlyContinue) { Remove-NetFirewallRule -Name $rule }
    }
    if ($state.ImportedRoot -and $state.CaThumbprint) {
        $certificatePath = 'Cert:\LocalMachine\Root\' + $state.CaThumbprint
        if (Test-Path -LiteralPath $certificatePath) { Remove-Item -LiteralPath $certificatePath }
    }
    if ($state.RestartAttempted) { Restart-OfficeApache }
    Write-Host "HTTPS configuration rolled back. Protected certificate material retained at $resolved for review; remove distributed client trust separately."
}

if ($MyInvocation.InvocationName -eq '.') { return }

$deployment = $null
$zipDestination = $null
$fingerprint = $null
$rollbackSucceeded = $null
trap {
    if ($Mode -eq 'Apply') { Write-OfficeHttpsResult -Status 'failed' -Message $_.Exception.Message }
    throw
}

if ($Mode -ne 'Preview') {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Apply and Rollback require an elevated Windows PowerShell window.' }
}
if ($Mode -eq 'Rollback') {
    if (-not $Archive) { throw 'Provide the protected deployment directory printed by Apply using -Archive.' }
    $deployment = $Archive
    Restore-OfficeHttps -Directory $Archive
    Write-OfficeHttpsResult -Status 'rolled-back' -Message 'Previous Apache configuration, application environment, and configuration cache mode restored; remove client trust separately.'
    return
}
if ($Mode -eq 'Apply') { Write-OfficeHttpsResult -Status 'starting' -Message 'Validating the reviewed server before changing HTTPS configuration.' }

$addresses = @([Net.NetworkInformation.NetworkInterface]::GetAllNetworkInterfaces() | Where-Object { $_.OperationalStatus -eq 'Up' } | ForEach-Object {
    $_.GetIPProperties().UnicastAddresses | Where-Object { $_.Address.AddressFamily -eq 'InterNetwork' } | ForEach-Object { $_.Address.IPAddressToString }
})
foreach ($expected in @('192.168.1.30', '100.99.218.62')) {
    if ($addresses -notcontains $expected) { throw "Expected address $expected is absent. Review DHCP/Tailscale addresses and reissue the certificate before proceeding." }
}
$addresses = @('192.168.1.30', '100.99.218.62')
if ($env:COMPUTERNAME.ToLowerInvariant() -ne $hostName) { throw 'This setup is restricted to the reviewed office server.' }
$main = [IO.File]::ReadAllText($mainPath)
$ssl = [IO.File]::ReadAllText($sslPath)
if ($ssl -notmatch '(?m)^Listen 127\.0\.0\.1:443\s*$' -or $main -notmatch '(?m)^Listen 127\.0\.0\.1:80\s*$' -or $main -notmatch '(?m)^Listen 0\.0\.0\.0:8080\s*$') {
    throw 'Expected local-only XAMPP and office 8080 listeners changed. Review configuration first.'
}
if ($main -notmatch '(?m)^Include conf/extra/httpd-vhosts.conf\s*$' -or $main -match '(?m)^\s*Listen\s+.*8443') { throw 'Unexpected main Apache configuration.' }
$original = [IO.File]::ReadAllText($vhostsPath)
if ($Mode -eq 'Preview') {
    New-OfficeHttpsConfiguration -Original $original -HostName $hostName -Addresses $addresses -Certificate "$privateRoot\deployment-REVIEW\server\server.pem" -Key "$privateRoot\deployment-REVIEW\server\server.key"
    Write-Host 'Preview only: no certificates, trust, firewall, configuration, tasks, or application environment changed.'
    return
}

$task = Get-ScheduledTask -TaskName $taskName
if ($task.State -ne 'Running' -or $task.Actions.Count -ne 1 -or $task.Actions[0].Execute -ne $apache) { throw 'Expected running Apache scheduled task changed.' }
$apacheAccount = $task.Principal.UserId
if (-not $apacheAccount) { throw 'Cannot determine the Apache account for the private server key ACL.' }
$sourceRules = @(Get-NetFirewallRule -Direction Inbound -Enabled True -Action Allow | Where-Object {
    $application = $_ | Get-NetFirewallApplicationFilter
    $ports = $_ | Get-NetFirewallPortFilter
    $application.Program -eq $apache -and $ports.Protocol -in @('TCP', '6') -and @($ports.LocalPort).Count -eq 1 -and $ports.LocalPort -eq '8080'
})
if ($sourceRules.Count -eq 0) { throw 'No existing Apache-only 8080 firewall allow rules were found to mirror.' }
foreach ($rule in $sourceRules) {
    $remote = @(($rule | Get-NetFirewallAddressFilter).RemoteAddress)
    if ($remote.Count -eq 0 -or @($remote | Where-Object { $_ -notin @('192.168.1.0/255.255.255.0', '192.168.1.0/24', '100.64.0.0/255.192.0.0', '100.64.0.0/10') }).Count) {
        throw "Existing firewall rule $($rule.Name) is broader than the reviewed office/Tailscale ranges."
    }
}
if (Get-NetFirewallRule -Name 'VoucherTracker-HTTPS-*' -ErrorAction SilentlyContinue) { throw 'HTTPS firewall rules already exist; review instead of overwriting them.' }
& $apache -t
if ($LASTEXITCODE -ne 0) { throw 'Existing Apache configuration is invalid.' }
if (-not (Test-Path -LiteralPath $privateRoot)) { New-Item -ItemType Directory -Path $privateRoot | Out-Null }
if ((Get-Item -LiteralPath $privateRoot -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'The private certificate root must not be a junction or symbolic link.' }
Set-OfficePrivateAcl -Path $privateRoot
$deployment = Join-Path $privateRoot ('deployment-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Path $deployment | Out-Null
Set-OfficePrivateAcl -Path $deployment
$issuer = New-Item -ItemType Directory -Path (Join-Path $deployment 'issuer')
$server = New-Item -ItemType Directory -Path (Join-Path $deployment 'server')
Set-OfficePrivateAcl -Path $issuer.FullName
Set-OfficePrivateAcl -Path $server.FullName -Reader $apacheAccount
foreach ($directory in @($privateRoot, $deployment)) {
    $acl = Get-Acl -LiteralPath $directory
    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($apacheAccount, 'Traverse', 'None', 'None', 'Allow')))
    Set-Acl -LiteralPath $directory -AclObject $acl
}
Copy-Item -LiteralPath $vhostsPath -Destination (Join-Path $deployment 'httpd-vhosts.conf')
$environmentBackup = Join-Path $deployment '.env'
New-OfficePrivateFile -Path $environmentBackup
[IO.File]::WriteAllBytes($environmentBackup, [IO.File]::ReadAllBytes($environmentPath))
$httpsEnvironment = New-OfficeHttpsEnvironment -Original ([IO.File]::ReadAllText($environmentPath)) -HostName $hostName
$statePath = Join-Path $deployment 'state.json'
$state = [ordered]@{ConfigChanged=$false; EnvironmentChanged=$false; WasConfigCached=(Test-Path -LiteralPath (Join-Path $PSScriptRoot 'bootstrap\cache\config.php')); RestartAttempted=$false; ImportedRoot=$false; CaThumbprint=$null; FirewallRules=@(); HostName=$hostName; Addresses=$addresses; ApacheAccount=$apacheAccount}
function Save-OfficeHttpsState {
    $state | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $statePath -Encoding UTF8
    Set-OfficeAdministratorsOwner -Path $statePath
}
Save-OfficeHttpsState
try {
    $caKey = Join-Path $issuer.FullName 'root.key'
    $caPem = Join-Path $issuer.FullName 'root.pem'
    $serverKey = Join-Path $server.FullName 'server.key'
    $serverPem = Join-Path $server.FullName 'server.pem'
    $request = Join-Path $server.FullName 'server.csr'
    $extensions = Join-Path $server.FullName 'server-extensions.cnf'
    New-OfficePrivateFile -Path $caKey
    New-OfficePrivateFile -Path $serverKey
    @"
[server]
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:$hostName,DNS:localhost,IP:127.0.0.1,IP:$($addresses[0]),IP:$($addresses[1])
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid,issuer
"@ | Set-Content -LiteralPath $extensions -Encoding ASCII
    Invoke-OfficeOpenSsl -Arguments @('req', '-x509', '-newkey', 'rsa:4096', '-nodes', '-sha256', '-days', '3650', '-config', 'C:\xampp\apache\conf\openssl.cnf', '-subj', '/CN=Voucher Tracker Office Root CA', '-addext', 'basicConstraints=critical,CA:TRUE,pathlen:0', '-addext', 'keyUsage=critical,keyCertSign,cRLSign', '-keyout', $caKey, '-out', $caPem)
    Invoke-OfficeOpenSsl -Arguments @('req', '-new', '-newkey', 'rsa:3072', '-nodes', '-sha256', '-config', 'C:\xampp\apache\conf\openssl.cnf', '-subj', "/CN=$hostName", '-keyout', $serverKey, '-out', $request)
    Invoke-OfficeOpenSsl -Arguments @('x509', '-req', '-in', $request, '-CA', $caPem, '-CAkey', $caKey, '-CAcreateserial', '-days', '365', '-sha256', '-extfile', $extensions, '-extensions', 'server', '-out', $serverPem)
    Invoke-OfficeOpenSsl -Arguments @('verify', '-CAfile', $caPem, '-purpose', 'sslserver', '-verify_hostname', $hostName, $serverPem)
    foreach ($address in @('127.0.0.1') + $addresses) { Invoke-OfficeOpenSsl -Arguments @('verify', '-CAfile', $caPem, '-verify_ip', $address, $serverPem) }
    $package = New-Item -ItemType Directory -Path (Join-Path $deployment 'client-package')
    $publicCa = Join-Path $package.FullName 'Office Voucher Root CA.cer'
    Invoke-OfficeOpenSsl -Arguments @('x509', '-in', $caPem, '-outform', 'DER', '-out', $publicCa)
    $fingerprint = (Get-FileHash -LiteralPath $publicCa -Algorithm SHA256).Hash
    $installer = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'Install office certificate.ps1')).Replace('__OFFICE_CA_SHA256__', $fingerprint)
    [IO.File]::WriteAllText((Join-Path $package.FullName 'Install office certificate.ps1'), $installer)
    '@echo off', 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install office certificate.ps1"', 'pause' | Set-Content -LiteralPath (Join-Path $package.FullName 'Install certificate.cmd') -Encoding ASCII
    $zip = Join-Path $deployment 'Office HTTPS certificate installer.zip'
    Compress-Archive -LiteralPath @($publicCa, (Join-Path $package.FullName 'Install office certificate.ps1'), (Join-Path $package.FullName 'Install certificate.cmd')) -DestinationPath $zip
    foreach ($item in @(Get-ChildItem -LiteralPath $deployment -Recurse -Force)) { Set-OfficeAdministratorsOwner -Path $item.FullName }
    foreach ($path in @($issuer.FullName, $caKey, $environmentBackup)) { Assert-OfficePrivateAcl -Path $path }
    Assert-OfficePrivateAcl -Path $serverKey -Reader $apacheAccount
    $certificate = New-Object Security.Cryptography.X509Certificates.X509Certificate2($publicCa)
    $state.CaThumbprint = $certificate.Thumbprint
    $state.CaSha256 = $fingerprint
    Save-OfficeHttpsState
    $rendered = New-OfficeHttpsConfiguration -Original $original -HostName $hostName -Addresses $addresses -Certificate $serverPem -Key $serverKey
    $state.ConfigChanged = $true
    Save-OfficeHttpsState
    [IO.File]::WriteAllText($vhostsPath, $rendered)
    & $apache -t
    if ($LASTEXITCODE -ne 0) { throw 'Generated Apache configuration is invalid.' }
    $index = 0
    foreach ($rule in $sourceRules) {
        $index++
        $name = "VoucherTracker-HTTPS-$index"
        $addressFilter = $rule | Get-NetFirewallAddressFilter
        $interfaceFilter = $rule | Get-NetFirewallInterfaceFilter
        $state.FirewallRules += $name
        Save-OfficeHttpsState
        New-NetFirewallRule -Name $name -DisplayName ('Voucher Tracker HTTPS - ' + $rule.DisplayName) -Direction Inbound -Action Allow -Enabled True -Profile $rule.Profile -Protocol TCP -LocalPort 8443 -RemoteAddress $addressFilter.RemoteAddress -LocalAddress $addressFilter.LocalAddress -InterfaceAlias $interfaceFilter.InterfaceAlias -Program $apache -EdgeTraversalPolicy Block | Out-Null
    }
    if (Test-Path -LiteralPath ('Cert:\LocalMachine\Root\' + $certificate.Thumbprint)) { throw 'New CA unexpectedly already exists in machine trust.' }
    $state.ImportedRoot = $true
    Save-OfficeHttpsState
    Import-Certificate -FilePath $publicCa -CertStoreLocation 'Cert:\LocalMachine\Root' | Out-Null
    $state.RestartAttempted = $true
    Save-OfficeHttpsState
    Restart-OfficeApache
    $healthy = $false
    $previousProtocol = [Net.ServicePointManager]::SecurityProtocol
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        for ($attempt = 0; $attempt -lt 20; $attempt++) {
            Start-Sleep -Seconds 1
            try {
                $response = Invoke-WebRequest -Uri "https://${hostName}:8443/login" -UseBasicParsing -TimeoutSec 3
                if ($response.StatusCode -eq 200) { $healthy = $true; break }
            } catch { }
        }
    } finally {
        [Net.ServicePointManager]::SecurityProtocol = $previousProtocol
    }
    if (-not $healthy) { throw 'Trusted HTTPS health check failed.' }
    $state.EnvironmentChanged = $true
    Save-OfficeHttpsState
    [IO.File]::WriteAllText($environmentPath, $httpsEnvironment)
    Update-OfficeConfigurationCache -WasCached $state.WasConfigCached
    $previousProtocol = [Net.ServicePointManager]::SecurityProtocol
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $response = Invoke-WebRequest -Uri "https://${hostName}:8443/login" -UseBasicParsing -TimeoutSec 5
        if ($response.StatusCode -ne 200 -or $response.Headers['Set-Cookie'] -notmatch '(?i);\s*secure(?:;|,|$)') { throw 'HTTPS did not issue a Secure session cookie after activation.' }
    } finally {
        [Net.ServicePointManager]::SecurityProtocol = $previousProtocol
    }
    $zipDestination = Join-Path $PSScriptRoot '.project-backups\Office HTTPS certificate installer.zip'
    Copy-Item -LiteralPath $zip -Destination $zipDestination -Force
    Write-OfficeHttpsResult -Status 'configured' -Message 'Trusted HTTPS, restricted firewall rules, and secure session cookies validated; public client installer ZIP is ready.'
    Write-Host "HTTPS ready: https://${hostName}:8443"
    Write-Host "CA SHA-256: $fingerprint"
    Write-Host "Certificate expires: $((New-Object Security.Cryptography.X509Certificates.X509Certificate2($serverPem)).NotAfter)"
    Write-Host "Client installer ZIP (public CA only): $zipDestination"
    Write-Host "Rollback: powershell -NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Mode Rollback -Archive `"$deployment`""
    Write-Host 'APP_URL and secure session cookies activated; the prior configuration-cache mode was preserved. Existing database sessions and pending uploads were retained.'
} catch {
    $failure = $_
    try {
        Restore-OfficeHttps -Directory $deployment
        $rollbackSucceeded = $true
    } catch {
        $rollbackSucceeded = $false
        Write-Warning "Automatic rollback needs administrator review: $_"
    }
    Write-Warning "Protected deployment archive: $deployment"
    throw $failure
}
