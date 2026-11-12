$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$setup = Join-Path $project 'Set up Tailscale HTTPS.ps1'

function Assert-Tailscale([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

if (Test-Path -LiteralPath $setup) { . $setup }
Assert-Tailscale ([bool](Get-Command New-TailscaleHttpsConfiguration -ErrorAction SilentlyContinue)) 'The Tailscale HTTPS configuration builder is missing.'

$hostname = 'voucher-server.tail123456.ts.net'
$main = "#LoadModule remoteip_module modules/mod_remoteip.so`r`nListen 127.0.0.1:80`r`n"
$officeHttp = @'
<VirtualHost *:8080>
    ServerName office-server
    RewriteRule ^ https://office-server:8443%{REQUEST_URI} [R=302,L]
</VirtualHost>
'@
$officeHttps = @'
<VirtualHost *:8443>
    ServerName office-server
    ServerAlias localhost 127.0.0.1 192.168.1.30 100.99.218.62
    RewriteCond expr "!(-R '127.0.0.0/8' || -R '::1' || -R '192.168.1.0/24' || -R '100.64.0.0/10')"
    RewriteRule ^ - [F,L]
    RewriteCond %{HTTP_HOST} !^(?:office-server|localhost|127\.0\.0\.1|192\.168\.1\.30|100\.99\.218\.62)(?::8443)?$ [NC]
    RewriteRule ^ - [F,L]
    SSLEngine on
    <Directory "C:/example/public">
        Require ip 100.64.0.0/10
    </Directory>
</VirtualHost>
'@
$original = $officeHttp + "`r`n" + $officeHttps
$result = New-TailscaleHttpsConfiguration -Main $main -Vhosts $original -HostName $hostname
Assert-Tailscale ($result.Vhosts.StartsWith($officeHttp)) 'Existing office HTTP configuration changed.'
Assert-Tailscale ($result.Main.Contains('LoadModule remoteip_module modules/mod_remoteip.so') -and -not $result.Main.Contains('#LoadModule remoteip_module')) 'Client address module is not enabled.'
$hostRule = [regex]::Match($result.Vhosts, '(?m)^\s*RewriteCond %\{HTTP_HOST\} !(\^[^\r\n]+\$) \[NC\]').Groups[1].Value
foreach ($allowed in @($hostname, "${hostname}:443", 'office-server:8443', '192.168.1.30:8443', '100.99.218.62:8443', 'localhost:8443')) {
    Assert-Tailscale ($allowed -match $hostRule) "Trusted hostname rejected: $allowed"
}
foreach ($denied in @('evil.example', "$hostname.evil", "evil.$hostname", "${hostname}:8080", "${hostname}:8443", "${hostname}@evil.example")) {
    Assert-Tailscale ($denied -notmatch $hostRule) "Untrusted hostname accepted: $denied"
}
Assert-Tailscale ($result.Vhosts.Contains('RemoteIPHeader X-Forwarded-For')) 'Client addresses are lost behind the proxy.'
Assert-Tailscale ($result.Vhosts.Contains('RemoteIPInternalProxy 127.0.0.1 ::1')) 'Proxy trust must be limited to loopback.'
Assert-Tailscale ($result.Vhosts.Contains("-R 'fd7a:115c:a1e0::/48'") -and $result.Vhosts.Contains('Require ip fd7a:115c:a1e0::/48')) 'Tailscale IPv6 clients are blocked.'
$again = New-TailscaleHttpsConfiguration -Main $result.Main -Vhosts $result.Vhosts -HostName $hostname
Assert-Tailscale ($again.Main -eq $result.Main -and $again.Vhosts -eq $result.Vhosts) 'Running setup twice changes configuration again.'
foreach ($invalid in @('evil.example', "bad`r`nRequire all granted", '*.tail123456.ts.net')) {
    $rejected = $false
    try { New-TailscaleHttpsConfiguration -Main $main -Vhosts $original -HostName $invalid | Out-Null } catch { $rejected = $true }
    Assert-Tailscale $rejected 'Unsafe Tailscale hostname accepted.'
}
$rejected = $false
try { New-TailscaleHttpsConfiguration -Main $main -Vhosts $officeHttp -HostName $hostname | Out-Null } catch { $rejected = $true }
Assert-Tailscale $rejected 'Missing HTTPS virtual host must fail before writing configuration.'
Assert-Tailscale ([bool](Get-Command Wait-TailscaleBackend -ErrorAction SilentlyContinue)) 'Setup needs a bounded backend health check before reporting success.'
$script:healthAttempts = 0
function Invoke-WebRequest {
    param([string]$Uri, [int]$TimeoutSec, [switch]$UseBasicParsing)
    Assert-Tailscale ($Uri -eq 'https://localhost:8443/up') 'Backend health check must verify the existing local HTTPS service.'
    $script:healthAttempts++
    if ($script:healthAttempts -eq 1) { throw 'Backend is still starting.' }
    return [pscustomobject]@{StatusCode=200}
}
function Start-Sleep { param([int]$Milliseconds) }
Wait-TailscaleBackend -Attempts 2
Assert-Tailscale ($script:healthAttempts -eq 2) 'Health check must wait for successful startup.'
function Invoke-WebRequest {
    param([string]$Uri, [int]$TimeoutSec, [switch]$UseBasicParsing)
    return [pscustomobject]@{StatusCode=503}
}
$rejected = $false
try { Wait-TailscaleBackend -Attempts 2 } catch { $rejected = $true }
Assert-Tailscale $rejected 'A failed startup must trigger rollback instead of reporting success.'
'Tailscale HTTPS configuration tests passed.'
