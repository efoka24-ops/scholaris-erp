#!/usr/bin/env pwsh
<#
.SYNOPSIS
  Configure le frontend Next.js pour pointer vers le backend Railway
  
.DESCRIPTION
  Ce script :
  1. Demande l'URL du backend Railway
  2. Met à jour apps/web/.env.local
  3. Redémarre Next.js automatiquement
  
.EXAMPLE
  .\scripts\configure-frontend-railway.ps1
#>

param(
    [string]$RailwayUrl
)

Write-Host ""
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host " Configuration Frontend → Backend Railway" -ForegroundColor Cyan
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

# Si l'URL n'est pas fournie en paramètre, demander
if (-not $RailwayUrl) {
    Write-Host "📍 Trouvez votre URL Railway :" -ForegroundColor Yellow
    Write-Host "   1. Allez sur https://railway.app" -ForegroundColor Gray
    Write-Host "   2. Ouvrez votre projet > Service Backend" -ForegroundColor Gray
    Write-Host "   3. Settings > Public Networking" -ForegroundColor Gray
    Write-Host "   4. Copiez le domaine (ex: scholaris-api-production.up.railway.app)" -ForegroundColor Gray
    Write-Host ""
    $RailwayUrl = Read-Host "URL du backend Railway (sans https:// ni /api)"
}

# Nettoyer l'URL si l'utilisateur a copié https:// ou /api
$RailwayUrl = $RailwayUrl -replace "https://", "" -replace "http://", "" -replace "/api.*", ""

if (-not $RailwayUrl -or $RailwayUrl -eq "votre-backend.up.railway.app") {
    Write-Host "❌ URL invalide ou placeholder détecté" -ForegroundColor Red
    Write-Host "   Exemple d'URL valide : scholaris-api-production.up.railway.app" -ForegroundColor Gray
    exit 1
}

Write-Host ""
Write-Host "🔧 Configuration de l'URL : https://$RailwayUrl/api" -ForegroundColor Cyan

# Créer le fichier .env.local
$envContent = @"
# URL de l'API NestJS déployée sur Railway
NEST_API_URL="https://$RailwayUrl/api"

# Généré automatiquement le $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")
# Par le script configure-frontend-railway.ps1
"@

$envPath = "apps/web/.env.local"
$envContent | Out-File -FilePath $envPath -Encoding UTF8 -Force

Write-Host "✅ Fichier $envPath créé" -ForegroundColor Green
Write-Host ""

# Vérifier si Next.js tourne déjà
$nextProcess = Get-Process -Name node -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -like "*next dev*"
}

if ($nextProcess) {
    Write-Host "⚠️  Next.js détecté en cours d'exécution" -ForegroundColor Yellow
    Write-Host "   Redémarrez le serveur pour prendre en compte les changements :" -ForegroundColor Gray
    Write-Host "   1. Appuyez sur Ctrl+C dans le terminal Next.js" -ForegroundColor Gray
    Write-Host "   2. Relancez : npm run dev --workspace=@scholaris/web" -ForegroundColor Cyan
} else {
    Write-Host "▶️  Démarrez Next.js avec :" -ForegroundColor Green
    Write-Host "   npm run dev --workspace=@scholaris/web" -ForegroundColor Cyan
}

Write-Host ""
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host " 🎯 Prochaines étapes" -ForegroundColor Cyan
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Seedez la base Railway pour créer le compte admin :" -ForegroundColor White
Write-Host "   railway run npm run db:seed --workspace=@scholaris/prisma" -ForegroundColor Cyan
Write-Host ""
Write-Host "2. Testez le login sur http://localhost:3000" -ForegroundColor White
Write-Host "   Email    : admin@scholaris.dev" -ForegroundColor Gray
Write-Host "   Password : ChangeMe123!" -ForegroundColor Gray
Write-Host ""
