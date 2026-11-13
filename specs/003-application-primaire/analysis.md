# Application École primaire : état des lieux du socle et écarts

Ce fichier tient lieu de `analysis.md` (le nom `analysis.md` a été refusé par l'outil d'écriture de l'agent ; à renommer si besoin).

Date : 2026-10-13. Analyse en lecture seule du code réel (`src/Modules/Socle/*`, `src/Modules/Apps/Higher`, migrations), des 24 maquettes (`scholaris-html`) et de l'archive `archive/nestjs-nextjs`. Aucun cahier des charges du primaire n'existe : tout ce qui touche aux règles officielles est une **hypothèse** à confirmer (voir [questions.md](questions.md)).

## Résumé (20 lignes)
1. Le socle couvre déjà l'essentiel d'une école primaire : type d'établissement `primary`, vocabulaire (élève, classe, séquence, « bulletin APC »), années, périodes `sequence`/`trimester`, niveaux et classes, inscriptions, garants, santé, frais, mobile money, messagerie, documents vérifiables, présences, notes, conseil de classe, bulletins en file, portails famille/enseignant.
2. Le catalogue de plateforme contient déjà `primary` (« Enseignement primaire », statut `coming_soon`, 60 000 XAF/mois, essai 90 jours, grâce 15 jours).
3. Sur 46 exigences de [spec.md](spec.md) : **21 couvertes** par le socle, **17 partielles**, **8 à créer** dans `src/Modules/Apps/Primary`.
4. Aucune maquette n'est propre au primaire : bulletin, notes et conseil de classe sont des maquettes du secondaire ; carnet de compétences, cantine/garderie et sortie sécurisée sont des maquettes de la maternelle. Référence visuelle, pas règle.
5. Écart 1 : les périodes n'ont pas de hiérarchie (`periods` sans `parent_id`) : un trimestre ne regroupe pas ses séquences ; ni moyenne trimestrielle ni annuelle native.
6. Écart 2 : l'évaluation par compétences (APC) se limite au mode `qualitative` à 3 valeurs fixes (`acquired`, `in_progress`, `not_acquired`) ; pas de référentiel domaines > compétences ni d'échelle paramétrable.
7. Écart 3 : l'appel exige un créneau d'emploi du temps (`attendance_sessions.slot_id NOT NULL`) ; l'appel par demi-journée d'un maître unique n'a pas de forme naturelle.
8. Écart 4 : aucun rôle propre au primaire (directeur d'école, maître de classe, secrétaire, économe) ; seuls les rôles de base existent.
9. Écart 5 : examen de fin de cycle (CEP / FSLC selon le sous-système) absent du socle ; l'ancienne application le prévoyait.
10. L'ancienne application (feature-matrix) donnait pour le primaire : moyenne simple sans coefficient, pas de mention, compétences actives, séquences et trimestres, rang, conseil de classe, cantine et transport actifs, CEP, rapport MINEDUB. **Pistes non vérifiées.**
11. Elle notait aussi « sur 10 jusqu'au CE1 » avec seuils 8 (Acquis) / 6 (En cours) : piste à confirmer, jamais à coder comme règle.
12. Mécanismes de Higher réutilisés tels quels : manifeste `platform.applications.primary`, `WriteGate`, `billing.fee_kinds.primary`, `portals.sections.primary`, `assessment.rule_strategies.primary`, `assessment.rule_scopes.primary`, `organisation.closure.promotion.primary`, `reporting.indicators.primary`, `documents.issuance_guards.primary`.
13. Demandes de changement du socle : 10 (PS1a, PS1b, PS2 à PS9, [socle-reuse.md](socle-reuse.md)), toutes rétrocompatibles ; 6 en P0 (hiérarchie des périodes et agrégat, échelle qualitative, appel par demi-journée, variables de bulletin, rôles).
14. Tables propres prévues : 14 tables `pri_*` ([data-model.md](data-model.md)).
15. Tâches T500 à T572 ([tasks.md](tasks.md)) ; plan en 5 vagues de 3 agents au plus ([wave-plan.md](wave-plan.md)).
16. Peut démarrer tout de suite : squelette `Primary`, manifeste, rôles, référentiels paramétrables (niveaux, domaines, compétences), inscriptions/frais/documents administratifs, extensions socle PS1-PS3 conçues de façon générique.
17. Bloqué par les réponses : barème par niveau, calcul des moyennes et du rang, appréciations, passage/redoublement, modèle de bulletin, CEP/FSLC, liste officielle des matières et domaines.
18. Questions prioritaires : Q1 sous-systèmes, Q2 modèle de bulletin officiel, Q3 barème et calcul, Q4 découpage séquences/trimestres, Q5 niveaux.
19. Hors périmètre V1 proposé : cantine, garderie, transport (Could), sauf réponse contraire à Q12.
20. Aucun code écrit ; seuls les fichiers de `specs/003-application-primaire/` ont été créés.

**Légende** : C = couvert par le socle tel quel ; P = partiel (écart décrit) ; N = à créer dans `src/Modules/Apps/Primary` ; H = hypothèse.

---

## 1. Sources consultées et fiabilité
| Source | Ce qu'elle apporte | Fiabilité |
|--------|--------------------|-----------|
| Code du socle (Contracts, `routes.php`, `definitions.php`, migrations) | Ce qui existe et fonctionne (5130 tests) | Fait |
| `Organisation\Application\DefaultApplications` | Vocabulaire `primary` : `learner=élève`, `grouping=classe`, `period=séquence`, `document=bulletin APC` | Fait (choix de conception, pas règle officielle) |
| `Platform/Database/Migrations/202610083451_platform_catalog_seed.sql` | `primary` au catalogue, `coming_soon`, modules requis `people, billing, messaging, documents, scheduling, attendance, assessment, staff, reporting` | Fait |
| `Fondations.html` | Tableau des quatre cibles : Primaire = période « séquence », document « bulletin APC » | Intention de design |
| `Bulletin.html`, `Notes.html`, `NotesMobile.html`, `ConseilClasse.html`, `SecDashboard.html` | Secondaire (Collège Les Palmiers, MINESEC, coefficients, rang, appréciation, conduite, tableau d'honneur, sections FR/EN) | Modèle visuel transposable ; règles du secondaire |
| `CarnetSuivi.html`, `MatDashboard.html`, `MatSortie.html`, `Parent.html` | Maternelle : carnet A/EC/NA par domaine, observation, photos sous consentement, cahier de vie, cantine/garderie, sortie sécurisée | Modèle visuel transposable (APC) |
| `Frais.html`, `Inscription*.html`, `Appel.html`, `Main.html` | Caisse, famille multi-établissements, remise fratrie, référence de paiement famille, appel hors ligne, lanceur | Transverse |
| Archive : `erp/database/feature-matrix.php`, `erp/src/Service/CalculationRules.php`, `GradeCalculator.php`, `erp/src/Support/Cameroon.php`, `erp/database/migrations/009_exams.sql` | Pistes primaire (§4) | **Piste à valider** |

## 2. Ce que le socle couvre déjà (vérifié dans le code)

### 2.1 Organisation (S02)
- **C** Type `primary` (`Organisation\Domain\EstablishmentType`), `artisan establishment:create <client> <nom> primary`, `ClientProvisioning`.
- **C** `GET /vocabulary?type=primary` ; `GET|PUT /establishments/{id}/branding` ; `GET|PUT /establishments/{id}/modules`.
- **C** `/org-units` (CRUD + export) : sections francophone/anglophone ou cycles (H).
- **C** `/academic-years`, `/periods` : `PeriodKind::allowedFor(primary)` accepte `trimester` et `sequence`, refuse `semester`.
- **C** `/structures` : `StructureKind` `level`, `class`, `group` permis ; `track` interdit hors `higher` ; `pedagogical_structures.parent_id` (classe → niveau). Contrats `StructureWriter`, `OrgUnitTree`, `StructureInfo`.
- **C** `/settings`, `/settings/effective` (héritage plateforme > client > établissement > unité), `SettingsReader`, `OrgUnitSettingsReader`.
- **C** `/authorizations`, `/authorizations/expiring`, `AuthorizationRegistry` ; garde `EnrolmentGate` / `EnrolmentGuard` (liste `EnrolmentGuards::PREFIX`) ; `GET /structures/{id}/enrolment`.
- **C** Clôture : `/academic-years/{id}/closure-check|close|close/approve|close/reject` ; extensions `organisation.closure.checks.*`, `organisation.closure.carry_over.*`, `organisation.closure.promotion.*`.
- **C** Montée générique `People\Application\YearPromotion` (paramètre `people.promotion.next_structure` classe → classe supérieure ; non admis = non promu) ; remplaçable (`PromotionPlanner`, `TypedPromotionPlanner`) ; admission lue par `Assessment\Contracts\AdmissionDirectory`.
- **P** `periods` = `kind, ordinal, starts_on, ends_on` : **pas de `parent_id`** (PS1).
- **P** `CurrentPeriod::at()` rend une seule période ; au primaire séquence et trimestre se superposent (PS1).

### 2.2 People (S03)
- **C** `/people` (CRUD, `import`, `duplicates`, `export`), `contacts`, `identifiers`, `schooling-history`, `relations` (garants), `health` (chiffrée), `documents`, `/consents` ; `PersonDirectory::guarantorsOf|learnersOf`, `ConsentChecker`, `BirthInfo`.
- **C** `/enrollments` (création, `validate`, `status`, export), `EnrollmentDirectory`, `StructureEnrollments`, `TeachingScope`.
- **C** Matricule remplaçable `EnrollmentNumberProvider` (`people.enrollment_number.providers.<app>`).
- **P** Redoublant : aucune colonne sur `enrollments` ; à déduire de l'inscription de l'année précédente au même niveau (lecture par contrat) ou à porter par `pri_enrolment_profiles`.

### 2.3 Billing (S04)
- **C** `/fee-items`, `/fee-schedules` (échéances), `/invoices`, `/invoices/{id}/discounts` + `/discounts/{id}/approve`, remise fratrie (`SiblingDiscountResolver`), `/payments`, `/cash/receipts`, `/cash-sessions` (+ `close`, `deposits`), `/reversals` (+ approbation), `/reconciliations`, `/payment-references`, `/billing/families/{reference}/balance`, `/billing/exports/{kind}`, webhook mobile money, relances `reminders:send`.
- **C** `InvoiceIssuer`, `PaymentRequester`, `InvoicePayer`, `DebtorDirectory`, `EstablishmentBalances` ; nature de frais extensible `billing.fee_kinds.<app>` (base `registration`, `tuition`, `other`).
- **P** Cantine, garderie, transport : facturables comme `fee_items` de nature `canteen`, `daycare`, `transport` (H) ; aucun suivi des services rendus.

### 2.4 Messaging (S05)
- **C** `/message-templates`, `/messages` (+ `audience-preview`, `send`, `deliveries`), `/announcements`, `/conversations` modérées, `/sms-usage` ; `Messenger`, `DirectNotifier`, `AbsenteeDirectory`, `DirectionDirectory`.
- **P** Modèles propres au primaire (bulletin disponible, convocation, cahier de liaison) : données à insérer par une migration nouvelle ; variables limitées à `Messaging\Domain\TemplateRenderer::ALLOWED`.

### 2.5 Documents (S06)
- **C** `/document-types`, `/document-templates` (+ `validate`, `versions`), `/document-seal`, `/documents` (+ `preview`, `duplicate`, `revoke`), `/document-batches`, `/document-requests`, `/public/verify/{token}` (QR) ; `DocumentIssuer`, `ExtendedDocumentIssuer`, `IssuanceGuard` (`documents.issuance_guards.<app>`), `DocumentCatalog`.
- **C** Bulletins en file : `Assessment\Application\ReportCardService` émet un document de type `report_card` par élève après validation du conseil (`GET|POST /class-councils/{id}/report-cards`).
- **P** Modèle « bulletin APC » (domaines, compétences, séquences d'un trimestre, bilingue) absent : modèle et variables à fournir par Primary.

### 2.6 Scheduling (S07)
- **C** `/calendar-events`, `/timetable-slots` (+ `check`), `/timetable-changes`, `/timetable`, `/timetable/consumption` ; `TimetableWriter`, `TimetableDirectory`, `TeacherClasses`.

### 2.7 Attendance (S08)
- **C** `/attendance-sessions` (+ `sync` hors ligne `client_uuid`, `records`, `validate`), `/attendance-records`, `/absence-justifications` (+ `accept`, `reject`), `/incidents`, `/sanctions` (+ `approve`), **`/authorized-pickups`** (+ `revoke`), `/attendance/students/{id}/summary` ; `AttendanceSummaryProvider`, `AttendanceCodes`, `attendance:retry-absences`.
- **P** `attendance_sessions.slot_id NOT NULL` et index unique `(tenant_id, slot_id, session_date)` : l'appel suppose un créneau (PS3).
- **P** Sortie sécurisée : autorisations présentes, journal des remises absent (maquette maternelle `MatSortie.html`).

### 2.8 Assessment (S09)
- **C** `/subjects` (`code`, `kind`, `coefficient`, éléments), `CurriculumCatalog` (upsert par clé externe, désactivation).
- **C** `/assessments` : `kind`, `weight`, `max_score` (barème libre), `score_step`, `grading_mode` `numeric|qualitative`, `period_id`, `structure_id` ; cycle saisie > verrouillage > publication (`/assessments/{id}/transition`, `/grade-sheets/transition`).
- **C** `/grades` (+ `batch`, `sync`, `import`, `import-template`, `export`, `events`), `/grade-corrections` (approbation), `/sequence-statistics`.
- **C** `/calculation-rules` versionnées (`RuleSet` : `scale`, `passMark`, `rounding`, `unjustifiedAbsence`, `weighting`, `mentions`, `councilProposals`, `decisions`) ; stratégie enregistrable `assessment.rule_strategies.<app>` (`RuleStrategy::computeLearner`, `decideYear`) ; rattachement par structure et lignée `RuleScopeLineage` (`assessment.rule_scopes.<app>`) : **une version de règles par niveau est possible**.
- **C** `/results` (+ `publish`, `export`) : `average`, `rank_in_class` (ex æquo au même rang, `RuleEngine::ranks`), `class_size`, `mention`, `proposal`, `decision`, `details`.
- **C** `/class-councils` : lignes `average`, `rank_in_class`, `unjustified_absences`, `conduct`, `proposal`, `decision`, `reason`, `appraisal` ; `refresh`, `validate`, procès-verbal.
- **P** `GradeService::QUALITATIVE = ['acquired','in_progress','not_acquired']`, figé (PS2).
- **P** Moyenne d'une période = épreuves de cette période seulement ; pas d'agrégat séquences → trimestre → année (PS1 + stratégie `primary`).
- **P** `council_lines.conduct` texte libre `VARCHAR(60)` : pas d'échelle de conduite.
- **N** Référentiel de compétences (domaines > compétences > indicateurs) et lien aux matières.

### 2.9 Staff, Portals, Reporting, Audit, Integrations, Platform
- **C** `/staff` (+ `qualifications`, `assignments`), `AssignmentWriter`, `StaffDirectory`, `TeacherWorkload`.
- **C** `/portal/family`, `/portal/learner`, `/portal/teacher` ; `portals.sections.<app>` (`PortalSection`), `portals.grading_todo.<app>`.
- **C** `/dashboards/{kind}` (`DashboardKind` : `direction`, `censeur`, `groupe`, extensible), `IndicatorProvider` (`reporting.indicators.<app>`), `reporting.todo.<app>`, `reporting.dashboards.<app>`.
- **C** Audit, `Approvals` à deux personnes, export client, rétention, `/data-requests`.
- **C** `TutelleExporter`, `ConfiguredExportType` (`integrations.export_types.<app>`), `CartescolaireExporter` ; colonnes MINEDUB inconnues (Q16).
- **C** `ApplicationManifest::TARGETS` contient `primary` ; `ApplicationAccess::allowsWrites|allowsReads` ; libre-service `POST /applications/{key}/open`.
- **P** Rôles existants : `super_admin`, `direction_groupe`, `directeur`, `comptable`, `caissier`, `enseignant`, `parent`, `apprenant` ; portées `tenant`, `establishment`, `org_unit`, `class`, `structure`. Maître de classe = `enseignant` portée `class` (suffisant) ; rôles propres au primaire à créer.

## 3. Spécifique au primaire
| Domaine | Ce que montrent maquettes / archive | Traitement proposé | Statut |
|---------|-------------------------------------|--------------------|--------|
| Cycle et niveaux | Archive : cycles > niveaux > classes, sections `opt` ; aucun nom de niveau trouvé | `pri_level_profiles` lié aux structures `level` ; jeu par défaut à confirmer (Q1, Q5) | N + H |
| Matières et domaines | Bulletin secondaire groupé ; carnet maternelle par domaine | `pri_domains` regroupant des `subjects` du socle ; liste officielle à fournir (Q6) | N |
| Compétences (APC) | « bulletin APC » ; carnet A/EC/NA ; archive `evaluation.competencies=on` | `pri_competencies`, `pri_competency_marks` ; échelle paramétrable (PS2) | N + H |
| Évaluations | Séquence (Fondations) ; séquences d'un trimestre (bulletin secondaire) ; archive séquences + trimestres | Séquences rattachées à un trimestre (PS1) ; nombre paramétrable (Q4) | P |
| Calcul | Archive : moyenne simple, sans mention, rang actif, « sur 10 jusqu'au CE1 » | Stratégie `primary` paramétrable (barème par niveau, coefficients 1 par défaut, arrondi, rang ex æquo) ; **aucun seuil codé** (Q3, Q7) | N + H |
| Bulletin | Maquette secondaire A4 bilingue (rang, appréciation, discipline, décision, QR) | Type `primary_report_card` : variables définies, mise en page validée sur un modèle réel (Q2) | P |
| Conduite et assiduité | Conseil : conduite (Très bonne… Mauvaise), heures NJ | `pri_conduct_scales` ; absences comptées en demi-journées (H, Q9) | P |
| Conseil de fin de période | Conseil de classe secondaire | Conseil du socle, présidé par le directeur (H, Q8) | C |
| Passage de classe | Archive : `enrollment.repeater=on` | `organisation.closure.promotion.primary` paramétré (Q10) | N + H |
| Examen de fin de cycle | Archive : `exams.cep=on`, `official_exams` | `pri_exam_sessions`, `pri_exam_candidates` ; règles à fournir (Q11) | N |
| Cahier de textes / liaison | Nav « Cahier de textes » ; « Cahier de vie » (maternelle) | `pri_class_journal` + Messaging (Should) | N |
| Cantine, garderie, transport | MatDashboard ; archive `life.catering=on`, `life.transport=on`, `finance.catering_fees=on` | Could : `pri_service_subscriptions` facturées par Billing (Q12) | N |
| Sortie sécurisée | MatSortie (maternelle) | Should si pratiquée (Q13) : `pri_pickup_logs` | N |
| Rapports tutelle | Archive : `report.minedub=on`, PRIMAIRE → MINEDUB | Export `integrations.export_types.primary` ; colonnes à fournir (Q16) | P |

## 4. Pistes de l'ancienne application (à valider)
- `feature-matrix.php`, PRIMAIRE : `structure.cycles=on`, `structure.sections=opt`, `subjects.coefficients=off`, `subjects.categories=off`, `evaluation.competencies=on`, `periods.sequences=on`, `periods.trimesters=on`, `calc.simple_average=on`, `calc.weighted_average=off`, `calc.mentions=off`, `exams.cep=on`, `exams.mock=opt`, `bulletins.sequential|trimestral|annual=on`, `bulletins.rank=on`, `bulletins.class_council=on`, `bulletins.certificate=on`, `bulletins.school_card=on`, `enrollment.repeater=on`, `enrollment.transfer=on`, `life.catering=on`, `life.transport=on`, `life.textbook=on`, `finance.catering_fees|transport_fees|exam_fees=on`, `report.minedub=on`, `report.by_level=on`, `report.exam_success=on`.
- `CalculationRules.php` : « une école primaire note sur dix jusqu'au CE1 et ne décerne pas de mention » ; seuils 8 → Acquis, 6 → En cours d'acquisition, sinon Non acquis. Contradiction : `calc.mentions=off` mais seuils d'appréciation présents. **À trancher (Q3, Q7).**
- `GradeCalculator.php` : ex æquo au même rang, rang suivant sauté (identique au socle).
- `Cameroon.php` : tutelle naturelle PRIMAIRE → MINEDUB, modifiable.
- Aucun nom de niveau (SIL, CP, CE1…, Class 1…6) dans l'archive : ne pas le supposer (Q5).

## 5. Écarts majeurs classés
1. Hiérarchie des périodes et agrégats trimestriel/annuel (PS1) : bloque les bulletins.
2. Évaluation par compétences paramétrable (PS2 + `pri_*`) : bloque le « bulletin APC ».
3. Règles officielles inconnues (barème, calcul, rang, appréciations, passage) : bloque la stratégie `primary` et ses tests chiffrés (Q3, Q7, Q10).
4. Appel par demi-journée sans créneau (PS3).
5. Rôles et périmètres du primaire : migration nouvelle `database/migrations/<date>_roles_primary.sql`.
6. Examen de fin de cycle et rapports MINEDUB : règles et formats inconnus (Q11, Q16).
7. Modèle de bulletin officiel absent (Q2).
