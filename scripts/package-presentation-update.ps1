$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$plugin = Join-Path $repo 'wordpress-plugins/off-label-production-rollout'
$assets = Join-Path $plugin 'assets'
$encoding = [System.Text.UTF8Encoding]::new($false)

# Generated copies; canonical shared HTML and navigation remain the source of truth.
$header = [IO.File]::ReadAllText((Join-Path $repo 'gitpress/partials/header.template.html'))
$header = [regex]::Replace($header, '(?s)^<style id="olr-inline-styles">.*?</style>\s*', '')
[IO.File]::WriteAllText((Join-Path $assets 'site-header.html'), $header, $encoding)
Copy-Item -LiteralPath (Join-Path $repo 'gitpress/partials/footer.html') -Destination (Join-Path $assets 'site-footer.html')
Copy-Item -LiteralPath (Join-Path $repo 'scripts/olr-site-navigation.js') -Destination (Join-Path $assets 'olr-site-navigation.js')

$outputDir = Join-Path $repo 'wordpress-plugins/_deploy'
New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
$zipPath = Join-Path $outputDir 'off-label-production-rollout-v1.0.13.zip'
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression
# Explicit entries give WordPress a single canonical folder and Unix separators.
$stream = [IO.File]::Open($zipPath, [IO.FileMode]::Create)
$archive = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $plugin -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($plugin.Length + 1).Replace('\', '/')
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, "off-label-production-rollout/$relative") | Out-Null
    }
} finally { $archive.Dispose(); $stream.Dispose() }
$check = [IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $required = @('off-label-production-rollout.php', 'assets/site-header.html', 'assets/site-footer.html', 'assets/customer-presentation.css', 'assets/footer-links.js', 'assets/olr-site-navigation.js')
    foreach ($file in $required) {
        if (-not $check.GetEntry("off-label-production-rollout/$file")) { throw "ZIP missing $file" }
    }
    if (@($check.Entries | Where-Object { -not $_.FullName.StartsWith('off-label-production-rollout/') }).Count) { throw 'Unexpected ZIP root' }
    Write-Output "PASS: $($check.Entries.Count) files, one plugin folder, all presentation assets present."
} finally { $check.Dispose() }
Get-FileHash -LiteralPath $zipPath -Algorithm SHA256
