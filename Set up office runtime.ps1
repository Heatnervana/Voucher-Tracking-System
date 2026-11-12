param(
    [ValidateSet('Preview', 'Stage')]
    [string]$Mode = 'Preview',
    [Parameter(Mandatory = $true)]
    [string]$ApacheRoot,
    [Parameter(Mandatory = $true)]
    [string]$PhpRoot,
    [Parameter(Mandatory = $true)]
    [string]$VhostsPath,
    [Parameter(Mandatory = $true)]
    [string]$ConfigPath,
    [Parameter(Mandatory = $true)]
    [string]$LogsPath,
    [string]$HttpListen = '0.0.0.0:8080'
)

$ErrorActionPreference = 'Stop'

function ConvertTo-OfficeRuntimePath([string]$Path) {
    if ($Path -notmatch '^[A-Za-z]:[\\/]' -or $Path -match '[\x00-\x1F"<>|?*$]' -or $Path.Substring(2).Contains(':')) {
        throw 'Use an absolute Windows drive path without control characters, quotes, wildcards or variable expansion.'
    }
    return [IO.Path]::GetFullPath($Path).Replace('\', '/').TrimEnd('/')
}

function Invoke-OfficeRuntimeConfiguration {
    param(
        [ValidateSet('Preview', 'Stage')]
        [string]$Mode = 'Preview',
        [string]$ApacheRoot,
        [string]$PhpRoot,
        [string]$VhostsPath,
        [string]$ConfigPath,
        [string]$LogsPath,
        [string]$HttpListen = '0.0.0.0:8080'
    )

    $apache = ConvertTo-OfficeRuntimePath $ApacheRoot
    $php = ConvertTo-OfficeRuntimePath $PhpRoot
    $vhosts = ConvertTo-OfficeRuntimePath $VhostsPath
    $configuration = ConvertTo-OfficeRuntimePath $ConfigPath
    $logs = ConvertTo-OfficeRuntimePath $LogsPath
    $listener = [regex]::Match($HttpListen, '\A([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+):([0-9]{1,5})\z')
    $address = $null
    $port = 0
    if (-not $listener.Success -or -not [Net.IPAddress]::TryParse($listener.Groups[1].Value, [ref]$address) -or
        $address.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork -or
        -not [int]::TryParse($listener.Groups[2].Value, [ref]$port) -or $port -lt 1 -or $port -gt 65535) {
        throw 'HttpListen must be an IPv4 address and port from 1 through 65535.'
    }

    $modules = @('authn_core', 'authz_core', 'authz_host', 'dir', 'mime', 'log_config', 'env', 'headers', 'rewrite', 'setenvif', 'ssl', 'socache_shmcb', 'remoteip')
    $moduleLines = ($modules | ForEach-Object { 'LoadModule ' + $_ + '_module "' + $apache + '/modules/mod_' + $_ + '.so"' }) -join "`r`n"
    $rendered = @"
ServerRoot "$apache"
ServerName localhost
Listen $HttpListen
$moduleLines
LoadFile "$php/php8ts.dll"
LoadFile "$php/libsqlite3.dll"
LoadModule php_module "$php/php8apache2_4.dll"
PHPIniDir "$php"
ServerTokens Prod
ServerSignature Off
TraceEnable Off
Protocols http/1.1
Timeout 600
LimitRequestBody 1073741824
<Directory />
    Options FollowSymLinks
    AllowOverride None
    Require all denied
</Directory>
DirectoryIndex index.php
TypesConfig "$apache/conf/mime.types"
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
ErrorLog "$logs/runtime-error.log"
LogLevel warn
LogFormat "%h %l %u %t \"%r\" %>s %b" common
CustomLog "$logs/runtime-access.log" common
SSLSessionCache "shmcb:$logs/ssl_scache(512000)"
Include "$vhosts"
"@

    if ($Mode -eq 'Preview') { return $rendered }

    $requiredFiles = @("$apache/bin/httpd.exe", "$apache/conf/mime.types", $vhosts)
    $requiredFiles += $modules | ForEach-Object { "$apache/modules/mod_$_.so" }
    $requiredFiles += @('php.exe', 'php.ini', 'php8ts.dll', 'libsqlite3.dll', 'php8apache2_4.dll') | ForEach-Object { "$php/$_" }
    foreach ($file in $requiredFiles) {
        if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Required staged runtime file is missing: $file" }
    }
    if (-not (Test-Path -LiteralPath $logs -PathType Container) -or
        -not (Test-Path -LiteralPath (Split-Path $configuration -Parent) -PathType Container)) {
        throw 'Create the dedicated staging log and configuration directories before staging.'
    }
    if (Test-Path -LiteralPath $configuration) { throw 'Stage refuses to overwrite an existing configuration.' }

    $stream = [IO.File]::Open($configuration, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write)
    try {
        $bytes = ([Text.UTF8Encoding]::new($false)).GetBytes($rendered)
        $stream.Write($bytes, 0, $bytes.Length)
    } finally {
        $stream.Dispose()
    }
    return $configuration
}

Invoke-OfficeRuntimeConfiguration @PSBoundParameters
