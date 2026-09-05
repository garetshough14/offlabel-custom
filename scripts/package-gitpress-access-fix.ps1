$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$plugin = Join-Path $repo 'wordpress-plugins/Gitpress-main'
$destination = Join-Path $repo 'wordpress-plugins/_deploy/Gitpress-main-v1.2.6.zip'
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression
$stream = [IO.File]::Open($destination, [IO.FileMode]::Create)
$archive = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $plugin -Recurse -File -Force | ForEach-Object {
        $relative = $_.FullName.Substring($plugin.Length + 1).Replace('\', '/')
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, "Gitpress-main/$relative") | Out-Null
    }
} finally { $archive.Dispose(); $stream.Dispose() }
$check = [IO.Compression.ZipFile]::OpenRead($destination)
try {
    foreach ($file in @('divi-github-sync.php', 'includes/class-page-shortcode-manager.php', 'includes/full-page-template.php')) {
        if (-not $check.GetEntry("Gitpress-main/$file")) { throw "Missing $file" }
    }
    if (@($check.Entries | Where-Object { -not $_.FullName.StartsWith('Gitpress-main/') }).Count) { throw 'Unexpected archive root' }
    Write-Output "PASS: $($check.Entries.Count) files, single Gitpress-main root, all patch files present."
} finally { $check.Dispose() }
Get-FileHash -LiteralPath $destination -Algorithm SHA256
