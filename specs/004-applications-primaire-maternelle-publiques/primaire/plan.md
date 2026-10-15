# Plan technique : Primaire publique et modules communs

Date : 2026-10-15. Entrées : [spec.md](spec.md), [couverture.md](couverture.md), [socle-reuse.md](socle-reuse.md), [data-model.md](data-model.md), ADR-0001, `specs/HANDOVER.md`, modèle `src/Modules/Apps/Higher`.

## 1. Contexte technique (réel)
PHP 8.1 sans framework (PSR-15, PHP-DI, FastRoute), MySQL 8 en production, SQLite en test, Dompdf, cron (pas de Redis, Docker, SSH, Node en production), front Vue 3 PWA hors ligne (IndexedDB, `client_uuid`, conflits 409 jamais écrasés). **Écart voulu** avec le CDC (« Cadre » : Laravel 13, PHP 8.4, PostgreSQL 16, Redis, Gotenberg, `app/Modules/Apps/Primary` et `Shared/Public`) : noté, non rediscuté. Conséquences : « politiques Laravel » → `Policies/` + `ScopeResolver` ; « Redis » → agrégats en table rafraîchis par cron (PP4) ; « Gotenberg » → Dompdf avec profil léger (PP15).

## 2. Décision : `PublicSchools` + `PublicPrimary` (et non `Primary`)
| Option | Pour | Contre |
|---|---|---|
| A. Tout dans `Apps/Primary` (privé + public, drapeaux) | un seul code primaire | client, droits et règles opposés : frais et gardes de paiement (privé) contre RG-ELV-1 « aucun champ ne lie inscription et paiement » ; notes /20 et rang contre A/ECA/NA sans rang ; périmètre interne contre chaîne territoriale. Chaque écran porterait des `if public` ; le préfixe `pri_` est déjà prévu par la spec 003 |
| B. `Apps/PublicPrimary` contenant aussi les modules communs | simple | la Maternelle dépendrait du Primaire ; ECO, ELV, CLA… ne sont pas « primaire » |
| **C. `Apps/PublicSchools` (communs) + `Apps/PublicPrimary` + `Apps/PublicNursery`** | suit le découpage du CDC (« Modules communs » / « propres au Primaire » / « propres à la Maternelle ») et sa structure `Shared/Public` ; la Maternelle travaille en parallèle sans toucher au Primaire ; le privé (003) reste intact | une dépendance application → application à autoriser explicitement (uniquement vers `PublicSchools/Contracts`) |

**Retenu : C.** `Apps/PublicPrimary` est déjà le nom utilisé par le code du 14/10 (namespace, route `/public-primary`) : on le garde, mais on en retire le registre (commun). Clés de plateforme : `public_schools` (non vendue seule, requise par les deux autres), `public_primary`, `public_nursery`. Préfixes : `pub_`, `pp_`, `pn_` (ce dernier à confirmer par l'agent Maternelle). Routes : `/public/...` (communs, conforme aux API-01, 11, 12 du CDC) et `/primary/...` **non** : `/public-primary/...` pour éviter toute collision avec l'application privée `primary` (écart de chemin avec API-02..10 du CDC, noté).

## 3. Arborescence cible
```
src/Modules/Apps/PublicSchools/
  PublicSchoolsApplication.php  manifeste public_schools, constantes de permissions
  definitions.php  routes.php   (glob des sous-modules)
  Contracts/   SchoolRegistry, PupilRegistry, ClassRoster, PupilTransfer, CensusSectionProvider, PublicMessages
  Support/     WriteGate (public_primary OU public_nursery), GatedAction, PublicRoutes, Perimeter, ScopedByTerritory
  Policies/    RoleMatrix (R01-R21), ScopeResolver (établissement, classe, territoire, agrégat seul)
  Schools/     ECO : fiche, acte, GPS, infrastructures annuelles, photos de visite
  Pupils/      ELV + CLA : inscription, identifiant (PP8), doublons, situations, transferts, abandon, classes jumelées, double flux
  Attendance/  PRE : appel (socle + PP17), présence des enseignants, « classe sans enseignant », DOC-06, DOC-22
  Staff/       PER : profil public, mutations (PP19), formations, besoins
  Messages/    COM : modèles, ciblage, annonces territoriales, sections portail famille
  Apee/        APE : vote, encaissements, dépenses, exonérations, paquet minimum, dons
  Census/      STA : questionnaire, contrôles, circuit (PP5), export, données de fin d'année
  Inspection/  INS : planning, grilles, visites, recommandations, comptages, signalements
  Canteen/     CAN : repas, stocks, campagnes de santé
  Territory/   API-01, tableaux territoriaux (lecture PP4), fournisseurs d'agrégats
  Sync/        gestionnaires `sync.handlers.public.*` (appel, inscriptions, visites)
  Documents/   DOC-01..07, 22..28 (types, modèles FR/EN, garde : aucune variable APEE)
src/Modules/Apps/PublicPrimary/
  PublicPrimaryApplication.php  definitions.php  routes.php
  Referential/ EVA-01, 02 : référentiels versionnés, unités d'apprentissage
  Evaluation/  EVA-03..08 : critères, stratégie de mention paramétrée, remédiation, bulletins, rectificatifs
  Promotion/   PAS : politique, propositions, conseil des maîtres, recours
  Exams/       EXA : listes, circuit, import des résultats, attestations, transfert vers le Secondaire
  Documents/   DOC-08..17
  Sync/        gestionnaire `sync.handlers.public_primary.criterion_checks`
```
Chaque sous-module : `Application/ Domain/ Infrastructure/ Http/ Database/Migrations/ routes.php definitions.php`.

## 4. Mécanismes
| Mécanisme | Réalisation |
|---|---|
| Écriture | `WriteGate::assertWritable` : `ApplicationAccess::allowsWrites($tenant, 'public_primary' \| 'public_nursery')` selon le type d'établissement (423 suspendu, 403 fermé) |
| Routes | `PublicRoutes` : GET libres, autres méthodes enveloppées par `GatedAction` ; test qui vérifie qu'aucune n'y échappe |
| Périmètre | `ScopeResolver` : `PermissionScopes::scopesFor` (après PP2) → établissement, classe (R10), territoire (R05-R08, R18-R20) ; `ScopedByTerritory` : nominatif seulement si niveau ≤ département **et** finalité déclarée (`purpose`), journalisée par `NamedAccessJournal` (PP3) ; région et national : agrégats seuls |
| Agrégats | fournisseurs `reporting.territory_aggregates.public_schools` / `.public_primary` (effectifs, parité, présence, élèves par enseignant, par salle, part de A, redoublement, abandon, réussite, sans acte) ; masquage par `DisclosurePolicy` |
| Hors ligne | gestionnaires `sync.handlers.*` (PP6) ; idempotence par `client_uuid` ; conflit = 409 rendu, jamais écrasé |
| Règles d'évaluation | `Evaluation/Domain/MentionCalculator` pur, paramètres lus par `SettingsReader` (verrou national, PP16) ; aucune constante |
| Documents | `public_schools.document_types` / `public_primary.document_types` (sur le modèle `higher.document_types`) ; garde d'émission qui refuse toute variable `apee_*` dans un document d'élève |
| Console | `public:census-open`, `public:absence-alerts` (quotidien : NOT-03, NOT-12, NOT-13, abandon présumé), `public:birth-certificate-alerts` (janvier : NOT-08), `public:aggregates` (appelé par `reporting:aggregate-territories`) |

## 5. Permissions (extrait de la matrice du CDC)
`public_schools.school.{read,write,validate}`, `public_schools.pupils.{read,write,named.read}`, `public_schools.attendance.{read,write}`, `public_schools.teacher_presence.{read,write,validate}`, `public_schools.staff.{read,write}`, `public_schools.messages.{send,announce_territory}`, `public_schools.apee.{read,write,read_receipts,non_payers.read}` (cette dernière : trésorier et président seulement, RG-APE-2), `public_schools.census.{write,validate}`, `public_schools.inspection.{read,write}`, `public_schools.canteen.write`, `public_schools.aggregates.read`, `public_primary.evaluation.{write,validate,publish,rectify}`, `public_primary.promotion.{write,validate,opinion}`, `public_primary.exams.{write,validate,consolidate,import}`. Table des rôles R01-R21 → permissions : migration PP18, recopiée dans `web/src/lib/permissions.ts`.

## 6. Dépendances et ordre
1. PP1, PP2, PP3, PP6, PP8, PP9, PP17, PP18 (vague 0) → squelettes `PublicSchools`, `PublicPrimary`.
2. Lot P1 : Schools (reprise du registre), Pupils, Attendance, Staff, Messages, Sync, Territory (API-01) ; front PWA.
3. Lot P2 : PP4, PP5, PP10, PP12, PP15, PP16, PP19 ; Referential, Evaluation, Promotion, Apee, Census, DOC-08..12, 23..25.
4. Lot P3 : Exams, Inspection, Canteen, tableaux territoriaux, PP11, PP13, PP14.
La Maternelle dépend de `PublicSchools/Contracts` livrés en fin de vague 1.

## 7. Front
`web/src/apps/public-schools/` et `web/src/apps/public-primary/` (dossiers exclusifs ; le registre actuel est déplacé). Écrans mobiles prioritaires : appel en un geste, saisie des critères (grille élève × critère × 3 occasions), visite d'inspection. Base IndexedDB chiffrée (clé dérivée du code personnel, PP7), effacement après 5 échecs. Éditions ciblées seulement de `router/routes.ts`, `AppShell`, `lib/permissions.ts`, `i18n/*`.

## 8. Tests
`tests/Architecture/PublicSchoolsConventionsTest.php` (contrats seulement, exception `PublicSchools/Contracts`, tables `pub_`/`pp_` avec `tenant_id`, WriteGate, aucun littéral de seuil dans `Domain/`, aucune colonne `apee` hors `Apee/`), `tests/Isolation/PublicTerritoryTest.php`, `tests/Unit/PublicPrimary/**` (exemple Paul E.), `tests/Feature/PublicSchools/**`, `tests/E2E/PublicSchools/*` (TST-01..10), contrats `specs/001-socle-plateforme/contracts/openapi.d/public-*.yaml`, Playwright `web/e2e/public/*`, charge `tests/Load` (NF-P2, NF-P3).

## 9. Risques techniques
| Risque | Parade |
|---|---|
| 17 600 établissements dans un client : listes, `/me/contexts`, gardes par établissement | pagination, `AccessibleEstablishments` par territoire (PP2), mesure dès le pilote (50 écoles) puis 500 simulées |
| Mutualisé Camoo : 40 000 lots/heure | file `sync_batches` + `sync:process` ; au-delà du pilote, hébergement dédié (Q19) |
| Règles officielles non validées | paramètres verrouillés au niveau national (PP16) ; tests sur paramètres fournis |
| Application native demandée | PWA d'abord (Q2) ; l'API de lots reste la même pour une application native |
