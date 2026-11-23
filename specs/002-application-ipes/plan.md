# Implementation Plan: Application Supérieur (IPES)

**Branch**: `feat/rewrite-php-laravel` (nom historique, le code est du PHP sans framework) | **Date**: 2026-10-08, aligné le 2026-10-10 | **Spec**: [spec.md](spec.md) | **CDC**: [cdc-ipes.md](cdc-ipes.md) | **Décisions**: [decisions.md](decisions.md) | **Vagues**: [wave-plan.md](wave-plan.md)

## Summary

Construire l'application Supérieur dans `src/Modules/Apps/Higher` (namespace `Scholaris\Modules\Apps\Higher`) en s'appuyant uniquement sur les `Contracts/` du socle ([001](../001-socle-plateforme/plan.md)). L'application déclare son manifeste au module Platform du socle (clé `platform.applications.higher`), son descripteur (vocabulaire) à l'`ApplicationRegistry`, ses modèles de documents, ses rôles (migration partagée), ses indicateurs et ses notifications, et ajoute ses tables préfixées `hi_`. Livraison par vagues d'agents (voir wave-plan.md) correspondant aux lots I1 à I4 du CDC.

Écarts volontaires avec le CDC : le CDC écrit « PHP 8.4, Laravel 13, app/Modules/Apps/Higher » ; l'implémentation est **PHP 8.1 sans framework (ADR-0001)**. Un client = une institution IPES (plusieurs écoles internes).

## Technical Context

**Language/Version**: PHP 8.1 sans framework (PSR, PHP-DI, PDO) ; `declare(strict_types=1)` partout (ADR-0001)
**Primary Dependencies**: celles du socle uniquement. Pas de Laravel, pas de `maatwebsite/excel`. XLSX : `Scholaris\Core\Http\XlsxWriter` (écriture, sur ZipArchive) ; CSV `;` UTF-8 BOM via `ExportsList` ; QR et PDF : services du socle (`PdfRenderer`, QR des documents). Import : CSV (lecture XLSX non disponible, voir decisions.md R12)
**Storage**: MySQL 8.0 (production) et SQLite (tests) : SQL portable ; tables `hi_*` avec `tenant_id` et `establishment_id`, ULID `CHAR(26)`, dates `VARCHAR(32)` ISO 8601 (Africa/Douala), montants entiers XAF, pas de clé étrangère, pas de mot réservé MySQL
**Testing**: PHPUnit (pas Pest) ; les TST du CDC et ses exemples chiffrés (Marie N. A et B, échéancier 410 000, vacations 261 000) deviennent des tests automatisés bloquants ; tests d'isolation par client, école, département, filière et affectation
**Target Platform**: identique à 001 (hébergement Camoo, PHP 8.1)
**Project Type**: module applicatif du monolithe (routes `/api/v1/higher/...`, chargées par glob `src/Modules/*/*/routes.php`)
**Performance Goals**: semestre d'un groupe de 200 étudiants en moins de 5 s ; 1 000 relevés en moins de 10 min (file par lots de 50 du socle, à mesurer)
**Constraints**: règles de calcul déclaratives et versionnées par programme et année (aucune formule codée en dur, stratégie de calcul enfichable S6) ; saisie de notes hors-ligne avec contrôle de version ; séparation des tâches ; 2FA récente pour verrouillage, signature, publication, réouverture, export de liste nationale, modification d'un règlement figé ; chaque écriture passe par `WriteGate` (`ApplicationAccess::allowsWrites`)
**Scale/Scope**: 16 modules, 32 documents, 23 rôles, 15 notifications annoncées (NOT-01 à NOT-11 reçues), 19 routes spécifiques annoncées

## Constitution Check

| Principe | Statut | Note |
|----------|--------|------|
| I. Socle d'abord | OK | `Higher` n'utilise que `Socle/*/Contracts` (`StandardsTest`, `NoForeignTableReadTest`) ; les contrats d'écriture manquants sont ajoutés au socle en vague 0 (D1-D21, S1-S21) |
| II. Cloisonnement | OK | Tables `hi_*` portent `tenant_id` + `establishment_id` ; périmètre école, département, filière, groupe imposé par `ScopedBySchool` d'après `PermissionScopes` (S15) ; `PermissionChecker` reste établissement seul |
| III. Test-first | OK | Exemples chiffrés du CDC écrits d'abord (wave-plan.md) |
| IV. API versionnée | OK | Routes `/api/v1/higher/...` ; un contrat OpenAPI par module dans `001-socle-plateforme/contracts/openapi.d/higher-*.yaml` |
| V. Paramétrage | OK | Règlement, grille de lettres, taux de vacation, seuils : clés `higher.*` via `SettingsReader`, surcharge par école (D6) ; valeurs « à confirmer » listées dans decisions.md |
| VI. Traçabilité | OK | `AuditLog` ; `Approvals` ; empreinte SHA-256 des PV ; réouverture motivée |
| VII. Simplicité | OK | SQL portable, pas de dépendance ajoutée, XlsxWriter existant |

## Project Structure

### Documentation

```text
specs/002-application-ipes/
├── spec.md  plan.md  data-model.md  tasks.md
├── cdc-ipes.md          # extrait fidèle du CDC (tronqué après NOT-11)
├── decisions.md         # réponses du CDC, questions restantes, écarts, contradictions
├── wave-plan.md         # exécution par vagues d'agents, tests d'acceptation
├── socle-reuse-A.md  socle-reuse-B.md   # analyses de réutilisation du socle (D1-D21, S1-S21)
└── checklists/requirements.md
```

### Source Code

```text
src/Modules/Apps/Higher/
├── HigherApplication.php      # descripteur (ApplicationRegistry) ; le manifeste est dans definitions.php
├── definitions.php            # PHP-DI : array_merge des <Module>/definitions.php ; clé platform.applications.higher
├── routes.php                 # inclut <Module>/routes.php (un seul routes.php par module Higher pour le glob du Kernel)
├── Support/                   # WriteGate (ApplicationAccess), conventions
├── Policies/                  # RoleMatrix, ScopeResolver, ScopedBySchool, MarkPolicy
├── Academic/                  # ACA : hi_schools, hi_departments, hi_programs, hi_levels, hi_groups, hi_authorizations, hi_tutelages, hi_regulations
├── Admissions/                # ADM : hi_campaigns, hi_applications, hi_application_documents, hi_admission_decisions
├── Enrolment/                 # INS : hi_enrolments, hi_learning_contracts, MatriculeGenerator
├── Curriculum/                # MAQ : hi_curricula, hi_semesters, hi_units, hi_elements, hi_curriculum_versions
├── Teaching/                  # EDT + ENS : hi_assignments, hi_session_logs (start_time, end_time) ; Vacations/
├── Attendance/                # PRE : assiduité par EC, conseil de discipline (l'appel reste au socle S08)
├── Grades/                    # NOT : couche IPES sur le socle (cycle, codes NA/FR, requêtes de note) ; pas de hi_assessments/hi_marks
├── Deliberation/              # DEL : hi_juries, hi_jury_members, hi_session_results, hi_unit_credits, hi_jury_points ; stratégie de calcul IPES
├── Billing/                   # FIN : catalogue de frais, bourses, blocages sur S04
├── Internships/               # STG : hi_internships, hi_defenses
├── NationalExams/             # EXN : hi_national_sessions, hi_candidates
├── Tutelage/                  # TUT : hi_tutelage_transmissions, hi_diploma_files
├── Documents/                 # DOC-01 à DOC-32 : catalogue, IssuanceGuard ; les modèles de chaque module sont dans <Module>/Documents/ (DocumentTypeProvider)
├── Dashboards/                # PIL
├── Portals/                   # POR : Student/, jury, tutelle, garant
├── Notifications/             # NOT-01 à NOT-11 (reçues)
└── <Module>/{Contracts,Application,Domain,Infrastructure,Http,Database/Migrations}
tests/{Feature,Unit}/Higher/<Module>/  tests/Fixtures/Higher/  tests/Support/Higher/  tests/Isolation/HigherScopeTest.php
```

**Structure Decision** : on garde les 16 modules du CDC avec `Teaching/` (EDT + ENS), `Billing/`, `Dashboards/` et `Portals/` regroupés. Les anciens chemins `app/Modules/Apps/Higher`, `Routes/api.php`, `Tests/` et `Http/Requests/Resources` Laravel sont remplacés par `src/Modules/Apps/Higher`, `routes.php`, `tests/` à la racine et les conventions de contrôleurs/requêtes/ressources à liste blanche du socle. Les rôles ne sont pas déclarés par l'application : migration partagée `database/migrations/*_roles_higher.sql` (tables `roles`/`role_permissions`).

## Tables : socle ou Higher
Le `data-model.md` historique prévoit des `hi_*` qui doublonnent le socle : ne pas créer `hi_assessments`, `hi_marks`, `hi_mark_changes`, `hi_deliberations` (couverts par `assessments`, `grades`, `grade_events`, `grade_corrections`, `deliberations`, `deliberation_lines`, `results`). Les `hi_*` sont des extensions 1-1 ou des concepts IPES sans équivalent. `hi_programs.structure_id` est obligatoire et unique (une filière = une structure du socle).

## Calendrier et dépendances sur le socle

| Lot IPES | Semaines CDC | Modules | Vague | Doit attendre |
|----------|--------------|---------|-------|---------------|
| Socle | avant I1 | D1-D21, S1-S21, squelette Higher, rôles | Vague 0 | module Platform (livré), phases 1 à 13 de 001 |
| I1 Entrée et inscription | S10 à S15 | ACA, ADM, INS, MAQ, FIN, DOC-01 à 06, DOC-27 à 30, portail étudiant | Vague 1 (H1, H2, H3) | W0-1, W0-3, W0-5 |
| I2 Enseignement et notes | S16 à S21 | EDT, ENS (affectations, cahier de textes), PRE, NOT, DOC-07/08/13 | Vague 2 (E1, E2, E3) | S07-S09 de 001, W0-4, W0-2 (S1-S4) |
| I3 Résultats et fin d'études | S22 à S26 | DEL, vacations, STG, EXN, TUT, PIL, autres DOC | Vague 3 (L1 à L5) | S10, S12-S14 de 001, S5-S9, S14, S20, S21 |
| I4 Recette et pilote | S27 à S28 | Pilote, reprise de données, formation, bibliothèque (option) | Vague 4 | tout |

Le risque d'ordonnancement relevé dans [analysis.md](../analysis.md) (ENS a besoin de S10 Personnel) est levé : le socle est livré.

## Complexity Tracking

| Écart | Pourquoi nécessaire | Alternative plus simple rejetée |
|-------|---------------------|----------------------------------|
| Stratégie de calcul IPES enfichable au-dessus de `RuleEngine` (S5-S7) | Règlements de tutelle différents par programme, année et session | Formules codées en dur : refusées par le principe V |
| 2FA récente sur six opérations | Exigence du CDC (sécurité propre) ; 2FA forcée seulement pour l'administrateur de plateforme | 2FA à la connexion seule : insuffisant |
| Empreinte SHA-256 des PV | Détection de modification après signature | Journal d'audit seul |
| Périmètre école/département/filière appliqué par Higher (`PermissionScopes`, S15) | `PermissionChecker` ne filtre que par établissement | Un établissement par école : contredit RG-ORG-3 (sélecteur « Toutes les écoles ») |
