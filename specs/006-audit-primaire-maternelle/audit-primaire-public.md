# Audit : Primaire public (`public_primary`) et commun (`public_schools`)

Spécification : `specs/004-.../primaire/` et `cdc-public.md` (69 exigences communes et primaire : ECO 5, ELV 7, CLA 4, PRE 5, PER 4, COM 5, APE 6, STA 6, INS 5, CAN 3, EVA 8, PAS 4, EXA 7). Tâches T600 à T699 : 11 cochées sur 82, **toutes au socle**. Architecture validée (Q4) : `PublicSchools` + `PublicPrimary` + `PublicNursery`.

## Code applicatif
- `src/Modules/Apps/PublicSchools/` : **n'existe pas** (T620 à T624 non faites). Il manque donc la garde d'écriture commune, `RoleMatrix` R01 à R21, `ScopeResolver` territorial et les contrats `SchoolRegistry`, `PupilRegistry`, `ClassRoster`, `PupilTransfer`.
- `src/Modules/Apps/PublicPrimary/` : seul le registre provisoire du 14/10 existe (`Registry/`, tables `pp_school_profiles`, `pp_infrastructure_snapshots`, 9 routes `/public-primary/registry/*`). Il n'a ni manifeste ni garde d'application (`RegistryPolicy` contrôle seulement la permission). Le plan prévoit de le déplacer dans `PublicSchools/Schools` puis de le supprimer (T621, T625).
- Front : `web/src/apps/public-primary/registry/RegistryView.vue`, `web/src/api/public-primary-registry.ts`.

## État par module (69 exigences)
| Module | Testé | Partiel | Socle prêt, application absente | Absent |
|---|---|---|---|---|
| ECO | 0 | 3 (EX-ECO-01 fiche, 02 infrastructures, 05 historique figé : `InfrastructureSnapshotTest`, `TerritoryRegistryTest`) | 0 | 2 |
| ELV | 0 | 0 | 2 (EX-ELV-01/02 : `LearnerWriter`, `LearnerNumbering`, testés au socle) | 5 |
| CLA | 0 | 0 | 0 | 4 |
| PRE | 0 | 0 | 1 (EX-PRE-01 : mode `day_part`, `DayPartModeTest`, `DailyPresenceTest`) | 4 |
| PER | 0 | 0 | 1 (mutations : `StaffMover`, `StaffMoverTest`) | 3 |
| COM | 0 | 0 | 3 (EX-COM-01 plafond SMS `SmsCapTest`, EX-COM-05 annonces `TerritorialAnnouncementTest`, catégorie `essential`) | 2 |
| APE, STA, INS, CAN, EVA, PAS, EXA | 0 | 0 | 0 (STA : circuit `PdoApprovalWorkflow` prêt) | 39 |
| **Total** | **0** | **3** | **7** | **59** |

## Socle livré pour le public (non commité, testé)
`So/Sync` (T609 : 64 tests Feature et Unit), territoires et rattachement daté (T601, `EstablishmentTerritoryTest`, `TerritoryHistoryTest`), `TerritorySettings` (T602, sans route HTTP), numérotation (T603), `LearnerWriter` (T604), `day_part` (T605), `StaffMover` (T611), `PdoApprovalWorkflow` (T612), `essential`, désinscriptions et annonces territoriales (T614), plafond SMS (T616), `DocumentReissuer` (T615 partiel), rôles R01 à R21 (`202610152351_roles_public_schools.sql`, 12 tests).
**Non livré** : T606 (affectation territoriale, `PermissionScopes`, rôles `aggregate_only`), T607 (journal nominatif), T608 (code personnel sur appareil partagé), T613 (agrégats territoriaux ; `Reporting/Contracts/DisclosurePolicy` n'a **aucune implémentation**), T615 (lot multi-établissements, PDF léger), T617, T618 (USSD), clés de catalogue `public_schools` et `public_primary` (T610).

## Qualité
PHPStan : 0 erreur sur `PublicPrimary`. Les tests `PublicSchoolsScopeTest` (3) et `Registry/*` (5) sont verts. Pas de lint en erreur sur `web/src/apps`. Aucun gestionnaire `sync.handlers.*` n'est enregistré : le point d'extension est vide.

## Écarts au CDC
Routes `/public-primary/*` au lieu de `/primary/*` (écart validé). L'application Android native n'existe pas : la PWA est retenue par défaut (Q2). L'agrégation se fait par cron et non par Redis (écart voulu).

## Risques
1. Sans `PublicSchools`, ni le primaire public ni le transfert maternelle → primaire (FR-M18) ne peuvent avancer.
2. Les règles d'évaluation (A/ECA/NA, passage), le questionnaire statistique et les formats CEP/FSLC dépendent du MINEDUB (Q3, Q8, Q9, Q10).
3. L'échelle (17 600 établissements, 40 000 lots par heure) dépasse le mutualisé Camoo (Q19).
4. HTTPS est absent : pas de données réelles de mineurs (Q23).
