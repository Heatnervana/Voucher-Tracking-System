param(
    [switch]$VerifyRewrite,
    [string]$TrustedApacheRoot = 'C:\xampp\apache'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$setup = Join-Path $root 'Set up office runtime.ps1'

function Assert-Runtime([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

function Assert-RuntimeRejected([scriptblock]$Action, [string]$Message) {
    $rejected = $false
    try { & $Action | Out-Null } catch { $rejected = $true }
    Assert-Runtime $rejected $Message
}

Assert-Runtime (Test-Path -LiteralPath $setup) 'The staging-only runtime generator is missing.'
$tokens = $null
$parseErrors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($setup, [ref]$tokens, [ref]$parseErrors)
Assert-Runtime ($parseErrors.Count -eq 0) 'The runtime generator must parse.'
foreach ($function in $ast.FindAll({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] }, $false)) {
    Invoke-Expression $function.Extent.Text
}

$fixture = Join-Path ([IO.Path]::GetTempPath()) ('voucher-runtime-test-' + [guid]::NewGuid().ToString('N'))
$apache = Join-Path $fixture 'Apache with spaces'
$php = Join-Path $fixture 'PHP with spaces'
$logs = Join-Path $fixture 'logs'
$vhosts = Join-Path $fixture 'reviewed vhosts.conf'
$configuration = Join-Path $fixture 'candidate httpd.conf'
New-Item -ItemType Directory -Path $fixture, $apache, $php, $logs | Out-Null
try {
    $originalVhosts = "# synthetic reviewed input`r`n<VirtualHost *:18980>`r`nServerName localhost`r`n</VirtualHost>`r`n"
    [IO.File]::WriteAllText($vhosts, $originalVhosts)
    $values = @{ ApacheRoot=$apache; PhpRoot=$php; VhostsPath=$vhosts; ConfigPath=$configuration; LogsPath=$logs; HttpListen='127.0.0.1:18980' }
    $rendered = Invoke-OfficeRuntimeConfiguration @values
    Assert-Runtime (-not (Test-Path -LiteralPath $configuration)) 'Preview must not write configuration.'
    Assert-Runtime ($rendered.Contains('ServerRoot "' + $apache.Replace('\', '/') + '"')) 'Quoted native roots must support spaces.'
    Assert-Runtime ($rendered.Contains('PHPIniDir "' + $php.Replace('\', '/') + '"')) 'The selected PHP root must supply php.ini.'
    Assert-Runtime ($rendered.Contains('Include "' + $vhosts.Replace('\', '/') + '"')) 'The reviewed vhosts file must remain an explicit include.'
    Assert-Runtime ($rendered -match '(?m)^Listen 127\.0\.0\.1:18980\r?$') 'The fixture listener must be explicit.'
    Assert-Runtime ($rendered -notmatch '<VirtualHost|SSLCertificate|Require ip|RemoteIPInternalProxy') 'The main generator must not invent or copy private vhost policy.'
    $expected = @('authn_core', 'authz_core', 'authz_host', 'dir', 'mime', 'log_config', 'env', 'headers', 'rewrite', 'setenvif', 'ssl', 'socache_shmcb', 'remoteip')
    $loaded = @([regex]::Matches($rendered, '(?m)^LoadModule ([a-z0-9_]+)_module ') | ForEach-Object { $_.Groups[1].Value } | Where-Object { $_ -ne 'php' })
    Assert-Runtime ((@($loaded | Sort-Object) -join ',') -eq (@($expected | Sort-Object) -join ',')) 'Only the reviewed Apache module allowlist may load.'
    foreach ($directive in @('ServerTokens Prod', 'ServerSignature Off', 'TraceEnable Off', 'Protocols http/1.1', 'LimitRequestBody 1073741824', 'Timeout 600', 'Options FollowSymLinks', 'Require all denied', 'SetHandler application/x-httpd-php')) {
        Assert-Runtime ($rendered.Contains($directive)) "Missing runtime control: $directive"
    }
    Assert-Runtime ($rendered.Contains('/php8ts.dll"') -and $rendered.Contains('/libsqlite3.dll"') -and $rendered.Contains('/php8apache2_4.dll"')) 'Thread-safe PHP and SQLite libraries must use the selected root.'
    Assert-Runtime ($rendered -notmatch 'libpq|proxy_module|http2_module|md_module|vhost_alias_module|userdir_module|autoindex_module|info_module|cgi_module') 'Unneeded native modules/libraries must stay unloaded.'

    foreach ($badListener in @('localhost:8080', '127.0.0.1:0', '127.0.0.1:65536', '999.1.1.1:80', "127.0.0.1:80`nListen 443", "127.0.0.1:80`n", '0.0.0.0:8080 https')) {
        $bad = $values.Clone(); $bad.HttpListen = $badListener
        Assert-RuntimeRejected { Invoke-OfficeRuntimeConfiguration @bad } 'Invalid or injected listener must fail before writing.'
    }
    foreach ($field in @('ApacheRoot', 'PhpRoot', 'VhostsPath', 'ConfigPath', 'LogsPath')) {
        foreach ($badPath in @("C:\bad`nInclude injected", 'C:\bad"path', 'C:\${INJECTED}\path', 'relative\path')) {
            $bad = $values.Clone(); $bad[$field] = $badPath
            Assert-RuntimeRejected { Invoke-OfficeRuntimeConfiguration @bad } "Unsafe $field must fail before writing."
        }
    }

    Assert-RuntimeRejected { Invoke-OfficeRuntimeConfiguration @values -Mode Stage } 'Stage must reject missing native files.'
    Assert-Runtime (-not (Test-Path -LiteralPath $configuration)) 'Failed staging must leave no output.'
    New-Item -ItemType Directory -Path (Join-Path $apache 'bin'), (Join-Path $apache 'modules'), (Join-Path $apache 'conf') | Out-Null
    $requiredFiles = @((Join-Path $apache 'bin/httpd.exe'), (Join-Path $apache 'conf/mime.types'))
    $requiredFiles += $expected | ForEach-Object { Join-Path $apache "modules/mod_$_.so" }
    $requiredFiles += @('php.exe', 'php.ini', 'php8ts.dll', 'libsqlite3.dll', 'php8apache2_4.dll') | ForEach-Object { Join-Path $php $_ }
    foreach ($file in $requiredFiles) { [IO.File]::WriteAllText($file, 'disposable file-presence fixture') }
    Invoke-OfficeRuntimeConfiguration @values -Mode Stage | Out-Null
    Assert-Runtime ([IO.File]::ReadAllText($configuration) -eq $rendered) 'Stage must write exactly the previewed configuration.'
    Assert-Runtime ([IO.File]::ReadAllText($vhosts) -eq $originalVhosts) 'Staging must preserve the vhosts file byte for byte.'
    Assert-RuntimeRejected { Invoke-OfficeRuntimeConfiguration @values -Mode Stage } 'Stage must refuse to overwrite an existing file.'
    Assert-Runtime ([IO.File]::ReadAllText($configuration) -eq $rendered) 'Refused overwrite must preserve the candidate.'

    $runner = Join-Path $root 'Run office task.ps1'
    $runnerAst = [Management.Automation.Language.Parser]::ParseFile($runner, [ref]$tokens, [ref]$parseErrors)
    Assert-Runtime ($parseErrors.Count -eq 0) 'The task runner must parse.'
    $parameters = @($runnerAst.ParamBlock.Parameters | ForEach-Object { $_.Name.VariablePath.UserPath })
    Assert-Runtime ($parameters -contains 'PhpPath') 'The task runner must accept an explicit PHP executable.'
    $runnerFixture = Join-Path $fixture 'task runner'
    New-Item -ItemType Directory -Path $runnerFixture, (Join-Path $runnerFixture 'storage/logs') | Out-Null
    Copy-Item -LiteralPath $runner -Destination (Join-Path $runnerFixture 'Run office task.ps1')
    $fakePhp = Join-Path $runnerFixture 'chosen php.cmd'
    [IO.File]::WriteAllText($fakePhp, "@echo off`r`necho SELECTED-PHP %*`r`nexit /b 0`r`n")
    & powershell -NoProfile -NonInteractive -File (Join-Path $runnerFixture 'Run office task.ps1') -Task Backup -BackupDestination (Join-Path $fixture 'backup with spaces') -PhpPath $fakePhp
    Assert-Runtime ($LASTEXITCODE -eq 0) 'Backup must invoke the selected executable.'
    $backupLog = [IO.File]::ReadAllText((Join-Path $runnerFixture 'storage/logs/voucher-backup-task.log'))
    Assert-Runtime ($backupLog.Contains('SELECTED-PHP artisan vouchers:backup') -and $backupLog.Contains('backup with spaces')) 'Backup must preserve the selected executable and spaced arguments.'
    if ($VerifyRewrite) {
        $trustedApache = Join-Path $TrustedApacheRoot 'bin/httpd.exe'
        Assert-Runtime (Test-Path -LiteralPath $trustedApache -PathType Leaf) 'The explicitly selected approved Apache executable is missing.'
        $webRoot = Join-Path $fixture 'static public'
        New-Item -ItemType Directory -Path $webRoot | Out-Null
        [IO.File]::WriteAllText((Join-Path $webRoot 'index.html'), 'RUNTIME-REWRITE-OK')
        $accessRules = [IO.File]::ReadAllText((Join-Path $root 'public/.htaccess')).Replace('index.php', 'index.html')
        [IO.File]::WriteAllText((Join-Path $webRoot '.htaccess'), $accessRules)
        $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
        $listener.Start()
        $port = $listener.LocalEndpoint.Port
        $listener.Stop()
        $staticVhosts = Join-Path $fixture 'static-vhosts.conf'
        $staticConfig = Join-Path $fixture 'static-httpd.conf'
        $webDirectory = $webRoot.Replace('\', '/')
        [IO.File]::WriteAllText($staticVhosts, @"
<VirtualHost 127.0.0.1:$port>
    ServerName localhost
    DocumentRoot "$webDirectory"
    <Directory "$webDirectory">
        Options -Indexes -Includes -ExecCGI
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
"@)
        $staticValues = $values.Clone()
        $staticValues.ApacheRoot = $TrustedApacheRoot
        $staticValues.VhostsPath = $staticVhosts
        $staticValues.ConfigPath = $staticConfig
        $staticValues.HttpListen = "127.0.0.1:$port"
        $staticConfiguration = Invoke-OfficeRuntimeConfiguration @staticValues
        # This fixture exercises Apache rewrite only; no PHP executable or DLL may load.
        $staticConfiguration = ($staticConfiguration -split '\r?\n' | Where-Object {
            $_ -notmatch '^(?:LoadFile |LoadModule php_module |PHPIniDir |<FilesMatch |</FilesMatch>)' -and
            $_ -notmatch '^\s+SetHandler '
        }) -join "`r`n"
        $staticConfiguration += "`r`nPidFile `"$($fixture.Replace('\', '/'))/static-httpd.pid`"`r`n"
        Assert-Runtime ($staticConfiguration -notmatch 'php8|LoadFile|PHPIniDir|SetHandler') 'The static fixture must not load or execute PHP.'
        [IO.File]::WriteAllText($staticConfig, $staticConfiguration)
        $ownedApache = $null
        try {
            $startupErrors = Join-Path $fixture 'static-startup-errors.log'
            $ownedApache = Start-Process -FilePath $trustedApache -ArgumentList @('-X', '-d', ('"' + $TrustedApacheRoot.Replace('\', '/') + '"'), '-f', ('"' + $staticConfig.Replace('\', '/') + '"')) -WindowStyle Hidden -PassThru -RedirectStandardError $startupErrors
            $ready = $false
            for ($attempt = 0; $attempt -lt 50; $attempt++) {
                if ($ownedApache.HasExited) { throw ('The static Apache fixture exited before listening: ' + [IO.File]::ReadAllText($startupErrors)) }
                $probe = [Net.Sockets.TcpClient]::new()
                try { $probe.Connect([Net.IPAddress]::Loopback, $port); $ready = $true } catch {} finally { $probe.Dispose() }
                if ($ready) { break }
                Start-Sleep -Milliseconds 100
            }
            Assert-Runtime $ready 'The static Apache fixture did not start on loopback.'
            $request = [Net.HttpWebRequest]::Create("http://127.0.0.1:$port/not-a-file")
            $request.Proxy = $null
            $request.Timeout = 3000
            $response = $null
            try { $response = $request.GetResponse() } catch [Net.WebException] { $response = $_.Exception.Response }
            Assert-Runtime ($null -ne $response) 'The static rewrite request did not return an HTTP response.'
            try {
                $status = [int]$response.StatusCode
                $reader = [IO.StreamReader]::new($response.GetResponseStream())
                try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
            } finally { $response.Dispose() }
            if (-not $ownedApache.HasExited) { Stop-Process -Id $ownedApache.Id -Force }
            $ownedApache.WaitForExit()
            $nativeErrors = [IO.File]::ReadAllText((Join-Path $logs 'runtime-error.log'))
            Assert-Runtime ($status -eq 200 -and $body -eq 'RUNTIME-REWRITE-OK') "Laravel-style rewrite failed: HTTP $status; AH00670=$($nativeErrors.Contains('AH00670'))."
            Assert-Runtime (-not $nativeErrors.Contains('AH00670')) 'Directory options must permit the application rewrite rules.'
            'PASS: approved static Apache served a missing route through the repository .htaccess rules (HTTP 200), without PHP.'
        } finally {
            if ($null -ne $ownedApache) {
                if (-not $ownedApache.HasExited) { Stop-Process -Id $ownedApache.Id -Force }
                $ownedApache.WaitForExit()
                $ownedApache.Dispose()
            }
        }
    }
    'PASS: runtime preview/stage, module allowlist, hardening, path/listener validation, input preservation and explicit backup PHP.'
} finally {
    $resolved = [IO.Path]::GetFullPath($fixture)
    $allowed = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
    if (-not $resolved.StartsWith($allowed, [StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolved -Leaf) -notmatch '^voucher-runtime-test-[a-f0-9]{32}$') { throw 'Unsafe runtime fixture cleanup path.' }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}
