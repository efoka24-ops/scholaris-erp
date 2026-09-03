# Construit l'archive de deploiement entree par entree, sans dossier de staging.
# Les chemins dans le zip utilisent "/" pour que l'extraction cote Linux
# reconstruise correctement l'arborescence.

param(
    [Parameter(Mandatory = $true)][string]$Source,
    [Parameter(Mandatory = $true)][string]$Destination,
    [Parameter(Mandatory = $true)][string]$EnvFile
)

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

if (Test-Path $Destination) { Remove-Item $Destination -Force }

$excludedDirs = @('node_modules', '.git', 'tests')

$files = Get-ChildItem -Path $Source -Recurse -File -Force | Where-Object {
    $rel = $_.FullName.Substring($Source.Length).TrimStart('\')
    $parts = $rel -split '\\'

    # Exclut les dossiers de premier niveau non deployables, la base SQLite de
    # developpement et le .env local (remplace par celui de production).
    if ($parts.Length -gt 1 -and $excludedDirs -contains $parts[0]) { return $false }
    if ($_.Name -eq 'database.sqlite') { return $false }
    if ($rel -eq '.env') { return $false }

    return $true
}

$zip = [System.IO.Compression.ZipFile]::Open($Destination, [System.IO.Compression.ZipArchiveMode]::Create)

try {
    foreach ($file in $files) {
        $entryName = ($file.FullName.Substring($Source.Length).TrimStart('\')) -replace '\\', '/'
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $file.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal
        )
    }

    # .env de production, ajoute sous son nom final.
    [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
        $zip, $EnvFile, '.env', [System.IO.Compression.CompressionLevel]::Optimal
    )
} finally {
    $zip.Dispose()
}

$archive = [System.IO.Compression.ZipFile]::OpenRead($Destination)
$entryCount = $archive.Entries.Count
$hasHtaccess = ($archive.Entries | Where-Object { $_.FullName -eq '.htaccess' }).Count -eq 1
$hasEnv = ($archive.Entries | Where-Object { $_.FullName -eq '.env' }).Count -eq 1
$hasVendor = ($archive.Entries | Where-Object { $_.FullName -eq 'vendor/autoload.php' }).Count -eq 1
$archive.Dispose()

Write-Output "entrees: $entryCount"
Write-Output ".htaccess: $hasHtaccess"
Write-Output ".env: $hasEnv"
Write-Output "vendor/autoload.php: $hasVendor"
Write-Output "taille: $([math]::Round((Get-Item $Destination).Length/1MB,1)) Mo"
