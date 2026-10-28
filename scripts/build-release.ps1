<#
 Construit un paquet de déploiement propre dans un dossier de sortie, sans toucher au dépôt de travail :
   - copie le code utile (pas de tests, specs, .git, .env, outils de développement) ;
   - installe les dépendances de production sous PHP 8.1 (la version du serveur de recette) ;
   - génère l'autoloader optimisé.
 Usage : powershell -File scripts/build-release.ps1 [-Out C:\chemin\release]
 Ensuite : powershell -File scripts/deploy-ftp.ps1 -LocalRoot <dossier de sortie> -DryRun
#>
param(
	[string]$Out = (Join-Path $env:TEMP "scholaris-release"),
	[string]$Zip = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
if (Test-Path $Out) { Remove-Item -Recurse -Force $Out }
New-Item -ItemType Directory -Path $Out | Out-Null

$include = @("public", "src", "config", "database", "lang", "artisan", "cron.php", "composer.json", "composer.lock", ".htaccess")
foreach ($item in $include) {
	$source = Join-Path $root $item
	if (-not (Test-Path $source)) { continue }
	Copy-Item -Path $source -Destination (Join-Path $Out $item) -Recurse -Force
}

# Dossiers d'exécution (le serveur y écrit : journaux et cache).
foreach ($dir in @("storage\logs", "storage\cache")) {
	New-Item -ItemType Directory -Path (Join-Path $Out $dir) -Force | Out-Null
}

# Les migrations SQL des modules sont dans src/ ; les fichiers de test éventuels n'ont rien à faire en production.
Get-ChildItem -Path $Out -Recurse -Include "*.sqlite", "*.bak", ".gitkeep" -Force | Remove-Item -Force

Push-Location $Out
try {
	& composer install --no-dev --optimize-autoloader --no-interaction --no-progress
	if ($LASTEXITCODE -ne 0) { throw "composer install a échoué." }
} finally {
	Pop-Location
}

$count = (Get-ChildItem -Path $Out -Recurse -File | Measure-Object).Count
Write-Host "Paquet prêt : $Out ($count fichiers)." -ForegroundColor Green

if ($Zip -ne "") {
	Add-Type -AssemblyName System.IO.Compression
	Add-Type -AssemblyName System.IO.Compression.FileSystem
	if (Test-Path $Zip) { Remove-Item -Force $Zip }
	$archive = [System.IO.Compression.ZipFile]::Open($Zip, [System.IO.Compression.ZipArchiveMode]::Create)
	try {
		$base = (Resolve-Path $Out).Path.TrimEnd("\") + "\"
		foreach ($file in Get-ChildItem -Path $Out -Recurse -File -Force) {
			$entryName = $file.FullName.Substring($base.Length).Replace("\", "/")
			[void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal)
		}
	} finally {
		$archive.Dispose()
	}
	$size = [math]::Round((Get-Item $Zip).Length / 1MB, 1)
	Write-Host "Archive prête : $Zip ($size Mo)." -ForegroundColor Green
}
