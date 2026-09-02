$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$pluginPath = Join-Path $repoRoot 'wordpress-plugins/off-label-account-hub/off-label-account-hub.php'
$stylePath = Join-Path $repoRoot 'wordpress-plugins/off-label-account-hub/assets/account-hub.css'
$bridgePath = Join-Path $repoRoot 'gitpress/woocommerce-bridge.php'
$headerPath = Join-Path $repoRoot 'gitpress/partials/header.template.html'

$plugin = Get-Content -LiteralPath $pluginPath -Raw -Encoding UTF8
$styles = Get-Content -LiteralPath $stylePath -Raw -Encoding UTF8
$bridge = Get-Content -LiteralPath $bridgePath -Raw -Encoding UTF8
$header = Get-Content -LiteralPath $headerPath -Raw -Encoding UTF8

$checks = [ordered]@{
    'Affiliate landing shortcode is registered' = $plugin.Contains("add_shortcode( 'olr_affiliate_landing'")
    'Affiliate guidelines shortcode is registered' = $plugin.Contains("add_shortcode( 'olr_affiliate_guidelines'")
    'Public pages are created as drafts' = $plugin.Contains("'post_status'    => 'draft'")
    'Approved 20 percent customer offer is present' = $plugin.Contains("'customer_discount' => '20%'")
    'Approved 10 percent commission is present' = $plugin.Contains("'commission'        => '10%'")
    'HPOS-safe order lookup is used' = $plugin.Contains('wc_get_order')
    'No mock affiliate name is embedded' = -not $plugin.Contains('SAMANTHA')
    'Guidelines include all 19 numbered sections' = ([regex]::Matches($plugin, "array\( '(?:0[1-9]|1[0-9])',").Count -eq 19)
    'Landing page fragment exists' = Test-Path -LiteralPath (Join-Path $repoRoot 'gitpress/pages/affiliate.html')
    'Guidelines page fragment exists' = Test-Path -LiteralPath (Join-Path $repoRoot 'gitpress/pages/affiliate-guidelines.html')
    'GitPress bridge allowlists landing shortcode' = $bridge.Contains("'olr_affiliate_landing'")
    'GitPress bridge allowlists guidelines shortcode' = $bridge.Contains("'olr_affiliate_guidelines'")
    'Desktop and mobile headers include Affiliate' = ([regex]::Matches($header, 'data-nav="affiliate"').Count -eq 2)
    'Guidelines use four-column desktop layout' = $styles.Contains('grid-template-columns: repeat(4, minmax(0, 1fr));')
    'Guidelines include single-column phone layout' = $styles.Contains('.olr-affiliate-guidelines__grid') -and $styles.Contains('@media (max-width: 38rem)')
}

$failed = $false
foreach ($check in $checks.GetEnumerator()) {
    if ($check.Value) {
        Write-Output "PASS: $($check.Key)"
    } else {
        Write-Error "FAIL: $($check.Key)"
        $failed = $true
    }
}

if ($failed) {
    exit 1
}

Write-Output 'Affiliate surface checks completed.'
