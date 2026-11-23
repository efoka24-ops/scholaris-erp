# Revue de sécurité OWASP API Top 10 (T142)

Revue **factuelle fondée sur la lecture du code** (2026-10-09). Elle ne remplace ni un test d'intrusion ni le scan ZAP sur la recette (non fait : aucun accès réseau ici), et ne couvre pas ASVS niveau 2 point par point. « Vérifié » = lu dans le code et, quand c'est précisé, couvert par un test.

## Synthèse par risque
| Risque | État | Constat |
|---|---|---|
| API1 Autorisation au niveau objet | Vérifié, correct | Toute table métier est lue via `TenantTable` ou un dépôt filtré par `tenant_id` (et `establishment_id`) ; un identifiant d'un autre client donne 404. Tests : `tests/Isolation/*` (un par module), `AllRoutesIsolationTest` (toutes les routes), `TenantScopeTest`. |
| API2 Authentification | Vérifié, correct | Mots de passe Argon2id ; jetons d'accès opaques stockés hachés (SHA-256, `access_tokens.token_hash`) ; verrouillage 15 min après 5 échecs sans distinguer « inconnu » et « faux » (`LockoutTest`) ; 2FA TOTP (secret chiffré), codes de secours hachés ; limite de débit sur `/auth/login` (10/min) et `/auth/(activation\|password\|2fa)` (20/h). |
| API3 Exposition excessive de données | Vérifié, correct | Ressources à liste blanche de champs (`Http/Resources/*`), requêtes de validation à liste blanche (`Champ non autorisé`) : pas d'affectation de masse. Export client : colonnes en liste blanche, sans hachage ni secret TOTP (`CompleteTenantExportTest::testAucunSecretNEstExporte`). Santé uniquement dans l'export client. Page publique de vérification : titulaire masqué, aucun identifiant (`PublicVerificationTest`). |
| API4 Consommation de ressources | Partiel | Limites de débit par route (`config/api.php`, 600/min par défaut), pagination par curseur bornée (`ListQuery`). Corps JSON plafonné à 2 Mio (`JsonBodyLimit`, `API_MAX_JSON_BODY_BYTES`, réponse 413 ; **corrigé ici**, test `JsonBodyLimitTest`). **Reste** : le compteur est par adresse `REMOTE_ADDR` : derrière le mandataire d'un hébergement mutualisé, tous les clients peuvent partager une adresse. |
| API5 Autorisation au niveau fonction | Vérifié | `PermissionChecker` : refus par défaut ; permissions par rôle en base (`role_permissions`) ; opérations sensibles soumises à double validation (`Approvals`). Tests de rôles : `RolePermissionsTest`, `ForbiddenActionTest`, tests d'accès par module. |
| API6 Flux métier sensibles | Vérifié | Paiements idempotents (`Idempotency-Key`), reçus sans trou, annulations et remises soumises à approbation, webhook de paiement signé (HMAC-SHA256, temps constant, tolérance 600 s), liste d'adresses IP optionnelle (`api.payment_webhook_ips`). |
| API7 Mauvaise configuration | Partiel | En-têtes de l'API : `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, CSP `default-src 'none'`, `Cache-Control: no-store`, HSTS sur connexion sécurisée. CORS : liste blanche d'origines, aucune par défaut. Erreurs : message interne masqué hors `APP_DEBUG` (Problem Details) ; **corrigé ici** : les réponses d'erreur (401, 403, 404, 422, 500) partaient SANS en-têtes de sécurité ni CORS, `SecurityHeaders` et `Cors` étant placés à l'intérieur du gestionnaire d'erreurs ; ils l'enveloppent désormais (`Kernel::handle`, test `JsonBodyLimitTest::testLesReponsesDErreurPortentLesEnTetesDeSecuriteEtCors`). `.htaccess` racine interdit `src`, `config`, `storage` (donc les sauvegardes), `vendor`, `.env*`, etc. (**corrigé ici** : ajout de `web`, `.phpunit*`, `phpunit.*.xml`, `.agents`, `.claude` ; test `WebRootProtectionTest`). **Reste** : le site statique (`index.html` et ressources) est servi sans en-têtes de sécurité (l'hébergement n'autorise pas `mod_headers`) ; pas de HTTPS sur le sous-domaine de recette tant qu'aucun certificat n'est posé. |
| API8 Injection | Vérifié | SQL : requêtes préparées avec valeurs liées partout (`ATTR_EMULATE_PREPARES=false`) ; les noms de tables/colonnes interpolés viennent de constantes ou de listes blanches (`TenantTable::assertIdentifier`, `TableExportSource`, `BackupService::quote`) ; pas de `eval`, `exec`, `shell_exec`, `unserialize`, `md5`/`sha1` pour de la sécurité. CSV : cellules neutralisées contre l'injection de formule (`ExportsList`, export fichier externe). |
| API9 Gestion des inventaires | Partiel | Contrat OpenAPI complet vérifié par `OpenApiContractTest` (toute route y figure). Une seule version (`/api/v1`). **Reste** : pas d'inventaire des routes de test hors production (`/_test/*` n'est ajouté que par les tests). |
| API10 Consommation non sécurisée d'API tierces | Vérifié | Aucune URL fournie par un utilisateur n'est appelée (pas de SSRF) : seules les URL de configuration sont utilisées (`sungku.base_url`, `sms.http.url`), HTTPS imposé par les clients SMS et Sungku (refus d'une URL non HTTPS), `CURLOPT_FOLLOWLOCATION=false`, vérification TLS activée, délais bornés. Réponse de Sungku non crue sans webhook signé. |

## Secrets
- Aucun secret dans le dépôt ; `.env*` ignorés par Git (sauf `.env.example`) et interdits au web.
- Clés : `APP_KEY`/`people.secret_key` (santé), `identity.secret_key` (secret 2FA), `SUNGKU_*`, `BACKUP_KEY` : toutes lues dans l'environnement. **Reste** : rotation des clés de chiffrement (santé, 2FA) non implémentée ; `.env` du serveur en clair (propre à l'hébergement).
- Sauvegardes chiffrées (`sauvegardes.md`) ; le fichier contient les hachages de mots de passe, d'où l'obligation de chiffrement.

## Journalisation
- Journal d'audit chaîné (SHA-256) vérifiable (`audit:verify`), actions sensibles tracées avec acteur, IP, appareil. Les exports et les suppressions de données personnelles y figurent.
- Appels de journalisation relus (tous les `logger->` de `src/`) : aucun corps de requête, mot de passe, jeton ni secret n'est journalisé ; les numéros sont masqués par la passerelle SMS simulée ; les exceptions non gérées sont journalisées avec leur trace (les messages PDO ne contiennent pas les valeurs liées). **Reste** : rotation des journaux non automatisée, pas d'alerte.

## Failles simples corrigées pendant la revue
1. `.htaccess` racine : fichiers de configuration d'outils et dossier `web/` non interdits, dossier de cache PHPUnit non interdit (corrigé, testé).
2. Réponses d'erreur sans en-têtes de sécurité (`nosniff`, CSP, `Cache-Control: no-store`) ni CORS : ordre des middlewares corrigé (testé).
3. Taille des corps JSON non bornée : plafond 413 configurable (testé).

## Reste à traiter (liste)
1. Scan ZAP sur la recette et test d'intrusion (T142 reste partiellement ouverte pour cette raison).
2. En-têtes de sécurité du site statique (CSP via `<meta>` dans `index.html`, ou hébergement avec `mod_headers`) ; certificat TLS.
3. Limitation de débit fondée sur une adresse réelle derrière mandataire (en-tête `X-Forwarded-For` accepté seulement depuis une liste de mandataires de confiance : non fait faute de connaître l'adresse du mandataire de l'hébergeur).
4. Rotation des clés de chiffrement applicatives.
5. Rotation des journaux applicatifs et alertes.
6. ASVS niveau 2 : contrôle point par point (gestion de session côté navigateur notamment). Vérifié ici pour FR-002 : `PasswordPolicy` refuse les mots de passe courants ou compromis d'après une liste locale (`Identity/Resources/common-passwords.txt`, sans appel réseau), avec variantes (suffixe chiffré, substitutions) et répétitions, ainsi que ceux qui contiennent l'identifiant ou le nom ; appliquée à l'activation et à la réinitialisation ; les comptes créés par console ou par le Super Admin reçoivent un mot de passe aléatoire. Limite : une liste locale ne remplace pas une base de fuites complète (de type HIBP, exclue faute d'appel réseau).
