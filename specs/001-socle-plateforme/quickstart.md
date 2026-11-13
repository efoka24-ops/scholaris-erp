# Quickstart — validation du socle

Document remis en accord avec la pile réelle (PHP 8.1 sans framework, voir [adr-0001](adr-0001-php81-sans-framework.md)) : l'ancienne version décrivait Laravel (`pint`, `key:generate`, `php artisan test`), qui n'existe pas dans ce dépôt.

## Prérequis
- PHP 8.1 ou plus dans le PATH avec `pdo_sqlite` (tests), `pdo_mysql` (production), `mbstring`, `sodium`, `zip`, `curl`, `openssl` ; Composer 2 ; Node 20+ pour `web/`.
- `.env` créé depuis `.env.example` ; **aucun secret dans Git**.

## Installation
```bash
composer install
cp .env.example .env          # puis renseigner la base et APP_KEY
php artisan migrate
php artisan status            # PHP, extensions, base
php artisan db:check          # migrations, schéma, utf8mb4, écriture annulée
```

## Contrôles qualité (tous exécutés dans le dépôt)
```bash
php vendor/bin/php-cs-fixer fix --allow-risky=yes --config=.php-cs-fixer.dist.php --dry-run --diff
php vendor/bin/phpstan analyse --memory-limit=2G --no-progress     # niveau 8, sans baseline
php vendor/bin/phpunit                                              # unit, architecture, isolation, feature (SQLite)
composer audit
TEST_DB_DRIVER=mysql php vendor/bin/phpunit -c phpunit.mysql.xml   # base MySQL jetable : voir tests-mysql.md
```

## Scénarios de validation
Automatisés dans `tests/Feature/QuickstartTest.php` (pile réelle : Identity, Organisation, People, Billing, Documents, Audit ; seules l'horloge et les passerelles SMS / e-mail sont remplacées ; double authentification désactivée par `AUTH_2FA_REQUIRED=false`, couverte par `tests/Feature/Identity`).

| # | Histoire | Vérifié par |
|---|---|---|
| 1 | US1 : deux clients, `/me/contexts` limité aux siens, appel croisé refusé, 5 échecs = verrouillage 423 | `testUs1...` |
| 2 | US2 : surcharge d'un paramètre, `GET /settings/effective?filter[key]=...` indique source et niveaux masqués | `testUs2...` |
| 3 | US3 : doublon de personne = 409 avec la fiche existante ; pas de doublon entre clients | `testUs3...` |
| 4 | US4 : même `Idempotency-Key` = un seul paiement ; 100 encaissements = reçus 1..100 sans trou | `testUs4...` |
| 5 | US6 : émission, `GET /public/verify/{token}` valide, révocation puis « revoked » | `testUs6...` |
| 6 | US8 : note > 20 refusée, correction après verrouillage sans approbation refusée, historique | tests du module (`tests/Feature/Assessment/GradeEntryTest`, `GradeLifecycleTest`) : non rejoué dans le déroulé bout en bout, la mise en place (classe, matière, épreuve, effectif) étant lourde |
| 7 | Audit : chaîne intacte, une ligne modifiée = rupture détectée (`php artisan audit:verify <client>`) | `testUs7...` |

Écarts constatés et corrigés : la route de paiement en espèces est `POST /cash/receipts` (champ `family_reference`) et non `/payments` ; le type de lien garant est `financial_guarantor` ; `php artisan key:generate`, `migrate --seed` et `php artisan test` n'existent pas (les données de départ viennent des migrations).

## Déploiement recette (Camoo)
Voir `scripts/deploy-ftp.ps1` et `scripts/build-release.ps1`. Pas de SSH : migrations par `php artisan migrate` dans le terminal du serveur. Planification : cron (voir [sauvegardes.md](sauvegardes.md)).
