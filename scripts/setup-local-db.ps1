<#
.SYNOPSIS
  Setup PostgreSQL local pour le développement SCHOLARIS ERP (Windows, sans Docker).
  Télécharge PostgreSQL portable via pgenv ou utilise une installation existante.
#>
param(
  [string]$PgBinDir = ""
)

$ErrorActionPreference = "Stop"

# ─── 1. Détection de PostgreSQL ──────────────────────────────────────────────
function Find-Pg {
  # Cherche pg_isready dans le PATH ou les emplacements standards
  $candidates = @(
    (Get-Command pg_isready -ErrorAction SilentlyContinue)?.Source
    "C:\Program Files\PostgreSQL\16\bin\pg_isready.exe"
    "C:\Program Files\PostgreSQL\17\bin\pg_isready.exe"
    "C:\Program Files\PostgreSQL\15\bin\pg_isready.exe"
  )
  foreach ($c in $candidates) {
    if ($c -and (Test-Path $c)) { return (Split-Path $c) }
  }
  return $null
}

$pgBin = if ($PgBinDir) { $PgBinDir } else { Find-Pg }

if (-not $pgBin) {
  Write-Host "===============================================" -ForegroundColor Red
  Write-Host " PostgreSQL n'est pas installé sur cette machine." -ForegroundColor Red
  Write-Host "===============================================" -ForegroundColor Red
  Write-Host ""
  Write-Host "Options :" -ForegroundColor Yellow
  Write-Host "  1. Installer PostgreSQL : https://www.postgresql.org/download/windows/"
  Write-Host "     (cocher 'Add to PATH' pendant l'installation)"
  Write-Host ""
  Write-Host "  2. Utiliser la base Railway distante (modifier DATABASE_URL dans .env) :"
  Write-Host "     DATABASE_URL=postgresql://postgres:xxx@reseau.proxy.rlwy.net:55973/railway"
  Write-Host ""
  Write-Host "Après installation, relancez ce script." -ForegroundColor Cyan
  exit 1
}

Write-Host "PostgreSQL trouvé : $pgBin" -ForegroundColor Green

# ─── 2. Vérifier si le serveur tourne ────────────────────────────────────────
$pgReady = & "$pgBin\pg_isready" -h localhost -p 5432 2>&1
if ($LASTEXITCODE -ne 0) {
  Write-Host "PostgreSQL n'écoute pas sur localhost:5432." -ForegroundColor Yellow
  Write-Host "Démarrez le service PostgreSQL puis relancez ce script." -ForegroundColor Yellow
  Write-Host "  → Services Windows : Win+R > services.msc > postgresql-x64-16 > Démarrer"
  exit 1
}

Write-Host "PostgreSQL est actif sur localhost:5432" -ForegroundColor Green

# ─── 3. Créer la base et l'utilisateur ───────────────────────────────────────
$env:PGPASSWORD = "postgres"  # mot de passe par défaut de l'installation Windows

# Créer l'utilisateur scholaris s'il n'existe pas
& "$pgBin\psql" -h localhost -U postgres -p 5432 -tc "SELECT 1 FROM pg_roles WHERE rolname='scholaris'" | ForEach-Object {
  if ($_.Trim() -ne "1") {
    Write-Host "Création de l'utilisateur 'scholaris'..." -ForegroundColor Cyan
    & "$pgBin\psql" -h localhost -U postgres -p 5432 -c "CREATE USER scholaris WITH PASSWORD 'scholaris_dev_password' CREATEDB;"
  } else {
    Write-Host "Utilisateur 'scholaris' existe déjà." -ForegroundColor Green
  }
}

# Créer la base scholaris si elle n'existe pas
& "$pgBin\psql" -h localhost -U postgres -p 5432 -tc "SELECT 1 FROM pg_database WHERE datname='scholaris'" | ForEach-Object {
  if ($_.Trim() -ne "1") {
    Write-Host "Création de la base 'scholaris'..." -ForegroundColor Cyan
    & "$pgBin\psql" -h localhost -U postgres -p 5432 -c "CREATE DATABASE scholaris OWNER scholaris;"
  } else {
    Write-Host "Base 'scholaris' existe déjà." -ForegroundColor Green
  }
}

Remove-Item Env:\PGPASSWORD -ErrorAction SilentlyContinue

# ─── 4. Prisma : generate + migrate + seed ───────────────────────────────────
Write-Host ""
Write-Host "Exécution des migrations Prisma..." -ForegroundColor Cyan
Set-Location $PSScriptRoot

npm run db:generate
npm run db:migrate
npm run db:seed

Write-Host ""
Write-Host "=============================================" -ForegroundColor Green
Write-Host " Setup local terminé avec succès !" -ForegroundColor Green
Write-Host "=============================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Base de données : postgresql://scholaris:scholaris_dev_password@localhost:5432/scholaris"
Write-Host "  Super admin     : admin@scholaris.dev / ChangeMe123!"
Write-Host ""
Write-Host "  Lancer le backend  : npm run dev --workspace=@scholaris/api"
Write-Host "  Lancer le frontend : npm run dev --workspace=@scholaris/web"
Write-Host ""
