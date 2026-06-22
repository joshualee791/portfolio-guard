param(
    [Parameter(Mandatory = $true)]
    [string]$SourceDir,

    [Parameter(Mandatory = $true)]
    [string]$DestinationZip
)

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Get-NormalizedPath {
    param([string]$Path)

    return [System.IO.Path]::GetFullPath($Path)
}

function Get-RelativeZipPath {
    param(
        [string]$BaseDir,
        [string]$FullPath
    )

    $baseUri = [System.Uri]((Get-NormalizedPath $BaseDir).TrimEnd('\') + '\')
    $fullUri = [System.Uri](Get-NormalizedPath $FullPath)
    $relative = $baseUri.MakeRelativeUri($fullUri).ToString()

    return $relative -replace '\\', '/'
}

$sourceRoot = Get-NormalizedPath $SourceDir

if (-not (Test-Path -LiteralPath $sourceRoot -PathType Container)) {
    throw "Source directory does not exist: $SourceDir"
}

$pluginFolderName = Split-Path -Path $sourceRoot -Leaf
$destinationPath = Get-NormalizedPath $DestinationZip
$destinationParent = Split-Path -Path $destinationPath -Parent

if (-not (Test-Path -LiteralPath $destinationParent -PathType Container)) {
    New-Item -ItemType Directory -Path $destinationParent -Force | Out-Null
}

if (Test-Path -LiteralPath $destinationPath) {
    Remove-Item -LiteralPath $destinationPath -Force
}

$fileStream = [System.IO.File]::Open($destinationPath, [System.IO.FileMode]::CreateNew)

try {
    $archive = New-Object System.IO.Compression.ZipArchive($fileStream, [System.IO.Compression.ZipArchiveMode]::Create, $false)

    try {
        $archive.CreateEntry("$pluginFolderName/") | Out-Null

        $directories = Get-ChildItem -LiteralPath $sourceRoot -Directory -Recurse | Sort-Object FullName
        foreach ($directory in $directories) {
            $relativeDir = Get-RelativeZipPath -BaseDir $sourceRoot -FullPath $directory.FullName
            $archive.CreateEntry("$pluginFolderName/$relativeDir/") | Out-Null
        }

        $files = Get-ChildItem -LiteralPath $sourceRoot -File -Recurse | Sort-Object FullName
        foreach ($file in $files) {
            $relativeFile = Get-RelativeZipPath -BaseDir $sourceRoot -FullPath $file.FullName
            $entryPath = "$pluginFolderName/$relativeFile"
            $entry = $archive.CreateEntry($entryPath, [System.IO.Compression.CompressionLevel]::Optimal)

            $entryStream = $entry.Open()
            try {
                $inputStream = [System.IO.File]::OpenRead($file.FullName)
                try {
                    $inputStream.CopyTo($entryStream)
                } finally {
                    $inputStream.Dispose()
                }
            } finally {
                $entryStream.Dispose()
            }
        }
    } finally {
        $archive.Dispose()
    }
} finally {
    $fileStream.Dispose()
}

Write-Output "Created canonical plugin ZIP: $destinationPath"
