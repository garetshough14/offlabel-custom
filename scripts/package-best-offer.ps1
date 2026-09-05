$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$plugin = Join-Path $repo 'wordpress-plugins/off-label-best-offer'
$target = Join-Path $repo 'wordpress-plugins/_deploy/off-label-best-offer-v0.3.0-LIVE-OPT-IN.zip'
$files = @('off-label-best-offer.php', 'includes/class-olr-offer-admin.php', 'includes/class-olr-offer-planner.php', 'includes/class-olr-offer-live-preview.php', 'assets/volume.js', 'assets/volume.css')
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression
$stream = [IO.File]::Open($target, [IO.FileMode]::CreateNew)
$archive = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($relative in $files) {
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, (Join-Path $plugin $relative), "off-label-best-offer/$relative") | Out-Null
    }
} finally { $archive.Dispose(); $stream.Dispose() }
$archive = [IO.Compression.ZipFile]::OpenRead($target)
try {
    foreach ($relative in $files) {
        if (-not $archive.GetEntry("off-label-best-offer/$relative")) { throw "Missing $relative" }
    }
    if ($archive.Entries.Count -ne $files.Count) { throw 'Unexpected files in plugin ZIP' }
    Write-Output 'PASS: six files in one plugin directory; no other plugins, tests, credentials or activation migrations.'
} finally { $archive.Dispose() }
Get-FileHash -LiteralPath $target -Algorithm SHA256
