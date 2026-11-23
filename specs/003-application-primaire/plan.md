# Plan technique : application Primaire

Date : 2026-10-13. Entrées : [spec.md](spec.md), [data-model.md](data-model.md), [socle-reuse.md](socle-reuse.md), ADR-0001, `specs/001-socle-plateforme/agent-conventions.md`, modèle `src/Modules/Apps/Higher`.

## 1. Contexte technique (réel, non négociable)
PHP **8.1 sans framework**, PSR (pipeline PSR-15, PHP-DI, FastRoute), MySQL 8 en production, SQLite en test, Dompdf pour les PDF, PHPUnit 10.5, PHPStan niveau 8 sans baseline, PHP-CS-Fixer. Hébergement mutualisé Camoo : pas de Redis, de Node ni de SSH ; tâches par cron (`queue:send`, `documents:process-jobs` chaque minute). Front Vue 3 + Vite + Pinia + PWA (hors ligne IndexedDB, `client_uuid`, conflits 409 jamais écrasés). Namespace `Scholaris\Modules\Apps\Primary`. Les documents de conception qui parlent de Laravel ou PostgreSQL sont périmés.

## 2. Arborescence cible
```
src/Modules/Apps/Primary/
  PrimaryApplication.php        manifeste (clé primary), descripteur, constantes de permissions
  definitions.php               platform.applications.primary + array_merge des */definitions.php (glob)
  routes.php                    inclut */routes.php (glob), toutes les routes sous /primary/...
  Support/    WriteGate, GatedAction, PrimaryRoutes, Perimeter, ScopedByClass
  Policies/   RoleMatrix, ScopeResolver
  Academic/   référentiel : profils de niveau, domaines, matières par niveau, compétences, modèles de référentiel
  Enrolment/  redoublant, transfert, paramétrage du passage (lecture/écriture people.promotion.next_structure)
  Grades/     marques de compétence, conduite, stratégie de calcul primary, lignée de règles, préremplissage du conseil (PS4),
              variables de bulletin (PS5)
  ClassLife/  cahier de liaison, accusés de lecture
  Exams/      sessions et candidats de l'examen de fin de cycle
  Billing/    natures de frais primary, facturation des services et de l'examen
  Services/   cantine, garderie, transport (Could)
  Documents/  types et modèles de documents primary, garde d'émission
  Portals/    sections du portail famille et enseignant
  Dashboards/ indicateurs, tableau de bord du directeur, « à faire »
  Tutelage/   types d'export tutelle (MINEDUB)
```
Chaque sous-module : `Application/ Domain/ Infrastructure/ Http/ Database/Migrations/ routes.php definitions.php` (et `Contracts/` seulement si un autre sous-module en a besoin).

## 3. Mécanismes repris de Higher
| Mécanisme | Higher | Primary |
|-----------|--------|---------|
| Manifeste | `platform.applications.higher` | `platform.applications.primary` : `ApplicationManifest(key: 'primary', label: 'Enseignement primaire', target: 'primary', status: available (à la recette), version: '0.1.0', requiredModules: [people, billing, messaging, documents, scheduling, attendance, assessment, staff, reporting], monthlyPriceXaf: 60000, trialDays: 90, graceDays: 15)` ; le catalogue en base fait foi pour le prix |
| Écriture | `WriteGate::assertWritable` (423/403) | identique (`ApplicationAccess::allowsWrites($tenantId, 'primary')`), appelé par `GatedAction` et par chaque commande planifiée qui écrit |
| Routes | `HigherRoutes` | `PrimaryRoutes` : enregistre GET libres, non-GET enveloppées par `GatedAction` ; `PrimaryConventionsTest` vérifie qu'aucune route non GET n'y échappe |
| Périmètre | `ScopeResolver` + `ScopedBySchool` | `ScopeResolver` (`PermissionScopes::scopesFor`, `OrgUnitTree`) + `ScopedByClass` : établissement > section (`org_unit`) > niveau (`structure`) > classe (`class`) ; lecture hors périmètre 404, écriture 403 journalisé |
| Matrice des rôles | `Policies/RoleMatrix` + test | identique, tirée de spec.md §2 |
| Extensions | `billing.fee_kinds.higher`, `portals.sections.higher`, `documents.issuance_guards.higher`… | mêmes clés suffixées `primary` (liste dans socle-reuse.md §0) |
| Console | `higher:*` | `primary:*` via `console.command.primary_<nom>` (ex. `primary:referential-import`, `primary:alerts`) |

## 4. Conception par sous-module
- **Academic** : CRUD `/primary/level-profiles`, `/primary/domains`, `/primary/level-subjects`, `/primary/competencies` ; `POST /primary/referentials/{code}/apply` copie un modèle (`pri_referential_templates`) vers l'année : crée niveaux et classes manquants par `StructureWriter`, matières par `CurriculumCatalog::upsertSubject` (clé externe `pri:<code>`), domaines et compétences en `pri_*`. Idempotent. Copie d'année : `POST /primary/referentials/copy-year`.
- **Grades** :
  - `PrimaryRuleStrategy` (`assessment.rule_strategies.primary`, clé `primary`) : `computeLearner` = moyenne pondérée des épreuves, puis des matières (coefficients des `subjects`, 1 par défaut) ; `aggregatePeriods` (PS1b) = moyenne pondérée par `primary.period_weights` ; `decideYear` = proposition selon `passMark` et `is_final_level`. **Aucune constante de seuil** : tout vient de `CalculationRules` et des options (`optionErrors` refuse une option inconnue).
  - `PrimaryRuleScopes` (`assessment.rule_scopes.primary`) : classe → niveau → établissement.
  - Marques de compétence : `GET|PUT /primary/classes/{id}/competency-sheet?period_id=` (lot, `client_uuid`, `version`), `POST .../lock`, historique ; correction après verrouillage = approbation du directeur (`Approvals`).
  - Conduite : `PUT /primary/classes/{id}/conduct?period_id=` ; `PrimaryCouncilLines` (PS4) préremplit `conduct`.
  - `PrimaryReportCardVariables` (PS5) : domaines, matières, séquences, compétences, redoublant, absences en demi-journées, rang si `show_rank`.
- **Enrolment** : `GET /primary/learners/{id}/profile` (redoublant calculé, classe, niveau) ; table de passage `people.promotion.next_structure` écrite par l'écran via `PUT /settings` (socle) ; Primary fournit seulement le contrôle de cohérence `GET /primary/promotion-map/check` (chaque niveau non final a une classe supérieure, le niveau final n'en a pas) ; transfert = statut d'inscription socle + document `primary_transfer`.
- **ClassLife** : `/primary/classes/{id}/journal` (CRUD, publication), `POST /primary/journal/{id}/seen` (parent) ; notification NOT-PRI-05 par `Messenger`.
- **Exams** : `/primary/exam-sessions` (CRUD, ouverture/clôture), `/primary/exam-sessions/{id}/candidates` (inscription en lot depuis le niveau d'examen, retrait, numéros), `POST .../results/import` (CSV) et saisie unitaire, export CSV/XLSX (`ExportsList`), facture d'examen par `InvoiceIssuer::forCharges`.
- **Billing** : `billing.fee_kinds.primary` = `pta_fee`, `uniform`, `supplies`, `exam_fee`, `canteen`, `daycare`, `transport` (à confirmer Q17) ; aucune garde de blocage par défaut ; garde `documents.issuance_guards.primary` active seulement si `primary.report_card.block_on_debt=true`.
- **Services** (Could) : `/primary/service-plans`, `/primary/service-subscriptions` ; facture par `InvoiceIssuer::forCharges`.
- **Documents** : types `primary_report_card`, `primary_annual_report`, `primary_transfer`, `primary_exam_list` déclarés par fournisseur (`primary.document_types.*`, interne) ; modèles HTML Dompdf par défaut FR et EN, éditables par `/document-templates`.
- **Portals** : `portals.sections.primary` : « Compétences », « Cahier de liaison », « Examen » (famille) ; « Ma classe » (enseignant) ; `portals.grading_todo.primary` : feuilles de compétences à remplir.
- **Dashboards** : `reporting.indicators.primary` et `reporting.dashboards.primary` (spec §8) ; `reporting.todo.primary` ; commande `primary:alerts` (quotidienne) pour les absences répétées.
- **Tutelage** : `integrations.export_types.primary` : effectifs par niveau/sexe, résultats par niveau, examen ; colonnes configurables (Q16).

## 5. Front (`web/`)
Écrans sous `web/src/apps/primary/` (dossier exclusif) ; éditions ciblées seulement dans `router/routes.ts`, `AppShell`, `lib/permissions.ts`, `i18n/*`. Réutilisation des écrans du socle (personnes, inscriptions, frais, appel, notes, messages, documents) avec le vocabulaire `primary`. Écrans nouveaux : référentiel, feuille de compétences (web + mobile hors ligne, sur le modèle `CarnetSuivi.html`), conduite, conseil (vue primaire), cahier de liaison, examen, tableau de bord directeur (sur le modèle `SecDashboard.html` / `MatDashboard.html`), sections du portail famille (`Parent.html`). Playwright : `web/e2e/primary/*`.

## 6. Tests
- `tests/Architecture/PrimaryConventionsTest.php` : aucun `use` de socle hors `Contracts`, tables `pri_*` avec `tenant_id`/`establishment_id`, aucun mot réservé, toute route non GET passe par `WriteGate`, **aucun littéral de seuil de note dans `Grades/Domain`** (SC-PRI-4 : liste de nombres interdits hors 0 et 1 dans la stratégie).
- `tests/Isolation/PrimaryScopeTest.php` : autre client, autre établissement, autre section, maître d'une autre classe.
- `tests/Unit/Primary/**` (stratégie avec jeux de règles **fournis par les tests**, jamais par des constantes), `tests/Feature/Primary/**`, `tests/ApiContract` (401, 403, isolation, RFC 9457), `tests/E2E/Primary/*` (parcours §3 de spec.md), contrats `specs/001-socle-plateforme/contracts/openapi.d/primary-<module>.yaml` (lus par `OpenApiContractTest`).
- Les tests d'acceptation chiffrés des règles officielles (barème, moyennes, rang, passage) sont écrits **après** les réponses Q3, Q4, Q7, Q10, à partir des exemples de DOC-1/DOC-2 ; avant, les tests utilisent des règles d'exemple explicitement nommées `example_*`.

## 7. Risques
| Risque | Mitigation |
|--------|-----------|
| Règles officielles différentes des hypothèses | Tout paramétré ; stratégie versionnée ; tests chiffrés écrits seulement après réponses |
| PS1/PS2/PS5 non livrés | Vague 0 dédiée ; Primary démarre par ce qui n'en dépend pas |
| Collisions avec les agents IPES/front | Dossiers exclusifs ; migrations partagées par un seul agent par vague ; éditions ciblées du front |
| Performance des bulletins en mutualisé | File `documents:process-jobs`, lots, mesure SC-PRI-2 en recette |
| Jamais testé sur MySQL réel | `phpunit.mysql.xml` + `artisan db:check` en recette |
