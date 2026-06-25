#Requires -Version 5.1
<#
.SYNOPSIS
    Downloads and stages the clean plugin corpus for Portfolio Guard validation.

.DESCRIPTION
    Reads validation/corpus/clean-plugins/manifest.json, downloads each plugin from
    the pinned source URL, verifies the SHA-256 checksum (if populated), and extracts
    the plugin into the correct fixture directory.

    Idempotent: already-staged directories whose checksums match are skipped.

    On first run, if sha256_zip is empty in the manifest, the computed hash is
    printed. Populate the manifest entry and re-run to enable verification.

.NOTES
    Staged directories are gitignored. Run this script before executing gate.php.
#>

$ErrorActionPreference = 'Stop'

$ScriptDir    = Split-Path -Parent $MyInvocation.MyCommand.Path
$ManifestPath = Join-Path (Split-Path -Parent $ScriptDir) 'corpus\clean-plugins\manifest.json'
$CorpusRoot   = Join-Path (Split-Path -Parent $ScriptDir) 'corpus\clean-plugins'

if (-not (Test-Path $ManifestPath)) {
    Write-Error "Manifest not found: $ManifestPath"
    exit 2
}

$manifest = Get-Content $ManifestPath -Raw | ConvertFrom-Json
$entries  = $manifest.entries

$staged = 0
$failed = 0

foreach ($entry in $entries) {
    $targetDir = Join-Path $CorpusRoot $entry.fixture_dir

    # Check if already staged with matching checksum
    if ((Test-Path $targetDir) -and $entry.sha256_zip -ne '') {
        $zipCache = Join-Path $env:TEMP "$($entry.plugin_slug)-$($entry.version).zip"
        if (Test-Path $zipCache) {
            $existing = (Get-FileHash $zipCache -Algorithm SHA256).Hash.ToLower()
            if ($existing -eq $entry.sha256_zip.ToLower()) {
                Write-Host "[SKIP] $($entry.plugin_slug) $($entry.version) — already staged"
                $staged++
                continue
            }
        } else {
            Write-Host "[SKIP] $($entry.plugin_slug) $($entry.version) — already staged (no cached zip)"
            $staged++
            continue
        }
    }

    $zipPath = Join-Path $env:TEMP "$($entry.plugin_slug)-$($entry.version).zip"

    Write-Host "[DOWN] $($entry.plugin_slug) $($entry.version) ..."

    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -Uri $entry.source_url -OutFile $zipPath -UseBasicParsing
    } catch {
        Write-Host "[FAIL] $($entry.plugin_slug) — download failed: $_"
        $failed++
        continue
    }

    $actualHash = (Get-FileHash $zipPath -Algorithm SHA256).Hash.ToLower()

    if ($entry.sha256_zip -ne '' -and $entry.sha256_zip -ne $null) {
        $expectedHash = $entry.sha256_zip.ToLower()
        if ($actualHash -ne $expectedHash) {
            Write-Host "[FAIL] $($entry.plugin_slug) — checksum mismatch"
            Write-Host "       expected: $expectedHash"
            Write-Host "       actual:   $actualHash"
            Remove-Item $zipPath -Force -ErrorAction SilentlyContinue
            $failed++
            continue
        }
    } else {
        Write-Host "[INFO] $($entry.plugin_slug) sha256_zip not set."
        Write-Host "       Computed: $actualHash"
        Write-Host "       Populate manifest.json to enable future verification."
    }

    # Extract
    $extractTemp = Join-Path $env:TEMP "$($entry.plugin_slug)-$($entry.version)-extract"
    if (Test-Path $extractTemp) {
        Remove-Item $extractTemp -Recurse -Force
    }

    try {
        Expand-Archive -Path $zipPath -DestinationPath $extractTemp -Force
    } catch {
        Write-Host "[FAIL] $($entry.plugin_slug) — extraction failed: $_"
        Remove-Item $zipPath -Force -ErrorAction SilentlyContinue
        $failed++
        continue
    }

    # WordPress plugin zips contain a single top-level directory matching the slug
    $pluginDirInZip = Get-ChildItem -Path $extractTemp -Directory | Select-Object -First 1
    if (-not $pluginDirInZip) {
        Write-Host "[FAIL] $($entry.plugin_slug) — no plugin directory found in zip"
        $failed++
        continue
    }

    if (Test-Path $targetDir) {
        Remove-Item $targetDir -Recurse -Force
    }

    Move-Item -Path $pluginDirInZip.FullName -Destination $targetDir
    Remove-Item $extractTemp -Recurse -Force -ErrorAction SilentlyContinue

    Write-Host "[OK]   $($entry.plugin_slug) $($entry.version) staged to $($entry.fixture_dir)"
    $staged++
}

Write-Host ""
Write-Host "Staging complete: $staged staged, $failed failed."

if ($failed -gt 0) {
    exit 1
}
exit 0
