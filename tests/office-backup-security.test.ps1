$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$setup = Join-Path $root 'Set up office access.ps1'
$tokens = $null
$parseErrors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($setup, [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count) { throw 'Office setup script did not parse.' }

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

# Load only the helpers, so the elevated setup and its network/task changes never run.
foreach ($name in @('New-OfficeBackupAcl', 'Assert-OfficeBackupDestination')) {
    $definition = $ast.FindAll({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $name }, $true) | Select-Object -First 1
    Assert-True ($null -ne $definition) "Missing backup protection helper: $name"
    . ([scriptblock]::Create($definition.Extent.Text))
}

$accountSid = New-Object Security.Principal.SecurityIdentifier('S-1-5-21-100-200-300-1001')
$acl = New-OfficeBackupAcl -AccountSid $accountSid
$allowed = @($accountSid.Value, 'S-1-5-18', 'S-1-5-32-544')
$rules = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
Assert-True $acl.AreAccessRulesProtected 'Backup ACL must disable inherited permissions.'
Assert-True ($rules.Count -eq 3) 'Backup ACL must contain exactly the three trusted identities.'
Assert-True (@($rules | Where-Object { $_.IdentityReference.Value -notin $allowed -or $_.AccessControlType -ne 'Allow' }).Count -eq 0) 'Backup ACL contains an unexpected grant.'
foreach ($rule in $rules) {
    Assert-True ($rule.FileSystemRights -eq [Security.AccessControl.FileSystemRights]::FullControl) 'Trusted identities need backup read/write rights.'
    Assert-True ($rule.InheritanceFlags -eq ([Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit)) 'Private grants must propagate to backup directories and files.'
}

$fixture = Join-Path $root ('storage/framework/testing/office-backup-security-' + [guid]::NewGuid().ToString('N'))
$link = Join-Path $fixture 'linked'
$descendantLink = Join-Path $fixture 'existing-backups/linked'
New-Item -ItemType Directory -Path (Join-Path $fixture 'target') | Out-Null
try {
    $ordinary = Join-Path $fixture 'new-backups/nested'
    Assert-True ((Assert-OfficeBackupDestination -Path $ordinary) -eq [IO.Path]::GetFullPath($ordinary)) 'An ordinary new local destination must be accepted.'
    Assert-True (-not (Test-Path -LiteralPath $ordinary)) 'Validation must not create a backup destination.'
    $file = Join-Path $fixture 'ordinary-file'
    [IO.File]::WriteAllText($file, 'fixture')
    $rejected = $false
    try { Assert-OfficeBackupDestination -Path (Join-Path $file 'backups') | Out-Null } catch { $rejected = $_.Exception.Message -match 'must be directories' }
    Assert-True $rejected 'An existing file must not be accepted as a backup directory ancestor.'
    New-Item -ItemType Junction -Path $link -Value (Join-Path $fixture 'target') | Out-Null
    foreach ($destination in @($link, (Join-Path $link 'new-backups'))) {
        $rejected = $false
        try { Assert-OfficeBackupDestination -Path $destination | Out-Null } catch { $rejected = $_.Exception.Message -match 'junction|symbolic link' }
        Assert-True $rejected 'A reparse destination or parent must be rejected before writes.'
    }

    $fixtureAccountSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $privateAcl = New-OfficeBackupAcl -AccountSid $fixtureAccountSid
    $privateAcl.SetOwner($fixtureAccountSid)
    $existing = Join-Path $fixture 'existing-backups'
    New-Item -ItemType Directory -Path $existing | Out-Null
    Set-Acl -LiteralPath $existing -AclObject $privateAcl
    $saved = Join-Path $existing 'synthetic-backup.txt'
    [IO.File]::WriteAllText($saved, 'synthetic backup fixture')
    $safeFileAcl = Get-Acl -LiteralPath $saved
    $broadFileAcl = Get-Acl -LiteralPath $saved
    $everyone = New-Object Security.Principal.SecurityIdentifier('S-1-1-0')
    $broadFileAcl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($everyone, 'Read', 'Allow')))
    Set-Acl -LiteralPath $saved -AclObject $broadFileAcl
    $before = (Get-Acl -LiteralPath $saved).Sddl
    $rejected = $false
    try { Assert-OfficeBackupDestination -Path $existing -AccountSid $fixtureAccountSid | Out-Null } catch { $rejected = $_.Exception.Message -match 'new empty protected destination' }
    Assert-True $rejected 'An explicit broad child grant must fail before setup changes permissions.'
    Assert-True ((Get-Acl -LiteralPath $saved).Sddl -eq $before) 'Validation must leave unsafe historical permissions unchanged.'
    Set-Acl -LiteralPath $saved -AclObject $safeFileAcl

    $protected = Join-Path $existing 'protected-backup'
    New-Item -ItemType Directory -Path $protected | Out-Null
    $broadProtectedAcl = New-OfficeBackupAcl -AccountSid $fixtureAccountSid
    $broadProtectedAcl.SetOwner($fixtureAccountSid)
    $broadProtectedAcl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($everyone, 'ReadAndExecute', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
    Set-Acl -LiteralPath $protected -AclObject $broadProtectedAcl
    $before = (Get-Acl -LiteralPath $protected).Sddl
    $rejected = $false
    try { Assert-OfficeBackupDestination -Path $existing -AccountSid $fixtureAccountSid | Out-Null } catch { $rejected = $_.Exception.Message -match 'new empty protected destination' }
    Assert-True $rejected 'A protected child with a broad grant must fail instead of surviving root ACL replacement.'
    Assert-True ((Get-Acl -LiteralPath $protected).Sddl -eq $before) 'Validation must not rewrite protected historical ACLs.'
    Remove-Item -LiteralPath $protected -Force
    New-Item -ItemType Directory -Path $protected | Out-Null
    $safeProtectedAcl = New-OfficeBackupAcl -AccountSid $fixtureAccountSid
    $safeProtectedAcl.SetOwner($fixtureAccountSid)
    Set-Acl -LiteralPath $protected -AclObject $safeProtectedAcl
    Assert-True ((Assert-OfficeBackupDestination -Path $existing -AccountSid $fixtureAccountSid) -eq [IO.Path]::GetFullPath($existing)) 'Safely protected existing backups must remain usable on repeat setup.'

    $nested = Join-Path $protected 'nested-synthetic-backup.txt'
    [IO.File]::WriteAllText($nested, 'nested synthetic backup fixture')
    $nestedAcl = Get-Acl -LiteralPath $nested
    $nestedAcl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($everyone, 'Read', 'Allow')))
    Set-Acl -LiteralPath $nested -AclObject $nestedAcl
    $rejected = $false
    try { Assert-OfficeBackupDestination -Path $existing -AccountSid $fixtureAccountSid | Out-Null } catch { $rejected = $_.Exception.Message -match 'new empty protected destination' }
    Assert-True $rejected 'A broad grant beneath a safe protected directory must also be found.'
    Remove-Item -LiteralPath $nested -Force

    New-Item -ItemType Junction -Path $descendantLink -Value (Join-Path $fixture 'target') | Out-Null
    $rejected = $false
    try { Assert-OfficeBackupDestination -Path $existing -AccountSid $fixtureAccountSid | Out-Null } catch { $rejected = $_.Exception.Message -match 'junction|symbolic link' }
    Assert-True $rejected 'A nested backup junction must be rejected before traversing its target.'
    Write-Host 'PASS: trusted backup ACLs, unsafe historical child rejection, safe repeat setup, and reparse boundaries without setup execution.'
} finally {
    if (Test-Path -LiteralPath $descendantLink) { Remove-Item -LiteralPath $descendantLink -Force }
    if (Test-Path -LiteralPath $link) { Remove-Item -LiteralPath $link -Force }
    $resolved = [IO.Path]::GetFullPath($fixture)
    $allowedRoot = [IO.Path]::GetFullPath((Join-Path $root 'storage/framework/testing')).TrimEnd('\') + '\'
    if (-not $resolved.StartsWith($allowedRoot, [StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolved -Leaf) -notmatch '^office-backup-security-[a-f0-9]{32}$') { throw 'Unsafe fixture cleanup path.' }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}
