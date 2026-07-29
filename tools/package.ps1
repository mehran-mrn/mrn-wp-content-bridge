[CmdletBinding()]
param(
	[string]$OutputPath = ''
)

$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path -Parent $PSScriptRoot
$version = if ((Get-Content -LiteralPath (Join-Path $pluginRoot 'mrn-content-bridge.php') -Raw) -match 'Version:\s*([0-9.]+)') {
	$matches[1]
} else {
	throw 'Plugin version was not found.'
}
$dist = Join-Path $pluginRoot 'dist'
$OutputPath = if ($OutputPath) { $OutputPath } else { Join-Path $dist "mrn-content-bridge-$version.zip" }
$stageRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("mrncb-package-" + [guid]::NewGuid().ToString('N'))
$stagePlugin = Join-Path $stageRoot 'mrn-content-bridge'

try {
	New-Item -ItemType Directory -Path $stagePlugin -Force | Out-Null
	$excluded = @(
		'.git', '.gitignore', '.phpunit.cache', 'composer.json', 'composer.lock',
		'dist', 'phpunit.xml.dist', 'tests', 'tools', 'vendor'
	)
	Get-ChildItem -LiteralPath $pluginRoot -Force | Where-Object {
		$excluded -notcontains $_.Name
	} | ForEach-Object {
		Copy-Item -LiteralPath $_.FullName -Destination $stagePlugin -Recurse -Force
	}
	New-Item -ItemType Directory -Path (Split-Path -Parent $OutputPath) -Force | Out-Null
	if (Test-Path -LiteralPath $OutputPath) {
		Remove-Item -LiteralPath $OutputPath -Force
	}
	& tar.exe -a -cf $OutputPath -C $stageRoot 'mrn-content-bridge'
	if ($LASTEXITCODE -ne 0) {
		throw "tar.exe failed to create the ZIP archive (exit $LASTEXITCODE)."
	}
	$hash = (Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256).Hash.ToLowerInvariant()
	Write-Output "artifact=$OutputPath"
	Write-Output "version=$version"
	Write-Output "sha256=$hash"
}
finally {
	if (Test-Path -LiteralPath $stageRoot) {
		$resolvedStage = [System.IO.Path]::GetFullPath($stageRoot)
		$resolvedTemp = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
		if (-not $resolvedStage.StartsWith($resolvedTemp, [System.StringComparison]::OrdinalIgnoreCase)) {
			throw "Refusing to remove a staging path outside the temp directory: $resolvedStage"
		}
		Remove-Item -LiteralPath $resolvedStage -Recurse -Force
	}
}
