# Tasks : Application Supérieur (IPES)

**Input** : `specs/002-application-ipes/` (spec, plan, data-model) et `specs/001-socle-plateforme/`
**Prérequis** : phases 1 et 2 de 001 terminées (projet, fondations transverses) et phase 3 (identité) au minimum.
**Tests** : obligatoires (constitution III). Les TST-01 à TST-12 du CDC et ses exemples chiffrés sont les premiers tests écrits.
**Numérotation** : T2xx pour éviter les collisions avec 001. `[Dépend: T0xx]` renvoie aux tâches de 001.
**Format** : `[ID] [P?] [Story] Description avec chemin`

## Récapitulatif des vagues (détail, dossiers et critères : [wave-plan.md](wave-plan.md))

| Vague | Agent | Contenu | Tâches |
|-------|-------|---------|--------|
| 0a | W0-1 Organisation, People, Identity | D1, D2, D3, D5, D6, D7, D8, S15 | T400-T402, T404, T410, T412, T354 |
| 0a | W0-2 Assessment | D9, S1-S9 | T403, T340-T348 |
| 0a | W0-3 Billing, Documents, Reporting, Portals, Integrations | D4, S10-S14, S16-S21 | T405, T349-T353, T355-T360 |
| 0b | W0-4 Staff, Scheduling, Attendance | D11-D21 | T406-T409, T411 |
| 0b | W0-5 Squelette Higher, rôles, lien Platform | arborescence, rôles R01-R23, WriteGate, conventions | T201-T206, T208, T210, T430-T432 |
| 1 (I1) | H1 Academic + Curriculum | ACA, MAQ | T207, T211-T225, T414, T415 |
| 1 (I1) | H2 Admissions + Enrolment | ADM, INS | T226-T237, T240, T416-T421 |
| 1 (I1) | H3 Billing + Documents I1 + portail étudiant | FIN, DOC-01 à 06 et 27 à 30, NOT-01 à 11, portail | T238, T239, T241-T253, T317 |
| 2 (I2) | E1 Teaching (EDT, ENS) | affectations, cahier de textes, emploi du temps | T262, T263, T270, T271, T422 |
| 2 (I2) | E2 Attendance (PRE) | 30 % par EC, discipline | T258, T264, T269, T425, T426 |
| 2 (I2) | E3 Grades (NOT) | notes, cycle, corrections | T254-T257, T259-T261, T265-T268, T272 |
| 3a (I3) | L1 Deliberation | DEL, DOC-09/10/14/15/16 | T273-T288 |
| 3a (I3) | L2 NationalExams + Tutelage | EXN, TUT, DOC-12/17/18 | T304-T311, T332 |
| 3a (I3) | L3 Vacations + Internships | paiement ENS, STG, DOC-20 à 26 | T295-T303, T423, T424 |
| 3b (I3) | L4 Dashboards + Portals | PIL, portails jury et tutelle | T312-T316, T318, T319 |
| 3b (I3) | L5 Documents (finalisation) | 32 types, gardes | T290-T294 |
| 4 (I4) | tous | recette, pilote | T320-T325 |

## Doublons supprimés (analyses A et B) : concordance
T326 et T427 vers T203 ; T327 vers T205 ; T328b vers T255, T259, T261, T266 ; T329b vers T284 ; T330 vers T273 ; T331 vers T280 ; T333 vers T290 ; T334 vers T245 ; T335 vers T310 ; T336 vers T249 ; T337 vers T317 ; T338 vers T288 ; T339 vers T321 ; T428 vers T210 ; T413 fait (plan.md). T332 conservée. Numérotation : T2xx (histoires), T340-T360 (S1-S21), T400-T426 (D1-D21 et analyse A), T430-T432 (vague 0 Higher) ; T326-T339 libérés, donc plus de collision avec T328-T330 de 001 ; les tâches Platform du socle sont T460-T479 (001).

---

## Phase A : Setup du module (lot I1, S10)

- [ ] T201 Créer l'arborescence `src/Modules/Apps/Higher/` selon plan.md (`HigherApplication`, `definitions.php`, `routes.php` inclusif, dossiers de modules) ; pas de `HigherServiceProvider` (chargement automatique) [agent W0-5]
- [ ] T202 [P] Écrire `HigherApplication` (descripteur dans l'`ApplicationRegistry`) et le manifeste `platform.applications.higher` (`ApplicationManifest` : modules requis, essai 90 jours, 60 000 XAF/mois) dans `Higher/definitions.php` ; `WriteGate` (T430)
- [ ] T203 [P] Rôles R01-R23 : demander la migration partagée `database/migrations/*_roles_higher.sql` (tables `roles`/`role_permissions`, niveau `establishment`) ; `Higher/Policies/RoleMatrix.php` ne porte que la matrice et le périmètre (voir socle-reuse-B, « Rôles ») [Dépend: T046, tableau des 23 rôles du CDC] [Absorbe T326 (migration partagée des rôles R04-R23, `RolePermissionsTest` étendu) et T427 (permissions `higher.*`) ; matrice et périmètres : wave-plan.md §0.3 ; agent W0-5]
- [ ] T204 [P] Test d'architecture : `Higher` ne référence que `Socle/*/Contracts` [Dépend: T013] ; étendu par T431
- [ ] T205 [P] Paramètres par défaut du règlement LMD, BTS, HND (seeder `HigherDefaultsSeeder`) : échelle, poids 40/60, arrondi, seuils, mentions, points de jury, HETD, relances, assiduité [Dépend: T054] [Absorbe T327 (`assessment.lifecycle`, `assessment.quorum_percent`, `billing.blocks`, `billing.reminder_offsets`) ; valeurs « à confirmer » : decisions.md (b)]
- [ ] T206 Rédiger les jeux d'essai des exemples du CDC dans `tests/Fixtures/Higher/` : Marie N. (deux règlements), vacations 43,5 HETD, échéancier 410 000

---

## Phase B : Fondations IPES (bloque les histoires)

- [ ] T207 [A] Migrations `hi_schools`, `hi_departments`, `hi_programs`, `hi_levels`, `hi_groups` : extensions des `org_units` et `pedagogical_structures` (kind `track`) du socle, `programs.structure_id` unique ; pas de duplication des tables socle (socle-reuse-A §1) [Dépend: T400, T401]
- [ ] T208 [P] Trait `ScopedBySchool` : filtre par école, département, filière ou groupes d'après `role_assignments` [Dépend: T044]
- [x] T209 [P] Step-up 2FA : **couvert par le socle** (`assessment.lifecycle` avec `require_recent_2fa` par transition, `Identity\RecentSecondFactor::hasRecent`). Reste à brancher sur sign/close/publish/reopen de délibération et export liste nationale (voir T329b, T332)
- [ ] T210 [P] Test d'isolation Higher : autre client, autre école, enseignant non affecté (TST-03, TST-12) `tests/Isolation/HigherScopeTest.php` [Dépend: T032] [Absorbe T428 (isolation par école, département, filière et affectation pour tous les modules)]
- [ ] T211 [A] Modèles et enums `Regime`, `Mode`, `UnitType` (PHP 8.1 sans framework : dépôts PDO selon agent-conventions, pas de factories Laravel) [Dépend: T207]
- [ ] T212 [A] Route de santé `GET /api/v1/higher/programs` (API-01) : `routes.php` de `Apps/Higher` (chargé par glob) + contrat OpenAPI `openapi.d/higher-academic.yaml` [Dépend: T207]

---

## Phase C : US1 Filières, autorisations, maquettes (P1) 🎯 MVP, lot I1

**Test indépendant** : filière LMD autorisée avec maquette de 30 crédits publiée ; sans autorisation, aucune inscription.

### Tests
- [ ] T213 [P] [US1] TST-01 : inscription refusée sans autorisation valide (RG-ACA-1). Le socle refuse déjà au niveau structure (`EnrolmentGate` via `EnrollmentService::validate`) ; tester la garde IPES par niveau et année `tests/Feature/Higher/Academic/AuthorizationBlockTest.php` [Dépend: T219]
- [ ] T214 [P] [US1] Refus sans tutelle pour une filière LMD (RG-ACA-2)
- [ ] T215 [P] [US1] Maquette déséquilibrée non publiable (RG-MAQ-2) ; maquette utilisée = nouvelle version (RG-MAQ-1)
- [ ] T216 [P] [US1] Règlement figé : modification exige le directeur académique (RG-ACA-3)

### Implémentation
- [ ] T217 [US1] Migrations `hi_authorizations` (niveaux, document ; validité dans `authorizations` du socle), `hi_tutelages`, `hi_regulations`, `hi_curricula`, `hi_semesters`, `hi_units`, `hi_elements`, `hi_curriculum_versions`
- [ ] T218 [P] [US1] Dépôts et services `RegisterAuthorization` (API-02, via Contract D2), `RegisterTutelage`, `FreezeRegulation`, `AmendFrozenRegulation` (Approvals + RecentSecondFactor du socle)
- [ ] T219 [US1] `HigherEnrolmentGate` (autorisation du niveau + tutelle LMD) enregistrée comme garde additionnelle du socle (D3) ; exposée aux campagnes et inscriptions [Dépend: T401, T402]
- [ ] T220 [US1] `PublishCurriculum` : 30 crédits/semestre réglable, crédits UE = somme des EC, UE sans EC, volumes ; `NewVersionOnUse` (RG-MAQ-1) ; API-07 ; projection UE/EC vers `subjects`/`subject_elements` via Contract D9 [Dépend: T217, T403]
- [ ] T221 [US1] Maquettes BTS/HND par matières, coefficients et rattachement aux épreuves nationales
- [ ] T222 [US1] Copie d'une maquette d'une année à l'autre ; import/export CSV (XLSX conditionné à l'accord sur une dépendance Composer, Q17) (Should)
- [x] T223 [US1] Calendrier académique par école : **couvert par le socle** (S07 `/calendar-events`, `/academic-years`, `periods`, `CurrentPeriod` ; socle-reuse-A §1) ; reste le paramétrage par école via D6 (T412)
- [ ] T224 [US1] Alertes d'expiration 90/30/7 jours (NOT-15) : tâche planifiée Higher s'appuyant sur `AuthorizationExpiryService` (socle, `GET /authorizations/expiring`, seuil unique de 30 jours) et `Messenger` ; seuils dans `higher.alerts.expiry_days`
- [ ] T225 [US1] Écrans : paramétrage académique et maquettes (voir design.md, menu « Filières & UE »)

---

## Phase D : US2 Admissions et inscriptions (P1), lot I1

**Test indépendant** : candidature → admission → inscription → matricule → carte étudiant.

### Tests
- [ ] T226 [P] [US2] TST-02 : « admis » impossible sans pièce vérifiée (RG-ADM-1)
- [ ] T227 [P] [US2] Expiration de « admis sous réserve » à 30 jours (RG-ADM-2) et plafond de places (RG-ADM-3)
- [ ] T228 [P] [US2] Matricule unique, jamais réattribué, format paramétrable année/code école/code filière/ordre sur 4 chiffres (RG-INS-1). Le socle ne fournit que `MAT-AAAA-NNNNN` ; tester le `MatriculeGenerator` IPES [Dépend: T404]
- [ ] T229 [P] [US2] Réinscription N+1 exige la décision de passage ; report des UE capitalisées et dettes (RG-INS-3/4)
- [ ] T230 [P] [US2] Consentement de l'étudiant majeur au partage avec son garant (RG-INS-7)

### Implémentation
- [ ] T231 [US2] Migrations `hi_campaigns`, `hi_applications`, `hi_application_documents`, `hi_admission_decisions`, `hi_enrolments` (extension 1-1 de `enrollments`), `hi_learning_contracts`, `hi_learning_contract_items`, `hi_enrolment_events`
- [ ] T232 [US2] Campagnes et candidature en ligne ou au guichet ; frais de dossier : facture et paiement via Contract D4 sur S04 (reçu et mobile money simulé déjà fournis) (API-03) [Dépend: T405]
- [ ] T233 [US2] Vérification des prérequis et décision d'admission (API-04), jury d'admission, classement
- [ ] T234 [US2] Concours : centres, salles, convocations, classement, listes d'attente (Should)
- [ ] T235 [US2] Inscription administrative et pédagogique (API-05, API-06) : création/validation de l'inscription socle (`/enrollments`, statuts fournis), `MatriculeGenerator` (D5), contrat pédagogique ; détection de doublons **couverte** par `GET /people/duplicates` [Dépend: T404]
- [ ] T236 [US2] Statuts d'inscription : **couverts** (`EnrollmentStatus` du socle) ; reste la réinscription en ligne (décision de passage, report des UE, dettes) avec un planificateur Higher remplaçant `YearPromotion` (D8) et paiement de la 1re tranche [Dépend: T410]
- [ ] T237 [US2] Limite d'inscriptions consécutives (RG-INS-5) et dérogation
- [ ] T238 [US2] Notifications NOT-01 à NOT-04
- [ ] T239 [US2] Documents DOC-01 à DOC-06 (accusé, convocation, lettre d'admission, fiche d'inscription, carte, certificat de scolarité) [Dépend: T101, T103]
- [ ] T240 [US2] Changement de filière/groupe/mode avec recalcul des frais (Should ; `PATCH /enrollments` du socle est limité aux brouillons, d'où D3) ; gel d'inscription (Could, statut `suspended` existant + motif) ; transfert avec équivalences (Should)
- [ ] T241 [US2] Portail étudiant : candidature, inscription, réinscription (design à créer)

---

## Phase E : US3 Frais de scolarité (P1), lot I1

**Test indépendant** : exemple CDC 450 000 − 20 % en 3 tranches, paiement 170 000 (TST-08).

### Tests
- [ ] T242 [P] [US3] TST-08 : affectation de 170 000 et chronologie du CDC sur `Billing` du socle (vérifier `AllocationPlanner` et la remise en pourcentage sur la pension répartie sur les tranches restantes) `tests/Feature/Higher/Billing/InstalmentExampleTest.php`
- [ ] T243 [P] [US3] Remises sur la pension uniquement, plafonnées (RG-FIN-1) : le socle couvre `discounts` ; plafond à créer (voir T336)
- [x] T244 [P] [US3] Relances J-7, J+1, J+15 (RG-FIN-3) : **couvert par le socle** (`billing.reminder_offsets`, `ReminderService`) ; seul un test de configuration IPES est à écrire
- [ ] T245 [P] [US3] Blocages (RG-FIN-5, RG-DOC-7) : le socle couvre `DebtorDirectory::isBlocked` (scopes `results`, `documents`, `reinscription`) ; tester la configuration IPES et marquer `blockable=false` les types non bloquables (T334) [Absorbe T334 (marquer `blockable=false` les types non bloquables : union RG-FIN-5 et RG-DOC-7, decisions.md C8)]
- [ ] T246 [P] [US3] Quitus refusé si solde non nul (RG-FIN-6) : `DebtorDirectory::balanceXaf` existe, garde à créer

### Implémentation
- [ ] T247 [US3] Réécrite : seeder `HigherFeeCatalog` (dossier, inscription, pension, examen national, soutenance, stage, carte, assurance) et grilles `fee_schedules` par structure/niveau/mode sur `Billing` ; `fee_items.kind` limité à registration/tuition/other (demande S10) [Dépend: T077]
- [x] T248 [US3] Échéancier en 3 tranches : **couvert par le socle** (`fee_schedules.terms`, `installments`) ; reste le paramétrage IPES (valeurs du CDC) et l'échéancier individuel validé par le comptable (via `Approvals` si exigé)
- [ ] T249 [US3] Réécrite : `ScholarshipPolicy` (types, taux, plafond cumulé, fratrie) au-dessus de `/invoices/{id}/discounts` (`merit`, `waiver` existent) ; table `hi_scholarships` seulement si un dossier de bourse est exigé [Dépend: T078, S11] [Absorbe T336 (plafond des bourses et remises cumulées, `billing.discount_cap_percent`, défaut à confirmer R8)]
- [ ] T250 [US3] Prise en charge par un tiers avec facture au tiers (Should) : à créer (`hi_sponsors`, demande S13)
- [ ] T251 [US3] Réécrite : `FinancialHoldPolicy` IPES (lit `DebtorDirectory`, réglage `billing.blocks`) appliquée aux notes, documents, réinscription et liste des candidats nationaux ; blocage à l'émission unitaire (demande S12)
- [ ] T252 [US3] DOC-27 à DOC-30 : reçu et reçu PDF **existent** (`/receipts`, `/receipts/{id}/pdf`) ; à créer : modèles relevé de compte, attestation de paiement, quitus (types Documents) avec garde de solde
- [ ] T253 [US3] Écran Caisse et journal du jour : API du socle prête (`/cash-sessions`, `/payments`, `/reconciliations`, `/reversals`) ; il reste l'écran web (maquette Frais) [Dépend: T082, T088]

---

## Phase F : US4 Enseignement, présences, notes (P2), lot I2

**Test indépendant** : un enseignant affecté fait l'appel hors-ligne, saisit et soumet les notes ; le chef de département verrouille avec 2FA récente.

### Tests
- [ ] T254 [P] [US4] TST-03 : enseignant non affecté → 403 + journalisé. Socle : `ScopeGuard` + `TeachingScope` ; brancher `TeachingScope` sur les affectations IPES (moitié A) `tests/Feature/Higher/Grades/MarkAccessTest.php`
- [ ] T255 [P] [US4] Barème 0–20 au quart de point (RG-NOT-1) : borne couverte par le socle, pas de 0,25 (demande S2 ou contrôle IPES) [Absorbe la partie « pas de 0,25 » de T328b]
- [ ] T256 [P] [US4] TST-06 : modification d'une note verrouillée sans procédure refusée (RG-NOT-2) : comportement du socle (`assertEditable`, `/grade-corrections`), écrire le test IPES
- [ ] T257 [P] [US4] Séparation des tâches : demande ≠ validation couverte (`Approvals`) ; saisir ≠ verrouiller par permissions distinctes (+ demande S4)
- [ ] T258 [P] [US4] Seuil de 30 % d'absences non justifiées par EC → non autorisé à composer (RG-PRE-1) : test de l'agrégat par EC (le socle ne calcule que par personne et par libellé de matière) [Dépend: T409]
- [ ] T259 [P] [US4] Codes ABJ, ABI, NA, FR et effets (RG-NOT-5) : ABJ/ABI couverts par le socle (`justified`/`unjustified`), NA et FR à créer (demande S1) [Absorbe la partie « codes NA et FR » de T328b]
- [x] T260 [P] [US4] Conflit de version hors-ligne : **couvert par le socle** (`POST /grades/sync`, `client_uuid`, `VersionConflict`) ; test IPES de non-régression seulement

### Implémentation
- [ ] T261 Réécrite : **pas de `hi_assessments`, `hi_marks`, `hi_mark_changes`** (couverts par `assessments`, `grades`, `grade_events`, `grade_corrections` du socle). Migrations IPES réduites à `hi_grade_queries` (requête de note) et, si S1 est refusée, aux codes NA/FR [Absorbe la partie « requête de note » de T328b]
- [ ] T262 [US4] Affectations par le chef de département (Contract D17). Emploi du temps **couvert pour l'essentiel par S07** (conflits enseignant/salle/groupe/capacité, mutualisés, soir, consommation, reports) ; reste `TimetablePlanner` Higher (contrôle d'affectation, consommation CM/TD/TP par EC ; D11/D12/D13) [Dépend: T406, T407]
- [ ] T263 [US4] Cahier de textes `hi_session_logs` (colonnes `start_time`/`end_time`) lié à la séance d'appel et à `vacation_hours` ; émargement enseignant (`/vacation-hours/{id}/sign` du socle) et contre-signature par délégué ou surveillant (Q23) (API-08) [Dépend: T408]
- [ ] T264 [US4] Appel par liste, QR, hors-ligne, justificatifs, alertes : **couverts par S08** (`/attendance-sessions*`, `/absence-justifications`, `AttendanceSummaryProvider`) ; reste le seuil de 30 % PAR EC (D19) et la liste des non-autorisés par EC [Dépend: T409]
- [x] T265 [US4] API de saisie, import CSV et hors-ligne (API-09, API-10) : **couvert par le socle** (`/grades`, `/grades/batch|sync|import`) ; adapter seulement le périmètre `MarkPolicy` aux affectations IPES
- [ ] T266 [US4] Réécrite : verrouillage avec step-up = **déclaration de `assessment.lifecycle`** (T328 de 001 livré) ; double validation chef de département puis directeur d'école = orchestration de deux `Approvals` au-dessus de `/grade-corrections` (demande S3) [Dépend: T028] [Absorbe la partie « double validation » de T328b]
- [ ] T267 [US4] Anonymat : **couvert par le socle** (`anonymous`, `/assessments/{id}/reveal`) ; reste la requête de note de l'étudiant (Should, `hi_grade_queries`)
- [ ] T268 [US4] Réécrite : commande console de relance NOT-11 sur les retards de saisie ; la liste existe (`PendingGradingTodo`, `assessment.entry_progress`)
- [ ] T269 [US4] Sanctions : **couvertes par S08** (`/incidents`, `/sanctions`, barème `attendance.sanction_scale` incluant `conseil_discipline`) ; reste le conseil de discipline IPES (PV signé, exclusion → statut `excluded`) et DOC-31 (Should)
- [ ] T270 [US4] DOC-07, DOC-08, DOC-13 : modèles Documents et données d'emploi du temps/examens ; notifications NOT-05 à NOT-07 par `Messenger` + modèles de messages (partagé avec la moitié A)
- [ ] T271 [US4] Portail enseignant : appel hors-ligne (maquette 05), cahier de textes, saisie de notes
- [ ] T272 [US4] Statistiques par EC (Should) : `GET /sequence-statistics` du socle couvre moyenne, taux, min, max ; ventilation par EC/groupe à ajouter

---

## Phase G : US5 Calculs, délibération, publication (P2), lot I3

**Test indépendant** : exemple « Marie N. » sous les deux règlements.

### Tests
- [ ] T273 [P] [US5] TST-04 : note éliminatoire 7 → 11,55 ; MGP 2,40 ; 30 crédits ; UE2 compensée ; mention Passable `tests/Unit/Higher/Deliberation/MarieSemesterTest.php` [Absorbe T330 (TST-04, TST-05, TST-07 écrits avant T279 à T286)]
- [ ] T274 [P] [US5] TST-05 : note éliminatoire 8 et rattrapage 11 → 9,80 ; 12,09 ; 30 crédits ; Assez bien
- [ ] T275 [P] [US5] Décisions de fin d'année (60, 45–59, <45, 180 crédits, deuxième redoublement)
- [ ] T276 [P] [US5] Délibération impossible sans notes toutes verrouillées (RG-DEL-1) ; points de jury plafonnés (RG-DEL-2)
- [ ] T277 [P] [US5] TST-07 : publication avant signature du PV refusée (RG-DEL-3) ; figement et réouverture (RG-DEL-4, EX-DEL-08)
- [ ] T278 [P] [US5] Règles BTS/HND : 0,4 devoirs + 0,6 examen, coefficients, passage ≥ 10, rang avec ex æquo

### Implémentation
- [ ] T279 [US5] Réécrite : `HigherRuleSet` JSON versionné par **programme** et année (LMD, BTS, HND) ; le socle versionne par établissement/année seulement (`calculation_rules`) : demandes S5 (règles par structure), S6 (stratégie de calcul) [Dépend: T120]
- [ ] T280 [US5] Réécrite : calcul F1 à F7 **au-dessus de `RuleEngine`** (moyenne pondérée, arrondi, rang, compensation et capitalisation de base déjà au socle) ; à créer : MGP, lettres et points, note éliminatoire, combinaison avec rattrapage, décisions annuelles, dettes ; session (demande S7) [Absorbe T331 (décisions annuelles, dettes, `hi_unit_credits`, résultats par session)]
- [ ] T281 [US5] Réécrite : **pas de `hi_deliberations`** (couvert par `deliberations`, `deliberation_lines`, `results`). Migrations IPES : `hi_juries`, `hi_jury_members`, `hi_session_results`, `hi_unit_credits`, `hi_jury_points`
- [ ] T282 [US5] Simulation sans écriture avec cas à moins de 0,5 point d'un seuil (API-13) : à créer (le socle calcule à l'ouverture, pas de mode à blanc)
- [ ] T283 [US5] Réécrite : séance de jury **couverte en partie** (`/deliberations` : présents, quorum, décisions motivées, clôture) ; à créer : membres du jury, points de jury plafonnés, rattrapage et recalcul, refus d'ouverture si notes non verrouillées (RG-DEL-1)
- [ ] T284 [US5] Réécrite : signature (`POST /deliberations/{id}/sign`) **existe** sans 2FA ni empreinte ; à créer : enveloppe IPES avec step-up, SHA-256 du PV, signature du président requise avant publication (demande S8) [Absorbe T329b (`DeliberationGuard` IPES : notes verrouillées, plafond des points de jury, 2FA, SHA-256, signature du président)]
- [ ] T285 [US5] Publication (API-15, API-16) : `POST /results/publish` **existe** ; ajouter garde PV signé + 2FA (S8) ; notification NOT-12 par `Messenger`
- [ ] T286 [US5] Réouverture : `POST /deliberations/{id}/reopen` **existe** (motif, signatures effacées) ; à créer : nouveau PV, double validation et alerte au DG, gel de `results` contre le recalcul
- [ ] T287 [US5] DOC-09, DOC-10, DOC-14, DOC-16 : modèles Documents à écrire ; `report_card` et `deliberation_minutes` sont appelés par Assessment sans modèle ; relevé semestriel d'après la maquette Releve (modèle officiel de la tutelle requis)
- [ ] T288 [US5] Écrans « Évaluations & jurys » : API du socle prête ; écrans web d'après la maquette Deliberation ; portail jury (T337) [Absorbe T338 (écrans de délibération et de saisie des notes)]

---

## Phase H : US6 Documents vérifiables (P2), lots I1 à I3

- [x] T289 [P] [US6] TST-10 : vérification publique d'un document révoqué → motif : **couvert par le socle** (`GET /public/verify/{token}`, `revoke_reason`) ; test IPES de non-régression
- [ ] T290 [P] [US6] Émission refusée sans PV signé (RG-DOC-3) : à créer (`IssuanceGuard`, S18) ; duplicata, révocation sans suppression et version de modèle : **couverts par le socle** [Absorbe T333 (`IssuanceGuard` : PV signé, solde nul, éligibilité) et les `DocumentDataBuilder`]
- [ ] T291 [US6] Réécrite : seeder `HigherDocumentCatalog` (types `DOC-01` à `DOC-32`, variables, modèles FR et EN) sur `/document-types` et `/document-templates` ; moteur couvert par le socle [Dépend: T101]
- [ ] T292 [US6] Réécrite : numérotation RG-DOC-1 : le gabarit existe (`NumberPattern`), manque le jeton d'école (demande S16) ; trancher entre `REL-EC-2026-000315` et `IPES-26-R-001204` [Dépend: T025]
- [ ] T293 [US6] Garde « jamais BTS/HND » sur les intitulés (RG-EXN-3) : validation des types/modèles IPES, à créer
- [ ] T294 [US6] Documents restants DOC-11, 12, 17 à 26, 32 : modèles et fournisseurs de données au fil des modules concernés

---

## Phase I : US7 Vacations (P3), lot I3

- [ ] T295 [P] [US7] TST-11 : 43,5 HETD et 261 000 FCFA bruts. Le calcul par défaut du socle (`VacationCalc`, 1,5/1/0,75) donne déjà ce résultat ; test sur le calculateur IPES paramétré `tests/Unit/Higher/Teaching/VacationTest.php`
- [ ] T296 [P] [US7] Heure non payable sans émargement, contre-signature et cahier de textes ; dépassement du volume de la maquette (accord écrit) ; solde retenu si notes incomplètes (RG-ENS-1/4/5). Plafonds de contrat et mensuel déjà fournis par S10 ; le reste via D16 [Dépend: T408]
- [ ] T297 [US7] HETD et taux par grade et niveau : S10 fournit `/vacation-rates` par catégorie et `VacationCalc` à coefficients fixes ; demander D14 et D15, puis `HetdCalculator` et `VacationRateResolver` Higher [Dépend: T411]
- [ ] T298 [US7] Validation mensuelle, état, approbation et paiement : **couverts par S10** (`/vacation-hours/{id}/validate`, `/vacation-statements*`, `/pay`) ; reste le chef de département limité à son département, DOC-25 et DOC-26 (modèles)
- [ ] T299 [US7] Contrat de vacation : **couvert par S10** (`/vacation-contracts*`) ; reste DOC-24 (modèle), NOT-14 et autorisation de l'employeur des vacataires fonctionnaires

---

## Phase J : US8 Stages et soutenances (P3), lot I3

- [ ] T300 [P] [US8] Soutenance non programmable sans convention, attestation, mémoire et quitus (RG-STG-1) ; jury ≥ 3, encadreur non président (RG-STG-2) : à créer
- [ ] T301 [US8] Migrations `hi_internships`, `hi_defenses`, `hi_defense_jury_members` : à créer (aucun équivalent au socle)
- [ ] T302 [US8] Convention tripartite (DOC-20), suivi, fiche d'évaluation (DOC-23), mémoire, planning (DOC-21), PV de soutenance (DOC-22), API-18
- [ ] T303 [US8] Répertoire d'entreprises et offres de stage (Should) ; contrôle de similarité (option)

---

## Phase K : US9 Examens nationaux et tutelle (P3), lot I3

- [ ] T304 [P] [US9] TST-09 : ajout d'un étudiant non inscrit en année de sortie refusé (RG-EXN-1), sur `EnrollmentDirectory`
- [ ] T305 [P] [US9] Modification de la liste après export tracée et signalée au DG (RG-EXN-2)
- [ ] T306 [P] [US9] Dossier de diplôme LMD transmis seulement après PV final et quitus (RG-TUT-2)
- [ ] T307 [US9] Migrations `hi_national_sessions`, `hi_candidates`, `hi_tutelage_transmissions`
- [ ] T308 [US9] Constitution de la liste, contrôle assiduité ≥ 70 % (`AttendanceSummaryProvider`), frais d'examen (`DebtorDirectory`), pièces ; exports Excel/PDF au format MINESUP par modèle ; `TutelleExporter` du socle ne produit qu'un CSV d'effectifs (demande S14) ; bibliothèque Excel indépendante de Laravel à choisir
- [ ] T309 [US9] Import des résultats nationaux, taux de réussite, attestation de fin de formation (DOC-12)
- [ ] T310 [US9] Transmissions à la tutelle : listes (DOC-18), notes, relevés, PV ; suivi des dossiers de diplôme ; frais reversés (Should) [Absorbe T335 (`hi_tutelage_transmissions`, `hi_diploma_files`, garde PV final + quitus, journal des consultations)]
- [ ] T311 [US9] Accès lecture de la tutelle (Could) avec journal des consultations

---

## Phase L : US10 Pilotage et portails (P2), lots I1 à I3

- [ ] T312 [P] [US10] Isolation par rôle : étudiant, garant, enseignant, jury, tutelle, auditeur ; `PermissionChecker` ne filtre que par établissement, le périmètre école/filière est à imposer (T208)
- [ ] T313 [US10] Réécrite : tableau de bord direction IPES = `IndicatorProvider` enregistrés sous `reporting.indicators.higher` ; indicateurs `people.enrolled`, `billing.recovery_rate`, `attendance.week_unjustified_rate`, `staff.vacation_hours` **existent** ; à ajouter : recouvrement par école et par canal, alerte d'autorisation [Dépend: T133, T155]
- [ ] T314 [US10] Indicateurs PIL : candidatures, taux de réussite par UE, coût des vacations, résultats nationaux (`DashboardKind` figé : demande S21)
- [ ] T315 [US10] Statistiques d'effectifs MINESUP (DOC-32) annuelles
- [ ] T316 [US10] Sélecteur « École » et vue « Toutes les écoles » (RG-ORG-3), identité visuelle de l'école (RG-ORG-4)
- [ ] T317 [US10] Portail garant (situation financière ; absences et notes si consentement) et portail étudiant complet [Absorbe T337 (portails étudiant, jury, tutelle ; `PublishedResult` étendu = S20)]
- [ ] T318 [US10] Comptes externes nominatifs en lecture seule et à durée limitée : `role_assignments.ends_at` existe ; rôles R22/R23 et restriction par filière à créer
- [ ] T319 [US10] Double cursus : un compte, un matricule, deux parcours (RG-ORG-6)

---

## Phase M : Recette et pilote (lot I4)

- [ ] T320 Exécuter tous les TST-01 à TST-12 en intégration continue
- [ ] T321 Recueillir les règlements des tutelles, grilles de lettres, formats MINESUP et taux de vacation auprès de l'IPES pilote (questions ouvertes) [Absorbe T339 (CDC reçu : reste ce que decisions.md (b) liste, dont la fin du CDC R37)]
- [ ] T322 Reprise des données : années en cours et UE capitalisées ; numérisation des anciens relevés
- [ ] T323 Pilote sur un IPES : formation des rôles, recette, correction
- [ ] T324 Bibliothèque (BIB, option) selon décision
- [ ] T325 Revue sécurité spécifique (step-up 2FA, exports filigranés, empreintes de PV, page publique de vérification limitée à 30 requêtes par minute et par adresse)

---

<!-- BEGIN A : ajouts de l'analyse socle-reuse-A (ACA, ADM, INS, MAQ, EDT, ENS, PRE) ; plage T400-T459 réservée à la moitié A -->
## Phase N (A) : demandes au socle et tâches ajoutées (réutilisation du socle)

Détail, motifs et questions : [socle-reuse-A.md](socle-reuse-A.md). Les demandes D1 à D21 sont des changements du socle à faire par son propriétaire, jamais par l'agent Higher.

### Contrats du socle à obtenir (bloquants)
- [ ] T400 [A] D1 `StructureWriter` (Organisation) : créer filière, niveau, groupe
- [ ] T401 [A] D2 CRUD ou Contract des `authorizations` avec niveaux et document (Organisation)
- [ ] T402 [A] D3 gardes d'inscription additionnelles enregistrables (Organisation/People)
- [ ] T403 [A] D9 `CurriculumCatalog` (Assessment) : upsert matières et éléments depuis la maquette
- [ ] T404 [A] D5 fournisseur de numéro d'inscription (People) et D7 double cursus
- [ ] T405 [A] D4 émission de facture et demande de paiement (Billing)
- [ ] T406 [A] D17/D18 `AssignmentWriter` et références EC/groupe (Staff), lot I2
- [ ] T407 [A] D11/D12/D13 `TimetableWriter`, `session_kind`, lecture des occurrences (Scheduling), lot I2
- [ ] T408 [A] D16 points d'extension de payabilité des heures (Staff), lot I2
- [ ] T409 [A] D19/D20/D21 assiduité par unité d'enseignement (Attendance), lot I2
- [ ] T410 [A] D8 planificateur de montée remplaçable par application (People/Organisation), lot I1
- [ ] T411 [A] D14/D15 HETD paramétrable et taux par grade et niveau (Staff), lot I3
- [ ] T412 [A] D6 paramètres par école (portée org_unit)

### Tâches Higher ajoutées
- [x] T413 [P] [A] Aligner plan.md et tasks.md sur PHP 8.1 sans framework, `src/Modules/Apps/Higher`, PHPUnit ; `hi_sessions_log.start/end` devient `start_time`/`end_time` : fait le 2026-10-10 (plan.md réécrit)
- [ ] T414 [A] [ACA] RG-ACA-2 : pas d'inscription LMD sans tutelle de l'année ; changement de tutelle sans toucher aux années passées [Dépend: T217, T219]
- [ ] T415 [A] [ACA] Refus de changement de régime d'une filière (RG-ORG-7) ; surcharge par école visible et tracée (RG-ORG-5) [Dépend: T412]
- [ ] T416 [A] [ADM] Pièce vérifiée obligatoire pour « admis » (RG-ADM-1), plafond de places avec dérogation via `Approvals` (RG-ADM-3), expiration de « admis sous réserve » (RG-ADM-2, tâche planifiée) [Dépend: T231]
- [ ] T417 [A] [ADM] Statistiques d'admission (Should) et conversion candidature vers inscription [Dépend: T235]
- [ ] T418 [A] [INS] Limite d'inscriptions consécutives et dérogation (RG-INS-5) ; blocage financier `financial_hold` distinct du statut `conditional` du socle [Dépend: T235]
- [ ] T419 [A] [INS] Double cursus : un compte, un matricule, deux parcours (RG-ORG-6) [Dépend: T404]
- [ ] T420 [A] [INS] Carte d'étudiant (DOC-05) via `DocumentIssuer` et `CartescolaireExporter` du socle [Dépend: T235]
- [ ] T421 [A] [MAQ] UE optionnelles dans le contrat pédagogique (règle à obtenir, Q14) et inscription mémorisant la version de maquette (RG-MAQ-1) [Dépend: T220, T235]
- [ ] T422 [A] [EDT] Planning des examens (Should) sur événements de calendrier, surveillants et salles [Dépend: T262]
- [ ] T423 [A] [ENS] Accord écrit pour heures hors volume (RG-ENS-4) et rétention du solde si notes incomplètes (RG-ENS-5) : `hi_overrun_approvals`, `hi_vacation_holds` [Dépend: T408]
- [ ] T424 [A] [ENS] Évaluation des enseignements par les étudiants (Could) [Dépend: T263]
- [ ] T425 [A] [PRE] Épreuve de remplacement après ABJ et levée d'interdiction de composer, tracée [Dépend: T264]
- [ ] T426 [A] [PRE] Deux indicateurs distincts : 30 % d'absences non justifiées (composition) et 70 % de présence (examens nationaux, avec EXN) [Dépend: T264]

### Ordre d'implémentation et dépendances (moitié A)
1. Avant le code Higher : T400 à T405, T410, T412 (socle). T413 en parallèle.
2. I1a : T207, T217, T218, T219, T414, T220 (ACA puis MAQ).
3. I1b : T231, T232, T416 (ADM) ; T235, T236, T418, T419, T420, T421 (INS). Dépend de I1a et de FIN (moitié B).
4. I2 : T406 à T409 puis T262, T263, T264, T258, T269, T422, T425, T426.
5. I3 : T411, T295 à T299, T423, T424.

### Découpage en lots et parallélisme entre agents
Remplacé par [wave-plan.md](wave-plan.md) (agents W0-1 à W0-5, H1 à H3, E1 à E3, L1 à L5, dossiers exclusifs). Tests sous `tests/{Feature,Unit}/Higher/<Module>/` ; un seul `routes.php` par module Higher (le Kernel ne charge que `src/Modules/*/*/routes.php`).
<!-- END A -->

---

## Ajouts issus de l'analyse du socle (partie B : NOT, DEL, FIN, STG, EXN, TUT, DOC, POR, PIL, rôles)

Voir [socle-reuse-B.md](socle-reuse-B.md). Les demandes S1 à S21 sont des changements du socle à faire valider ; elles ne sont pas réalisées ici. Le socle est du PHP 8.1 sans framework : `app/Modules/...` du plan se lit `src/Modules/Apps/Higher/...`.

- [ ] T332 Export de la liste nationale avec step-up 2FA et filigrane nominatif ; journal et alerte DG après export

## Ordre d'implémentation et lots (partie B)

Dépendances : T205 → T254-T272 (NOT) → T279-T288 (DEL) → T287/T291-T294 (DOC relevés) → T304-T311 (EXN/TUT). FIN (T242-T253) ne dépend que du socle et d'ACA. STG (T300-T303) dépend de FIN (quitus) et de NOT. Répartition par agents et dossiers : [wave-plan.md](wave-plan.md).

## Phase O : demandes S1 à S21 au socle (vague 0) et tâches Higher de vague 0

D1 à D21 : T400 à T412 (D10 est traité par S5 à S7). S1 à S21 : détail dans [socle-reuse-B.md](socle-reuse-B.md), propriétaires et dossiers dans [wave-plan.md](wave-plan.md) §0.1.

- [ ] T340 [W0] S1 (Assessment) : codes d'absence extensibles (NA, FR) avec effet déclaratif
- [ ] T341 [W0] S2 (Assessment) : pas de saisie `assessment.step` (0,25)
- [ ] T342 [W0] S3 (Assessment) : approbation à plusieurs niveaux (chef de département puis directeur d'école)
- [ ] T343 [W0] S4 (Assessment) : contrôle optionnel auteur de saisie différent de l'auteur du verrouillage
- [ ] T344 [W0] S5 (Assessment) : `calculation_rules` rattachées à un programme/structure
- [ ] T345 [W0] S6 (Assessment) : `RuleStrategy` enregistrable (éliminatoire par UE, rattrapage, lettres, MGP, décisions annuelles)
- [ ] T346 [W0] S7 (Assessment) : dimension `session` dans `results` et `deliberations`
- [ ] T347 [W0] S8 (Assessment) : `DeliberationGuard` enfichable
- [ ] T348 [W0] S9 (Assessment) : étiquettes de décision paramétrables
- [ ] T349 [W0] S10 (Billing) : `fee_items.code` stable et `kind` extensible
- [ ] T350 [W0] S11 (Billing) : `billing.discount_cap_percent` dans `addDiscount`
- [ ] T351 [W0] S12 (Documents) : blocage `documents` appliqué à l'émission unitaire
- [ ] T352 [W0] S13 (Billing) : facture d'un candidat sans inscription et au nom d'un tiers
- [ ] T353 [W0] S14 (Integrations) : export à colonnes configurables par type (MINESUP, tutelle) en CSV et XLSX
- [ ] T354 [W0] S15 (Identity, Organisation) : `PermissionScopes`, `ScopeType::Structure`, `OrgUnitTree` (périmètre école/département/filière)
- [ ] T355 [W0] S16 (Documents) : jeton `{ECOLE}` dans `NumberPattern`
- [ ] T356 [W0] S17 (Documents) : signataire par type de document
- [ ] T357 [W0] S18 (Documents) : `IssuanceGuard` enregistrable avant `issue`
- [ ] T358 [W0] S19 (Documents) : état « remplacé » dans la vérification publique
- [ ] T359 [W0] S20 (Portals) : `PublishedResult` étendu et sections de portail enregistrables
- [ ] T360 [W0] S21 (Reporting) : `DashboardKind` extensible (ecole, academique, tutelle)
- [ ] T430 [W0] `WriteGate` (`Higher/Support`) appelant `ApplicationAccess::allowsWrites(tenant, 'higher')` avant toute écriture HTTP ou planifiée ; lecture et export restent permis si suspendue ; `WriteGateTest` [Dépend: module Platform livré]
- [ ] T431 [W0] `HigherConventionsTest` : aucune route non GET sans `WriteGate`, aucun `use` hors `Contracts`, tables `hi_*` avec `tenant_id`, aucun mot réservé MySQL [Dépend: T201]
- [ ] T432 [W0] `ScopeResolver` et `ScopedBySchool` consommant `PermissionScopes` (S15 = T354) : filtre école, département, filière, groupe ; test TST-03/TST-12 [Dépend: T354]

## Dépendances et ordre

- A → B → C → D → E (lot I1) ; F (I2) exige S07, S08, S09 de 001 ; G, H, I, J, K (I3) exigent F ; L progresse avec chaque lot ; M clôt.
- US1 (autorisations et maquettes) bloque US2 (inscription) et toute la chaîne.
- US4 (notes verrouillées) bloque US5 (délibération) qui bloque US6 (relevés), US9 (dossiers) et la publication.
- US3 (frais) alimente le quitus (US6, US8, US9).
- Voir [analysis.md](../analysis.md) pour les conflits d'ordonnancement avec 001 (S10 Personnel avant ENS).

## Stratégie

**MVP** = phases A à D (filières, maquettes, admissions, inscriptions) + E (frais). Livrer et valider sur la recette avant I2. Chaque lot se termine par ses TST correspondants au vert.
