[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$cssPath = Join-Path $repositoryRoot 'styles.css'
$templatePath = Join-Path $PSScriptRoot 'partials\header.template.html'
$outputPath = Join-Path $PSScriptRoot 'partials\header.html'
$placeholder = '/*__OLR_INLINE_CSS__*/'

if ( -not ( Test-Path -LiteralPath $cssPath -PathType Leaf ) ) {
	throw "Canonical stylesheet is missing: $cssPath"
}

if ( -not ( Test-Path -LiteralPath $templatePath -PathType Leaf ) ) {
	throw "Header template is missing: $templatePath"
}

$css = [System.IO.File]::ReadAllText( $cssPath )
$template = [System.IO.File]::ReadAllText( $templatePath )
$placeholderCount = ( [regex]::Matches( $template, [regex]::Escape( $placeholder ) ) ).Count

if ( [string]::IsNullOrWhiteSpace( $css ) ) {
	throw "Canonical stylesheet is empty: $cssPath"
}

if ( $css -match '(?i)</style\s*>' ) {
	throw 'Canonical stylesheet contains a closing style tag and cannot be embedded safely.'
}

if ( 1 -ne $placeholderCount ) {
	throw "Header template must contain the inline CSS placeholder exactly once: $templatePath"
}

$output = $template.Replace( $placeholder, $css.Trim() )
$encoding = [System.Text.UTF8Encoding]::new( $false )
[System.IO.File]::WriteAllText( $outputPath, $output, $encoding )

Write-Output "Built $outputPath with embedded CSS from $cssPath"
