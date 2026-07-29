<#
.SYNOPSIS
	Deploy MRN Content Bridge to masnavipodcast.ir with rollback.

.DESCRIPTION
	Uses the existing DPAPI-protected deployment credential, verifies the SSH
	host fingerprint, checksums and lints the staged artifact, backs up the
	database and prior plugin, activates the release, and performs WP-CLI smoke
	checks. No credential is written to the repository or artifact.
#>
[CmdletBinding()]
param(
	[string]$ArtifactPath = '',
	[string]$SecretsRoot = '',
	[switch]$StatusOnly
)

$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path -Parent $PSScriptRoot
$workspaceRoot = Split-Path -Parent (Split-Path -Parent $pluginRoot)
$version = if ((Get-Content -LiteralPath (Join-Path $pluginRoot 'mrn-content-bridge.php') -Raw) -match 'Version:\s*([0-9.]+)') {
	$matches[1]
} else {
	throw 'Plugin version was not found.'
}
$ArtifactPath = if ($ArtifactPath) { $ArtifactPath } else { Join-Path $pluginRoot "dist\mrn-content-bridge-$version.zip" }
$SecretsRoot = if ($SecretsRoot) { $SecretsRoot } else { Join-Path $workspaceRoot 'Docs\.secrets' }
$metadataPath = Join-Path $SecretsRoot 'infrastructure.env'
$credentialPath = Join-Path $SecretsRoot 'server-root.credential.xml'
$plinkPath = 'C:\Program Files\PuTTY\plink.exe'
$pscpPath = 'C:\Program Files\PuTTY\pscp.exe'
$documentRoot = '/home/masnavi/domains/masnavipodcast.ir/public_html'

foreach ($requiredPath in @($metadataPath, $credentialPath, $plinkPath)) {
	if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
		throw "Required deployment file is missing: $requiredPath"
	}
}
if (-not $StatusOnly) {
	foreach ($requiredPath in @($ArtifactPath, $pscpPath)) {
		if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
			throw "Required deployment file is missing: $requiredPath"
		}
	}
}

$connection = @{}
Get-Content -LiteralPath $metadataPath | ForEach-Object {
	if ($_ -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$') {
		$connection[$matches[1]] = $matches[2].Trim().Trim('"').Trim("'")
	}
}
$credential = Import-Clixml -LiteralPath $credentialPath
$networkCredential = $credential.GetNetworkCredential()
$sshUser = $networkCredential.UserName
$sshPassword = $networkCredential.Password
$sshHost = $connection['MRN_SSH_FORWARD_HOST']
$sshPort = [int]$connection['MRN_SSH_FORWARD_PORT']
$expectedFingerprint = $connection['MRN_SSH_ED25519_FINGERPRINT']
$keyScanFile = New-TemporaryFile

try {
	$previousErrorAction = $ErrorActionPreference
	$ErrorActionPreference = 'Continue'
	$keyLines = & ssh-keyscan -p $sshPort -t ed25519 $sshHost 2>$null
	$ErrorActionPreference = $previousErrorAction
	if (-not $keyLines) {
		throw 'SSH key scan returned no ED25519 key.'
	}
	[System.IO.File]::WriteAllLines($keyScanFile.FullName, [string[]]$keyLines, [System.Text.UTF8Encoding]::new($false))
	$shaLine = & ssh-keygen -lf $keyScanFile.FullName 2>$null | Select-Object -First 1
	if ($shaLine -notmatch '(SHA256:[A-Za-z0-9+/=]+)' -or $matches[1] -ne $expectedFingerprint) {
		throw 'SSH host fingerprint mismatch.'
	}
	$md5Line = & ssh-keygen -l -E md5 -f $keyScanFile.FullName 2>$null | Select-Object -First 1
	if ($md5Line -notmatch '(MD5:[0-9a-f:]+)') {
		throw 'Could not derive the PuTTY host fingerprint.'
	}
	$puttyHostKey = $matches[1].Substring(4)
}
finally {
	Remove-Item -LiteralPath $keyScanFile.FullName -Force -ErrorAction SilentlyContinue
}

function Invoke-RemoteCommand {
	param([Parameter(Mandatory)][string]$Command)
	$arguments = @(
		'-batch', '-ssh', '-P', "$sshPort", '-l', $sshUser, '-pw', $sshPassword,
		'-hostkey', $puttyHostKey, $sshHost, $Command
	)
	& $plinkPath @arguments
	if ($LASTEXITCODE -ne 0) {
		throw "Remote command failed with exit code $LASTEXITCODE."
	}
}

if ($StatusOnly) {
	Invoke-RemoteCommand -Command @"
set -eu
cd '$documentRoot'
printf 'installed='
if wp --allow-root plugin is-installed mrn-content-bridge; then printf 'yes\n'; else printf 'no\n'; fi
printf 'active='
if wp --allow-root plugin is-active mrn-content-bridge; then printf 'yes\n'; else printf 'no\n'; fi
if wp --allow-root plugin is-installed mrn-content-bridge; then
	printf 'version='
	wp --allow-root plugin get mrn-content-bridge --field=version
	printf 'last_worker='
	wp --allow-root option get mrncb_last_worker_success 2>/dev/null || true
	printf 'job_table='
	wp --allow-root db tables --all-tables-with-prefix | tr ' ' '\n' | grep -E 'mrncb_jobs$' | head -n1
	printf 'worker_cron='
	wp --allow-root cron event list --fields=hook,next_run_relative --format=csv | grep '^mrncb_worker_tick,' || true
	printf 'cli_command='
	if wp --allow-root help mrn-content-bridge worker >/dev/null 2>&1; then printf 'registered\n'; else printf 'missing\n'; fi
fi
"@
	exit
}

$deploymentId = Get-Date -Format 'yyyyMMdd-HHmmss'
$remoteStage = "/home/masnavi/.mrn-deploys/content-bridge-$deploymentId"
$remoteBackup = "/home/masnavi/backups/mrn-content-bridge-$deploymentId"
$remotePackage = "$remoteStage/mrn-content-bridge-$version.zip"
$localHash = (Get-FileHash -LiteralPath $ArtifactPath -Algorithm SHA256).Hash.ToLowerInvariant()

Invoke-RemoteCommand -Command @"
set -eu
test -f '$documentRoot/wp-config.php'
cd '$documentRoot'
wp --allow-root core is-installed
mkdir -p '$remoteStage/extract' '$remoteBackup'
wp --allow-root db export '$remoteBackup/database.sql' --add-drop-table --quiet
chmod 600 '$remoteBackup/database.sql'
if wp --allow-root plugin is-active mrn-content-bridge; then
	printf 'active_before=1\n' > '$remoteBackup/state'
else
	printf 'active_before=0\n' > '$remoteBackup/state'
fi
printf 'preflight=ok\nbackup=%s\n' '$remoteBackup'
"@

$copyArguments = @(
	'-batch', '-P', "$sshPort", '-l', $sshUser, '-pw', $sshPassword,
	'-hostkey', $puttyHostKey, $ArtifactPath, "${sshUser}@${sshHost}:$remotePackage"
)
& $pscpPath @copyArguments
if ($LASTEXITCODE -ne 0) {
	throw "Package upload failed with exit code $LASTEXITCODE."
}

Invoke-RemoteCommand -Command @"
set -eu
stage='$remoteStage'
backup='$remoteBackup'
docroot='$documentRoot'
live="`$docroot/wp-content/plugins/mrn-content-bridge"
incoming="`$stage/extract/mrn-content-bridge"
previous="`$backup/mrn-content-bridge.previous"
active_before="`$(cut -d= -f2 "`$backup/state")"
rollback() {
	set +e
	cd "`$docroot"
	wp --allow-root plugin deactivate mrn-content-bridge >/dev/null 2>&1
	if [ -d "`$live" ]; then mv "`$live" "`$stage/mrn-content-bridge.failed"; fi
	if [ -d "`$previous" ]; then mv "`$previous" "`$live"; fi
	wp --allow-root db import "`$backup/database.sql" >/dev/null 2>&1
	if [ "`$active_before" = '1' ] && [ -d "`$live" ]; then wp --allow-root plugin activate mrn-content-bridge >/dev/null 2>&1; fi
	wp --allow-root cache flush >/dev/null 2>&1
}
trap rollback EXIT HUP INT TERM
printf '%s  %s\n' '$localHash' '$remotePackage' | sha256sum -c -
unzip -q '$remotePackage' -d "`$stage/extract"
test -f "`$incoming/mrn-content-bridge.php"
test -f "`$incoming/src/Core/Plugin.php"
test -f "`$incoming/src/Queue/Worker.php"
test -f "`$incoming/assets/css/admin.css"
grep -q 'Version:[[:space:]]*$version' "`$incoming/mrn-content-bridge.php"
find "`$incoming" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
chown -R masnavi:masnavi "`$incoming"
find "`$incoming" -type d -exec chmod 755 {} +
find "`$incoming" -type f -exec chmod 644 {} +
if [ -d "`$live" ]; then mv "`$live" "`$previous"; fi
mv "`$incoming" "`$live"
cd "`$docroot"
wp --allow-root plugin activate mrn-content-bridge
wp --allow-root plugin is-active mrn-content-bridge
test "`$(wp --allow-root plugin get mrn-content-bridge --field=version)" = '$version'
wp --allow-root help mrn-content-bridge worker >/dev/null
wp --allow-root db tables --all-tables-with-prefix | tr ' ' '\n' | grep -E -q 'mrncb_jobs$'
wp --allow-root mrn-content-bridge worker --batch-size=1 --quiet
wp --allow-root cache flush >/dev/null
trap - EXIT HUP INT TERM
printf 'deploy=ok\nversion=$version\nbackup=%s\n' "`$backup"
"@

Write-Host ''
Write-Host 'MRN Content Bridge deployment completed.' -ForegroundColor Green
Write-Host "Version: $version"
Write-Host "Backup: $remoteBackup"
