param([ValidateSet('1.4.3', '1.4.4')][string]$Version = '1.4.3')
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$candidate = Join-Path $repo "output/checkout-update-$Version/off-label-checkout-test"
$original = 'C:/Users/User/Downloads/off-label-checkout-test'
$files = @('off-label-checkout-test.php', 'assets/checkout-test.js', 'assets/checkout-test.css')
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression
function Package-Checkout($source, $target) {
    $stream = [IO.File]::Open($target, [IO.FileMode]::CreateNew)
    $zip = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in $files) {
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, (Join-Path $source $file), "off-label-checkout-test/$file") | Out-Null
        }
    } finally { $zip.Dispose(); $stream.Dispose() }
    $zip = [IO.Compression.ZipFile]::OpenRead($target)
    try {
        if ($zip.Entries.Count -ne 3) { throw 'Unexpected ZIP contents' }
        foreach ($file in $files) {
            $entry = $zip.GetEntry("off-label-checkout-test/$file")
            if (-not $entry) { throw "Missing $file" }
            $hash = [Security.Cryptography.SHA256]::Create()
            $entryStream = $entry.Open()
            try { $actual = [BitConverter]::ToString($hash.ComputeHash($entryStream)).Replace('-', '') }
            finally { $entryStream.Dispose(); $hash.Dispose() }
            $expected = (Get-FileHash -LiteralPath (Join-Path $source $file) -Algorithm SHA256).Hash
            if ($actual -ne $expected) { throw "ZIP content differs: $file" }
        }
    } finally { $zip.Dispose() }
    Get-FileHash -LiteralPath $target -Algorithm SHA256
}
Package-Checkout $candidate (Join-Path $repo "wordpress-plugins/_deploy/off-label-checkout-test-v$Version.zip")
$rollback = Join-Path $repo 'wordpress-plugins/_deploy/off-label-checkout-test-v1.4.1-ROLLBACK.zip'
if (-not (Test-Path -LiteralPath $rollback)) { Package-Checkout $original $rollback }
