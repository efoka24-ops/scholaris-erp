# Implementation Plan: Socle commun SCHOLARIS

**Branch**: `001-socle-plateforme` (non créée) | **Date**: 2026-10-08 | **Spec**: [spec.md](spec.md)

## Summary

Reconstruire à zéro le socle de SCHOLARIS en monolithe modulaire PHP sans framework, standards PSR (API REST `/api/v1`) : identité et rôles à périmètre, organisation multi-tenant, personnes, frais et paiements, communication, documents, puis calendrier, présences, évaluation, personnel, tableaux de bord, audit et intégrations. Le socle est livré par lots L0 à L3 du CDC ; la recette tourne sur Camoo (MySQL 8, sans Redis), la production visée sur PostgreSQL 16 et Redis.

## Technical Context

**Language/Version**: PHP 8.1.34 (version imposée par l'hébergeur de recette), code compatible PHP 8.4 ; **sans framework applicatif**, standards PSR (voir [ADR-0001](adr-0001-php81-sans-framework.md)). Poste local : PHP 8.1 en tête du PATH, 8.4 disponible pour la CI
**Primary Dependencies**: nikic/fast-route, nyholm/psr7 et psr7-server, php-di/php-di 7 (PSR-11), monolog 3 (PSR-3), vlucas/phpdotenv, symfony/uid ; à venir par phase : spomky-labs/otphp (TOTP), dompdf/dompdf (PDF en recette), phpoffice/phpspreadsheet ou openspout (Excel/CSV), endroid/qr-code. Outils : PHPUnit 10.5, PHPStan, PHP-CS-Fixer
**Storage**: MySQL 8.0 (recette, Camoo) ; PostgreSQL 16 (cible). Fichiers sur disque privé hors racine web, URL signées
**Testing**: PHPUnit (Unit, Architecture, Isolation, Feature), suite d'isolation inter-tenant, PHPStan, PHP-CS-Fixer (PSR-12), `composer audit`
**Target Platform**: Recette : hébergement mutualisé Camoo (PHP, FTP, cron). Production : conteneurs Docker au Cameroun
**Project Type**: web-service (API) + front PWA séparé
**Performance Goals**: p95 < 300 ms ; 1 000 PDF < 10 min ; 5 000 utilisateurs simultanés (cible production)
**Constraints**: pas de Redis, Docker ni SSH en recette ; `.htaccess` limité à `mod_rewrite` ; files sur la table `jobs` ; cron (si disponible) pour `artisan queue:work --stop-when-empty` ; montants XAF entiers ; fuseau `Africa/Douala` ; hors-ligne 3 jours
**Scale/Scope**: 200 établissements, 150 000 apprenants (cible) ; 14 services, 19 FR

## Constitution Check

| Principe | Statut | Note |
|----------|--------|------|
| I. Socle d'abord, test d'architecture | OK | Pest Arch dès la phase 1 |
| II. Cloisonnement tenant | OK avec réserve | Pas de RLS sous MySQL : filtre tenant obligatoire dans les dépôts + tests uniquement ; RLS ajoutée au passage PostgreSQL (voir Complexity) |
| III. Test-first | OK | Tâches de tests avant implémentation pour calculs, paiements, isolation |
| IV. API versionnée | OK | Problem Details, curseur, idempotence en foundation |
| V. Paramétrage | OK | Service de paramètres à 3 niveaux en foundation |
| VI. Traçabilité | OK | Audit chaîné avant tout module métier |
| VII. Simplicité | Écart justifié | Voir Complexity Tracking |

**Re-check post-design** : voir fin de `research.md`, aucune violation non justifiée.

## Project Structure

### Documentation

```text
specs/001-socle-plateforme/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── api-conventions.md
└── tasks.md
```

### Source Code (racine du dépôt)

Le dépôt actuel (NestJS/Next.js, `erp/`, `php/`) est **remplacé** : l'application PHP est créée à la racine après sauvegarde de l'ancien code sur la branche `archive/nestjs-nextjs` (fait).

```text
public/                       # racine web : index.php, .htaccess (mod_rewrite seul)
config/                       # app.php, database.php (lisent l'environnement)
src/                          # namespace Scholaris\ (PSR-4)
├── Core/                     # Kernel, Http (Pipeline, middlewares PSR-15, erreurs RFC 9457), Container, Config, Database, Console, Tenancy, Numbering, Applications
└── Modules/
    ├── Socle/                # Identity (S01), Organisation (S02), People (S03), Billing (S04), Messaging (S05),
    │                         # Documents (S06), Scheduling (S07), Attendance (S08), Assessment (S09), Staff (S10),
    │                         # Reporting (S12), Audit (S13), Integrations (S14)
    └── Apps/                 # Higher, Secondary, Primary, Nursery (n'utilisent que les Contracts du socle)
database/                     # migrations SQL communes
storage/                      # journaux et cache (hors dépôt)
tests/                        # Unit, Architecture, Isolation, Feature
artisan                       # console (nom imposé par le terminal de l'hébergeur)
```

Chaque module : `Contracts/` (interfaces publiques), `Domain/` (entités, valeurs, politiques), `Application/` (cas d'usage), `Http/` (contrôleurs, requêtes, ressources), `Infrastructure/` (dépôts PDO), `Database/Migrations/` (SQL) et `routes.php`.

**Structure Decision**: monolithe modulaire unique, front PWA dans un dépôt ou un dossier `web/` distinct, décidé en cadrage (hors de ce plan).

## Plan de phases (aligné sur le CDC)

| Phase | CDC | Contenu |
|-------|-----|---------|
| 0 Cadrage | L0 | Archive de l'ancien code, nouveau projet PHP 8.1 sans framework (PSR), outillage qualité, CI, déploiement FTP recette |
| 1 Fondations transverses | L1 | `Core` : tenancy, Problem Details, idempotence, pagination, i18n, audit chaîné, paramètres 3 niveaux |
| 2 US1 Identité | L1 | S01 |
| 3 US2 Organisation | L1 | S02 |
| 4 US3 Personnes | L1 | S03 |
| 5 US4 Frais et paiements | L1 | S04 + S14 (paiement simulé, SMS, e-mail) |
| 6 US5 Communication | L1 | S05 |
| 7 US6 Documents | L1 | S06 |
| 8 US7 Calendrier et présences | L2 | S07, S08 |
| 9 US8 Évaluation | L2 | S09 |
| 10 US9 Personnel | L3 | S10 |
| 11 US10 Portails, tableaux de bord, intégrations | L2/L3 | S11, S12, S13 (export, clôture), S14 (WhatsApp, tutelle, comptable) |
| 12 Finition | — | Sécurité, performance, durcissement, quickstart |

## Complexity Tracking

| Écart | Pourquoi nécessaire | Alternative plus simple rejetée |
|-------|---------------------|----------------------------------|
| MySQL au lieu de PostgreSQL (écart CDC) | Seul hébergement disponible aujourd'hui (Camoo) | Attendre un hébergement PostgreSQL retarderait la recette ; code portable pour migrer |
| Pas de RLS en recette | MySQL n'en dispose pas | Aucune équivalente ; compensé par tests d'isolation obligatoires |
| Files `database` + cron au lieu de Redis/Horizon | Pas de Redis sur mutualisé | Aucune ; Horizon réintroduit en production |
| DomPDF au lieu de Gotenberg | Pas de conteneur ni Chromium sur mutualisé | Gotenberg en production ; interface `PdfRenderer` pour échanger |
| Portée globale « maison » pour le multi-tenant | Une seule base, un seul schéma ; `archtechx/tenancy` alourdit sans bénéfice en base unique | `archtechx/tenancy` rejeté : orienté multi-base |
