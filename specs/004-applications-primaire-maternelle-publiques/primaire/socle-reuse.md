# Réutilisation du socle et changements demandés (PP1-PP19)

Date : 2026-10-15. Modèles : `specs/002-application-ipes/socle-reuse-A.md`, `specs/003-application-primaire/socle-reuse.md`. PP = « Public Primary/Schools » : changement du socle, fait par l'agent propriétaire du module concerné, jamais par un agent d'application. Chaque PP est **additif** : nouvelle migration datée, nouvelle méthode ou nouvelle clé de conteneur, valeur par défaut qui garde le comportement actuel ; aucun test existant ne change de résultat.

## 0. Règles reprises
1. `Apps/PublicSchools`, `Apps/PublicPrimary` (et `Apps/PublicNursery`) n'utilisent que `Socle/*/Contracts` (`tests/Architecture/StandardsTest.php`) et ne lisent aucune table d'un autre module (`NoForeignTableReadTest`). Exception proposée et testée : `PublicPrimary` et `PublicNursery` peuvent utiliser `Apps/PublicSchools/Contracts` (et seulement eux) ; à inscrire dans `PublicSchoolsConventionsTest`.
2. Extensions par **clés de liste du conteneur** (`<prefixe>.<application>`), jamais par liaison unique (fusion `array_merge` des `definitions.php`).
3. Mécanismes Higher recopiés (pas partagés) : `Support/WriteGate`, `GatedAction`, `HigherRoutes` → `PublicRoutes`, `Policies/RoleMatrix`, `ScopeResolver`, `Perimeter`.
4. SQL portable (voir [data-model.md](data-model.md)).

## 1. Services du socle réutilisés tels quels
| Besoin | Contrat ou mécanisme existant | Usage |
|---|---|---|
| Établissement, années, trimestres, classes | `Organisation/Contracts/EstablishmentDirectory`, `CurrentPeriod`, `StructureWriter`, `OrgUnitTree`, `SettingsReader` | une école publique = un établissement ; classes = structures ; trimestres = périodes |
| Gardes d'inscription | `Organisation/Contracts/EnrolmentGuard` (`organisation.enrolment.guards.<app>`) | **aucune** garde liée à l'APEE (RG-ELV-1) ; garde « une seule école à la fois » (RG-ELV-4) |
| Personnes, garants, consentements | `People/Contracts/PersonDirectory`, `ConsentChecker`, `BirthInfo`, `StructureEnrollments`, `EnrollmentDirectory` | élèves, parents, consentements photos/SMS |
| Appel | routes `/attendance-sessions*`, `/attendance-sessions/sync`, `Attendance/Contracts/AttendanceSummaryProvider` | appel des élèves (après PP17) |
| Personnel | `Staff/Contracts/StaffDirectory`, `AssignmentWriter` | fiche et titulaire de classe |
| Paiement mobile money | `Billing/Contracts/PaymentRequester` | encaissement APEE **sans facture** (`Billing` n'émet rien pour le public) |
| Documents | `Documents/Contracts/DocumentIssuer`, `ExtendedDocumentIssuer`, `IssuanceGuard`, `DocumentCatalog` ; numéro `{CODE}-{AAAA}-…` | DOC-01..17, 22..28 |
| Messages | `Messaging/Contracts/Messenger`, `DirectNotifier`, modèles en données | NOT-01..05, 08..14 |
| Indicateurs, tableaux de bord | `Reporting/Contracts/IndicatorProvider` (`reporting.indicators.<app>`), `TodoProvider` | tableau de bord de l'école |
| Approbation simple | `Audit/Contracts/Approvals` | correction de mention après publication (RG-EVA-3), avis de l'inspecteur (RG-PAS-2) |
| Journal | `Audit/Contracts/AuditLog` | toute écriture sensible |
| Exports | `integrations.export_types.<app>`, `TabularExporter` | questionnaire STA, listes d'examen |
| Plateforme | `platform.applications.<clé>`, `ApplicationAccess::allowsWrites` | manifestes `public_schools`, `public_primary` |
| Vie privée | `privacy.tenant_data_source.<app>`, `privacy.subject_data_source.<app>`, `retention:run` | export et purge des données d'élèves (Q12) |
| Notes chiffrées | `/assessments`, `/grades` | EX-EVA-08 (épreuves blanches) |

## 2. Changements demandés au socle
| N° | Module | Changement (additif) | Exigences | Bloque | Priorité |
|---|---|---|---|---|---|
| PP1 | Organisation | Table `organisation_establishment_territories` (id, tenant_id, establishment_id, territory_id, valid_from, valid_to, created_at) ; `TerritoryDirectory::territoryOf(tenant, establishmentId, at): ?TerritoryInfo` et `establishmentsIn(tenant, territoryId, at): list<string>` (descendants compris) ; routes `PUT /territories/{id}/establishments` (rattachement daté, ancien lien clos, jamais réécrit) ; permission de niveau client `organisation.territories.manage` remplace le contrôle par établissement de `TerritoryPolicy` (lecture inchangée) | RG-TER-1, RG-TER-4 | droits territoriaux, agrégats | P0 |
| PP2 | Identity | Affectation de rôle `scope_type = territory` **sans** `establishment_id` ; `AccessibleEstablishments` inclut les établissements de `establishmentsIn` ; `PermissionScopes::scopesFor` rend `Scope::territory(...)` pour tout établissement du territoire ; dépendances injectées (plus de `ContainerInterface`). Rôles marqués `aggregate_only` (colonne nullable sur `roles`, défaut faux) : `PermissionChecker` refuse à ces rôles les permissions marquées nominatives (`*.named.*`) | RG-TER-2, RG-TER-3, TST-05 | toute supervision | P0 |
| PP3 | Identity + Audit | Contrat `Identity/Contracts/NamedAccessJournal::record(tenant, actor, establishmentId, recordType, recordId, purpose)` (implémenté par l'actuel `ScopedAccessAudit`, déplacé) ; `purpose` ∈ `transfer`, `exam`, `control` ; route `GET /audit/named-access?establishment_id=` lisible par le directeur | RG-TER-2, « Journalisation » | US-09 | P0 |
| PP4 | Reporting | Table `reporting_territory_aggregates` (tenant, territory_id, establishment_id nullable, indicator, period_key, numerator, denominator, cell_count, computed_at) ; fournisseurs `reporting.territory_aggregates.<app>` ; commande `reporting:aggregate-territories` (cron nocturne, par lots d'écoles, reprise) ; `ThresholdDisclosurePolicy` implémente `DisclosurePolicy` (seuil lu de `reporting.disclosure.min_cell`, défaut 5 pour le client public, sans effet ailleurs car non appelé) ; route `GET /public/dashboards/{territory}` lue seulement dans la table | EX-STA-05, NF-P3, TST-10, API-12, DOC-28 | tableaux territoriaux | P1 |
| PP5 | Reporting | `PdoApprovalWorkflow` implémente `ApprovalWorkflow` (tables `reporting_workflows`, `reporting_workflow_steps`, `reporting_workflow_snapshots`) : étapes ordonnées fournies par l'appelant, retour motivé, gel versionné (une correction après gel = version n+1 motivée) | EX-STA-03, RG-STA-1, EX-EXA-03, RG-EXA-2 | STA, EXA | P1 |
| PP6 | **nouveau** `Socle/Sync` | `POST /sync/batches` : corps gzip, en-têtes `X-Device-Id`, `X-Batch-Signature` (HMAC-SHA256 avec la clé de l'appareil), `Idempotency-Key` = id du lot ; éléments `{kind, client_uuid, payload}` ; répartis vers `sync.handlers.<kind>` (interface `Sync/Contracts/BatchItemHandler`) ; résultat par élément (`applied`, `duplicate`, `conflict` 409 jamais écrasé, `rejected`) ; table `sync_devices` (appareil, établissement, clé chiffrée, révoqué) et `sync_batches`, `sync_items` ; grands lots traités par `sync:process` (cron). Les routes `/attendance-sessions/sync` et `/grades/sync` restent inchangées | API-04, NF-P2, NF-P4, TST-04 | hors ligne | P0 |
| PP7 | Identity | Code personnel sur appareil partagé : `POST /sync/devices/{id}/unlock` (utilisateur + code à 6 chiffres) rend un jeton court lié à l'appareil ; `identity_device_pins` (haché) ; 5 échecs = appareil verrouillé et ordre d'effacement de la base locale ; révocation à distance | « Comptes partagés interdits », « Hors-ligne » | US-10 | P1 |
| PP8 | People | Colonne `people.learner_number VARCHAR(40) NULL` + index unique (tenant_id, learner_number) ; contrat `People/Contracts/LearnerNumbering` (`assign` une seule fois, refuse toute modification) avec fournisseurs `people.learner_number.providers.<app>` ; `DuplicateMatcher` étendu par fournisseurs `people.duplicate_criteria.<app>` (critère « parent commun », restriction aux établissements donnés) ; sans fournisseur, comportement actuel | EX-ELV-02, EX-ELV-03 | US-02 | P0 |
| PP9 | People | Contrat d'écriture `People/Contracts/LearnerWriter` : `createLearner(tenant, draft, guardians)`, `enroll(tenant, establishmentId, personId, structureId, yearId, date)`, `closeEnrollment(tenant, enrollmentId, status, date)` (statuts existants `transferred`, `withdrawn`), mêmes contrôles que les routes `/people` et `/enrollments` | EX-ELV-01, 05, 07, RG-ELV-4 | inscriptions par lots, transferts | P0 |
| PP10 | Messaging | Catégorie de modèle `essential` (colonne nullable `message_templates.category`, défaut `standard`) ; table `messaging_opt_outs` (personne, canal, catégorie) ; `Messenger` saute les destinataires désinscrits sauf `essential` | RG-COM-2 | NOT-02..14 | P1 |
| PP11 | Messaging | Plafond de SMS par élève et par an : paramètre `messaging.sms.cap_per_learner_year` (absent = illimité, comportement actuel) ; compteur `messaging_sms_learner_usage` ; au-delà, seuls les `essential` partent | NF-P6 | — | P2 |
| PP12 | Messaging | Annonces territoriales : `announcements.territory_id` (nullable) ; destinataires = établissements de `establishmentsIn` ; rôles territoriaux autorisés | EX-COM-05, NOT-11..13 | — | P1 |
| PP13 | Integrations + Messaging | Contrat `Integrations/Contracts/VoiceGateway` (désactivé, simulateur) et canal `voice` dans `Messenger` (fichier audio enregistré) | EX-COM-02, NOT-05, NOT-14 | — | P3 (Could) |
| PP14 | Messaging | `POST /ussd/session` (sans authentification utilisateur, signé par l'opérateur), menus fournis par `messaging.ussd_menus.<app>`, code parent haché (`messaging_parent_pins`) ; aucune donnée sensible | EX-COM-03, API-15 | — | P3 |
| PP15 | Documents | Réédition « Rectificatif » : `DocumentIssuer::reissue(tenant, documentId, reason, actor)` garde le numéro, ajoute la mention, révoque l'ancienne version ; lot d'impression multi-établissements pour un territoire (`/document-batches` avec `territory_id`, rôle territorial) ; profil PDF « léger » (polices de base, images compressées) | RG-DOC-5, RG-DOC-6, « impression à l'inspection » | DOC-09, 10 | P1 |
| PP16 | Organisation | Paramètres au niveau territoire : `territory_settings` entre client et établissement dans l'héritage ; métadonnée de paramètre `lock_level` (`national`, `region`, `school`) qui refuse l'écriture à un niveau inférieur | RG-TER-6, RG-PAS-1 | calendrier, règles d'évaluation | P1 |
| PP17 | Attendance | Mode `day_part` (= PS3 de la spec 003, à faire une seule fois pour le privé et le public) | EX-PRE-01, indicateur « demi-journées » | appel | P0 |
| PP18 | Platform + Identity (données) | Migration de catalogue : applications `public_schools` (socle commun du public, non vendue seule), `public_primary`, `public_nursery` ; migration `database/migrations/<date>_roles_public_schools.sql` : rôles R01-R21, niveaux (client, territoire, établissement), `role_permissions` | R01-R21 | tout | P0 |
| PP19 | Staff | Contrat `Staff/Contracts/StaffMover::move(tenant, staffId, fromEstablishment, toEstablishment, date, actor)` (clôt l'affectation, met à jour l'établissement de rattachement, historise) | EX-PER-02 | mutations | P1 |

## 3. Ce qui ne demande pas de changement du socle (choix assumé)
- **Évaluation par critères** (EVA) : portée par `PublicPrimary` (`pp_*`), le modèle de notes du socle n'a ni critère ni occasion. Les trimestres restent des périodes du socle ; les unités d'apprentissage sont des lignes `pp_learning_units` rattachées à un trimestre (pas besoin de PS1a).
- **Présence des enseignants** : tables `pub_teacher_presence` de l'application (le CDC la place dans le modèle propre).
- **APEE** : grand livre de l'application, jamais une facture du socle (RG-ELV-1 par construction).
- **Statuts du personnel public** : profil `pub_staff_profiles` (la colonne `staff_members.status` fait 12 caractères et sert déjà au cycle de vie).

## 4. Ordre
Tour a (en parallèle) : PP1+PP16 (Organisation), PP8+PP9 (People), PP17 (Attendance). Tour b : PP2+PP3+PP7 (Identity, après PP1), PP6 (Sync), PP18 (données). Tour c : PP4+PP5 (Reporting), PP10+PP12 (Messaging), PP15 (Documents), PP19 (Staff). Plus tard (P3) : PP11, PP13, PP14.
