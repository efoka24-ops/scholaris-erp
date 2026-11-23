# Plan d'exécution par vagues d'agents : application IPES

Date : 2026-10-10. Entrées : [decisions.md](decisions.md), [cdc-ipes.md](cdc-ipes.md), [socle-reuse-A.md](socle-reuse-A.md) (D1-D21), [socle-reuse-B.md](socle-reuse-B.md) (S1-S21), [tasks.md](tasks.md), [agent-conventions](../001-socle-plateforme/agent-conventions.md).

## Règles communes
1. **Maximum 3 agents simultanés.** Une vague peut comporter plusieurs tours ; on lance l'agent suivant dès qu'un créneau se libère, dans l'ordre indiqué.
2. **Priorité du CDC** : lot I1 (ACA, ADM, INS, MAQ, FIN, DOC-01 à 06 et 27 à 30, portail étudiant) avant I2 (EDT, ENS, PRE, NOT) avant I3 (DEL, STG, EXN, TUT, PIL, autres DOC). I4 = recette/pilote.
3. **Dossiers exclusifs** : un dossier n'a qu'un agent. `database/migrations/**`, `config/**`, `composer.*`, `src/Core/**` : un seul agent par vague (W0-5 pour les rôles), les autres décrivent leur besoin dans leur rapport. Les migrations d'une application vont dans `src/Modules/Apps/Higher/<Module>/Database/Migrations/AAAAMMJJHHMM_higher_<module>_*.sql` (chargées par le Migrator) : seuls les rôles passent par `database/migrations`.
4. **Un seul `routes.php` et un seul `definitions.php` par module Higher** : le Kernel charge `src/Modules/*/*/routes.php` ; `Higher/routes.php` (W0-5) inclut `Higher/<Module>/routes.php` (fonction `register(RouteRegistry)`) et `Higher/definitions.php` fait `array_merge` des `Higher/<Module>/definitions.php`. Un module ajoute son fichier ; l'inclusion est préparée par W0-5 par un glob sur `Higher/*/routes.php` (aucun agent ne modifie le fichier racine).
5. **Contrats** : Higher n'utilise que `Scholaris\Modules\Socle\<M>\Contracts\*` (StandardsTest). Contrat OpenAPI par module : `specs/001-socle-plateforme/contracts/openapi.d/higher-<module>.yaml` (le test `OpenApiContractTest` lit ce dossier).
6. **Tests** : sous `tests/Feature/Higher/<Module>/`, `tests/Unit/Higher/<Module>/`, `tests/Support/Higher/` (base de test propre à Higher), fixtures `tests/Fixtures/Higher/` (propriétaire : W0-5 pour le dossier, chaque agent ajoute ses fichiers préfixés). SQL portable MySQL 8 / SQLite, mots réservés interdits (`start`, `end`, `groups`, `rank`, `range`, `order`, `condition` ; utiliser `start_time`, `group_ref`, `rank_in_group`…).
7. **Qualité avant rendu** (cf. agent-conventions) : php-cs-fixer limité à ses fichiers, phpstan sans erreur ni baseline, phpunit tout vert.
8. **Plateforme** : chaque écriture Higher passe par `WriteGate` (voir 0.4). Tout rapport d'agent liste les permissions `higher.*` utilisées.

---

# VAGUE 0 : socle, squelette Higher, rôles, lien Platform

## 0.1 Changements du socle D1-D21 et S1-S21, regroupés par module propriétaire
Ce sont des changements du socle (autorisés), faits par des agents « propriétaires de module », chacun dans ses dossiers uniquement, avec ses tests (`tests/**/<Module>`), son contrat OpenAPI et sans toucher aux autres modules. Contrat d'écriture « application » = interface dans `Contracts/` du module + implémentation dans `Infrastructure/` ou `Application/` + liaison dans le `definitions.php` du module ; accès aux services internes seulement par ce contrat (aucune ouverture de service).

| Agent | Dossiers exclusifs | Demandes | Livrables précis |
|-------|--------------------|----------|------------------|
| **W0-1 Organisation, People, Identity** (tour a, première priorité) | `src/Modules/Socle/Organisation/**`, `src/Modules/Socle/People/**`, `src/Modules/Socle/Identity/**`, `tests/**/{Organisation,People,Identity}/**` | D1, D2, D3, D5, D6, D7, D8, S15 | D1 `Organisation\Contracts\StructureWriter` (créer/modifier `track|level|group` rattaché à un org_unit, retourne l'id, types `school|department` acceptés pour `org_units`) ; D2 `Organisation\Contracts\AuthorizationRegistry` (CRUD autorisations : niveaux couverts, `document_id`, validité) ; D3 `EnrolmentGuard` enregistrable (liste injectée dans `PdoEnrolmentGate`, appelée par `EnrollmentService::validate`, avec niveau et année) ; D5 `People\Contracts\EnrollmentNumberProvider` (le numéro MAT du socle est remplacé quand une application en enregistre un) ; D6 portée école des paramètres : `SettingsReader::value` accepte une portée `org_unit` (héritage plateforme > client > établissement > école), clé `settings.scope_type='org_unit'` ; D7 paramètre `enrolment.allow_multiple_tracks` (double cursus, une inscription vivante par filière) ; D8 `PromotionPlanner` remplaçable par type d'établissement (`higher` remplace `YearPromotion`) ; **S15 (périmètre)** : `Identity\Contracts\PermissionScopes::scopesFor(Principal, permission, establishmentId): list<Scope>` (types `establishment`, `org_unit`, `class`, **`structure`** ajouté à `ScopeType` pour la filière) et `Organisation\Contracts\OrgUnitTree::descendantsOf(orgUnitId)` / `structuresOf(orgUnitId)` ; `PermissionChecker::can` reste inchangé (établissement) |
| **W0-2 Assessment** (tour a) | `src/Modules/Socle/Assessment/**`, `tests/**/Assessment/**` | D9, D10, S1, S2, S3, S4, S5, S6, S7, S8, S9 | D9 `Assessment\Contracts\CurriculumCatalog` (upsert idempotent matière/élément par clé externe, désactivation, lecture) ; S1 codes d'absence extensibles (`justified`, `unjustified`, `na`, `fr`) avec effet déclaratif (`zero`, `excluded`, `retake`) ; S2 pas de saisie `assessment.step` (0,25) ; S3 approbation à n niveaux (chaîne chef de département puis directeur d'école) ; S4 contrôle auteur de saisie ≠ verrouilleur (option) ; S5 `calculation_rules` rattachées à une structure/programme (`structure_id` nullable) ; S6 `RuleStrategy` enregistrable (formule de crédits, éliminatoire par UE, rattrapage remplaçant l'examen, décisions annuelles, lettres/points/MGP) ; S7 dimension `session` (normal, rattrapage) dans `results` et `deliberations` ; S8 `DeliberationGuard` enfichable (notes verrouillées, signature du président, 2FA, empreinte SHA-256, nouveau PV à la réouverture, alerte DG) ; S9 étiquettes de décision paramétrables ; D10 recoupe S5 à S7 : traité par eux |
| **W0-3 Billing, Documents, Reporting, Portals, Integrations** (tour a) | `src/Modules/Socle/Billing/**`, `src/Modules/Socle/Documents/**`, `src/Modules/Socle/Reporting/**`, `src/Modules/Socle/Portals/**`, `src/Modules/Socle/Integrations/**` et leurs tests | D4, S10, S11, S12, S13, S14, S16, S17, S18, S19, S20, S21 | D4 `Billing\Contracts\InvoiceIssuer` et `PaymentRequester` (facture, paiement, y compris candidat sans inscription = S13) ; S10 `fee_items.code` stable et `kind` extensible ; S11 `billing.discount_cap_percent` ; S12 blocage `documents` appliqué à l'émission unitaire quand `blockable` ; S13 facture au nom d'un tiers ; S14 export tutelle/MINESUP à colonnes configurables par type d'export (CSV et XLSX via `XlsxWriter`) ; S16 jeton `{ECOLE}` dans `NumberPattern` (numéro `REL-EC-{AAAA}-{N6}`) ; S17 signataire par type de document ; S18 `IssuanceGuard` enregistrable avant `issue` ; S19 état « remplacé » dans la vérification publique ; S20 `PublishedResult` étendu (crédits, mention, détail UE) et sections de portail enregistrables ; S21 `DashboardKind` extensible (`ecole`, `academique`, `tutelle`) |
| **W0-4 Staff, Scheduling, Attendance** (tour b) | `src/Modules/Socle/Staff/**`, `src/Modules/Socle/Scheduling/**`, `src/Modules/Socle/Attendance/**` et tests | D11 à D21 | D11 `TimetableWriter` ; D12 `session_kind` sur `timetable_slots` ; D13 lecture des occurrences tenues ; D14 HETD paramétrable (`staff.hetd.cm|td|tp`) ; D15 taux par grade et niveau (`vacation_rates.level`) ; D16 `VacationPayabilityCheck` enfichable (RG-ENS-1/4/5) ; D17 `AssignmentWriter` ; D18 colonnes `element_ref`, `group_ref` sur `staff_assignments` ; D19 `AttendanceSummaryProvider::forLearnerByUnit` et colonne `course_unit_id` sur `attendance_sessions` ; D20 paramètre `attendance.base=held` ; D21 constantes de contrat de statut de présence |

Ordre : tour a = W0-1, W0-2, W0-3 en parallèle (3 agents). Tour b = W0-4 et W0-5 (2 agents). Les vagues 1 démarrent dès que W0-1 et W0-5 sont rendus (ACA/INS) ; H3 (FIN, DOC) attend W0-3 ; la vague 2 attend W0-4 et W0-2 (S1-S4).

## 0.2 W0-5 : squelette `src/Modules/Apps/Higher` et rôles (tour b)
Dossiers exclusifs : `src/Modules/Apps/Higher/` (fichiers racine uniquement : `definitions.php`, `routes.php`, `HigherApplication.php`, `Support/`, `Policies/`, `Http/Support/`), `database/migrations/*_roles_higher.sql`, `tests/Support/Higher/`, `tests/Fixtures/Higher/` (création du dossier), `tests/Architecture/HigherConventionsTest.php`, `tests/Isolation/HigherScopeTest.php`, `specs/001-socle-plateforme/contracts/openapi.d/higher-core.yaml`.
Contenu :
- Arborescence : `Academic/ Admissions/ Enrolment/ Curriculum/ Teaching/ Attendance/ Grades/ Deliberation/ Billing/ Internships/ NationalExams/ Tutelage/ Documents/ Dashboards/ Portals/ Notifications/ Policies/ Support/`, chacun avec `Contracts/? Application/ Domain/ Infrastructure/ Http/ Database/Migrations/ routes.php definitions.php`.
- `HigherApplication` : enregistre dans l'`ApplicationRegistry` le descripteur `higher` (vocabulaire étudiant, filière, semestre, relevé).
- Conventions : préfixe de table `hi_`, `tenant_id` et `establishment_id` obligatoires, ULID, VARCHAR(32) dates, montants XAF entiers, clés `higher.*` des paramètres.
- Tests d'architecture (`HigherConventionsTest`) : aucun `use` de socle hors `Contracts`, tables `hi_*` avec `tenant_id`, aucun mot réservé, **toute route non GET de Higher passe par `WriteGate`** (analyse de `routes.php`), aucun `use` de Laravel, aucune lecture de table d'un autre module (NoForeignTableReadTest étendu par le test existant).

## 0.3 Les 23 rôles R01-R23 et leurs périmètres
**Déclaration (migration partagée, W0-5)** : `database/migrations/202610101000_roles_higher.sql` insère dans `roles` les rôles manquants (code `higher_<slug>`, `level='establishment'`, `is_sensitive` selon decisions.md R33) puis leurs `role_permissions`. Réutilise les rôles existants quand l'équivalent est exact : R02 = `direction_groupe` (niveau client), R12 = `apprenant`, R13 = `parent`, R14 = `caissier`, R15 = `comptable`, R09/R10 partagent `enseignant` (R10 en plus `higher_vacataire` pour le suivi de ses heures). `RolePermissionsTest` du socle est étendu par le test fourni avec la migration.

| Rôle | Code | Périmètre d'affectation (RG-ORG-2) | Permissions principales (nouvelles préfixées `higher.*`, socle réutilisées) |
|------|------|-------------------------------------|---------------------------------------------------------------------------------|
| R01 Promoteur | `higher_promoteur` | établissement | `reporting.read`, `higher.dashboard.read` ; aucune permission de saisie de note |
| R02 Directeur général | `direction_groupe` | établissement | lectures, `higher.settings.sensitive`, `higher.document.sign_institutional`, `higher.vacation.validate`, réception des alertes (`higher.alert.dg`), `higher.national_list.export` |
| R03 Directeur académique | `higher_dir_academique` | établissement | `higher.curriculum.approve`, `higher.regulation.amend`, `higher.calendar.manage`, `assessment.reopen`, `higher.tutelage.manage` |
| R04 Directeur d'école | `higher_dir_ecole` (+ `directeur`) | une ou plusieurs écoles (`org_unit`) | `higher.admission.decide`, `higher.deliberation.preside`, `assessment.correction.approve`, `higher.enrolment.derogate`, `higher.document.sign_school`, `higher.vacation.validate_school` |
| R05 Chef de département | `higher_chef_dept` | département (`org_unit`) | `higher.assignment.manage`, `assessment.validate`, `assessment.correction.approve` (1re étape), `higher.hours.validate` |
| R06 Responsable de filière | `higher_resp_filiere` | filière (`structure`) | `scheduling.manage`, `higher.curriculum.manage`, `higher.internship.manage` |
| R07 Scolarité | `higher_scolarite` | école | `people.manage`, `higher.enrol`, `higher.application.manage`, `documents.issue`, `higher.deliberation.prepare`, `higher.national_list.manage` |
| R08 Service des examens | `higher_examens` | école | `higher.exam.manage`, anonymat (`assessment.reveal`), PV d'examen, convocations |
| R09 Enseignant permanent | `enseignant` | groupes ou EC affectés (`class`/`structure`) | `grades.enter`, `assessment.submit`, `attendance.take`, `higher.session_log.write` |
| R10 Enseignant vacataire | `enseignant` + `higher_vacataire` | idem | idem + `staff.vacation.read_own` |
| R11 Responsable discipline | `higher_discipline` | école | `attendance.sanction.manage`, `higher.council.manage` |
| R12 Étudiant | `apprenant` | soi | `self.read`, `higher.query.create` |
| R13 Garant | `parent` | ses étudiants | `self.read`, consentement via People |
| R14 Caissier | `caissier` | école | `billing.cash` |
| R15 Comptable | `comptable` | établissement ou école | `billing.manage`, `higher.quitus.issue`, `higher.vacation.pay` |
| R16 Responsable des stages | `higher_resp_stages` | filière/école | `higher.internship.manage`, `higher.defense.manage` |
| R17 Responsable RH | `higher_rh` | établissement | `staff.manage`, `staff.contract.manage` |
| R18 Bibliothécaire (option) | `higher_biblio` | établissement | déclaré, aucune permission (lot I4) |
| R19 Responsable communication | `higher_communication` | établissement | `messaging.manage` |
| R20 Administrateur fonctionnel | `higher_admin_fonct` (+ `super_admin` client) | établissement | `identity.manage`, `settings.manage`, `documents.template.manage` |
| R21 Membre de jury | `higher_jury` | session de jury | `assessment.deliberation.sign`, portail jury ; durée = session |
| R22 Université de tutelle | `higher_tutelle` | filières sous tutelle (`structure`), `ends_at` | `higher.tutelage.read` (inactif en V1, voir R4) |
| R23 Auditeur | `higher_auditeur` | périmètre accordé, `ends_at` | lecture seule, `audit.read` |

Matrice L/E/V du CDC → tests : fichier `Higher/Policies/RoleMatrix.php` (données) et `tests/Unit/Higher/Policies/RoleMatrixTest.php` : une ligne du CDC (ex. « Inscriptions : R07 É, R04 V, R12 L(la sienne) ») = un cas. Les trous de la matrice (C1, C2 de decisions.md) sont encodés en « refus par défaut ».

**Comment le contrôle des permissions du socle gère un périmètre école/département/filière** : `PermissionChecker::can(Principal, permission, establishmentId)` répond « cette personne a-t-elle la permission dans l'établissement » (une IPES = un établissement, U1). Le périmètre n'est pas dans `can` ; il est appliqué en deux temps par Higher :
1. `Higher\Policies\ScopeResolver` appelle `can(...)`, puis `PermissionScopes::scopesFor(...)` (S15) qui rend les affectations en vigueur pour cette permission (`establishment`, `org_unit` école ou département, `structure` filière, `class` groupe) ; il développe les `org_unit` par `OrgUnitTree` (école → départements → filières → groupes).
2. `ScopedBySchool` (T208) transforme ce résultat en filtre SQL imposé à tous les dépôts Higher (clause `school_id IN (...)`, `department_id`, `program_id`, `group_id`) ; une lecture hors périmètre répond 404 (l'existence est cachée) pour la lecture, 403 journalisé pour une écriture. Sélecteur d'école (RG-ORG-3) : paramètre `school_id` ou `all` (permis seulement avec plusieurs écoles). Un test d'isolation (TST-03, TST-12) par agent vérifie : autre client, autre école, autre département, enseignant non affecté. Sans S15, l'agent ne peut pas livrer : S15 est la première tâche de W0-1.

## 0.4 Lien avec le module Platform (livré)
- **Manifeste** : `Higher/definitions.php` expose la clé de conteneur `platform.applications.higher` = `new ApplicationManifest(key: 'higher', label: 'Supérieur (IPES)', target: 'higher', status: available, version: '0.1.0', requiredModules: ['people','billing','messaging','documents','scheduling','attendance','assessment','staff','reporting'], monthlyPriceXaf: 60000, trialDays: 90, graceDays: 15)`. Le catalogue en base fait foi pour le commercial.
- **Activation des modules** : l'ouverture de l'application à un client active les modules du socle requis (`modules_enabled` de l'établissement `higher`) ; Higher ne le fait pas lui-même.
- **`WriteGate`** (`Higher/Support/WriteGate.php`) : `assertWritable(Principal)` appelle `ApplicationAccess::allowsWrites($tenantId, 'higher')` ; en échec, `HttpException 402/403` Problem Details « application suspendue : consultation et export seuls ». Appelé en premier par tout contrôleur non GET et par chaque tâche planifiée Higher qui écrit (expirations, relances) ; `allowsReads` protège les GET (fermée = 404). Test : `tests/Feature/Higher/Core/WriteGateTest.php` (états `trial`, `active`, `past_due` écrivent ; `suspended` lit et exporte, n'écrit pas ; `closed` ni l'un ni l'autre).
- **Export** : les listes Higher branchent `ExportsList` (CSV/XLSX) ; l'export client complet du socle est appelé tel quel (aucune table `hi_*` n'est exclue : test d'export à ajouter au rapport de recette).
- **2FA** : seule celle de l'administrateur de plateforme est forcée (U3) ; Higher consomme `RecentSecondFactor::hasRecent` pour les opérations sensibles du CDC.

---

# VAGUE 1 : lot I1 (Entrée et inscription)

Prérequis : W0-1, W0-5 (H1, H2) ; W0-3 (H3). W0-2 (D9) pour la projection de la maquette (T220).

| Agent | Dossiers exclusifs (sous `src/Modules/Apps/Higher/`) | Tâches (tasks.md) | Dépendances | Critères d'acceptation |
|-------|-------------------------------------------------------|-------------------|-------------|-------------------------|
| **H1 Academic + Curriculum** | `Academic/`, `Curriculum/`, `tests/**/Higher/{Academic,Curriculum}/`, `openapi.d/higher-{academic,curriculum}.yaml` | T207, T211, T212, T213-T225, T414, T415 | D1, D2, D3, D6, D9, S15 | TST-01 (inscription refusée sans autorisation du niveau et de l'année) ; refus sans tutelle LMD l'année N (RG-ACA-2) sans toucher N-1 ; régime non modifiable (RG-ORG-7) ; règlement figé, modification = R03 + 2FA (RG-ACA-3) ; maquette publiée avec 30 crédits (RG-MAQ-2) ; nouvelle version si notes (RG-MAQ-1) ; UE sans EC refusée ; alertes d'expiration 90/30/7 ; export CSV/XLSX des maquettes ; `WriteGate` sur toute écriture |
| **H2 Admissions + Enrolment** | `Admissions/`, `Enrolment/`, `tests/**/Higher/{Admissions,Enrolment}/`, `openapi.d/higher-{admissions,enrolment}.yaml` | T226-T241, T416-T421 | H1 (garde et structures), D4, D5, D7, D8, W0-3 (DOC-01 à 06 modèles via H3) | TST-02 (« admis » impossible sans pièce vérifiée) ; « sous réserve » expire à 30 jours puis « refusé » + notification (tâche planifiée) ; admis > places = décision tracée de R04 ; matricule `26EC-CF-0042` (voir tests) ; réinscription exige décision « admis » ou « admis avec dettes » ; UE capitalisées reportées, dettes inscrites d'office ; 3e inscription consécutive refusée sauf dérogation (RG-INS-5) ; consentement RG-INS-7 ; double cursus : 1 compte, 1 matricule, 2 parcours ; carte d'étudiant + QR |
| **H3 Billing + Documents (I1) + portail étudiant** | `Billing/`, `Documents/` (catalogue, `IssuanceGuard` de base, DOC-01 à 06 et 27 à 30), `Portals/Student/`, `Notifications/` (NOT-01 à NOT-11), `tests/**/Higher/{Billing,Documents,Portals,Notifications}/`, `openapi.d/higher-{billing,documents,portals,notifications}.yaml` | T242-T253, T238, T239, T241, T252, T289-T293 (socle des types), T317 (portail étudiant et garant) | D4, S10, S11, S12, S16, S17, S18 | TST-08 (échéancier 410 000 ci-dessous) ; remises sur la pension seulement, plafond ; blocages notes/documents/réinscription configurables, jamais cours ni composition ; types non bloquables (certificat de scolarité, reçu, relevé de compte, attestation de paiement) ; quitus refusé si solde non nul ; numéro `REL-EC-2026-000315` conforme (regex `^[A-Z]{3}-[A-Z]{2,}-\d{4}-\d{6}$`) ; page de vérification sans notes ; réception NOT-01 à NOT-11 par `Messenger` ; portail étudiant : candidature, inscription, paiement, reçus, documents |

Ordre dans la vague : H1 d'abord ; H2 et H3 en parallèle une fois la garde (T219) et les structures disponibles. Les modèles de documents d'un module sont écrits par l'agent du module dans `<Module>/Documents/` et déclarés par `DocumentTypeProvider` (tag `higher.document_types`) ; H3 possède le catalogue qui itère ces providers (aucun chevauchement de dossier).

---

# VAGUE 2 : lot I2 (Enseignement, présences, notes)

Prérequis : vague 1 terminée (au moins H1, H2) ; W0-2 (S1-S4) ; W0-4 (D11-D21).

| Agent | Dossiers exclusifs | Tâches | Dépendances | Critères d'acceptation |
|-------|--------------------|--------|-------------|-------------------------|
| **E1 Teaching (EDT + ENS hors paiement)** | `Teaching/` (hors `Teaching/Vacations/`), tests et openapi `higher-teaching` | T262, T263, T406-T408 (consommation), T422, T270 (DOC-07, 08, 13), T271 | D11-D13, D17, D18, D16 | affectation par R05 limitée à son département ; consommation CM/TD/TP par EC ; conflits enseignant/salle/groupe/capacité ; cahier de textes (`start_time`, `end_time`), signature, contre-signature ; planning des examens (Should) ; NOT-05 |
| **E2 Attendance (PRE)** | `Attendance/`, tests et openapi `higher-attendance` | T258, T264, T269, T425, T426, T409 | D19-D21 | seuil 30 % par EC (absences non justifiées ÷ séances tenues) → non autorisé à composer ; alerte à 25 % (NOT-07) ; deux indicateurs distincts (30 % non justifié, 70 % présence) ; conseil de discipline avec PV, exclusion → statut `excluded` ; DOC-31 ; épreuve de remplacement après ABJ |
| **E3 Grades (NOT)** | `Grades/`, tests et openapi `higher-grades` | T254-T257, T259-T261, T265-T268, T272, T328b (fusionnée), T327 (fusionnée dans T205) | S1-S4, D9, E1 (affectations) | TST-03 ; TST-06 ; barème au quart de point ; codes ABJ, ABI, NA, FR avec effets ; cycle brouillon, soumise, validée, verrouillée (2FA), publiée ; double validation des corrections ; requête de note 7 jours ; relances NOT-11 ; statistiques par EC |

E1 et E2 en parallèle ; E3 démarre dès que E1 expose les affectations (contrat `TeachingAssignments`). Les 3 agents tiennent dans la limite.

---

# VAGUE 3 : lot I3 (Résultats, fin d'études, pilotage)

Prérequis : vague 2 ; W0-2 (S5-S9) ; W0-3 (S14, S20, S21).

Tour 3a (3 agents) :
| Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|-------|--------------------|--------|-------------------------|
| **L1 Deliberation** | `Deliberation/`, tests/openapi `higher-deliberation` ; DOC-09, 10, 14, 15, 16 dans `Deliberation/Documents/` | T273-T288, T329b (fusionnée), T331 (fusionnée), T287 | TST-04, TST-05, TST-07, décisions de fin d'année, BTS/HND, simulation, points de jury plafonnés, PV signé + SHA-256, réouverture par R03 avec nouveau PV |
| **L2 NationalExams + Tutelage** | `NationalExams/`, `Tutelage/`, tests/openapi `higher-{nationalexams,tutelage}` ; DOC-12, 17, 18 | T304-T311, T332, T306 | TST-09 ; éligibilité 70 % (E1) ; export tracé et alerte DG ; transmission après PV final et quitus |
| **L3 Vacations + Internships** | `Teaching/Vacations/`, `Internships/`, tests/openapi `higher-{vacations,internships}` ; DOC-20 à 26 | T295-T303, T423, T424, T411 | TST-11 (261 000) ; RG-ENS-1/4/5 ; RG-STG-1/2 ; DOC-24 à 26 |

Tour 3b (2 agents) :
| Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|-------|--------------------|--------|-------------------------|
| **L4 Dashboards + Portals (jury, tutelle, garant)** | `Dashboards/`, `Portals/` (hors `Portals/Student/`), tests/openapi `higher-{dashboards,portals}` ; DOC-32 | T312-T319, T314, T315, T316 | indicateurs du CDC (fréquences) ; sélecteur d'école ; comptes R22/R23 à durée limitée ; isolation par rôle |
| **L5 Documents (finalisation)** | `Documents/` (catalogue, garde « jamais BTS/HND », `blockable`), `tests/**/Higher/Documents/` | T290, T291, T293, T294, T245 (liste non bloquable) | 32 types présents, FR/EN, signataires du CDC, RG-DOC-1 à 8, RG-EXN-3 |

H3 est propriétaire de `Documents/` en vague 1 ; L5 en reprend la propriété en vague 3 (H3 terminé).

Vague 4 (I4) : recette, pilote, reprise de données (T320-T325) ; un agent « qualité » en lecture/tests.

---

# Tests d'acceptation tirés du CDC (à écrire avant le code)

Chiffres vérifiés (arithmétique contrôlée). Fixtures dans `tests/Fixtures/Higher/` (T206).

**L1 S1 Marie N., Comptabilité et finance, 30 crédits**, poids 40/60, arrondi au centième à chaque étape.
EC (crédits, CC, examen → note) : Comptabilité générale (4 ; 12 ; 9 → 10,20) ; TD de comptabilité (2 ; 14 ; 11 → 12,20) ; Mathématiques financières (6 ; 8 ; 6,5 → 7,10) ; Microéconomie (3 ; 13 ; 12 → 12,40) ; Macroéconomie (3 ; 11 ; 10,5 → 10,70) ; Droit civil (3 ; 10 ; 13 → 11,80) ; Institutions administratives (3 ; 15 ; 14 → 14,40) ; Anglais (3 ; 14 ; 15 → 14,60) ; Bureautique (3 ; 16 ; 15,5 → 15,70).
- Commun : UE1 10,87 (C, 2,00) ; UE2 7,10 (D, 1,00) ; UE3 11,55 (C+, 2,30) ; UE4 13,10 (B, 3,00) ; UE5 15,15 (A, 3,70) ; Σ(note × crédits) = 346,60 ; moyenne de semestre 346,60 ÷ 30 = **11,55** ; MGP = (2,00+1,00+2,30+3,00+3,70) × 6 ÷ 30 = **2,40**.
- **Scénario A** (éliminatoire 7) : UE2 (7,10 ≥ 7) acquise **par compensation** (moyenne de semestre ≥ 10) ; **30/30 crédits** ; mention **Passable** ; relevé « Acquise par compensation ». Test aussi : mêmes données avec éliminatoire 7,5 → UE2 non acquise.
- **Scénario B** (éliminatoire 8) : UE2 7,10 < 8, pas de compensation ; **24 crédits** ; rattrapage de Mathématiques financières ; avec 11 au rattrapage : note EC = 0,4×8 + 0,6×11 = **9,80** (CC conservé, rattrapage remplace l'examen) ; moyenne de semestre = (346,60 − 42,60 + 58,80) ÷ 30 = **12,09** ; UE2 acquise par compensation après rattrapage ; **30 crédits** ; mention **Assez bien**.
- Pour chaque fonction F1 à F7 : un test unitaire ; invariants : arrondi à chaque étape (ex. 10,87 = (10,20×4+12,20×2)/6 = 10,8667), notes saisies au quart de point (refus de 6,3).

**Décisions de fin d'année** : 60 crédits → admis ; 45 à 59 → admis avec dettes (UE manquantes inscrites d'office) ; 44 → redouble (UE capitalisées conservées) ; 180 crédits cumulés dernière année de licence → admis + dossier transmis ; 3e inscription du même niveau (après 2e redoublement selon C6) → exclu pour insuffisance sauf dérogation. **BTS/HND** : note de matière 0,4×devoirs + 0,6×examen ; moyenne semestrielle Σ(note×coefficient) ÷ Σ coefficients ; passage si moyenne annuelle ≥ 10 ; rang avec ex æquo (1, 2, 2, 4).

**Vacations (TST-11)** : assistant, L2, novembre, 6 000 FCFA/HETD : CM 18 × 1,5 = 27,0 ; TD 12 × 1 = 12,0 ; TP 6 × 0,75 = 4,5 ; **43,5 HETD × 6 000 = 261 000 FCFA** brut, sans retenue. Variante : HETD CM 2 (paramètre) → résultat recalculé.

**Échéancier (TST-08)** : inscription 50 000 + pension 450 000 ; bourse de mérite 20 % sur la pension → 360 000 ; 3 tranches de 120 000 (15 octobre, 15 janvier, 15 avril) ; total dû **410 000**. Chronologie : 10 oct, 170 000 Orange Money → inscription 50 000 + tranche 1 120 000 ; 16 janv : tranche 2 en retard, relance J+1 (NOT-09) ; 20 janv, 100 000 MTN MoMo → tranche 2 (reste 20 000) ; 30 janv relance J+15 ; 2 fév, 20 000 au guichet → tranche 2 soldée, élève à jour, tranche 3 restante 120 000. Tests supplémentaires : remise sur l'inscription refusée (RG-FIN-1) ; plafond de bourse dépassé → refus ; blocage de réinscription actif ne bloque pas le certificat de scolarité (RG-DOC-7).

**Autres tests tirés du CDC** : matricule `26EC-CF-0042` (année 26, école EC, filière CF, ordre 0042, jamais réattribué, inchangé après changement de filière) ; numéro `REL-EC-2026-000315` (type REL, école EC, année 2026, ordre 6 chiffres) et page publique sans notes (statuts valide/annulé/remplacé) ; duplicata « Duplicata » + date + numéro d'origine ; 30 % : 4 absences non justifiées sur 12 séances tenues (33 %) → non autorisé, 3 sur 12 (25 %) → alerte NOT-07 seulement ; « admis sous réserve » 30 jours ; points de jury : 0,6 demandé → plafonné à 0,5 ; publication avant signature du PV refusée (TST-07) ; liste nationale : étudiant hors année de sortie refusé (TST-09) ; `WriteGate` : application suspendue = lecture et export seuls.

## Risques du plan
1. Le socle est modifié par 4 agents en parallèle : conflits évités par dossiers exclusifs, mais l'ordre D→Higher est strict ; tout retard de W0-1 ou W0-2 bloque ACA et NOT.
2. S6 (stratégie de calcul) est le point le plus difficile : à valider par TST-04/05 avant d'écrire DEL.
3. Fin du CDC manquante (decisions.md R37) et textes officiels (R35) : modèles de documents et exports MINESUP restent des gabarits.
4. Un seul `routes.php` par module Higher : discipline d'inclusion de W0-5 à tenir.
5. Import XLSX impossible sans lecteur : import CSV en V1.
