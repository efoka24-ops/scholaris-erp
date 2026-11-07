# Tasks : Socle commun SCHOLARIS

**Input** : `specs/001-socle-plateforme/` (spec, plan, research, data-model, contracts)
**Tests** : demandés par la constitution (principe III) pour calculs, paiements, autorisations et isolation : écrits avant l'implémentation.
**Format** : `[ID] [P?] [US?] Description avec chemin`. `[P]` = parallélisable (fichiers différents, sans dépendance).
**Chemins** : racine du dépôt ; modules sous `src/Modules/Socle/<Module>/`.

> **Mise à jour du 2026-10-08 (ADR-0001)** : le projet est en **PHP 8.1 sans framework**, standards PSR. Les tâches ci-dessous gardent les termes du CDC ; les lire selon ces équivalences : FormRequest = classe `Request` du module ; Policy = classe `Policy` du module ; JsonResource = classe `Resource` ; Eloquent / portée globale = dépôt PDO avec `TenantContext` obligatoire ; Sanctum = jetons opaques hachés ; Fortify = TOTP `spomky-labs/otphp` ; spatie/permission = `PermissionResolver` du module `Identity` ; activitylog = `AuditLogger` ; Horizon/Redis = table `jobs` + `artisan queue:work` ; Scramble = contrat OpenAPI écrit ; Pest = PHPUnit ; Larastan = PHPStan ; Pint = PHP-CS-Fixer ; `app/Core`, `app/Modules` = `src/Core`, `src/Modules` ; `routes/api` = `routes.php` par module. Détail : [adr-0001-php81-sans-framework.md](adr-0001-php81-sans-framework.md).

---

## Phase 1 : Setup (cadrage et projet)

- [x] T001 Sauvegarder l'ancien code : `git checkout -b archive/nestjs-nextjs` puis commit de l'état actuel (y compris les modifications non commitées) ; revenir sur `preprod`
- [x] T002 Installer PHP 8.4 dans le PATH (remplacer 8.1.34), Composer 2, extensions requises ; documenter dans `README.md`
- [x] T003 Supprimer l'ancien code (`apps/`, `packages/`, `erp/`, `php/`, `monitoring/`, `nginx/`, scripts `.ts/.mjs/.ps1` obsolètes, rapports `RAPPORT_*.md`) en conservant `.specify/`, `.claude/`, `specs/`, `CDC_Plateforme_SCHOLARIS.pdf`, `scripts/deploy-ftp.ps1` (après T001, avec confirmation)
- [x] T004 Créer le projet PHP à la racine : `composer.json` (PHP ^8.1), PSR-4 `Scholaris\` → `src/`, point d'entrée `public/index.php`, `Kernel`, fuseau `Africa/Douala` (remplace le projet Laravel essayé puis abandonné, voir ADR-0001)
- [x] T005 [P] Réécrire `.gitignore` et `.env.example` (aucune valeur réelle) ; vérifier qu'aucun mot de passe n'est dans l'historique à partir de ce commit
- [x] T006 [P] Installer et configurer PHP-CS-Fixer (PSR-12), PHPStan (niveau 6 puis relever), PHPUnit 10.5 : `.php-cs-fixer.dist.php`, `phpstan.neon`, `phpunit.xml`
- [x] T007 [P] Créer la structure `src/Core/`, `src/Modules/Socle/*`, `src/Modules/Apps/*` (dossiers + `Contracts/`) ; les routes des modules sont découvertes par le `Kernel` (`src/Modules/*/*/routes.php`)
- [~] T008 [P] Configurer la base : variables MySQL dans `.env.example` ; tests sur SQLite en mémoire pour l'instant. Reste : installer un MySQL 8 local (Docker ou Laragon) pour les tests de concurrence (T071) et la CI MySQL
- [x] T009 [P] Workflow CI `.github/workflows/ci.yml` : Pint, Larastan, Pest, `composer audit`
- [x] T010 Corriger `scripts/deploy-ftp.ps1` : `RemoteRoot = "/"`, mot de passe lu depuis une variable d'environnement (`$env:SCHOLARIS_FTP_PASSWORD`), exclusion de `.env`, `.git`, `tests`, `node_modules`
- [x] T011 (vérifié le 2026-10-09 : http://scholariserp.trugroup.cm/api/v1/health répond status ok, base ok) Déployer un « hello API » sur Camoo (`GET /api/v1/health`) pour valider PHP, FTP, `.htaccess`, base et cron ; relever la version PHP du serveur
- [x] T012 DÉCISION (2026-10-09, utilisateur) : front en Vue 3 + Vite, PWA ; seul un front statique est possible sur Camoo ; consignée dans `research.md` (R12)

---

## Phase 2 : Fondations transverses (bloque toutes les histoires)

**⚠️ Aucune histoire avant la fin de cette phase.**

### Tests d'abord
- [x] T013 [P] Test d'architecture : un module `Apps/*` ne référence que `Socle/*/Contracts` dans `tests/Arch/ModuleBoundariesTest.php`
- [x] T014 [P] Test Problem Details (422, 403, 404, 500 avec `trace_id`) dans `tests/Feature/Core/ProblemDetailsTest.php`
- [x] T015 [P] Test d'idempotence (rejeu → même réponse, charge différente → 422) dans `tests/Feature/Core/IdempotencyTest.php`
- [x] T016 [P] Test de portée tenant (modèle sans tenant → exception ; lecture croisée impossible) dans `tests/Isolation/TenantScopeTest.php`

### Implémentation
- [x] T017 SPIKE (tranché : `role_assignments` + `PermissionResolver` maison, voir research R4) : `spatie/laravel-permission` (teams) vs `role_assignments` maison ; consigner dans `research.md` (R4)
- [x] T018 [P] Trait `BelongsToTenant` + `TenantContext` dans `app/Core/Tenancy/`
- [x] T019 [P] Middleware `ResolveEstablishment` (en-tête `X-Establishment-Id` contrôlé contre les rôles) dans `app/Core/Http/Middleware/`
- [x] T020 [P] Gestionnaire d'exceptions RFC 9457 + `trace_id` dans `app/Core/Errors/` et `bootstrap/app.php`
- [x] T021 [P] Middleware `Idempotency` + table `idempotency_keys` dans `app/Core/Http/Middleware/`
- [x] T022 [P] Pagination par curseur, filtres et tris en liste blanche : `app/Core/Http/Concerns/ListsResources.php`
- [x] T023 [P] Middleware de langue (`Accept-Language` fr/en) et fichiers `lang/fr`, `lang/en`
- [x] T024 [P] Limitation de débit nommée (login, otp, payment) dans `app/Providers/RouteServiceProvider.php`
- [x] T025 Service de numérotation sans trou `app/Core/Numbering/SequenceService.php` + migration `number_sequences`
- [x] T026 Journal d'audit chaîné : migration `audit_log`, `app/Modules/Socle/Audit/AuditLogger.php`, commande `audit:verify`
- [x] T027 Test de la chaîne d'audit (altération détectée) dans `tests/Feature/Audit/AuditChainTest.php`
- [x] T028 Service d'approbation à deux personnes `app/Modules/Socle/Audit/Approvals/` (demandeur ≠ validateur) + test
- [x] T029 [P] OpenAPI : installer `dedoc/scramble`, route `/docs/api` désactivée en production
- [x] T030 [P] En-têtes de sécurité (CSP, HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy) et CORS restreint dans `config/cors.php` et middleware
- [x] T031 Interfaces des ports d'intégration : `SmsGateway`, `MailGateway`, `PaymentGateway`, `PdfRenderer` avec simulateurs dans `app/Modules/Socle/Integrations/`
- [x] T032 [P] Suite d'isolation générique : parcourt toutes les routes `/api/v1` avec un utilisateur d'un autre client et exige 403/404 dans `tests/Isolation/AllRoutesIsolationTest.php`

**Checkpoint** : fondations prêtes.

**Réalisé (emplacements réels)** : `src/Core/Http/` (RouteRegistry, middlewares RouteResolver, Authenticate, RateLimit, ResolveEstablishment, Idempotency, Locale, Cors, ErrorHandler, ListQuery, HttpException) ; `src/Core/Tenancy/` (TenantContext, TenantTable) ; `src/Core/Security/` (Principal, Authenticator, EstablishmentAccess) ; `src/Core/Numbering/SequenceService.php` ; `src/Core/Database/Migrator.php` ; `src/Modules/Socle/Audit/` (AuditLog chaîné, Approvals) ; `src/Modules/Socle/Integrations/` (4 ports + simulateurs) ; `database/migrations/` et `…/Database/Migrations/` ; `lang/` ; contrat `contracts/openapi.yaml` (maintenu à la main, vérifié par `tests/Architecture/OpenApiContractTest.php`). 70 tests, PHPStan niveau 6 sans erreur.
**Limite connue** : tests exécutés sur SQLite en mémoire ; la concurrence réelle (numérotation, chaîne d'audit, idempotence) reste à rejouer sur MySQL (tâche T008).


---

## Phase 3 : US1 Identité et accès (P1) 🎯 MVP

**Objectif** : connexion, activation, 2FA, rôles à périmètre, changement de contexte.
**Test indépendant** : deux clients, connexion, contextes, 403/404 croisé.

### Tests
- [x] T033 [P] [US1] Activation par OTP (5 min, 3 essais) dans `tests/Feature/Identity/OtpActivationTest.php`
- [x] T034 [P] [US1] Verrouillage après 5 échecs + alerte dans `tests/Feature/Identity/LockoutTest.php`
- [x] T035 [P] [US1] 2FA obligatoire pour rôles sensibles dans `tests/Feature/Identity/TwoFactorTest.php`
- [x] T036 [P] [US1] Changement de contexte sans reconnexion dans `tests/Feature/Identity/ContextSwitchTest.php`
- [x] T037 [P] [US1] Action interdite forcée → 403 dans `tests/Feature/Identity/ForbiddenActionTest.php`

### Implémentation
- [x] T038 [US1] Migrations : `tenants`, `people` (squelette), `users`, `one_time_codes`, `devices`, `role_assignments`, `service_accounts` dans `app/Modules/Socle/Identity/Database/Migrations/`
- [x] T039 [P] [US1] Modèles et factories `User`, `RoleAssignment`, `OneTimeCode`, `Device`
- [x] T040 [US1] Action `ActivateAccount` (OTP via port SMS/e-mail) + règles de mot de passe (10 car., contrôle des fuites, Argon2id) dans `app/Modules/Socle/Identity/Actions/`
- [x] T041 [US1] Authentification Sanctum : `POST /auth/login`, `/auth/logout`, `DELETE /me/sessions` dans `Http/Controllers/AuthController.php`
- [x] T042 [US1] 2FA TOTP + codes de secours (Fortify) dans `Http/Controllers/TwoFactorController.php`
- [x] T043 [US1] Verrouillage temporaire et alerte nouvel appareil (événement `NewDeviceLoggedIn`)
- [x] T044 [US1] Résolveur de permissions par périmètre `app/Modules/Socle/Identity/Access/PermissionResolver.php` (selon T017)
- [x] T045 [US1] `GET /me`, `GET /me/contexts`, `POST /me/context` + `ContextResource`
- [x] T046 [US1] Policies de base et rôles standards (seeder `RoleSeeder`) incluant Super Admin, direction de groupe, directeur, comptable, enseignant, parent, apprenant
- [x] T047 [US1] Délégation temporaire (Should) et comptes de service à clés tournantes
- [x] T048 [US1] Documentation OpenAPI des routes d'identité

**Checkpoint** : US1 testable seule.

---

## Phase 4 : US2 Organisation et paramétrage (P1)

**Test indépendant** : groupe + 3 établissements, héritage et surcharge tracée.

- [x] T049 [P] [US2] Test d'héritage et de surcharge des paramètres dans `tests/Feature/Organisation/SettingsInheritanceTest.php`
- [x] T050 [P] [US2] Test : un établissement a un seul type ; clôture d'année isolée par établissement
- [x] T051 [P] [US2] Test : inscription refusée sans autorisation valide
- [x] T052 [US2] Migrations `establishments`, `org_units`, `academic_years`, `periods`, `pedagogical_structures`, `authorizations`, `settings`, `rooms`
- [x] T053 [P] [US2] Modèles, factories, enums `EstablishmentType`, `PeriodKind`
- [x] T054 [US2] `SettingsService` à trois niveaux (`get`, `set`, `effective`) avec trace d'audit dans `app/Modules/Socle/Organisation/Services/`
- [x] T055 [US2] Contrôleurs, FormRequests, Resources, Policies : `/establishments`, `/org-units`, `/academic-years`, `/periods`, `/structures`, `/settings`, `/authorizations`, `/rooms`
- [x] T056 [US2] Identité de l'établissement (logo, couleurs, en-têtes, signataires) avec stockage privé
- [x] T057 [US2] Activation des modules par établissement (`modules_enabled`) et middleware `EnsureModuleEnabled`
- [x] T058 [US2] Contrat d'application : `ApplicationDescriptor` (vocabulaire, structure, règles, documents) et registre dans `app/Core/Applications/`

---

## Phase 5 : US3 Personnes, relations, inscriptions (P1)

- [x] T059 [P] [US3] Test de détection de doublons dans `tests/Feature/People/DuplicateDetectionTest.php`
- [x] T060 [P] [US3] Test des consentements (révocation, majeur) dans `tests/Feature/People/ConsentTest.php`
- [x] T061 [P] [US3] Test d'accès : un parent ne voit que ses enfants dans `tests/Isolation/ParentScopeTest.php`
- [x] T062 [US3] Migrations `people`, `person_contacts`, `person_relations`, `person_identifiers`, `person_documents`, `health_records`, `consents`, `enrollments`
- [x] T063 [P] [US3] Modèles avec champs sensibles chiffrés (`encrypted` cast)
- [x] T064 [US3] Service `PersonService` : création, doublons, rattachements, historique de scolarité
- [x] T065 [US3] `/people`, `/people/{id}/relations`, `/people/{id}/documents`, `/consents`, `/enrollments` (Controllers, Requests, Resources, Policies)
- [x] T066 [US3] Dossier documentaire : téléversement contrôlé (type, taille), URL signée, contrôle de complétude, expiration
- [x] T067 [US3] Import de personnes depuis Excel/CSV avec rapport d'erreurs
- [x] T068 [US3] Matricule national optionnel (champ prêt pour cartescolaire.cm)

---

## Phase 6 : US4 Frais, facturation, paiements (P1)

### Tests d'abord
- [x] T069 [P] [US4] Affectation automatique (retards d'abord, puis plus anciennes) `tests/Unit/Billing/AllocationTest.php`
- [x] T070 [P] [US4] Idempotence des paiements `tests/Feature/Billing/PaymentIdempotencyTest.php`
- [x] T071 [P] [US4] Numérotation de reçus sans trou sous concurrence (100 encaissements) `tests/Feature/Billing/ReceiptNumberingTest.php`
- [x] T072 [P] [US4] Annulation : second validateur obligatoire `tests/Feature/Billing/ReversalApprovalTest.php`
- [x] T073 [P] [US4] Webhook : signature, IP, rejeu `tests/Feature/Billing/PaymentWebhookTest.php`
- [x] T074 [P] [US4] Remises de fratrie inter-établissements `tests/Unit/Billing/SiblingDiscountTest.php`

### Implémentation
- [x] T075 [US4] Migrations `fee_items`, `fee_schedules`, `installments`, `invoices`, `invoice_lines`, `discounts`, `payment_references`, `payments`, `allocations`, `receipts`, `cash_sessions`, `reversals`, `reminders`
- [x] T076 [P] [US4] Modèles, enums `PaymentChannel`, `PaymentStatus`, factories
- [x] T077 [US4] Catalogue, grilles, échéanciers : actions `CreateFeeSchedule`, `GenerateInvoices`
- [x] T078 [US4] Moteur de remises et bourses (règles paramétrables via `SettingsService`)
- [x] T079 [US4] `AllocationService` (règle paramétrable, version de règle enregistrée)
- [x] T080 [US4] `RecordPayment` transactionnel : paiement + affectations + reçu + audit
- [x] T081 [US4] Génération du reçu PDF (via `PdfRenderer`) et SMS
- [x] T082 [US4] Caisse : ouverture, arrêté, écarts, versements
- [x] T083 [US4] Annulation par écriture inverse + approbation (utilise T028)
- [x] T084 [US4] Webhook `POST /webhooks/payments/{provider}` avec simulateur et vérification de signature/IP
- [x] T085 [US4] Paiement multi-enfants multi-établissements en une opération (Should)
- [~] T086 [US4] Relances planifiées avant/après échéance (tâche planifiée `reminders:send`) Reste : enregistrer la commande console `reminders:send` (le service `ReminderService::run()` du module Billing est prêt)
- [x] T087 [US4] Blocages paramétrables en cas d'impayé (résultats, documents, réinscription) ; jamais l'accès aux cours
- [x] T088 [US4] Rapprochement des encaissements mobile money avec relevé importé (CSV)
- [x] T089 [US4] Export comptable (journal des encaissements, ventes, remises) en CSV
- [x] T090 [US4] Routes `/fee-items`, `/fee-schedules`, `/invoices`, `/payments`, `/receipts`, `/cash-sessions`, `/reversals`, `/reconciliations`

---

## Phase 7 : US5 Communication (P2)

- [x] T091 [P] [US5] Test de ciblage + consentement + plage 7 h–21 h `tests/Feature/Messaging/DeliveryRulesTest.php`
- [x] T092 [US5] Migrations `message_templates`, `messages`, `message_deliveries`, `sms_usage`, `announcements`
- [x] T093 [US5] Modèles bilingues et rendu de variables
- [x] T094 [US5] `AudienceResolver` (personne, classe, niveau, établissement, impayés, absents du jour)
- [x] T095 [US5] Job `SendMessage` : canaux SMS, e-mail, in-app via ports ; WhatsApp en phase 11
- [x] T096 [US5] Suivi de délivrance et compteur SMS par établissement
- [x] T097 [US5] `/message-templates`, `/messages`, `/announcements` ; messagerie interne modérée (Should) (vérifié 2026-10-09 : modèles, messages, annonces, conversations, signalement et masquage ; `TeachingScope` réel = affectations Staff + emploi du temps Scheduling, `DirectionDirectory` réel = rôles `directeur`/`directeur_adjoint` via le contrat Identity `RoleDirectory`)

---

## Phase 8 : US6 Documents officiels (P2)

- [x] T098 [P] [US6] Test génération en lot (file) + numéro unique `tests/Feature/Documents/BatchGenerationTest.php`
- [x] T099 [P] [US6] Test page publique de vérification (valide, révoqué, inconnu) `tests/Feature/Documents/PublicVerificationTest.php`
- [x] T100 [US6] Migrations `document_templates`, `documents`, `document_batches`
- [x] T101 [US6] Éditeur de modèles HTML avec champs dynamiques (liste blanche de variables)
- [x] T102 [US6] Implémentation `DompdfRenderer` du port `PdfRenderer` (vérifié : vrai PDF Dompdf sans réseau, choisi par `documents.pdf_renderer` ou par défaut en production ; `tests/Unit/Documents/RealRenderingTest.php`)
- [x] T103 [US6] Génération à l'unité et en lot via file ; QR code et numéro unique (vérifié : `POST /documents`, lots traités par `documents:process-jobs`, QR réel `LibraryQrCodeGenerator`, index unique sur le numéro et le jeton)
- [x] T104 [US6] `GET /public/verify/{token}` limité en débit, sans donnée personnelle superflue
- [x] T105 [US6] Registre des documents, révocation, signature simple (Should), demande en ligne avec paiement (Should)
- [x] T106 [US6] Exports Excel/CSV de toutes les listes principales (trait `ExportsList`, écrivain `XlsxWriter` ; `?format=csv|xlsx`, csv par défaut) (2026-10-09 : CSV UTF-8 BOM `;` et Excel .xlsx sur People personnes/inscriptions, Billing factures/paiements/reçus, Attendance séances/présences/justificatifs/incidents/sanctions, Assessment notes/résultats, Staff personnel/heures/contrats/relevés/congés, Scheduling créneaux/calendrier/modifications, Documents registre/lots/demandes, Messaging modèles/messages/journal d'envoi/annonces/conversations signalées, Organisation établissements/unités/années/périodes/structures/autorisations/salles, Audit demandes de personnes concernées et journal d'audit (permission `privacy.export`, sans états avant/après, IP, appareil ni empreintes) ; 5 000 lignes au plus ; hors périmètre : listes secondaires de configuration et de référence — grilles de frais, séances de caisse, types et modèles de documents, affectations/qualifications/taux du personnel, personnes autorisées à récupérer, comptes Identity, Reporting/Portails/Intégrations — à ajouter au besoin)

---

## Phase 9 : US7 Calendrier, emplois du temps, présences (P2)

- [x] T107 [P] [US7] Test de conflits d'emploi du temps (enseignant, salle, classe) `tests/Unit/Scheduling/ConflictTest.php`
- [x] T108 [P] [US7] Test d'appel hors-ligne rejoué sans doublon `tests/Feature/Attendance/OfflineSyncTest.php`
- [x] T109 [US7] Migrations `calendar_events`, `timetable_slots`, `timetable_changes`, `attendance_sessions`, `attendance_records`, `incidents`, `sanctions` (Scheduling livré : `calendar_events`, `timetable_slots` + `timetable_slot_structures`, `timetable_changes` ; le reste relève d'Attendance)
- [x] T110 [US7] `ConflictDetector` et `/timetable-slots`, `/calendar-events`, `/timetable-changes` (notifications aux concernés)
- [x] T111 [US7] `/attendance-sessions` avec `client_uuid` idempotent, justificatifs
- [x] T112 [US7] Notification des parents le jour même (paramétrable)
- [x] T113 [US7] Incidents et sanctions avec circuit de validation (Should)

---

> **Ordonnancement (voir [analysis.md](../analysis.md), C1 et C2)** : dans la phase 10, livrer d'abord le cycle de vie des notes (T116 à T119, T328), puis le moteur de règles (T120 à T124) en parallèle de l'application IPES. Le dossier du personnel et les affectations (T330) sont avancés dans la phase 9, car le module Enseignants de l'IPES (lot I2) en dépend avant la phase 11.

- [x] T328 [US8] Rendre le cycle de vie des notes paramétrable par application (liste d'états, rôle autorisé à chaque transition, étape exigeant une 2FA récente) : le socle fournit le défaut de EX-S09-05, l'IPES déclare le sien (brouillon, soumise, validée, verrouillée, publiée)
- [x] T329 [US1] Ajouter le rôle de niveau groupe « Direction de groupe » au socle et aligner les libellés de rôles avec la maquette (R02 Directeur général, R04 Directeur d'école)
- [x] T330 [US7] Migrations et API de base du personnel (`staff_members`, `contracts`, `assignments`, `teaching_logs`), `/staff`, cahier de textes : à réaliser en phase 9 avant la phase 11

## Phase 10 : US8 Moteur d'évaluation (P2)

- [x] T114 [P] [US8] Tests unitaires du moteur de règles déclaratives (moyenne pondérée, rang, crédits, compensation) `tests/Unit/Assessment/RuleEngineTest.php`
- [x] T115 [P] [US8] Test du cycle de vie d'une note (bornes, verrouillage, correction approuvée) `tests/Feature/Assessment/GradeLifecycleTest.php`
- [x] T116 [US8] Migrations `subjects`, `subject_elements`, `assessments`, `grades`, `grade_events`, `calculation_rules`, `results`, `deliberations`
- [x] T117 [US8] Référentiel, types d'épreuves pondérés, `/subjects`, `/assessments`
- [x] T118 [US8] Saisie en grille, import Excel, `client_uuid` pour hors-ligne, contrôles (bornes, élèves inconnus)
- [x] T119 [US8] Cycle saisie → validation (verrou) → publication ; demande de correction avec second validateur
- [x] T120 [US8] `RuleEngine` : définitions JSON versionnées par année ; exécution déterministe
- [x] T121 [US8] Calcul des résultats, rangs, décisions ; `/results`
- [x] T122 [US8] Délibérations / conseils de classe avec proposition, décision motivée, PV (document S06)
- [x] T123 [US8] Évaluations qualitatives (compétences, appréciations) ; anonymat des copies (Should)
- [x] T124 [US8] Historique complet par note (`grade_events`) consultable

---

## Phase 11 : US9 Personnel et vacations (P3)

- [x] T125 [P] [US9] Test du calcul de vacation (heures cahier de textes × taux) `tests/Unit/Staff/VacationCalcTest.php`
- [x] T126 [US9] Migrations `vacation_rates`, `vacation_statements`, `leaves` (phase 11). Les tables `staff_members`, `contracts`, `assignments`, `teaching_logs` sont créées plus tôt par T330 (voir ci-dessous)
- [x] T127 [US9] Dossier du personnel multi-établissements ; `/staff`
- [x] T128 [US9] Cahier de textes et état de vacations ; validation par chef de département/directeur avant paiement
- [x] T129 [US9] Congés et absences (Should) ; paie simplifiée (Could, reportée) (vérifié : demandes avec Approvals, soldes par type, calendrier, `tests/Feature/Staff/LeaveTest.php` ; la paie simplifiée reste reportée)

---

## Phase 12 : US10 Portails, tableaux de bord, audit, intégrations

- [x] T130 [P] [US10] Test : un parent voit tous ses enfants, tous établissements du groupe, sur un écran `tests/Feature/Portal/ParentOverviewTest.php` (livré : tests/Feature/Portals/FamilyPortalTest.php)
- [x] T131 [US10] API portail parent : `/portal/parent/overview` (soldes, absences, résultats publiés, documents) (livré sous /portal/family)
- [x] T132 [US10] API portails apprenant et enseignant (vérifié : `/portal/learner`, `/portal/teacher` ; saisies de notes à faire fournies par Assessment via `portals.grading_todo.assessment`)
- [x] T133 [US10] Tableaux de bord par rôle et vue consolidée groupe `/dashboards/{role}` (livré : /dashboards/{direction,censeur,groupe} ; /me/todo agrégé)
- [x] T134 [US10] Rapports planifiés envoyés par e-mail, statistiques réglementaires (Should) (vérifié : planification, commande `reports:run`, envoi par e-mail ; statistiques par année, classe et sexe ; la ventilation par niveau reste indisponible car aucune liaison classe-niveau n'existe dans Organisation)
- [x] T135 [US10] Registre des traitements, `/data-requests` (export, rectification, suppression)
- [x] T136 [US10] Export complet d'un client en format ouvert (`tenant:export`), test de volumétrie (2026-10-09 : archive ZIP de JSON, une source à liste blanche de colonnes par module enregistrée sous `privacy.tenant_data_source.<module>` : Identity sans hachage ni secret TOTP ni jeton, Organisation, People, Billing, Messaging, Documents, Scheduling, Attendance, Assessment, Staff, Reporting, Audit ; effacement par personne pour People, Messaging, Identity ; `tests/Feature/Audit/CompleteTenantExportTest.php` : multi-modules, aucun secret, isolation entre clients, 10 000 lignes de 1 Ko à mémoire bornée par lots)
- [x] T137 [US10] Clôture d'année : archivage, report des soldes, passage en classe supérieure (vérifié de bout en bout avec les vrais fournisseurs : `tests/Feature/Organisation/RealYearClosureTest.php` ; demande puis approbation à deux personnes avec 2FA récente)
- [ ] T138 [US10] Intégrations : adaptateur SMS réel, agrégateur mobile money réel, WhatsApp (Should), cartescolaire.cm, tutelle, export comptable (Should) selon décisions ouvertes (2026-10-09 : `sungku:check` sans appel réseau et procédure de mise en service, webhook HTTPS requis, rien d'activé ; ports `CartescolaireExporter` et `TutelleExporter` à sortie fichier CSV/JSON générique à colonnes configurables, `external:export` ; export comptable CSV `journal`/`sales`/`discounts` déjà livré par Billing ; voir `integrations-externes.md`. Case laissée ouverte : dépend de tiers (clé et secret Sungku, certificat TLS, formats ou API de cartescolaire.cm et de la tutelle, fournisseur SMS, compte WhatsApp Business))
- [ ] T139 [US10] Front et PWA (hors-ligne, installation) selon décision T012 et la maquette `design.md` : tâches détaillées dans une spécification dédiée (constat 2026-10-09, `web/` non modifié : Vue 3 + Vite + vite-plugin-pwa, manifeste et service worker générés, file hors ligne IndexedDB pour appel et notes, écrans connexion/2FA, accueil, tableau de bord, appel, notes, emploi du temps, personnes, facturation, messages, documents, portails famille et apprenant, confidentialité, administration ; manquent notamment l'écran du portail enseignant et les écrans personnel/vacations et rapports ; travail en cours, case laissée ouverte ; 2026-10-09 : livrés portail enseignant, personnel/vacations/congés, rapports planifiés et statistiques réglementaires, boutons d'export CSV/Excel sur les listes ; reste la fidélité aux maquettes et les écrans manquants du socle, suivis par un autre chantier, et la vérification visuelle)
- [x] T152 [US2] Valider `accent` d'établissement (hex, contraste ≥ 4,5:1 avec texte noir) dans `Organisation/Http/Requests/UpdateBrandingRequest.php` + test
- [x] T153 [US2] Endpoint `GET /api/v1/vocabulary` : dictionnaire des termes par type d'établissement (voir `design.md`)
- [x] T154 [US1] Enrichir `/me/contexts` (accent, logo, période en cours, 2 KPI par tuile) et ajouter `GET /me/todo` (approbations, signatures, saisies en attente) (2026-10-09 : contrat `Organisation\Contracts\CurrentPeriod` implémenté par `PdoCurrentPeriod`, branché dans les tuiles d'Identity, champ `period` omis si inconnu ; `tests/Feature/Identity/ContextPeriodTest.php`, `ContextSwitchTest.php`)
- [x] T155 [US2] Alerte d'expiration des autorisations de filière (30 jours) exposée au tableau de bord

---

## Phase 13 : Finition

- [ ] T140 [P] Passage Larastan au niveau maximal et corrections (2026-10-09 : PHPStan niveau 8 sur `src`, `config`, `public`, 0 erreur, ni baseline ni ignore ; niveau 9 mesuré : 1 643 erreurs, essentiellement des `mixed` issus des lignes PDO et des tableaux de configuration, dans tous les modules : correction module par module à planifier, case laissée ouverte)
- [ ] T141 [P] Test de charge (k6) : p95 < 300 ms, 1 000 PDF en lot < 10 min
- [ ] T142 [P] Revue sécurité OWASP API Top 10 et ASVS niveau 2 ; scan ZAP sur la recette (2026-10-09 : revue par lecture du code dans `security-review.md`, failles simples corrigées et testées : `.htaccess`, en-têtes de sécurité et CORS absents des réponses d'erreur, corps JSON non borné ; restent le scan ZAP, ASVS point par point, l'adresse réelle derrière mandataire)
- [x] T143 Sauvegardes quotidiennes chiffrées et test de restauration (2026-10-09 : `backup:keygen`, `backup:run` (dump logique PHP, gzip, chiffrement sodium secretstream, rétention), `backup:restore` atomique ; test de restauration sur SQLite `tests/Feature/Audit/BackupTest.php` ; cron documenté dans `sauvegardes.md`. Actions humaines : clé `BACKUP_KEY` sur le serveur, cron quotidien, copie hors serveur, test de restauration sur MySQL)
- [ ] T144 Migration MySQL → PostgreSQL : jeu de migrations validé sur les deux moteurs, RLS ajoutée, bascule documentée (2026-10-09 : état de portabilité, reste à faire et bascule proposée dans `migration-postgresql.md` ; rien migré)
- [ ] T145 Remplacement DomPDF → Gotenberg, files → Redis/Horizon pour la production (2026-10-09 : préparé, interfaces et étapes dans `passage-gotenberg-redis.md` ; impossible sur l'hébergement actuel)
- [ ] T146 Exécuter `quickstart.md` de bout en bout et corriger les écarts
- [ ] T147 Mettre à jour le CDC avec les écarts consignés (2026-10-09 : écarts recensés dans `cdc-ecarts.md` ; le report dans le document du CDC lui-même reste à faire par son propriétaire)

---

## Dépendances et ordre

- Phase 1 → Phase 2 → toutes les histoires. US1 (identité) précède toutes les autres car elle fournit utilisateurs, rôles et contexte.
- US2 (organisation) avant US3 (personnes) ; US3 avant US4 (frais), US5, US7, US8.
- US4 dépend de US2, US3 et des ports d'intégration (T031) ; US6 dépend de US2 et alimente US4 (reçus) et US8 (PV).
- US7 et US8 peuvent avancer en parallèle après US3 ; US9 dépend de US7 (cahier de textes).
- US10 consomme toutes les autres.
- Les tâches [P] d'une même phase sont parallélisables ; les tests (rouges) précèdent l'implémentation.

## Stratégie

**MVP** = Phases 1, 2, 3 (identité + isolation) puis 4 et 5. Chaque histoire est livrée, testée et déployée sur la recette Camoo avant la suivante. L'application Supérieur (IPES) démarre après la Phase 5 (S10 du CDC), en parallèle des phases 6 à 9, avec sa propre spécification.

## Risques à traiter avant de démarrer

1. **Base distante inaccessible** depuis le poste (utilisateur MySQL limité à `localhost`) : développement sur MySQL local, migrations sur Camoo via SSH ou script.
2. **PHP du PATH = 8.1.34** : installer/activer 8.4 (T002) ; vérifier la version PHP offerte par Camoo (T011).
3. **Secrets** : les identifiants FTP et MySQL ont été partagés en clair dans la conversation ; les changer après la mise en place et les ranger hors du dépôt.
4. **Suppression de l'ancien code** (T003) : irréversible hors Git ; faire T001 d'abord.
