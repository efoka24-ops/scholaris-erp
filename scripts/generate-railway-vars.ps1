#!/usr/bin/env pwsh
<#
.SYNOPSIS
  Génère les variables d'environnement Railway à copier-coller.
#>

Write-Host ""
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host " Variables Railway pour SCHOLARIS ERP Backend" -ForegroundColor Cyan
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""
Write-Host "Allez dans Railway > Votre Service Backend > Settings > Variables" -ForegroundColor Yellow
Write-Host "puis ajoutez ces variables :" -ForegroundColor Yellow
Write-Host ""

# Générer des secrets aléatoires sécurisés
$jwtAccess = [Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
$jwtRefresh = [Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))

Write-Host "JWT_ACCESS_SECRET=$jwtAccess" -ForegroundColor Green
Write-Host "JWT_REFRESH_SECRET=$jwtRefresh" -ForegroundColor Green
Write-Host "CORS_ORIGIN=*" -ForegroundColor Green
Write-Host ""
Write-Host "Note : DATABASE_URL devrait être automatiquement liée si PostgreSQL" -ForegroundColor Gray
Write-Host "       est dans le même projet Railway (Reference > DATABASE_URL)" -ForegroundColor Gray
Write-Host ""
Write-Host "Après ajout des variables, redéployez le service." -ForegroundColor Yellow
Write-Host ""
