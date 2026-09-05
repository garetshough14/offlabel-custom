$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$plugin = Join-Path $repo 'wordpress-plugins/off-label-site-controls'
$target = Join-Path $repo 'wordpress-plugins/_deploy/off-label-site-controls-v1.0.9.zip'
$files = @('off-label-site-controls.php', 'cart-preview.php', 'assets/cart-preview.js', 'assets/site-controls.css', 'assets/promo-banner.js', 'assets/catalog-documentation-light-v2.png', 'assets/about-philosophy-approved-v1.png', 'assets/about-philosophy-wide-v2.png', 'assets/build-box-transparent-v1.png')
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression
$stream = [IO.File]::Open($target, [IO.FileMode]::Create)
$archive = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($relative in $files) {
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, (Join-Path $plugin $relative), "off-label-site-controls/$relative") | Out-Null
    }
} finally { $archive.Dispose(); $stream.Dispose() }
$archive = [IO.Compression.ZipFile]::OpenRead($target)
try {
    foreach ($relative in $files) {
        if (-not $archive.GetEntry("off-label-site-controls/$relative")) { throw "Missing $relative" }
    }
    if ($archive.Entries.Count -ne $files.Count) { throw 'Unexpected files in plugin ZIP' }
    Write-Output 'PASS: nine files, one plugin folder; PHP, CSS, JS and all artwork assets present.'
} finally { $archive.Dispose() }
Get-FileHash -LiteralPath $target -Algorithm SHA256
