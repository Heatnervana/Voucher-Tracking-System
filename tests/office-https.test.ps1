$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$setup = Join-Path $root 'Set up office HTTPS.ps1'
if (-not (Test-Path -LiteralPath $setup)) { throw 'HTTPS setup script is missing.' }
. $setup

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

$fixture = Join-Path ([IO.Path]::GetTempPath()) ('voucher-https-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixture | Out-Null
try {
    $openssl = 'C:\xampp\apache\bin\openssl.exe'
    $certificate = Join-Path $fixture 'leaf.pem'
    $key = Join-Path $fixture 'leaf.key'
    Invoke-OfficeOpenSsl -Arguments @('req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '1', '-config', 'C:\xampp\apache\conf\openssl.cnf', '-subj', '/CN=HTTPS test fixture', '-keyout', $key, '-out', $certificate)
    $original = [IO.File]::ReadAllText('C:\xampp\apache\conf\extra\httpd-vhosts.conf')
    $rendered = New-OfficeHttpsConfiguration -Original $original -HostName 'pmo-moz-asststa' -Addresses @('192.168.1.30', '100.99.218.62') -Certificate $certificate -Key $key
    Assert-True ($rendered -match 'Listen 0\.0\.0\.0:8443 https') 'Dedicated HTTPS listener missing.'
    Assert-True ($rendered -match 'SSLProtocol -all \+TLSv1.2 \+TLSv1.3') 'Old TLS protocols are not disabled.'
    Assert-True ($rendered -notmatch 'Listen.*:443\b') 'Local XAMPP 443 must remain untouched.'
    Assert-True ([regex]::Matches($rendered, 'Require ip 100.64.0.0/10').Count -eq 2) 'HTTP and HTTPS must preserve Tailscale authorization.'
    Assert-True ($rendered -match 'https://%1:8443%\{REQUEST_URI\}') 'Redirect must preserve the captured whitelisted host.'
    $hostPattern = Get-OfficeHostPattern -HostName 'pmo-moz-asststa' -Addresses @('192.168.1.30', '100.99.218.62') -Port 8080
    foreach ($hostValue in @('pmo-moz-asststa:8080', 'localhost', '192.168.1.30:8080', '100.99.218.62:8080', '127.0.0.1')) {
        Assert-True ($hostValue -match $hostPattern) "Trusted host rejected: $hostValue"
    }
    foreach ($hostValue in @('evil.example', 'pmo-moz-asststa.evil', 'localhost:8443', '192x168x1x30', 'evil@localhost')) {
        Assert-True ($hostValue -notmatch $hostPattern) "Untrusted host accepted: $hostValue"
    }
    $capturePattern = $hostPattern.Replace('^(?:', '^(')
    foreach ($hostValue in @('192.168.1.30:8080', '100.99.218.62:8080', 'pmo-moz-asststa:8080')) {
        Assert-True ($hostValue -match $capturePattern) 'Redirect capture rejected a trusted host.'
        Assert-True ($matches[1] -eq $hostValue.Split(':')[0]) 'Redirect must preserve the selected IP or hostname without the HTTP port.'
    }
    $environment = "APP_KEY=preserve-this-key`r`nAPP_URL=http://localhost:8080`r`nSESSION_SECURE_COOKIE=false`r`n"
    $httpsEnvironment = New-OfficeHttpsEnvironment -Original $environment -HostName 'pmo-moz-asststa'
    Assert-True ($httpsEnvironment -match '(?m)^APP_URL=https://pmo-moz-asststa:8443\r?$') 'HTTPS activation must set the application URL.'
    Assert-True ($httpsEnvironment -match '(?m)^SESSION_SECURE_COOKIE=true\r?$') 'HTTPS activation must require secure session cookies.'
    Assert-True ($httpsEnvironment.Contains('APP_KEY=preserve-this-key')) 'HTTPS activation must preserve application secrets.'
    $httpsEnvironment = New-OfficeHttpsEnvironment -Original "APP_URL=http://localhost`nSESSION_DOMAIN=null" -HostName 'pmo-moz-asststa'
    Assert-True ($httpsEnvironment -match '(?m)^SESSION_SECURE_COOKIE=true\r?$') 'HTTPS activation must add the secure cookie setting if absent.'
    Assert-True ($httpsEnvironment.Contains('SESSION_DOMAIN=null')) 'HTTPS activation must preserve host-only cookies.'
    $failed = $false
    try { New-OfficeHttpsEnvironment -Original "APP_URL=http://one`nAPP_URL=http://two" -HostName 'pmo-moz-asststa' | Out-Null } catch { $failed = $true }
    Assert-True $failed 'Duplicate dotenv keys must fail closed.'
    Assert-True ($rendered -match 'RewriteCond %\{REQUEST_METHOD\} !\^\(GET\|HEAD\)\$\s+RewriteRule \^ - \[F,L\]') 'Plain HTTP must reject state-changing requests before redirecting.'
    $failed = $false
    try { New-OfficeHttpsConfiguration -Original '<VirtualHost *:9000></VirtualHost>' -HostName 'pmo-moz-asststa' -Addresses @('192.168.1.30') -Certificate $certificate -Key $key | Out-Null } catch { $failed = $true }
    Assert-True $failed 'Unexpected existing configuration must fail closed.'
    $vhosts = Join-Path $fixture 'vhosts.conf'
    [IO.File]::WriteAllText($vhosts, $rendered)
    & 'C:\xampp\apache\bin\httpd.exe' -t -c ('Include "' + $vhosts.Replace('\', '/') + '"')
    Assert-True ($LASTEXITCODE -eq 0) 'Generated configuration failed the installed Apache parser.'
    $template = [IO.File]::ReadAllText((Join-Path $root 'Install office certificate.ps1'))
    Assert-True ($template -match 'Cert:\\CurrentUser\\Root') 'Client trust must be scoped to the signed-in user.'
    Assert-True ($template -notmatch 'Cert:\\LocalMachine') 'Client installer must not alter machine trust.'
    $publicCa = Join-Path $fixture 'Office Voucher Root CA.cer'
    Invoke-OfficeOpenSsl -Arguments @('x509', '-in', $certificate, '-outform', 'DER', '-out', $publicCa)
    $fingerprint = (Get-FileHash -LiteralPath $publicCa -Algorithm SHA256).Hash
    $installerPath = Join-Path $fixture 'Install office certificate.ps1'
    [IO.File]::WriteAllText($installerPath, $template.Replace('__OFFICE_CA_SHA256__', $fingerprint))
    & $installerPath -ValidateOnly
    [IO.File]::WriteAllText($installerPath, $template.Replace('__OFFICE_CA_SHA256__', ('0' * 64)))
    $failed = $false
    try { & $installerPath -ValidateOnly } catch { $failed = $_.Exception.Message -match 'fingerprint mismatch' }
    Assert-True $failed 'The installer must reject a mismatched certificate before writing trust.'
    Write-Host 'PASS: HTTPS rendering, hostname boundaries, fail-closed configuration, current-user installer scope, and installed Apache syntax.'
} finally {
    $resolved = [IO.Path]::GetFullPath($fixture)
    $allowed = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
    if (-not $resolved.StartsWith($allowed, [StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolved -Leaf) -notmatch '^voucher-https-test-[a-f0-9]{32}$') { throw 'Unsafe fixture cleanup path.' }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}
