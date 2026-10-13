<#
.SYNOPSIS
  Construit le front-end SCHOLARIS (Vue 3 + Vite) et le copie dans public/, servi a la racine du site.

.DESCRIPTION
  1. npm ci (ou npm install si aucun verrou) dans web/ ; si l'installation echoue, rien n'est construit ni copie.
  2. npm run build : verification de types puis vite build (base /) vers web/dist.
  3. Copie web/dist dans public/ : seuls les fichiers du front sont remplaces (index.html, assets, icons, sw.js,
     workbox-*.js, manifest, favicon). public/index.php (l'API) et public/.htaccess ne sont jamais touches.
  Aucun deploiement, aucun FTP : ce script ne fait que preparer les fichiers.

.PARAMETER SkipTests
  Ne lance pas les tests Vitest avant la construction.
#>
[CmdletBinding()]
param([switch]$SkipTests)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$web = Join-Path $root 'web'
$dist = Join-Path $web 'dist'
$target = Join-Path $root 'public'

if (-not (Test-Path (Join-Path $web 'package.json'))) { throw "web/package.json introuvable : $web" }

Push-Location $web
try {
  Write-Host '== Installation des dependances'
  if (Test-Path 'package-lock.json') { npm ci } else { npm install }
  if ($LASTEXITCODE -ne 0) { throw "npm install a echoue (code $LASTEXITCODE) : rien n'a ete construit ni copie." }

  if (-not $SkipTests) {
    Write-Host '== Tests'
    npm test
    if ($LASTEXITCODE -ne 0) { throw "Les tests ont echoue (code $LASTEXITCODE) : construction annulee." }
  }

  Write-Host '== Construction (base /)'
  npm run build
  if ($LASTEXITCODE -ne 0) { throw "La construction a echoue (code $LASTEXITCODE)." }
} finally {
  Pop-Location
}

if (-not (Test-Path (Join-Path $dist 'index.html'))) { throw "web/dist/index.html absent apres la construction." }

Write-Host "== Copie vers $target"
# Ancien emplacement (/app/) : supprime pour eviter deux copies du front.
$old = Join-Path $target 'app'
if (Test-Path $old) { Remove-Item -Recurse -Force $old }

# Fichiers du front precedent (jamais index.php ni .htaccess).
foreach ($name in @('assets', 'icons')) {
  $p = Join-Path $target $name
  if (Test-Path $p) { Remove-Item -Recurse -Force $p }
}
foreach ($pattern in @('index.html', 'sw.js', 'workbox-*.js', 'manifest.webmanifest', 'favicon.svg', 'registerSW.js')) {
  Get-ChildItem -Path $target -Filter $pattern -File -ErrorAction SilentlyContinue | Remove-Item -Force
}

foreach ($item in Get-ChildItem -Path $dist -Force) {
  if ($item.Name -in @('index.php', '.htaccess')) { continue }
  Copy-Item -Path $item.FullName -Destination $target -Recurse -Force
}
if (-not (Test-Path (Join-Path $target 'index.html'))) { throw 'public/index.html absent apres la copie.' }
if (-not (Test-Path (Join-Path $target 'index.php'))) { throw 'public/index.php absent : l''API ne demarrerait plus.' }

Write-Host "Termine : le front est dans public/ (index.html, assets, icons, sw.js)."
