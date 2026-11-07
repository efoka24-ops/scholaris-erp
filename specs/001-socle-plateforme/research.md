# Research — Socle SCHOLARIS

## R1. Version de PHP et choix du framework (mis à jour le 2026-10-08)
- **Decision** : PHP 8.1 sans framework, standards PSR (voir [ADR-0001](adr-0001-php81-sans-framework.md)). Code compatible PHP 8.4, CI sur 8.1 et 8.4.
- **Rationale** : le serveur de recette n'offre que PHP 8.1.34 ; Laravel 12 exige 8.2 ; Laravel 10 et 11 sont refusés par Composer (avis de sécurité ouverts) ; une tentative Laravel précédente y a déjà échoué (constat du `DEPLOIEMENT.md` présent sur le serveur).
- **Alternatives** : obtenir PHP 8.3 ou 8.4 de Camoo (non disponible aujourd'hui, à redemander) ; changer d'hébergeur ; Symfony 6.4 LTS (compatible 8.1, correctifs de sécurité jusqu'en novembre 2027) : non retenu pour ne pas dépendre d'un framework alors que 8.1 est lui-même hors support.
- **Risque** : PHP 8.1 est hors support depuis le 31/12/2025 ; pas de données réelles en production tant qu'une version maintenue n'est pas disponible.
- **Environnement local** : PHP 8.1.34 en tête du PATH ; PHP 8.4 (winget) disponible ; Composer 2.10.

## R2. MySQL 8 maintenant, PostgreSQL ensuite
- **Decision**: développer sur MySQL 8.0 (recette), en gardant le code portable : PDO avec requêtes préparées (émulation désactivée), SQL standard dans les dépôts, JSON sérialisé côté PHP, ULID (symfony/uid) en clés primaires ; tout SQL propre à un moteur est isolé dans les migrations et signalé.
- **Rationale**: seul environnement disponible ; limiter la dette de migration vers PostgreSQL.
- **Points de vigilance**: pas de RLS ; `utf8mb4` ; index composés commençant par `tenant_id` ; numérotation sans trou par verrou de ligne (`SELECT … FOR UPDATE` sur table de compteurs) valable sur les deux moteurs ; contraintes CHECK acceptées par MySQL 8.0.16+.
- **Alternatives**: SQLite pour les tests (rejeté : comportements de verrou différents) → tests sur MySQL local.
- **Accès base**: depuis un poste distant l'accès est refusé (utilisateur limité à `localhost`) ; développer sur MySQL local et exécuter les migrations sur Camoo via script déployé ou phpMyAdmin/IP autorisée.

## R3. Multi-tenant
- **Decision** : base unique, colonne `tenant_id` ; tous les dépôts passent par un `TenantContext` obligatoire qui ajoute `tenant_id = ?` aux requêtes et refuse toute requête sans contexte (refus par défaut) ; `establishment_id` contrôlé par les politiques.
- **Rationale** : simplicité d'exploitation, cohérent avec le CDC et avec l'ancienne application `erp/` (même principe).
- **Alternatives** : multi-base (rejeté : complique migrations et déploiement mutualisé).

## R4. Rôles à périmètre
- **Decision**: module `Identity` avec table `role_assignments` (rôle, `scope_type`, `scope_id`, dates) et un `PermissionResolver` qui déduit les permissions d'une personne dans un contexte (client, établissement, unité, classe) ; rôle de niveau client « Direction de groupe » inclus.
- **Rationale**: le modèle du CDC (rôle + périmètre client, établissement, unité, classe) dépasse les équipes simples.
- **Alternatives**: aucune bibliothèque d'autorisation compatible PHP 8.1 sans framework n'est retenue ; la tâche T017 (spike) se limite à valider le modèle de permissions par un jeu de cas.

## R5. Authentification
- **Decision**: jetons d'accès opaques stockés hachés (SHA-256), durée limitée, révocables (table `access_tokens`) ; cookie `HttpOnly`/`Secure`/`SameSite` pour le web et en-tête `Authorization: Bearer` pour le mobile ; TOTP (RFC 6238) avec `spomky-labs/otphp` et codes de secours hachés ; mots de passe Argon2id (`ext-sodium`) ; OTP SMS/e-mail via le service Messaging. Identifiant = téléphone ou e-mail (colonnes uniques par tenant).
- **Alternatives**: Passport (OAuth2) reporté aux partenaires.

## R6. Files et tâches planifiées sans Redis
- **Decision**: table `jobs` ; console `artisan queue:work --stop-when-empty --max-time=55` lancée par cron (à vérifier : la présence d'un cron chez Camoo n'est pas confirmée ; à défaut, déclenchement par une route interne protégée appelée par un service externe). En production : Redis.
- **Risque**: débit PDF/SMS limité sur mutualisé ; acceptable pour la recette.

## R7. Génération de PDF
- **Decision**: interface `PdfRenderer` ; implémentation DomPDF en recette, Gotenberg en production. QR via `endroid/qr-code` ou `simplesoftwareio/simple-qrcode`.

## R8. Numérotation sans trou
- **Decision**: table `number_sequences(tenant_id, establishment_id, kind, year, last)` incrémentée sous verrou dans la même transaction que l'objet numéroté ; test de concurrence (SC-004).

## R9. Audit inaltérable
- **Decision**: `AuditLogger` du module `Audit` : table `audit_log` append-only avec `hash = sha256(prev_hash || payload)`. Aucun `UPDATE/DELETE` via l'application (utilisateur de base distinct avec droit INSERT seul en production). Commande `artisan audit:verify` recalcule la chaîne.

## R10. Hors-ligne
- **Decision**: PWA (service worker, IndexedDB) avec file de mutations rejouées par `Idempotency-Key` ; conflits signalés (jamais écrasés silencieusement). Pris en charge côté API : identifiants client (ULID générés côté appareil) et horodatage d'appareil. Détail mis en œuvre avec le front (hors plan).

## R11. Déploiement recette Camoo (mis à jour le 2026-10-08)
- **Constat serveur** (lu dans le `DEPLOIEMENT.md` présent sur le serveur) : PHP 8.1.34 seul, pas de SSH, `.htaccess` limité à `mod_rewrite`, terminal web (CSHIELD) limité à `artisan`, `composer`, `git`, `unzip`, `ls`, `cd`, `cat`, `rm`, application PHP existante en ligne sur `scholaris-erp.trugroup.cm` (racine du compte FTP = `/home/trugro9159/scholaris-erp`).
- **Decision**: build local (`composer install --no-dev -o` sous PHP 8.1), envoi FTP avec `vendor/`, `.env` créé une fois côté serveur et jamais envoyé depuis le dépôt ; racine du sous-domaine à pointer sur `public/` (sinon `.htaccess` racine en réécriture seule) ; migrations lancées depuis le terminal web (`artisan migrate`).
- **Avant tout déploiement** : l'application existante est archivée (dossier local `scholaris-camoo-archive-2026-10-08`) ; le déploiement de la nouvelle application remplacera l'ancienne sur ce sous-domaine : à confirmer, ou utiliser un autre sous-domaine/dossier de recette.
- **À vérifier** : présence d'un cron, version de MySQL disponible côté PDO (`pdo_mysql`), extensions PHP du serveur (`sodium`, `intl`, `mbstring`, `gd`), limites de mémoire et de temps d'exécution, activation de HTTPS.

## R12. Front-end
- **Decision différée** : question ouverte du CDC (Next.js ou Vue/Nuxt). Le plan et les tâches livrent l'API ; une tâche de cadrage (T012) force la décision avant la phase 11. **Contrainte serveur** : pas de Node en production sur Camoo, donc un front compilé en fichiers statiques (Vue/Vite ou export statique) ; Next.js en mode serveur n'y est pas exécutable.

## Re-check Constitution (post-design)
Aucune violation nouvelle. Les écarts MySQL/Redis/DomPDF sont consignés dans Complexity Tracking.

## R12 — Front-end (décision du 2026-10-09)
Vue 3 + Vite + TypeScript + Pinia + Vue Router, PWA (hors ligne, installable). Construction statique servie par Apache sous /app/ ; l’API reste sous /api/v1. Next.js écarté : l’hébergement Camoo n’exécute pas Node.
