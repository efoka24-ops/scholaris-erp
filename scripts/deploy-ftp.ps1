<#
 Déploiement de recette de SCHOLARIS par FTP (Camoo).
 - Le compte FTP est enfermé dans le dossier de l'application : la racine distante est "/".
 - Le mot de passe est lu dans la variable d'environnement SCHOLARIS_FTP_PASSWORD (sinon saisie masquée).
 - Le fichier .env n'est jamais envoyé : il se crée sur le serveur.
 - Utiliser -DryRun pour lister les fichiers sans rien envoyer.
 Préparer avant : composer install --no-dev --optimize-autoloader --no-interaction (PHP 8.1).
#>
param(
	[string]$HostName = "ftp-12.camoo.net",
	[int]$Port = 21,
	[string]$Username = "trugro9159_schol",
	[string]$RemoteRoot = "/",
	[string]$LocalRoot = ".",
	[switch]$DryRun,
	[string[]]$Paths = @(),
	[int]$DelayMs = 0
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function New-FtpRequest {
	param(
		[string]$Method,
		[string]$Path,
		[System.Net.NetworkCredential]$Credential
	)

	$cleanPath = $Path -replace "\\", "/"
	if (-not $cleanPath.StartsWith("/")) {
		$cleanPath = "/$cleanPath"
	}

	$uri = "ftp://$HostName`:$Port$cleanPath"
	$req = [System.Net.FtpWebRequest]::Create($uri)
	$req.Method = $Method
	$req.Credentials = $Credential
	$req.UseBinary = $true
	$req.UsePassive = $true
	$req.KeepAlive = $false
	return $req
}

function Ensure-RemoteDirectory {
	param(
		[string]$DirectoryPath,
		[System.Net.NetworkCredential]$Credential
	)

	$parts = $DirectoryPath.Trim("/").Split("/", [System.StringSplitOptions]::RemoveEmptyEntries)
	$current = ""
	foreach ($part in $parts) {
		$current += "/$part"
		if ($script:knownDirectories.Contains($current)) { continue }
		try {
			$mk = New-FtpRequest -Method ([System.Net.WebRequestMethods+Ftp]::MakeDirectory) -Path $current -Credential $Credential
			$resp = $mk.GetResponse()
			$resp.Close()
		} catch {
			# Le dossier existe déjà : on continue.
		}
		[void]$script:knownDirectories.Add($current)
	}
}

function Send-FileFtp {
	param(
		[string]$LocalFile,
		[string]$RemoteFile,
		[System.Net.NetworkCredential]$Credential
	)

	$upload = New-FtpRequest -Method ([System.Net.WebRequestMethods+Ftp]::UploadFile) -Path $RemoteFile -Credential $Credential
	$content = [System.IO.File]::ReadAllBytes($LocalFile)
	$upload.ContentLength = $content.Length

	$stream = $upload.GetRequestStream()
	$stream.Write($content, 0, $content.Length)
	$stream.Close()

	$response = $upload.GetResponse()
	$response.Close()
}

$script:knownDirectories = New-Object System.Collections.Generic.HashSet[string]

$plain = $env:SCHOLARIS_FTP_PASSWORD
if ([string]::IsNullOrEmpty($plain) -and -not $DryRun) {
	$secure = Read-Host "Mot de passe FTP" -AsSecureString
	$bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
	try { $plain = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
	finally { [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}
$credential = New-Object System.Net.NetworkCredential($Username, $plain)

$resolvedLocalRoot = (Resolve-Path $LocalRoot).Path
Write-Host "LocalRoot : $resolvedLocalRoot"
Write-Host "FTP       : $HostName`:$Port"
Write-Host "Remote    : $RemoteRoot"

# Fichiers et dossiers exclus du déploiement. vendor/ est envoyé : l'hébergement mutualisé n'a pas Composer.
$excludeRegex = @(
	"(^|/)node_modules(/|$)",
	"(^|/)\.git(/|$)",
	"(^|/)\.github(/|$)",
	"(^|/)\.claude(/|$)",
	"(^|/)\.specify(/|$)",
	"(^|/)specs(/|$)",
	"(^|/)scripts(/|$)",
	"(^|/)tests(/|$)",
	"(^|/)storage/logs/",
	"(^|/)storage/framework/(cache|sessions|views)/.+",
	"(^|/)bootstrap/cache/.+\.php$",
	"(^|/)public/build/\.vite(/|$)",
	"(^|/)\.env(\..+)?$",
	"(^|/)(phpunit\.xml|phpstan\.neon|pint\.json|\.phpunit\.cache)",
	"(^|/)database/.+\.sqlite$",
	"\.bak$"
) -join "|"

$allFiles = Get-ChildItem -Path $resolvedLocalRoot -File -Recurse -Force
$files = $allFiles | Where-Object {
	$relative = $_.FullName.Substring($resolvedLocalRoot.Length).TrimStart("\") -replace "\\", "/"
	if ($Paths.Count -gt 0) {
		$inPaths = $false
		foreach ($p in $Paths) {
			if ($relative -eq $p -or $relative.StartsWith($p.TrimEnd("/") + "/")) { $inPaths = $true }
		}
		if (-not $inPaths) { return $false }
	}
	$relative -notmatch $excludeRegex
}

if (-not $files -or @($files).Count -eq 0) {
	throw "Aucun fichier à envoyer (vérifiez LocalRoot et les exclusions)."
}

$total = @($files).Count

if ($DryRun) {
	$files | ForEach-Object { $_.FullName.Substring($resolvedLocalRoot.Length).TrimStart("\") -replace "\\", "/" }
	Write-Host "Simulation : $total fichier(s) seraient envoyés vers ${HostName}${RemoteRoot}" -ForegroundColor Yellow
	return
}

$i = 0
foreach ($file in $files) {
	$i++
	$relative = $file.FullName.Substring($resolvedLocalRoot.Length).TrimStart("\") -replace "\\", "/"
	$remoteFile = ($RemoteRoot.TrimEnd("/") + "/" + $relative)
	$remoteDir = [System.IO.Path]::GetDirectoryName($remoteFile).Replace("\", "/")

	Ensure-RemoteDirectory -DirectoryPath $remoteDir -Credential $credential
	for ($attempt = 1; $attempt -le 5; $attempt++) {
		try {
			Ensure-RemoteDirectory -DirectoryPath $remoteDir -Credential $credential
			Send-FileFtp -LocalFile $file.FullName -RemoteFile $remoteFile -Credential $credential
			break
		} catch {
			if ($attempt -eq 5) { throw }
			Write-Host "Échec sur $relative (tentative $attempt/5) : attente de $($attempt * 20) s" -ForegroundColor Yellow
			Start-Sleep -Seconds ($attempt * 20)
		}
	}
	if ($DelayMs -gt 0) { Start-Sleep -Milliseconds $DelayMs }

	if ($i % 25 -eq 0 -or $i -eq $total) {
		Write-Host "[$i/$total] Envoyé : $relative"
	}
}

Write-Host "Déploiement FTP terminé : $total fichier(s) envoyés." -ForegroundColor Green
