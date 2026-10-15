# Réutilisation du socle : moitié A (ACA, ADM, INS, MAQ, EDT, ENS, PRE)

Date : 2026-10-09. Analyse en lecture seule du code réel (`src/Core`, `src/Modules/Socle/*`, migrations, `routes.php`, `definitions.php`) face à [spec.md](spec.md), [plan.md](plan.md), [data-model.md](data-model.md). La moitié B (NOT, DEL, FIN, STG, EXN, TUT, DOC, POR, PIL, rôles) est dans `socle-reuse-B.md`.

**Limite de sources.** Le CDC IPES (PDF) n'est pas dans le dépôt : les intitulés des EX-xx et RG-xx ci-dessous sont déduits des paragraphes FR-001 à FR-010 et des scénarios de spec.md. Seules les plages (EX-ACA-01 à 07, etc.) sont certaines. À recouper avec le PDF avant de figer les tests.

**Légende** : C = couvert par le socle (réutilisé tel quel) ; P = partiel (écart décrit) ; N = à créer dans `src/Modules/Apps/Higher`.

## 0. Constats transverses (valables pour les sept modules)

1. **Plan périmé.** plan.md parle de Laravel, PHP 8.4, `app/Modules`, Pest, `maatwebsite/excel`. La réalité (ADR-0001) : PHP 8.1 sans framework, `src/Modules/Apps/Higher` (namespace `Scholaris\Modules\Apps\Higher`), PHPUnit, SQL portable MySQL 8 / SQLite. Le chargement est automatique : `routes.php` (glob `src/Modules/*/*/routes.php`, préfixe `/api/v1`, donc routes déclarées `/higher/...`), `definitions.php` (PHP-DI) et `Database/Migrations/*.sql` (préfixe de fichier `AAAAMMJJHHMM_`). `Apps/Higher` ne contient aujourd'hui qu'un `.gitkeep`.
2. **Règle d'architecture bloquante.** `tests/Architecture/StandardsTest` interdit à `Apps/*` tout `use` d'un module Socle hors `Contracts/` ; `NoForeignTableReadTest` interdit de lire les tables d'un autre module (liste d'exceptions vide). Conséquence : Higher peut LIRE le socle via des Contracts, mais le socle n'expose presque aucun Contract d'ÉCRITURE (créer une structure, une matière, une affectation, un créneau, une inscription). C'est l'écart n° 1 : voir §9 (demandes de changement du socle).
3. **Conventions SQL** (agent-conventions) : ULID `CHAR(26)`, dates `VARCHAR(32)`, montants entiers XAF, pas de clé étrangère, noms de colonnes hors mots réservés MySQL. Dans data-model.md, `hi_sessions_log.start` / `end` sont à renommer (`start_time`, `end_time`) ; éviter `groups`, `rank`, `range`, `condition`, `order`. Un contrat OpenAPI par module dans `specs/001-socle-plateforme/contracts/openapi.d/higher-*.yaml` (le test `OpenApiContractTest` lit ce dossier, pas celui de 002).
4. **Autorisation** : `Core\Security\PermissionChecker`, refus par défaut ; les rôles sont dans les tables globales `roles` / `role_permissions` (seed par migration). Aucun rôle IPES (R04 à R23) n'existe : chaque permission Higher est ajoutée par une migration `role_permissions` propre à Higher (hors périmètre de la moitié A, voir B). Portées de rôle existantes : `tenant`, `establishment`, `org_unit`, `class` (`ScopeType`) : suffisantes pour école (org_unit), département (org_unit enfant) et groupe (class) ; la filière (`track`) est une structure pédagogique, pas un type de portée.
5. **Paramètres** : `SettingsReader::value(tenant, établissement, clé, défaut)` avec héritage plateforme, client, établissement ; PUT `/settings`, GET `/settings/effective`. Pas de portée « école interne » : l'école interne étant une `org_unit`, la surcharge par école exige soit une clé suffixée par l'école (`higher.<param>.<school_id>`), soit une extension de `SettingScope` (voir §9, D6).
6. **Multi-écoles IPES.** Le socle connaît tenant > établissement. Le CDC IPES a « établissement > écoles internes > départements > filières ». Mapping retenu : un IPES = un établissement de type `higher` ; école interne = `org_unit` de type `school` ; département = `org_unit` de type `department` (parent = école) ; filière = `pedagogical_structures.kind = 'track'` rattachée à l'org_unit du département. Question ouverte Q1 : un « groupe d'écoles » (plusieurs IPES) est-il un tenant ?

---

## 1. ACA : référentiel académique

**1. Exigé.** EX-ACA-01/02 écoles, départements, filières, spécialités, niveaux, groupes, avec régime (LMD, BTS, HND, certificat), langue, mode (jour, soir, alternance), durée. EX-ACA-03/04 registre des autorisations MINESUP (arrêté, date, niveaux, fin, document) et des tutelles (université, filières, années, convention). EX-ACA-05 règlement des études par régime et par école, figé à l'ouverture des inscriptions. EX-ACA-06 calendrier académique. EX-ACA-07 alertes d'expiration 90/30/7 jours. RG-ACA-1 (pas de candidature/inscription sans autorisation valide), RG-ACA-2 (pas d'inscription LMD sans tutelle de l'année), RG-ACA-3 (modification du règlement figé = validation du directeur académique, tracée). Edge cases : régime non modifiable (RG-ORG-7), changement de tutelle sans toucher aux années passées, surcharge par école visible et tracée (RG-ORG-5).

**2. Déjà fourni, réutilisé tel quel.**
- Établissement de type `higher`, vocabulaire (étudiant, filière, semestre, relevé) : `ApplicationRegistry` / `DefaultApplications` (le descripteur `higher` est déjà enregistré) ; `GET /vocabulary`, `GET/PUT /establishments/{id}/branding` (RG-ORG-4).
- Unités d'organisation : table `org_units` (type libre, `parent_id`), pour écoles et départements ; `rooms` (nom, capacité, équipements).
- Années et périodes : `academic_years`, `periods` (kind, ordinal) ; routes `/academic-years/{id}/closure-check`, `/close`, `/close/approve`, `/close/reject` ; contrat `CurrentPeriod::at()`. Calendrier (EX-ACA-06) : `/calendar-events` (S07), types dans `CalendarKind`.
- Garde d'inscription : `EnrolmentGate::canEnrol(tenant, établissement, structure, date)` (implémentation `PdoEnrolmentGate` : structure existante + une `authorizations` valide à la date). Déjà appelée par `People\EnrollmentService::validate` (message « cette filière n'a pas d'autorisation valide »).
- Alerte d'expiration : `GET /authorizations/expiring` (`AuthorizationExpiryService`, 30 jours par défaut) pour le tableau de bord.
- Paramètres : `/settings`, `/settings/effective`, `SettingsReader`. Validation à deux personnes : contrat `Audit\Contracts\Approvals` (`request/approve/reject/find`), déjà utilisé pour la clôture d'année, corrections de notes, remboursements. Journal : `AuditLog`. Double authentification récente : `Identity\Contracts\RecentSecondFactor`.
- Notifications e-mail/SMS : `Messaging\Contracts\Messenger`, `MailGateway`.

**3. Partiel / manquant.**
- `pedagogical_structures` : seulement `kind` (level, class, group, track), nom, capacité, `org_unit_id`. Pas de régime, langue, mode, durée, spécialité, niveau parent, code. Pas de contrat d'écriture ni de lecture hors `EnrolmentGate`. Route `/structures/{id}/enrolment` en lecture. `StructureKind::Track` n'est autorisé que pour `higher` : bon point d'accroche.
- `authorizations` : structure + référence + autorité + validité. Manque : niveaux couverts, document joint (`document_id`), contrôle « pour le niveau et l'année » (la garde ignore le niveau). Aucune route CRUD d'autorisations visible (seulement `/authorizations/expiring`) : à vérifier, sinon la saisie passe par un Contract à ajouter.
- Tutelles : rien.
- Règlement figé : les `calculation_rules` (Assessment) sont versionnées par année, mais sans notion de gel ni de validation par un directeur académique, et ne portent que 11 paramètres (voir §4 MAQ/§5).
- Alertes 90/30/7 par e-mail : le socle n'a que l'indicateur « échéance dans N jours », pas de relance planifiée multi-seuils ni NOT-15.

**4. À créer dans `Apps/Higher` (module `Academic/`).**
- Tables : `hi_schools` (lien `org_unit_id`, code, directeur), `hi_departments` (lien org_unit, chef), `hi_programs` (lien `structure_id` de la filière socle, école, département, code, régime, mode, langue, durée, statut), `hi_levels` (programme, rang, libellé L1…M2, BTS1…), `hi_groups`, `hi_authorizations` (programme, niveaux JSON, document_id ; la validité temporelle reste dans `authorizations` du socle), `hi_tutelages` (programme, année, université, convention, contacts), `hi_regulations` (programme/école, année, paramètres, version, `frozen_at`).
- Services : `EnrolmentGate` IPES (autorisation du niveau + année ET tutelle si LMD), exposé par un nouveau Contract Higher (`HigherEnrolmentGate`) et appelé avant toute ouverture de campagne ou inscription ; `FreezeRegulation` (gel à l'ouverture des inscriptions), `AmendFrozenRegulation` (Approvals + `RecentSecondFactor`) ; tâche planifiée d'alertes 90/30/7 (NOT-15) via `Messenger` ; refus de changement de régime (409).
- Routes (préfixe `/higher`) : `/schools`, `/departments`, `/programs`, `/programs/{id}/levels`, `/groups`, `/authorizations`, `/tutelages`, `/regulations` (+ `/freeze`, `/amend`).

**5. Points d'extension / changements du socle à demander.** (a) Contract d'écriture de structures (`StructureWriter` : créer/modifier une structure `track|level|group` rattachée à un org_unit, retournant l'id) — D1. (b) Contract d'écriture des autorisations ou route CRUD documentée — D2. (c) `EnrolmentGate` : accepter un second garde-fou enregistrable (liste de `EnrolmentGuard` fournis par les applications, appelée par `PdoEnrolmentGate`) pour que `People\EnrollmentService::validate` applique aussi la tutelle et le niveau sans que Higher doive réimplémenter l'inscription — D3. (d) Portée « école » des paramètres (D6). (e) Types d'org_unit `school`/`department` : vérifier qu'aucune liste fermée ne les rejette.

**6. Risques / questions.** Q1 (groupe d'écoles). Q2 : une autorisation MINESUP porte-t-elle sur un niveau précis ou sur toute la filière ? Q3 : « directeur académique » (validation du règlement) est-il le rôle R-xx « directeur d'école » ou un rôle distinct ? (non résolu par spec.md). Q4 : une filière LMD change-t-elle de tutelle en cours d'année : une seule tutelle active par filière et année, ou plusieurs ? Risque : double source de vérité si la filière existe à la fois dans `pedagogical_structures` et `hi_programs` ; règle : `hi_programs.structure_id` obligatoire et unique.

---

## 2. ADM : admissions et concours

**1. Exigé.** EX-ADM-01 à 04, 06 (Must) : campagnes par filière et niveau, candidature en ligne ou au guichet, frais de dossier par mobile money, contrôle bloquant des prérequis, admission sur dossier / concours / mixte, décision motivée notifiée. EX-ADM-05/07/08 (Should) : concours complet, transferts, statistiques. RG-ADM-1 (diplôme exigé justifié par une pièce vérifiée), RG-ADM-2 (« admis sous réserve » expire à 30 jours, devient « refusé »), RG-ADM-3 (plafond de places, dépassement = décision tracée du directeur d'école), RG-ADM-4 (frais de dossier non remboursables).

**2. Déjà fourni.**
- Personne (candidat) : `/people` (+ `GET /people/duplicates`, `POST /people/import`) ; contrats `PersonDirectory`, `DuplicateMatcher` (domaine, via route). Pièces : `/people/{id}/documents` (+ `DocumentKind`, `Completeness`, `FileScanner`), historique scolaire `/people/{id}/schooling-history` (diplômes antérieurs). Garants : `/people/{id}/relations`. Consentements : `/consents`.
- Frais de dossier : S04 Billing (`billing_catalog`, `invoices`, `payments`, reçus numérotés, `PaymentGateway` mobile money simulé en recette). Contrats : `DebtorDirectory` seul est exposé.
- Notifications : `Messenger` + `/message-templates`. Documents : `DocumentIssuer::issue(tenant, étab, type, personne, data, par, locale)` pour accusé, convocation, lettre d'admission (DOC-01 à 03).
- Approvals pour la dérogation de places ; `AuditLog`.

**3. Partiel / manquant.** Aucun module d'admission dans le socle. `Assessment\Contracts\AdmissionDirectory` porte un nom trompeur : c'est la liste des « admis en classe supérieure » lue dans les résultats, sans rapport avec ADM (risque de confusion ; à ne pas réutiliser). Les pièces ont un type et une complétude mais pas d'état « vérifiée » explicite à exposer par Contract à confirmer (`DocumentKind`, `UploadPolicy`). Pas de contrat de création de facture/paiement appelable par une application (les routes S04 existent, pas de Contract d'écriture) : D4.

**4. À créer (module `Admissions/`).** Tables `hi_campaigns` (programme, niveau, ouverture/clôture, places, frais de dossier, pièces requises, prérequis, mode d'évaluation), `hi_applications` (statuts du data-model, motif, `conditional_until`, `person_id`, `invoice_id`), `hi_application_documents` (pièce requise → document du socle, état vérifié par/le), `hi_admission_decisions` (historique, motif, auteur), Should : `hi_contests` (centres, salles, notes de concours, classement), `hi_waitlist`. Services : `OpenCampaign` (appelle la garde Higher, RG-ACA-1/2), `SubmitApplication` (facture des frais + paiement), `AdmitApplication` (RG-ADM-1 bloquante, RG-ADM-3 plafond), tâche planifiée d'expiration (RG-ADM-2, paramètre `higher.admission.conditional_days` = 30), `ConvertToEnrolment`. Routes : `/higher/campaigns`, `/higher/applications` (+ `/submit`, `/verify-document`, `/decide`, `/enrol`), `/higher/contests` (Should). Règles : frais non remboursables (aucune écriture inverse proposée).

**5. Extensions du socle.** Contract `InvoiceIssuer`/`PaymentRequester` côté Billing (D4) ; paramètre `higher.admission.*` ; modèles de message NOT-01 à NOT-04 insérés par migration Higher.

**6. Risques / questions.** Q5 : le candidat sans compte peut-il déposer en ligne (compte créé à la soumission via `AccountProvisioner` ?) — flux public à sécuriser (limite de débit). Q6 : le concours (EX-ADM-05) est-il dans la version 1 ? Q7 : classement : barème de concours fourni par l'école ? Q8 : candidat refusé : conservation et suppression des données (module `privacy` d'Audit).

---

## 3. INS : inscriptions administratives et pédagogiques

**1. Exigé.** EX-INS-01 à 08 : inscription administrative et pédagogique, matricule unique et définitif, réinscription en ligne, statuts, carte d'étudiant, consentement de partage avec le garant ; Should : changement de filière/groupe ; Could : gel. RG-INS-1 (matricule : année, code école, code filière, ordre sur 4 chiffres, jamais réattribué), RG-INS-3/4 (réinscription N+1 exige la décision de passage ; UE capitalisées reportées, dettes inscrites d'office), RG-INS-5 (limite d'inscriptions consécutives), RG-INS-7 (consentement de l'étudiant majeur). Doublons (même nom, naissance, téléphone). Double cursus : un compte, un matricule (RG-ORG-6).

**2. Déjà fourni.**
- Inscriptions : `/enrollments` (+ `/validate`, `/status`, `/export`), table `enrollments` (personne, établissement, structure, année, statut, `internal_number`, complétude, `documents_due_on`), statuts `EnrollmentStatus` : draft, active, conditional, suspended, withdrawn, excluded, transferred, graduated (couvre « suspendu | abandon | exclu | transféré | diplômé » et « sous réserve »). Une seule inscription vivante par personne, établissement et année. Garde d'autorisation `EnrolmentGate` déjà branchée. Contrats : `EnrollmentDirectory` (`find`, `forPerson`, `personIdsInStructure`, `personIdsInEstablishment`), `EnrollmentInfo`.
- Doublons : `GET /people/duplicates` (`DuplicateMatcher`). Majorité : `Majority`, `AdultLearnerCheck`. Consentement : `ConsentChecker` (`parent_access`), `/consents`, portail garant (`/portal/family`) qui respecte le consentement des majeurs.
- Matricule : `SequenceService::next` transactionnel par (tenant, établissement, type, année) : unicité et non-réattribution garanties ; stocké comme identifiant `internal` de la personne.
- Montée d'année : `PromotionPlanner` implémenté par `People\YearPromotion` (crée des brouillons N+1 selon le paramètre `people.promotion.next_structure` et les admis lus par `AdmissionDirectory`), `BalanceCarryOver` (report des soldes), `ClosureCheck`. Carte d'étudiant : `Integrations\Contracts\CartescolaireExporter`, `DocumentIssuer`.
- Frais de première tranche : S04.

**3. Partiel / manquant.**
- Format du matricule : fixe `MAT-AAAA-NNNNN` (5 chiffres, séquence par établissement) ; le CDC veut année + code école + code filière + 4 chiffres. Le format n'est pas paramétrable et `SequenceService` ne connaît pas l'école/la filière.
- Passage N→N+1 : `YearPromotion` ne distingue que admis / non admis, avec une cible unique par structure. Le CDC exige décision à trois issues (admis, admis avec dettes, redouble), report des UE capitalisées, dettes inscrites d'office, exclusion au 2e redoublement. `AdmissionDirectory` accepte « Admis » et « Admis par compensation » seulement.
- Inscription pédagogique (choix d'UE/EC, contrat pédagogique) : inexistante. `PATCH /enrollments/{id}` limité aux brouillons : le changement de filière/groupe d'une inscription validée n'est pas possible. Pas de notion de niveau ni de groupe sur `enrollments` (`structure_id` seul).
- Gel d'inscription : statut `suspended` existe, sans motif/durée ni effet sur les frais.
- Limite d'inscriptions consécutives (RG-INS-5), double cursus (deux inscriptions vivantes la même année refusées par le socle : `assertNoLiveEnrollment` bloque), carte d'étudiant avec QR, blocage financier de l'inscription : à construire/étendre.

**4. À créer (module `Enrolment/`).** Tables `hi_enrolments` (extension 1-1 de `enrollments` : `enrollment_id`, programme, niveau, groupe, matricule IPES, `financial_hold`, `repeat_count`), `hi_learning_contracts` (inscription, UE inscrites/capitalisées/en dette, version de maquette), `hi_learning_contract_items` (UE/EC, origine : normale, dette, capitalisée), `hi_enrolment_events` (changements de filière/groupe, gel). Services : `MatriculeGenerator` (format paramétrable, séquence par clé année+école+filière, jamais de réutilisation), `EnrolStudent` (garde Higher + création/validation de l'inscription socle + contrat pédagogique), `ReenrolStudent` (exige une décision de passage publiée ; copie UE capitalisées ; dettes), `ChangeProgramOrGroup` (Should), `FreezeEnrolment` (Could), `ConsecutiveEnrolmentLimit` (RG-INS-5, dérogation tracée). Routes : `/higher/enrolments` (+ `/validate`, `/reenrol`, `/change-group`, `/freeze`), `/higher/learning-contracts`, `/higher/students/{id}/transcript-position`. Carte d'étudiant (DOC-05) via `DocumentIssuer` + `CartescolaireExporter`.

**5. Extensions du socle.** D3 (garde d'inscription extensible). D5 : `People\EnrollmentService::validate` doit accepter un fournisseur de numéro d'inscription (`EnrollmentNumberProvider` enregistrable par application) ; sinon deux numéros coexistent (`MAT-…` socle et matricule IPES). D7 : autoriser plusieurs inscriptions vivantes pour des filières distinctes (double cursus) via un paramètre `enrolment.allow_multiple_tracks`. D8 : `PromotionPlanner` : l'IPES enregistre son propre planificateur (la mécanique `ClosureProviders` existe déjà, résolution par contrat) ; vérifier qu'il peut REMPLACER `YearPromotion` pour le type `higher`.

**6. Risques / questions.** Q9 : matricule : l'année est-elle l'année d'entrée ou d'inscription ? Le code école/filière change-t-il le matricule en cas de changement de filière (RG-INS-1 dit « définitif ») ? Q10 : nombre maximal d'inscriptions consécutives et dérogation (RG-INS-5) : valeurs absentes. Q11 : transferts entrants et équivalences : algorithme absent du CDC. Q12 : étudiant « sous réserve financière » : statut socle `conditional` (pièces) ou indicateur financier distinct ? Je recommande l'indicateur distinct (`financial_hold`) pour ne pas mélanger.

---

## 4. MAQ : maquettes de formation, UE, EC, crédits

**1. Exigé.** EX-MAQ-01 à 06 : maquettes versionnées par filière, niveau et année ; UE et EC avec crédits, coefficients, volumes CM/TD/TP/travail personnel ; types d'UE (fondamentale, transversale, optionnelle, stage, projet) ; rattachement aux épreuves nationales (BTS/HND) ; contrôle de cohérence ; import/export Excel et copie d'année (Should). RG-MAQ-1 (maquette utilisée = nouvelle version, étudiants restent sur la version de leur inscription), RG-MAQ-2 (30 crédits par semestre en LMD, réglable), RG-MAQ-3 (conformité à la tutelle : contrôle humain).

**2. Déjà fourni.** Catalogue d'évaluation S09 : `subjects` (code, nom, `kind`, coefficient, crédits, actif) et `subject_elements` (nom, coefficient, crédits) ; routes `/subjects`, `/subjects/{id}`, `/subjects/{id}/elements`. Le moteur de calcul (`RuleEngine`) raisonne déjà sur « matière avec crédits » (= UE) composée de « groupes » (= EC, coefficient d'EC) ; compensation et capitalisation par crédits existent (`compensation_from`, `capitalize_from`, `weighting = credits`). Heures prévues : `staff_assignments.planned_minutes` + `session_kind` (cm, td, tp). `timetable_slots.course_unit_id` accroche un créneau à une unité d'enseignement ; `GET /timetable/consumption` calcule le réalisé et le restant par unité.

**3. Partiel / manquant.** Pas de maquette (niveau, semestre, année, version, statut brouillon/publié) ni de hiérarchie niveau > semestre > UE > EC ; `subjects` est plat, sans semestre, sans volumes, sans type IPES ; pas de versionnement ; pas de lien matière-filière (les évaluations portent `structure_id` + `subject_id`, mais le catalogue n'est pas rattaché à une structure). Aucun contrôle de crédits par semestre. Les `credits` sont entiers (suffisant pour LMD). Pas d'import Excel : `GradeCsv` existe pour les notes (CSV), pas d'Excel dans les dépendances (plan.md prévoyait `maatwebsite/excel`, indisponible hors Laravel : voir risque).

**4. À créer (module `Curriculum/`).** Tables `hi_curricula` (programme, niveau, année, version, statut, référence tutelle, `published_at`), `hi_semesters` (maquette, ordinal, crédits cibles), `hi_units` (UE : code, type, crédits, semestre, `subject_id` du socle), `hi_elements` (EC : code, crédits, coefficient, `cm_minutes`, `td_minutes`, `tp_minutes`, `personal_minutes`, `national_exam_subject`, `element_id` du socle), `hi_curriculum_versions` (instantané immuable à la publication). Services : `PublishCurriculum` (RG-MAQ-2 : 30 crédits ±, UE sans EC, somme des crédits d'EC = crédits d'UE, volumes), `NewVersionOnUse` (RG-MAQ-1 : si des notes existent, la modification crée une version ; l'inscription mémorise `curriculum_id`), `CopyCurriculum` (année N → N+1), import/export (CSV d'abord, XLSX si une bibliothèque compatible PHP 8.1 est approuvée : demande de dépendance Composer), `SyncToAssessment` (projette UE/EC vers `subjects`/`subject_elements` via le Contract à créer, D9), barème BTS/HND par matières et coefficients (même tables, `regime = bts|hnd`). Routes : `/higher/curricula` (+ `/publish`, `/copy`, `/export`, `/import`), `/higher/units`, `/higher/elements`.

**5. Extensions du socle.** D9 : Contract `Assessment\Contracts\CurriculumCatalog` (upsert idempotent `subject`/`element` par clé externe `hi_unit_id`/`hi_element_id`, désactivation, lecture) ; sans lui Higher ne peut ni créer ni lier les matières exigées par `assessments`. D10 : les règles de calcul déclaratives d'Assessment doivent couvrir ce que le CDC demande (voir moitié B pour le détail) : points/lettres/MGP, note éliminatoire par UE distincte du seuil de compensation, rattrapage remplaçant l'examen et conservant le contrôle continu, décisions 60/45/redoublement ; côté MAQ, la demande est que `subjects.kind` accepte les types d'UE et que le moteur lise les crédits depuis la version de maquette de l'inscription.

**6. Risques / questions.** Q13 : tolérance de la somme de crédits (exactement 30 ou intervalle ?), crédits fractionnaires ? Q14 : UE optionnelles : l'étudiant en choisit N parmi M — règle absente. Q15 : volume horaire d'un EC : heures présentielles seules ou avec travail personnel ? Q16 : 1 crédit = combien d'heures (pour contrôle de cohérence) ? Q17 : import Excel obligatoire en version 1 ? Risque : le moteur du socle n'a pas de notion de version de maquette par étudiant : la notion `rule_version` existe pour les règles, pas pour les matières.

---

## 5. EDT : emplois du temps

**1. Exigé.** EX-EDT-01 à 06 : emplois du temps par groupe, enseignant et salle ; détection des conflits (enseignant, salle, groupe, capacité) ; cours du soir et mutualisés ; suivi de consommation des volumes ; reports notifiés ; planning des examens (Should).

**2. Déjà fourni (quasi complet).** S07 : `/timetable-slots` (+ `/check`, `/export`), `/timetable` (semaine), `/timetable/consumption`, `/timetable-changes` (reports, annulations, remplacements), `/calendar-events`. `timetable_slot_structures` (un créneau pour plusieurs structures : cours mutualisés), `period_of_day`, `room_id`, `course_unit_id`. `ConflictDetector` : enseignant, salle, structure, capacité (`RoomCapacity`, effectif). Contrats : `TimetableDirectory` (`slot`, `slotsOn`, `teacherSlotsOn`), `TeacherClasses`, `SlotInfo`, `RoomCapacity`. Notifications de report : `Messenger`.

**3. Partiel / manquant.**
- Consommation : calcul par `course_unit_id` avec `plannedMinutes` ; il faut que `course_unit_id` désigne l'EC IPES et que le volume prévu (CM/TD/TP séparés) vienne de la maquette ; aujourd'hui un seul total en minutes.
- Création de créneaux depuis une application : pas de Contract d'écriture (D11) ; Higher ne peut pas produire des créneaux sans passer par HTTP interne.
- Planning des examens : possible comme événements de calendrier (`CalendarKind`), sans surveillance, sans salles multi-épreuves ni anonymat (liens avec DOC-08, moitié B).
- Effectif pour la capacité : repose sur les inscriptions par structure ; pour un groupe IPES à UE optionnelles, l'effectif réel est celui du contrat pédagogique.
- Pas de contrôle « enseignant affecté à cet EC » à la pose d'un créneau (le socle n'exige pas l'affectation).

**4. À créer (module `Teaching/`, partie EDT).** `TimetablePlanner` (service Higher) : propose/valide des créneaux pour un EC à partir des affectations, refuse un créneau si l'enseignant n'est pas affecté (règle IPES), calcule consommation CM/TD/TP par EC (agrégat de `TimetableDirectory` + volumes de maquette), alerte de dépassement ; `ExamPlanner` (Should) sur événements de calendrier ; vue mutualisée (une UE transversale pour plusieurs groupes). Routes : `/higher/timetable/plan`, `/higher/timetable/consumption`, `/higher/exam-schedules`.

**5. Extensions du socle.** D11 : Contract `TimetableWriter` (créer/modifier/supprimer un créneau avec détection de conflit, rattaché à `course_unit_id` et `session_kind`). D12 : `timetable_slots` : ajouter `session_kind` (cm/td/tp) pour la consommation par nature (aujourd'hui seulement `subject_label`). D13 : lecture des occurrences tenues d'un EC (déjà dans `ViewService::consumption`) à exposer par Contract.

**6. Risques / questions.** Q18 : créneaux de durées non entières, jours fériés (Cameroun) : calendrier des jours chômés fourni ? Q19 : cours du soir et alternance : plages horaires standard de l'IPES ? Q20 : génération automatique d'emploi du temps exclue (EX-S07-04 Could) : confirmer.

---

## 6. ENS : enseignants et vacations

**1. Exigé.** EX-ENS-01 à 08 : dossier enseignant, affectations par le chef de département, cahier de textes, émargement et contre-signature, validation mensuelle, état et paiement des vacations, contrat (Should), évaluation des enseignements (Could). RG-ENS-1 (heure payable seulement si émargée, contre-signée et reportée au cahier de textes), RG-ENS-2/3 (non précisées dans spec.md : taux par grade et niveau, retenues ?), RG-ENS-4 (heures au-delà du volume de la maquette payées après accord écrit du directeur d'école), RG-ENS-5 (solde de vacations retenu si les notes de l'EC ne sont pas toutes saisies). Exemple : 18 h CM + 12 h TD + 6 h TP à 6 000 FCFA/HETD = 43,5 HETD, 261 000 FCFA (TST-11).

**2. Déjà fourni (S10 Personnel).**
- Dossier : `/staff` (+ `/export`), `staff_members` (type permanent/vacataire/invité, statut, grade, catégorie, matricule employé, `department_id`, embauche/départ), `/staff/{id}/qualifications`. Contrats : `StaffDirectory` (`find`, `teacherIdsIn`), `StaffInfo`, `TeacherWorkload` (`assignmentsOf`, `hoursOf`).
- Affectations : `/staff/{id}/assignments` (`staff_assignments` : année, structure, matière, `session_kind`, `planned_minutes`, période, statut).
- Vacations : `/vacation-rates` (taux horaire et plafond mensuel par catégorie), `/vacation-contracts` (workflow soumettre/approuver/rejeter/résilier, volume maximal), `/vacation-hours` (déclarer, `sign` par l'enseignant, `validate` avec contre-signature, rejet), `/vacation-statements` (état mensuel, soumettre, approuver, `pay` avec référence), plafond de contrat et plafond mensuel contrôlés à la validation. Calcul : `VacationCalc` (CM 1,5 / TD 1 / TP 0,75 en millièmes, montant entier XAF arrondi au franc) : les valeurs de l'exemple sont les valeurs par défaut du socle (`SessionKind::hetdPermille`, codées en dur).
- Enregistrement des heures tenues : `TaughtHoursRecorder::record` (appelé quand une séance est validée côté présence : `Attendance\TaughtHoursReporter`).
- Congés : `/leave-requests`, `/leave-balances` ; portail enseignant `/portal/teacher`.

**3. Partiel / manquant.**
- HETD : coefficients non paramétrables (constantes d'enum) ; le CDC les veut réglables. Taux : par catégorie, pas par grade ET niveau (L, M, BTS, HND) comme demandé (T297) ; pas de retenues (« Aucune retenue » dans `VacationCalc`).
- Affectation : `staff_assignments` pointe `subject_id` (matière socle) ; pas de lien à l'EC IPES ni au groupe ; pas de contrôle « affectation par le chef de département de son département seulement ».
- Cahier de textes : absent (contenu, devoirs, signature enseignant `teacher_signed_at` existent dans `vacation_hours`, mais pas de cahier lié à la séance de présence). Contre-signature par le délégué ou le surveillant : le socle a `countersigned_at` mais la validation est faite par un agent ; le rôle « délégué » n'existe pas.
- Dépassement du volume de la maquette (RG-ENS-4) : le socle plafonne par contrat et par catégorie, pas par EC/maquette, et sans « accord écrit ».
- Rétention du solde si notes incomplètes (RG-ENS-5) : absente (nécessite une lecture de l'état de saisie par EC : `Portals\GradingTodo`/`GradeService`, pas de Contract adéquat).
- Autorisation de l'employeur pour vacataires fonctionnaires (T299), bulletin de vacation (DOC-26), état (DOC-25), contrat (DOC-24) : modèles de documents à déclarer ; le contrat existe côté données.
- Évaluation des enseignements (Could) : rien.

**4. À créer (module `Teaching/`, partie ENS).** Tables `hi_assignments` (staff, EC, groupe, nature, volume prévu, affecté par), `hi_session_logs` (cahier de textes : séance de présence ou créneau, contenu, devoirs, signature enseignant, contre-signature par rôle délégué/surveillant, lien `vacation_hours.id`), `hi_overrun_approvals` (heures au-delà du volume, document d'accord, validé par), `hi_vacation_holds` (retenue RG-ENS-5 par EC), `hi_vacation_params` (HETD par nature ; taux par grade × niveau), Could `hi_teaching_evaluations`. Services : `AssignTeaching` (chef de département limité à son département ; appelle le Contract d'écriture d'affectation du socle), `RecordSessionLog` (alimente `TaughtHoursRecorder`), `PayableHours` (applique RG-ENS-1/4/5 avant la validation mensuelle), `HetdCalculator` IPES (valeurs lues dans les paramètres, vérifié par TST-11), `VacationRateResolver` (grade × niveau). Routes : `/higher/assignments`, `/higher/session-logs` (+ `/sign`, `/countersign`), `/higher/vacations/payable`, `/higher/vacations/overrun-approvals`.

**5. Extensions du socle.** D14 : `VacationCalc`/`SessionKind` : lire les coefficients HETD dans les paramètres (`staff.hetd.cm|td|tp`, défaut 1,5/1/0,75). D15 : taux par grade et niveau (`vacation_rates.level` optionnel) ou résolveur enregistrable. D16 : point d'extension « conditions de payabilité » : l'application déclare des contrôles (`VacationPayabilityCheck`) appelés par `HoursService::validate` et `StatementService::approve`, pour RG-ENS-1/4/5 sans toucher au socle ensuite. D17 : Contract d'écriture d'affectation (`AssignmentWriter`) avec EC/groupe en clés externes. D18 : colonnes `element_ref` et `group_ref` sur `staff_assignments`.

**6. Risques / questions.** Q21 : RG-ENS-2/3 (spec.md ne les cite pas) : retenues fiscales (IRPP, CAC), bulletins ? Le CDC exclut la paie complète : où s'arrêtent les retenues ? Q22 : taux de vacation par grade : valeurs à fournir par le comptable (question ouverte du CDC). Q23 : contre-signature : par qui exactement (délégué étudiant ? surveillant ?) et comment authentifier (code, QR de séance) ? Q24 : HETD au-delà de 8 h/jour ou plafonds légaux ? Q25 : un enseignant est permanent dans un établissement et vacataire dans un autre (multi-établissement) : le dossier central `staff_members.home_establishment_id` le gère-t-il pour les vacations ?

---

## 7. PRE : présences et discipline

**1. Exigé.** EX-PRE-01 à 05 : appel par liste ou QR de séance, justificatifs d'absence, taux d'assiduité avec alertes, liste des non-autorisés à composer, sanctions avec conseil de discipline (Should). RG-PRE-1 (au-delà de 30 % d'absences NON justifiées d'un EC : non autorisé à composer, note 0, rattrapage selon règlement), RG-PRE-2 (non citée dans spec.md : codes ABJ/ABI/NA/FR et effets, ou exclusion définitive via procès-verbal du conseil). Codes : ABJ (rattrapage ou épreuve de remplacement), ABI et NA (0), FR (0 et saisine du conseil de discipline). Hors-ligne : synchronisation puis notification des garants consentants. Seuil BTS/HND d'éligibilité à l'examen national : 70 % (EXN, moitié B).

**2. Déjà fourni (S08 quasi complet).**
- Appel : `/attendance-sessions` (liste), `POST /attendance-sessions/sync` (hors-ligne, idempotence par `client_uuid`), `PUT /attendance-sessions/{id}/records`, `/validate`, `/qr-token`, `/scan` (jeton QR à durée `attendance.qr_window_minutes`), `/attendance-records`, exports.
- Justificatifs : `/absence-justifications` (+ accept/reject). Discipline : `/incidents`, `/sanctions` (+ approve/reject, barème `attendance.sanction_scale` paramétrable, y compris `conseil_discipline`).
- Assiduité : `GET /attendance/students/{id}/summary` (par matière, `by_subject`), `GET /attendance/not-allowed-to-sit`. Seuils : `attendance.max_unjustified_percent` (30 par défaut), `attendance.alert_unjustified_percent` (25), `attendance.notify_parents`. Contrat : `AttendanceSummaryProvider::forLearner(…, from, to)` → `AttendanceSummary` (séances, absences, non justifiées, retards, `unjustifiedRatePercent()`). Notification aux parents consentants : `AbsenceNotifier` + `ConsentChecker`.
- Lien avec les heures : `TaughtHoursReporter` → `TaughtHoursRecorder`.

**3. Partiel / manquant.**
- **Seuil par EC.** Le seuil 30 % du socle s'applique à une personne sur une plage de dates, toutes séances confondues (`aggregate(..., 'person')`), ou par libellé de matière (`by_subject`, clé = texte libre `subject_label`). Le CDC l'applique PAR EC (et sur l'année/le semestre de l'EC), avec effet sur la composition de CET EC. Il faut un agrégat par EC identifié (`course_unit_id` via `slot_id` ou une colonne `course_unit_id` sur `attendance_sessions`) et un Contract par EC.
- Codes d'absence ABJ/ABI/NA/FR : le socle connaît présent/absent/retard/excusé (`AttendanceStatus`, `justified`) ; pas de ces quatre codes ni d'épreuve de remplacement. Les codes ABJ/ABI/NA/FR du CDC sont des codes de NOTE (NOT, moitié B) ; seule la correspondance « absent justifié / injustifié » intéresse PRE.
- Calcul du dénominateur : « séances d'un EC » — séances prévues ou tenues ? Le socle compte les séances d'appel validées ; séances annulées/reportées à exclure (à vérifier avec `timetable-changes`).
- Conseil de discipline : `sanctions` + approbation, mais ni procès-verbal signé ni convocation ni composition du conseil ; l'exclusion définitive doit clore l'inscription (statut `excluded`) et exiger le PV : lien non câblé.
- QR de séance pour étudiants adultes sur smartphone personnel : le jeton existe ; contrôle anti-triche (géolocalisation, une seule présence) à examiner.
- Mise en œuvre de « non autorisé à composer → note 0 » : décision de calcul côté NOT/DEL, qui doit consommer un Contract par EC (D19).

**4. À créer (module `Attendance/` Higher, léger).** `hi_ec_assiduity` (vue/table matérialisée par inscription et EC : séances, absences non justifiées, taux, statut `ok|alert|barred`, version de seuil), `hi_barred_decisions` (levée de l'interdiction par dérogation tracée), `hi_discipline_councils` (convocation, membres, PV signé, décision), `hi_replacement_exams` (épreuve de remplacement suite à ABJ). Services : `EcAssiduityCalculator` (lit `AttendanceSummaryProvider` étendu), `BarredFromSitting` (consommé par saisie des notes et liste DOC-13/DOC-08), `DisciplineCouncil` (lie `sanctions` et exclusion). Seuils lus via `SettingsReader` : `attendance.max_unjustified_percent` (30), possibilité de surcharge par régime/école (§0.5) ; `higher.national_exam.min_attendance_percent` (70, moitié B). Routes : `/higher/assiduity/ec`, `/higher/assiduity/barred`, `/higher/discipline-councils`.

**5. Extensions du socle.** D19 : `AttendanceSummaryProvider::forLearnerByUnit(tenant, établissement, learner, courseUnitId, from, to)` et `unitsAbove(…)`; colonne `course_unit_id` sur `attendance_sessions` (héritée du créneau). D20 : paramètre de politique « séances prises en compte » (tenues seulement) ; D21 : exposer `AttendanceStatus` justifié/non justifié via des constantes de contrat.

**6. Risques / questions.** Q26 : le 30 % se calcule sur quelle base (séances prévues de l'EC sur le semestre, ou tenues à date) et à partir de quelle séance (alerte vs interdiction) ? Q27 : un étudiant « non autorisé à composer » reçoit 0 : en session normale seulement ou aussi au rattrapage (« rattrapage possible selon le règlement ») ? Q28 : l'absence non justifiée sans justificatif dans le délai se transforme-t-elle automatiquement (délai de dépôt) ? Q29 : alternance (entreprise) : présences en entreprise comptées ? Risque : le seuil 70 % (examens nationaux) et 30 % non justifié ne sont pas symétriques (un taux de présence vs un taux d'absences non justifiées) ; définir deux indicateurs distincts.

---

## 8. Synthèse chiffrée (48 exigences EX, 24 règles RG de la moitié A)

Comptage fait sur les plages de spec.md (EX-ACA 7, ADM 8, INS 8, MAQ 6, EDT 6, ENS 8, PRE 5 = 48 EX ; RG-ACA 3, ADM 4, INS 7, MAQ 3, ENS 5, PRE 2 = 24 RG). EDT n'a pas de RG. Classement par jugement sur le code lu, intitulés déduits (voir limite de sources).

| Module | EX couvertes (C) | EX partielles (P) | EX à créer (N) | RG à créer ou étendre |
|--------|------------------|-------------------|----------------|-----------------------|
| ACA | 1 (06) | 5 (01, 02, 03, 05, 07) | 1 (04) | 3 sur 3 (RG-ACA-1 étend la garde du socle) |
| ADM | 1 (06 notification) | 1 (03 pièces) | 6 | 4 sur 4 |
| INS | 2 (06 consentement, 05 carte) | 3 (01, 03, 04) | 3 (02, 07, 08 partiel) | 5 sur 7 (RG-INS-1, 3, 4, 5 + 6) ; RG-INS-7 couverte |
| MAQ | 0 | 2 (01, 02) | 4 | 3 sur 3 (RG-MAQ-3 = contrôle humain) |
| EDT | 4 (01, 02, 03 conflits/mutualisés/soir, 04 consommation) | 2 (05 reports via Contract, 06 examens) | 0 | 0 |
| ENS | 4 (01, 03 partiel, 05 validation, 06 paiement) | 3 (02, 04, 07 contrat) | 1 (08) | 4 sur 5 (RG-ENS-1 en partie couverte) |
| PRE | 3 (01, 02, 03 hors seuil par EC) | 2 (04, 05) | 0 | 1 sur 2 (RG-PRE-1 : seuil par EC) |
| **Total** | **15** | **18** | **15** | **20 sur 24** |

Soit environ 31 % des exigences de la moitié A couvertes par le socle, 38 % partielles, 31 % à créer. Les « partielles » le sont souvent faute de Contract d'écriture, pas faute de fonctionnalité.

## 9. Changements du socle à demander (sans les faire)

| # | Contract / changement | Module socle | Motif | Bloque |
|---|-----------------------|--------------|-------|--------|
| D1 | `StructureWriter` (créer/modifier filière, niveau, groupe) | Organisation | Higher ne peut pas créer de `pedagogical_structures` | ACA, tout |
| D2 | CRUD ou Contract des `authorizations` (niveaux, document) | Organisation | RG-ACA-1 par niveau | ACA, INS |
| D3 | Gardes d'inscription additionnelles enregistrables par application | Organisation / People | Tutelle LMD, niveau, blocage financier | INS |
| D4 | Contract d'émission de facture et de demande de paiement | Billing | Frais de dossier, première tranche | ADM, INS |
| D5 | Fournisseur de numéro d'inscription (matricule IPES) | People | Format paramétrable avec codes école/filière | INS |
| D6 | Portée « org_unit » des paramètres, ou clés suffixées | Organisation | RG-ORG-5 (surcharge par école) | ACA, tous |
| D7 | Inscriptions multiples pour filières distinctes (double cursus) | People | RG-ORG-6 | INS |
| D8 | Planificateur de montée remplaçable par application | People / Organisation | Décisions de passage, dettes, capitalisation | INS |
| D9 | `CurriculumCatalog` (upsert matières/éléments) | Assessment | UE/EC → `subjects` | MAQ, NOT |
| D10 | Moteur de règles étendu (points/lettres/MGP, éliminatoire, rattrapage) | Assessment | voir B | DEL |
| D11 | `TimetableWriter` | Scheduling | Pose de créneaux depuis Higher | EDT |
| D12 | `session_kind` sur `timetable_slots` | Scheduling | Consommation CM/TD/TP | EDT, ENS |
| D13 | Contract de lecture des occurrences tenues d'une unité | Scheduling | Consommation | EDT |
| D14 | HETD paramétrable | Staff | Coefficients réglables | ENS |
| D15 | Taux par grade et niveau | Staff | T297 | ENS |
| D16 | Points d'extension de « payabilité » des heures | Staff | RG-ENS-1/4/5 | ENS |
| D17 | `AssignmentWriter` (affectation avec EC/groupe) | Staff | EX-ENS-02 | ENS |
| D18 | Colonnes `element_ref`, `group_ref` sur `staff_assignments` | Staff | Idem | ENS |
| D19 | Assiduité par unité d'enseignement (Contract + colonne `course_unit_id`) | Attendance | RG-PRE-1 par EC | PRE, NOT |
| D20 | Paramètre de base du calcul (séances tenues) | Attendance | Cohérence du 30 % | PRE |
| D21 | Constantes de contrat de statut de présence | Attendance | Lecture sans dépendre du domaine | PRE |

Mécanisme recommandé : un dossier `Contracts/` par module avec une interface d'écriture « application » (jeton d'application) plutôt que d'ouvrir les services internes, pour respecter `StandardsTest`.

## 10. Questions à poser à l'utilisateur (récapitulatif)

1. Groupe d'écoles ou un seul IPES par tenant (Q1) ; qui valide le règlement figé (Q3) ?
2. Autorisation MINESUP par niveau ou par filière (Q2) ; une ou plusieurs tutelles actives (Q4) ?
3. Matricule : année d'entrée ou d'inscription, stable en cas de changement de filière (Q9) ?
4. Valeurs absentes du CDC : limite d'inscriptions consécutives (Q10), tolérance des 30 crédits et crédits fractionnaires (Q13), règle des UE optionnelles (Q14), heures par crédit (Q16).
5. Base de calcul du seuil de 30 % par EC et traitement du rattrapage (Q26, Q27) ; deux indicateurs (30 % non justifié, 70 % présence) confirmés ?
6. Vacations : retenues et grade × niveau (Q21, Q22), contre-signataire (Q23).
7. Concours, transferts, import Excel, évaluation des enseignements : version 1 ou après (Q6, Q11, Q17) ?
8. Compte du candidat avant admission (Q5).
9. Dépendance Excel : une bibliothèque XLSX compatible PHP 8.1 sans framework est-elle acceptée ? (le CDC prévoyait `maatwebsite/excel`, indisponible hors Laravel).
