Add-Type -AssemblyName System.Drawing
$sourcePath = 'C:\Users\User\.codex\generated_images\01a06a98-ef60-7fc3-b804-1748b458f225\exec-bd826446-abe8-4939-8283-9252e9a36512.png'
$targetPath = 'C:\Users\User\Desktop\offlabel-custom\exports\website-product-image-library\images\ss-31-10mg.jpg'
if (Test-Path -LiteralPath $targetPath) { throw 'Refusing to overwrite existing bottle artwork.' }
$source = [System.Drawing.Image]::FromFile($sourcePath)
$bitmap = [System.Drawing.Bitmap]::new(1150,1600)
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
try {
    $graphics.Clear([System.Drawing.Color]::White)
    $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $graphics.DrawImage($source,0,0,1150,1600)
    $codec = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() | Where-Object MimeType -eq 'image/jpeg'
    $parameters = [System.Drawing.Imaging.EncoderParameters]::new(1)
    $parameters.Param[0] = [System.Drawing.Imaging.EncoderParameter]::new([System.Drawing.Imaging.Encoder]::Quality,[long]95)
    try { $bitmap.Save($targetPath,$codec,$parameters) } finally { $parameters.Dispose() }
} finally { $graphics.Dispose(); $bitmap.Dispose(); $source.Dispose() }
$check = [System.Drawing.Image]::FromFile($targetPath)
try {
    if ($check.Width -ne 1150 -or $check.Height -ne 1600) { throw 'Incorrect export dimensions.' }
    Write-Output "Verified 1150 x 1600 JPEG: $targetPath"
} finally { $check.Dispose() }
