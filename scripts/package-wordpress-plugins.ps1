$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$pluginRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'wordpress-plugins'))
$stageRoot = [System.IO.Path]::GetFullPath((Join-Path $pluginRoot '.release-staging'))
$plugins = @(
    @{ Name = 'off-label-account-hub'; Main = 'off-label-account-hub.php' },
    @{ Name = 'off-label-build-a-box'; Main = 'off-label-build-a-box.php' },
    @{ Name = 'off-label-checkout-test'; Main = 'off-label-checkout-test.php' },
    @{ Name = 'off-label-coa-manager'; Main = 'off-label-coa-manager.php' }
)

if (-not $stageRoot.StartsWith($pluginRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "Refusing to use a staging directory outside wordpress-plugins: $stageRoot"
}

if (Test-Path -LiteralPath $stageRoot) {
    Remove-Item -LiteralPath $stageRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $stageRoot | Out-Null

Add-Type -AssemblyName System.IO.Compression.FileSystem

foreach ($plugin in $plugins) {
    $source = [System.IO.Path]::GetFullPath((Join-Path $pluginRoot $plugin.Name))
    $staged = [System.IO.Path]::GetFullPath((Join-Path $stageRoot $plugin.Name))
    $zip = [System.IO.Path]::GetFullPath((Join-Path $pluginRoot ($plugin.Name + '.zip')))

    if (-not $source.StartsWith($pluginRoot, [System.StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $source -PathType Container)) {
        throw "Plugin source is missing or outside the expected root: $source"
    }
    if (-not (Test-Path -LiteralPath (Join-Path $source $plugin.Main) -PathType Leaf)) {
        throw "Plugin main file is missing: $($plugin.Main)"
    }

    Copy-Item -LiteralPath $source -Destination $staged -Recurse
    Compress-Archive -LiteralPath $staged -DestinationPath $zip -CompressionLevel Optimal -Force

    $archive = [System.IO.Compression.ZipFile]::OpenRead($zip)
    try {
        $topLevels = @($archive.Entries | ForEach-Object { ($_.FullName -split '/|\\')[0] } | Where-Object { $_ } | Sort-Object -Unique)
        $expectedMain = $plugin.Name + '/' + $plugin.Main
        $mainEntry = $archive.Entries | Where-Object { $_.FullName.Replace('\', '/') -eq $expectedMain }
        if ($topLevels.Count -ne 1 -or $topLevels[0] -ne $plugin.Name -or -not $mainEntry) {
            throw "Archive topology verification failed for $zip"
        }
        Write-Output "PASS: $($plugin.Name).zip contains one canonical top-level directory."
    } finally {
        $archive.Dispose()
    }
}

Remove-Item -LiteralPath $stageRoot -Recurse -Force

foreach ($plugin in $plugins) {
    $zip = Join-Path $pluginRoot ($plugin.Name + '.zip')
    $hash = Get-FileHash -LiteralPath $zip -Algorithm SHA256
    Write-Output ("{0}  {1}" -f $hash.Hash.ToLowerInvariant(), (Split-Path -Leaf $zip))
}
