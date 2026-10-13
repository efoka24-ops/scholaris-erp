# Conventions de travail pour les agents de développement (phases 9 à 12)

Projet : SCHOLARIS, PHP 8.1 **sans framework**, standards PSR (voir [adr-0001](adr-0001-php81-sans-framework.md)). Dépôt : `C:\Users\YCXL3291\scholaris-erp`. Plusieurs agents travaillent en parallèle, chacun dans son module : respecte la répartition de fichiers de ta consigne.

## À lire d'abord
`adr-0001-php81-sans-framework.md`, `design-api-requirements*.md` (3 fichiers : suis-les à la lettre, messages compris), `spec.md`, `tasks.md` (tes tâches), `data-model.md`, `contracts/api-conventions.md`, `.specify/memory/constitution.md`. Modèles de code déjà livrés et validés, à imiter pour le style et la conception : `src/Core/**`, `src/Modules/Socle/Billing/**`, `src/Modules/Socle/People/**`, `src/Modules/Socle/Organisation/**` (structure, contrôleurs, requêtes de validation, ressources à liste blanche, dépôts PDO, `routes.php`, `definitions.php`, tests).

## Règles techniques (non négociables)
1. **Architecture** : un module n'utilise que les `Contracts/` des autres modules ; le noyau ne dépend pas des modules ; `declare(strict_types=1)` partout ; pas de `var_dump`/`dd`. Les tests `tests/Architecture/*` le vérifient.
2. **Isolation** : toute table métier porte `tenant_id` (et `establishment_id` si elle appartient à un établissement) ; accès via `Core\Tenancy\TenantTable` ou équivalent qui impose le filtre ; un id d'un autre client donne 404 ; chaque module a un test d'isolation inter-clients et inter-établissements.
3. **Autorisation** : `Core\Security\PermissionChecker`, refus par défaut. Liste dans ton rapport les permissions que tu utilises (je les enregistre dans les rôles) ; ne touche pas aux tables d'Identity.
4. **SQL portable MySQL 8 ET SQLite** (la production est MySQL 8.0, les tests tournent sur SQLite) : ids ULID `CHAR(26)` (`Symfony\Component\Uid\Ulid`), dates en `VARCHAR(32)` ISO 8601 fuseau Africa/Douala, montants entiers en XAF, pas d'AUTO_INCREMENT/ENGINE, pas de clé étrangère, une colonne par `ALTER TABLE`. **Interdit : mots réservés MySQL 8 comme noms de colonnes ou de tables** (ex. `last_value`, `sensitive`, `rank`, `groups`, `system`, `lead`, `lag`, `window`, `usage`, `release`, `row_number`, `function`, `condition`, `range`, `rows`, `read`, `write`, `order`, `key`) : choisis des noms explicites (`rank_in_class`, `current_value`, …). Aucun index sur TEXT/BLOB/JSON ; clés composites sous 3 072 octets en utf8mb4 (4 octets par caractère). Le test `tests/Architecture/MysqlCompatibilityTest.php` échoue sinon. Les requêtes PHP doivent aussi éviter ces mots non protégés. Fichiers de migration préfixés `AAAAMMJJHHMM_` avec le préfixe de ton module (voir ta consigne) pour éviter les collisions.
5. **Erreurs** : `Core\Http\HttpException` (Problem Details RFC 9457) ; pagination `Core\Http\ListQuery` à liste blanche ; montants entiers XAF ; dates ISO 8601.
6. **Tests d'abord** quand c'est possible. PHPUnit ; ta base de test dans `tests/Support/<TonModule>/` (inspire-toi de `tests/Support/AppTestCase.php` sans le modifier, avec doublures de `PermissionChecker`, `Authenticator` et des contrats des autres modules). Pas de dépendance au réseau.
7. **Contrat OpenAPI** : crée `specs/001-socle-plateforme/contracts/openapi.d/<module>.yaml` (OpenAPI 3.1, clé `paths:` avec chaque route et méthode, même format que `contracts/openapi.yaml`). `tests/Architecture/OpenApiContractTest.php` échoue si une route n'y figure pas.
8. Les migrations (`Database/Migrations/*.sql`), les routes (`routes.php` : fonction recevant un `RouteRegistry`) et les liaisons de conteneur (`definitions.php` : tableau de définitions PHP-DI) de ton module sont chargées automatiquement.

## Fichiers partagés : n'y touche pas
`src/Core/**`, `composer.json`/`composer.lock`, `config/**`, `phpunit.xml`, `tests/Support/` hors de ton sous-dossier, `database/migrations/**`, `.env*`, les autres modules (sauf exception écrite dans ta consigne). Besoin d'un changement partagé (règle de limitation de débit, commande console, nouvelle dépendance Composer…) : décris-le précisément dans ton rapport.

## Interdits
Pas de `git commit`, pas de FTP/serveur, pas d'appel réseau, aucun secret en clair, aucune écriture hors du dépôt.

## Qualité avant de rendre la main (depuis la racine, PHP 8.1 déjà dans le PATH)
```
php vendor/bin/php-cs-fixer fix --allow-risky=yes --config=.php-cs-fixer.dist.php --path-mode=intersection <tes dossiers et fichiers>   # JAMAIS sur tout le dépôt
php vendor/bin/phpstan analyse --memory-limit=2G --no-progress        # 0 erreur dans tes fichiers ; ni @phpstan-ignore ni baseline
php vendor/bin/phpunit                                                # tout vert ; si un test d'un autre module échoue à cause du travail en cours des autres agents, relance plus tard et signale-le
```

## Rapport final (court, factuel, en français)
Fichiers créés, routes, décisions non évidentes, permissions utilisées, résultats RÉELS des trois commandes (nombre de tests), ce qui reste, demandes de changement partagé. N'affirme rien que tu n'as pas exécuté. Sois économe : pas de commentaires superflus, pas de relectures inutiles.
