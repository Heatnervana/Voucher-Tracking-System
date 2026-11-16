param(
    [ValidateSet('Preview', 'Apply')]
    [string]$Mode = 'Preview',
    [string]$HostName
)

$ErrorActionPreference = 'Stop'

function New-TailscaleHttpsConfiguration([string]$Main, [string]$Vhosts, [string]$HostName) {
    if ($HostName -notmatch '^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.[a-z0-9-]+\.ts\.net$') {
        throw 'Expected this server''s full Tailscale hostname.'
    }
    $modulePattern = '(?m)^#?LoadModule remoteip_module modules/mod_remoteip\.so\r?$'
    if ([regex]::Matches($Main, $modulePattern).Count -ne 1) { throw 'Expected one remoteip module declaration.' }
    $updatedMain = [regex]::Replace($Main, $modulePattern, 'LoadModule remoteip_module modules/mod_remoteip.so')
    $httpsPattern = '(?s)<VirtualHost \*:8443>.*?</VirtualHost>'
    $httpsHosts = [regex]::Matches($Vhosts, $httpsPattern)
    if ($httpsHosts.Count -ne 1) { throw 'Expected one existing office HTTPS virtual host.' }
    $originalHost = $httpsHosts[0].Value
    $updatedHost = $originalHost
    $hostRules = [regex]::Matches($updatedHost, '(?m)^[ \t]*RewriteCond %\{HTTP_HOST\} !(\^[^\r\n]+\$) \[NC\]\r?$')
    $aliases = [regex]::Matches($updatedHost, '(?m)^[ \t]*ServerAlias [^\r\n]+')
    if ($hostRules.Count -ne 1 -or $aliases.Count -ne 1) { throw 'Expected the reviewed office hostname allowlist.' }
    $escapedHostName = [regex]::Escape($HostName)
    $existingPattern = $hostRules[0].Groups[1].Value
    if (-not $existingPattern.Contains($escapedHostName)) {
        $updatedPattern = '^(?:' + $existingPattern.Substring(1, $existingPattern.Length - 2) + '|' + $escapedHostName + '(?::443)?)$'
        $updatedHost = $updatedHost.Replace($hostRules[0].Value, $hostRules[0].Value.Replace($existingPattern, $updatedPattern))
    }
    if (($aliases[0].Value.Trim() -split '\s+') -notcontains $HostName) {
        $updatedHost = $updatedHost.Replace($aliases[0].Value, $aliases[0].Value + ' ' + $HostName)
    }
    if ($updatedHost -match '(?m)^\s*RemoteIP') {
        if ($updatedHost -notmatch '(?m)^    RemoteIPHeader X-Forwarded-For\r?$' -or $updatedHost -notmatch '(?m)^    RemoteIPInternalProxy 127\.0\.0\.1 ::1\r?$') {
            throw 'Existing proxy trust needs review.'
        }
    } else {
        if ($updatedHost -notmatch '(?m)^    SSLEngine on\r?$') { throw 'The office backend must use HTTPS.' }
        $updatedHost = $updatedHost.Replace('    SSLEngine on', "    RemoteIPHeader X-Forwarded-For`r`n    RemoteIPInternalProxy 127.0.0.1 ::1`r`n    SSLEngine on")
    }
    if (-not $updatedHost.Contains("-R 'fd7a:115c:a1e0::/48'")) {
        if (-not $updatedHost.Contains("|| -R '100.64.0.0/10'")) { throw 'Expected Tailscale source restrictions.' }
        $updatedHost = $updatedHost.Replace("|| -R '100.64.0.0/10'", "|| -R '100.64.0.0/10' || -R 'fd7a:115c:a1e0::/48'")
    }
    if (-not $updatedHost.Contains('Require ip fd7a:115c:a1e0::/48')) {
        if (-not $updatedHost.Contains('Require ip 100.64.0.0/10')) { throw 'Expected directory access restrictions.' }
        $updatedHost = $updatedHost.Replace('Require ip 100.64.0.0/10', "Require ip 100.64.0.0/10`r`n            Require ip fd7a:115c:a1e0::/48")
    }
    return [pscustomobject]@{Main=$updatedMain; Vhosts=$Vhosts.Replace($originalHost, $updatedHost)}
}

function Wait-TailscaleBackend([int]$Attempts = 20) {
    for ($attempt = 0; $attempt -lt $Attempts; $attempt++) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri 'https://localhost:8443/up' -TimeoutSec 2
            if ($response.StatusCode -eq 200) { return }
        } catch {}
        if ($attempt -lt $Attempts - 1) { Start-Sleep -Milliseconds 500 }
    }
    throw 'Apache did not return a successful HTTPS health response after restart.'
}

if ($MyInvocation.InvocationName -eq '.') { return }

$resultPath = Join-Path $PSScriptRoot 'storage/logs/tailscale-https-result.json'
$archive = $null
try {
    if ($Mode -eq 'Apply' -and -not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Apply requires an elevated Windows PowerShell window.'
    }
    $tailscale = 'C:\Program Files\Tailscale\tailscale.exe'
    $status = & $tailscale status --json | ConvertFrom-Json
    if ($LASTEXITCODE -ne 0 -or $status.BackendState -ne 'Running') { throw 'Tailscale must be connected.' }
    $serverHostName = $status.Self.DNSName.TrimEnd('.')
    if ($HostName -and $HostName -ne $serverHostName) { throw 'Hostname differs from this server''s Tailscale identity.' }
    $HostName = $serverHostName
    $mainPath = 'C:\xampp\apache\conf\httpd.conf'
    $vhostsPath = 'C:\xampp\apache\conf\extra\httpd-vhosts.conf'
    $main = [IO.File]::ReadAllText($mainPath)
    $vhosts = [IO.File]::ReadAllText($vhostsPath)
    $configuration = New-TailscaleHttpsConfiguration -Main $main -Vhosts $vhosts -HostName $HostName
    if ($Mode -eq 'Preview') { $configuration; return }

    $archive = Join-Path $PSScriptRoot ('.project-backups/tailscale-https-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Path $archive | Out-Null
    Copy-Item -LiteralPath $mainPath -Destination (Join-Path $archive 'httpd.conf')
    Copy-Item -LiteralPath $vhostsPath -Destination (Join-Path $archive 'httpd-vhosts.conf')
    $restartAttempted = $false
    try {
        [IO.File]::WriteAllText($mainPath, $configuration.Main)
        [IO.File]::WriteAllText($vhostsPath, $configuration.Vhosts)
        $ErrorActionPreference = 'Continue'
        $validation = & 'C:\xampp\apache\bin\httpd.exe' -t 2>&1 | Out-String
        $validationExit = $LASTEXITCODE
        $ErrorActionPreference = 'Stop'
        if ($validationExit -ne 0) { throw "Apache rejected the configuration: $validation" }
        $restartAttempted = $true
        & { . (Join-Path $PSScriptRoot 'Set up office HTTPS.ps1'); Restart-OfficeApache }
        Wait-TailscaleBackend
    } catch {
        Copy-Item -LiteralPath (Join-Path $archive 'httpd.conf') -Destination $mainPath -Force
        Copy-Item -LiteralPath (Join-Path $archive 'httpd-vhosts.conf') -Destination $vhostsPath -Force
        if ($restartAttempted) {
            & { . (Join-Path $PSScriptRoot 'Set up office HTTPS.ps1'); Restart-OfficeApache }
            Wait-TailscaleBackend
        }
        throw
    }
    [pscustomobject]@{Status='applied'; HostName=$HostName; Archive=$archive; UpdatedAt=(Get-Date -Format o)} | ConvertTo-Json | Set-Content -LiteralPath $resultPath -Encoding UTF8
    Write-Host "Apache is configured for private Tailscale HTTPS. Backup saved at $archive"
} catch {
    [pscustomobject]@{Status='failed'; Message=$_.Exception.Message; Archive=$archive; UpdatedAt=(Get-Date -Format o)} | ConvertTo-Json | Set-Content -LiteralPath $resultPath -Encoding UTF8
    throw
}
