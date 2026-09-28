[CmdletBinding()]
param(
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string] $Version = '1.8.3'
)

$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$versionSource = Get-Content -Raw -LiteralPath (Join-Path $repoRoot 'src\Version.php')
$versionPattern = "public const NUMBER = '$([regex]::Escape($Version))';"
if ($versionSource -notmatch $versionPattern) {
    throw "src/Version.php does not declare version $Version."
}

$mainPlugin = Get-Content -Raw -LiteralPath (Join-Path $repoRoot 'site-add-on-watchdog.php')
if ($mainPlugin -notmatch "(?m)^\s*\* Version:\s+$([regex]::Escape($Version))\s*$") {
    throw "The main plugin header does not declare version $Version."
}

$readme = Get-Content -Raw -LiteralPath (Join-Path $repoRoot 'readme.txt')
if ($readme -notmatch "(?m)^Stable tag:\s+$([regex]::Escape($Version))\s*$") {
    throw "readme.txt does not declare Stable tag $Version."
}

$distRoot = Join-Path $repoRoot 'dist'
$stagingRoot = Join-Path $distRoot '.staging'
$packageName = "site-add-on-watchdog-$Version"
$packageRoot = Join-Path $stagingRoot 'site-add-on-watchdog'
$zipPath = Join-Path $distRoot "$packageName.zip"

foreach ($path in @($distRoot, $stagingRoot, $packageRoot, $zipPath)) {
    $absolute = [System.IO.Path]::GetFullPath($path)
    if (-not $absolute.StartsWith($repoRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Release path escaped the repository: $absolute"
    }
}

$runtimePaths = @(
    'assets',
    'languages',
    'src',
    'templates',
    'CHANGELOG.md',
    'readme.txt',
    'site-add-on-watchdog.php'
)

if (Test-Path -LiteralPath $stagingRoot) {
    Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}
if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null

try {
    foreach ($relativePath in $runtimePaths) {
        $source = Join-Path $repoRoot $relativePath
        if (-not (Test-Path -LiteralPath $source)) {
            throw "Required release path is missing: $relativePath"
        }

        Copy-Item -LiteralPath $source -Destination $packageRoot -Recurse -Force
    }

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::Open(
        $zipPath,
        [System.IO.Compression.ZipArchiveMode]::Create
    )
    try {
        foreach ($file in Get-ChildItem -LiteralPath $stagingRoot -File -Recurse) {
            $entryName = $file.FullName.Substring($stagingRoot.Length + 1).Replace('\', '/')
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $file.FullName,
                $entryName,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        $archive.Dispose()
    }

    $verificationArchive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    try {
        $entryNames = @($verificationArchive.Entries | ForEach-Object { $_.FullName })
        if (-not ($entryNames -contains 'site-add-on-watchdog/site-add-on-watchdog.php')) {
            throw 'Release ZIP is missing the main plugin file at the expected path.'
        }
        if ($entryNames | Where-Object { $_ -match '\\' }) {
            throw 'Release ZIP contains Windows path separators and cannot be published.'
        }
    } finally {
        $verificationArchive.Dispose()
    }
} finally {
    if (Test-Path -LiteralPath $stagingRoot) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force
    }
}

$zip = Get-Item -LiteralPath $zipPath
Write-Output "Built $($zip.FullName) ($($zip.Length) bytes)"
