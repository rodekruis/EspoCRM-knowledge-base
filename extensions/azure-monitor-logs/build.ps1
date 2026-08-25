#Requires -Version 5.1
<#
.SYNOPSIS
    Packages the extension into an installable EspoCRM .zip.
.DESCRIPTION
    Builds the archive through System.IO.Compression directly rather than
    Compress-Archive, because Compress-Archive on Windows PowerShell writes
    backslash separators into entry names, which PHP's ZipArchive on Linux
    extracts as literal filenames instead of a directory tree.
#>
[CmdletBinding()]
param(
    [string] $OutputDirectory = (Join-Path $PSScriptRoot 'build')
)

$ErrorActionPreference = 'Stop'

if (-not ('System.IO.Compression.ZipFile' -as [type])) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
}

$manifest = Get-Content -Raw -Path (Join-Path $PSScriptRoot 'manifest.json') | ConvertFrom-Json
$version = $manifest.version
$zipPath = Join-Path $OutputDirectory "azure-monitor-logs-$version.zip"

$include = @('manifest.json', 'files', 'scripts')

New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null

if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

$root = (Resolve-Path $PSScriptRoot).Path.TrimEnd([char]92, [char]47)
$archive = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')

try {
    foreach ($item in $include) {
        $path = Join-Path $PSScriptRoot $item

        if (-not (Test-Path $path)) {
            throw "Missing required path: $item"
        }

        $files = if (Test-Path $path -PathType Container) {
            Get-ChildItem $path -Recurse -File
        }
        else {
            Get-Item $path
        }

        foreach ($file in $files) {
            $entryName = $file.FullName.Substring($root.Length + 1).Replace([char]92, [char]47)

            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive, $file.FullName, $entryName, 'Optimal') | Out-Null
        }
    }
}
finally {
    $archive.Dispose()
}

$reader = [System.IO.Compression.ZipFile]::OpenRead($zipPath)

try {
    $names = @($reader.Entries | ForEach-Object { $_.FullName })
}
finally {
    $reader.Dispose()
}

if ($names | Where-Object { $_.Contains([char]92) }) {
    throw 'Archive contains backslash entry paths; it would not install on Linux.'
}

if ($names -notcontains 'manifest.json') {
    throw 'Archive is missing manifest.json at the root.'
}

Write-Host "Built $zipPath ($($names.Count) entries)"
