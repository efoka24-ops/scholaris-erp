#!/usr/bin/env pwsh
<#
.SYNOPSIS
  Diagnostic rapide de l'environnement Railway avant déploiement
  
.DESCRIPTION
  Vérifie que tous les prérequis sont remplis pour un déploiement Railway réussi
#>

param(
    [switch]$Verbose
)

$ErrorActionPreference = "Continue"
$checks = @()

Write-Host ""
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host " 🔍 SCHOLARIS ERP - Diagnostic Railway" -ForegroundColor Cyan
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

# ─── Check 1: Railway CLI installée ────────────────────────────────────────
Write-Host "🔧 Vérification de Railway CLI..." -NoNewline
try {
    $railwayVersion = railway --version 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host " ✅" -ForegroundColor Green
        if ($Verbose) { Write-Host "   Version: $railwayVersion" -ForegroundColor Gray }
        $checks += @{ Name = "Railway CLI"; Status = "OK"; Details = $railwayVersion }
    } else {
        throw "Railway CLI non trouvée"
    }
} catch {
    Write-Host " ❌" -ForegroundColor Red
    Write-Host "   Railway CLI n'est pas installée" -ForegroundColor Yellow
    Write-Host "   Installation: npm i -g @railway/cli" -ForegroundColor Gray
    $checks += @{ Name = "Railway CLI"; Status = "MANQUANT"; Details = "Non installée" }
}

# ─── Check 2: Git repository ───────────────────────────────────────────────
Write-Host "📦 Vérification du repository Git..." -NoNewline
try {
    $gitRemote = git remote get-url origin 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host " ✅" -ForegroundColor Green
        if ($Verbose) { Write-Host "   Remote: $gitRemote" -ForegroundColor Gray }
        $checks += @{ Name = "Git Remote"; Status = "OK"; Details = $gitRemote }
    } else {
        throw "Pas de remote Git"
    }
} catch {
    Write-Host " ⚠️" -ForegroundColor Yellow
    Write-Host "   Aucun remote Git configuré" -ForegroundColor Yellow
    $checks += @{ Name = "Git Remote"; Status = "ATTENTION"; Details = "Pas de remote" }
}

# ─── Check 3: Dernier commit poussé ────────────────────────────────────────
Write-Host "🚀 Vérification du dernier commit..." -NoNewline
try {
    $lastCommit = git log -1 --oneline 2>&1
    Write-Host " ✅" -ForegroundColor Green
    if ($Verbose) { Write-Host "   $lastCommit" -ForegroundColor Gray }
    
    # Vérifier si le commit avec bcrypt fix est présent
    $bcryptFix = git log --oneline --all -10 | Select-String "rebuild bcrypt"
    if ($bcryptFix) {
        Write-Host "   ✓ Commit bcrypt fix trouvé" -ForegroundColor Green
        $checks += @{ Name = "Bcrypt Fix"; Status = "OK"; Details = "Présent dans l'historique" }
    } else {
        Write-Host "   ⚠️  Commit bcrypt fix non trouvé dans les 10 derniers commits" -ForegroundColor Yellow
        $checks += @{ Name = "Bcrypt Fix"; Status = "ATTENTION"; Details = "Commit pas trouvé" }
    }
} catch {
    Write-Host " ❌" -ForegroundColor Red
    $checks += @{ Name = "Git Commits"; Status = "ERREUR"; Details = $_.Exception.Message }
}

# ─── Check 4: Fichiers de déploiement ──────────────────────────────────────
Write-Host "📄 Vérification des fichiers de déploiement..." -NoNewline
$deployFiles = @("Dockerfile", "railway.json", ".dockerignore")
$allPresent = $true
foreach ($file in $deployFiles) {
    if (-not (Test-Path $file)) {
        Write-Host " ❌" -ForegroundColor Red
        Write-Host "   Fichier manquant: $file" -ForegroundColor Yellow
        $allPresent = $false
        $checks += @{ Name = $file; Status = "MANQUANT"; Details = "Fichier requis absent" }
    }
}
if ($allPresent) {
    Write-Host " ✅" -ForegroundColor Green
    $checks += @{ Name = "Fichiers déploiement"; Status = "OK"; Details = "Tous présents" }
}

# ─── Check 5: Prisma schema ────────────────────────────────────────────────
Write-Host "🗄️  Vérification du schéma Prisma..." -NoNewline
$prismaSchema = "packages/prisma/prisma/schema.prisma"
if (Test-Path $prismaSchema) {
    Write-Host " ✅" -ForegroundColor Green
    $checks += @{ Name = "Prisma Schema"; Status = "OK"; Details = "Trouvé" }
    
    # Vérifier que DATABASE_URL est bien dans le schema
    $schemaContent = Get-Content $prismaSchema -Raw
    if ($schemaContent -match 'url\s*=\s*env\("DATABASE_URL"\)') {
        if ($Verbose) { Write-Host "   ✓ DATABASE_URL référencée" -ForegroundColor Gray }
    }
} else {
    Write-Host " ❌" -ForegroundColor Red
    Write-Host "   Schema Prisma non trouvé" -ForegroundColor Yellow
    $checks += @{ Name = "Prisma Schema"; Status = "MANQUANT"; Details = "Non trouvé" }
}

# ─── Check 6: Package.json validité ────────────────────────────────────────
Write-Host "📦 Vérification des package.json..." -NoNewline
try {
    $rootPkg = Get-Content "package.json" -Raw | ConvertFrom-Json
    $apiPkg = Get-Content "apps/api/package.json" -Raw | ConvertFrom-Json
    
    # Vérifier que bcrypt est bien dans les dependencies
    if ($apiPkg.dependencies.bcrypt) {
        Write-Host " ✅" -ForegroundColor Green
        if ($Verbose) { Write-Host "   bcrypt version: $($apiPkg.dependencies.bcrypt)" -ForegroundColor Gray }
        $checks += @{ Name = "bcrypt dependency"; Status = "OK"; Details = $apiPkg.dependencies.bcrypt }
    } else {
        Write-Host " ⚠️" -ForegroundColor Yellow
        Write-Host "   bcrypt non trouvé dans apps/api/package.json" -ForegroundColor Yellow
        $checks += @{ Name = "bcrypt dependency"; Status = "ATTENTION"; Details = "Non trouvé" }
    }
} catch {
    Write-Host " ❌" -ForegroundColor Red
    Write-Host "   Erreur de parsing JSON" -ForegroundColor Yellow
    $checks += @{ Name = "Package.json"; Status = "ERREUR"; Details = $_.Exception.Message }
}

# ─── Résumé ─────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host " 📊 Résumé" -ForegroundColor Cyan
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

$okCount = ($checks | Where-Object { $_.Status -eq "OK" }).Count
$totalCount = $checks.Count

Write-Host "✅ Checks réussis: $okCount / $totalCount" -ForegroundColor Green

$issues = $checks | Where-Object { $_.Status -ne "OK" }
if ($issues.Count -gt 0) {
    Write-Host ""
    Write-Host "⚠️  Points d'attention:" -ForegroundColor Yellow
    foreach ($issue in $issues) {
        Write-Host "   - $($issue.Name): $($issue.Details)" -ForegroundColor Yellow
    }
}

Write-Host ""
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host " 🚀 Prochaines étapes" -ForegroundColor Cyan
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Vérifiez que les variables Railway sont configurées:" -ForegroundColor White
Write-Host "   - DATABASE_URL (référence PostgreSQL)" -ForegroundColor Gray
Write-Host "   - JWT_ACCESS_SECRET" -ForegroundColor Gray
Write-Host "   - JWT_REFRESH_SECRET" -ForegroundColor Gray
Write-Host ""
Write-Host "2. Générez les secrets JWT:" -ForegroundColor White
Write-Host "   .\scripts\generate-railway-vars.ps1" -ForegroundColor Cyan
Write-Host ""
Write-Host "3. Consultez la checklist complète:" -ForegroundColor White
Write-Host "   Get-Content RAILWAY_CHECKLIST.md" -ForegroundColor Cyan
Write-Host ""

# Code de sortie basé sur les erreurs critiques
$criticalIssues = $checks | Where-Object { $_.Status -eq "MANQUANT" -or $_.Status -eq "ERREUR" }
if ($criticalIssues.Count -gt 0) {
    exit 1
} else {
    exit 0
}
