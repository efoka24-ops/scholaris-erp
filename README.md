# SCHOLARIS

Plateforme éducative à interface unique et applications spécialisées (maternelle, primaire, secondaire, supérieur), construite sur un socle commun. API REST en PHP 8.1, sans framework, bâtie sur les standards PSR.

- Cahiers des charges : `CDC_Plateforme_SCHOLARIS.pdf`, `CDC_Application_IPES.pdf`
- Spécifications, plans, tâches : [specs/](specs/) (Spec Kit)
- Principes du projet : [.specify/memory/constitution.md](.specify/memory/constitution.md)
- Décision d'architecture : [specs/001-socle-plateforme/adr-0001-php81-sans-framework.md](specs/001-socle-plateforme/adr-0001-php81-sans-framework.md)

## Pourquoi PHP 8.1 sans framework

L'hébergement de recette (Camoo) n'offre que PHP 8.1. Aucune version maintenue de Laravel n'y est installable. Le projet suit donc les standards (PSR-1, PSR-3, PSR-4, PSR-7, PSR-11, PSR-12, PSR-15, PSR-17) et s'appuie sur de petites bibliothèques maintenues. Il reste portable vers PHP 8.4 : la CI teste 8.1 et 8.4.

## Structure

```text
public/                  racine web (index.php, .htaccess)
config/                  configuration (lit l'environnement)
src/
  Core/                  noyau : HTTP (PSR-7/15), conteneur (PSR-11), config, base, erreurs, console
  Modules/
    Socle/<Module>/      14 services du socle : Contracts, Domain, Application, Http, Infrastructure, Database/Migrations
    Apps/<Application>/  Higher, Secondary, Primary, Nursery (n'utilisent que les Contracts du socle)
database/                migrations SQL communes
storage/                 journaux et cache (hors dépôt)
tests/                   Unit, Architecture, Isolation, Feature
artisan                  console (nom imposé par le terminal de l'hébergement)
```

## Démarrage

Prérequis : PHP 8.1 (extensions `mbstring`, `intl`, `pdo_mysql`, `pdo_sqlite`, `sodium`), Composer 2.

```bash
composer install
cp .env.example .env     # puis renseigner la base ; ne jamais commiter .env
php artisan status
php artisan migrate      # applique les migrations SQL du noyau et des modules
php artisan admin:create "Groupe Les Palmiers" "Prénom" "Nom" "699123456"   # premier super administrateur
php -S localhost:8000 -t public
# GET http://localhost:8000/api/v1/health
```

`admin:create` génère un mot de passe aléatoire affiché une seule fois ; le super administrateur doit activer la double authentification à sa première connexion.

## Qualité

```bash
composer cs       # style PSR-12
composer stan     # PHPStan
composer test     # PHPUnit
composer audit    # vulnérabilités des dépendances
```

## Secrets

Aucun secret dans le dépôt. `.env` est ignoré. Les accès de recette (base de données, FTP) se saisissent côté serveur et dans des variables d'environnement locales.

## Déploiement de recette

`scripts/deploy-ftp.ps1` envoie le projet compilé (`composer install --no-dev`) par FTP. Le mot de passe est lu dans `SCHOLARIS_FTP_PASSWORD`. Le serveur n'accepte que `mod_rewrite` dans les `.htaccess` et ne donne pas d'accès SSH. Voir l'ADR.
