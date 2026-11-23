# Modèle de données : application Enseignement secondaire (un produit, 7 types)

Date : 2026-10-22 (remplace la version du 2026-10-15). Aucun code ni migration n'est écrit ici : ce sont les schémas à réaliser.

## Résumé pour les non-techniciens
- L'application garde ses **propres tables** (préfixe `se_`) pour ce que le socle ne porte pas : profil de l'établissement, séries et spécialités, coefficients, cahier de textes, discipline, examens, ateliers, stages, paie, services annexes.
- **Nouveauté** : le profil d'établissement enregistre le **type** (une des 7 valeurs), la **propriété** (public, privé laïc, privé confessionnel), la **liste des modules actifs** et la **version du profil de type** appliqué ; une table de **journal** garde chaque changement avec son motif.
- Notes, résultats, conseils, présences, sanctions, frais, factures, paiements et documents **restent dans le socle** : on ne les duplique pas.
- Les **profils de type** et les **barèmes** ne sont pas des tables : ce sont des **paramètres** (§3), donc modifiables sans développement.

## 0. Conventions
Préfixe **`se_`**. Conventions du dépôt ([HANDOVER](../HANDOVER.md) §3) : SQL portable MySQL 8 et SQLite ; `id CHAR(26)` (ULID) ; `tenant_id VARCHAR(40)` et `establishment_id VARCHAR(40)` sur **toutes** les tables ; dates ISO `VARCHAR(32)` (jours `VARCHAR(10)`) ; montants entiers en XAF ; notes `DECIMAL(5,2)` ; pas de clé étrangère, pas d'AUTO_INCREMENT, pas d'ENGINE ; aucun index sur TEXT ; **aucun mot réservé MySQL** (`rank`, `order`, `key`, `range`, `groups`, `condition`, `option`, `match`, `lines`, `rows`, `usage`, `interval`, `release`… → `rank_in_class`, `sort_order`, `ref_key`, `optional_subjects`, `matched_person_id`) ; une colonne par `ALTER TABLE`. Références vers le socle : identifiants opaques `VARCHAR(40)`, jamais lus en SQL. Migrations : `src/Modules/Apps/Secondary/<Module>/Database/Migrations/AAAAMMJJHHMM_secondary_<module>_*.sql`, horodatage ≤ date de fusion.

**Restent dans le socle** : personnes, inscriptions, notes (`assessments`, `grades`, `grade_corrections`), résultats (`results`), conseils (`class_councils`, `council_lines`), présences, incidents, sanctions, frais, factures, paiements, documents, salles, créneaux. Les entités `se_students`, `se_enrolments`, `se_assessments`, `se_marks`, `se_absences`, `se_sanctions`, `se_period_results`, `se_council_decisions` du CDC sont remplacées par les tables du socle et les profils ci-dessous.

## 1. Profil d'établissement (nouveau et modifié) : `Setup/Database/Migrations/…_secondary_setup_core.sql`
```sql
CREATE TABLE se_establishment_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subtype VARCHAR(24) NOT NULL,              -- leg, lycee_technique, ces, cetic, college_prive, lycee_prive, autre (copie de establishments.establishment_subtype)
    profile_version INTEGER NOT NULL,          -- version du profil de type copié à l'ouverture
    ownership VARCHAR(20) NOT NULL,            -- public, private_secular, private_faith (obligatoire pour « autre »)
    official_code VARCHAR(40) NULL,
    network_label VARCHAR(160) NULL,
    sections TEXT NOT NULL,                    -- JSON ["fr","en"]
    cycles TEXT NOT NULL,                      -- JSON ["first","second"]
    teaching_kinds TEXT NOT NULL,              -- JSON ["general","technical"]
    modules TEXT NOT NULL,                     -- JSON des modules actifs (liste fermée, spec.md §1.3)
    head_title VARCHAR(20) NOT NULL,           -- vocabulaire : principal, proviseur
    region_label VARCHAR(120) NULL,
    division_label VARCHAR(120) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX se_establishment_profiles_one ON se_establishment_profiles (tenant_id, establishment_id);

-- Journal des changements de profil (type, propriété, cycles, modules) : motif obligatoire, rien n'est supprimé.
CREATE TABLE se_profile_changes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    change_kind VARCHAR(20) NOT NULL,          -- opened, subtype, ownership, cycles, modules, sections
    previous_value TEXT NULL,                  -- JSON
    new_value TEXT NOT NULL,                   -- JSON
    reason VARCHAR(255) NOT NULL,
    changed_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_profile_changes_estab ON se_profile_changes (tenant_id, establishment_id, created_at);
```
Règles : `subtype` ne prend que les 7 codes ; `modules` ne contient que les codes connus ; une valeur absente de `cycles` interdit les classes de ce cycle ; `profile_version` permet de proposer la mise à jour du profil quand la plateforme le fait évoluer.

## 2. Autres tables centrales
```sql
CREATE TABLE se_streams (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    stream_kind VARCHAR(12) NOT NULL,          -- series (général), specialty (technique)
    teaching_kind VARCHAR(12) NOT NULL,        -- general, technical
    code VARCHAR(20) NOT NULL,                 -- code de la liste de référence de la plateforme
    label VARCHAR(160) NOT NULL,
    cycle_code VARCHAR(10) NOT NULL,
    section_code VARCHAR(4) NOT NULL,
    authorization_id VARCHAR(40) NULL,         -- registre des autorisations [authorizations]
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX se_streams_code ON se_streams (tenant_id, establishment_id, section_code, code);

CREATE TABLE se_class_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,         -- classe du socle
    academic_year_id VARCHAR(40) NOT NULL,
    section_code VARCHAR(4) NOT NULL,
    teaching_kind VARCHAR(12) NOT NULL,
    cycle_code VARCHAR(10) NOT NULL,           -- doit figurer dans se_establishment_profiles.cycles
    level_code VARCHAR(20) NOT NULL,           -- code de la liste de référence
    stream_id CHAR(26) NULL,
    exam_code VARCHAR(20) NULL,                -- code de la liste de référence des examens
    head_teacher_person_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX se_class_profiles_structure ON se_class_profiles (tenant_id, structure_id, academic_year_id);

CREATE TABLE se_subject_coefficients (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subject_id VARCHAR(40) NOT NULL,           -- matière du socle
    level_code VARCHAR(20) NOT NULL,
    stream_id CHAR(26) NULL,
    coefficient DECIMAL(5,2) NOT NULL,
    weekly_hours DECIMAL(5,2) NOT NULL,
    subject_role VARCHAR(12) NOT NULL,         -- official, project, practical, internship
    origin VARCHAR(10) NOT NULL,               -- example, official, custom (tant que non fourni par le client : example)
    locked INTEGER NOT NULL DEFAULT 0,
    source_ref VARCHAR(120) NULL,              -- texte de référence fourni par le client
    version_no INTEGER NOT NULL,
    valid_from VARCHAR(10) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_subject_coefficients_lookup ON se_subject_coefficients (tenant_id, establishment_id, level_code, version_no);

CREATE TABLE se_student_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    national_id VARCHAR(40) NULL,              -- matricule national, jamais dans une adresse ni une page publique
    national_id_status VARCHAR(12) NOT NULL,   -- pending, recorded, mismatch
    national_id_recorded_at VARCHAR(32) NULL,
    repeater INTEGER NOT NULL DEFAULT 0,
    final_exclusion_on VARCHAR(10) NULL,       -- RG-INS-3
    special_situation VARCHAR(255) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX se_student_profiles_person ON se_student_profiles (tenant_id, establishment_id, person_id);
CREATE UNIQUE INDEX se_student_profiles_national ON se_student_profiles (tenant_id, establishment_id, national_id);

CREATE TABLE se_cs_payments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    import_id CHAR(26) NOT NULL,
    national_id VARCHAR(40) NOT NULL,
    payment_kind VARCHAR(20) NOT NULL,         -- contribution, exam_fee
    exam_code VARCHAR(20) NULL,
    amount INTEGER NOT NULL,
    paid_on VARCHAR(10) NOT NULL,
    external_ref VARCHAR(80) NOT NULL,
    matched_person_id VARCHAR(40) NULL,
    match_status VARCHAR(12) NOT NULL,         -- matched, unknown, duplicate
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX se_cs_payments_ref ON se_cs_payments (tenant_id, establishment_id, external_ref);

CREATE TABLE se_fee_catalog (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,     -- '*' = tout le client
    fee_kind VARCHAR(20) NOT NULL,             -- apee, boarding, canteen, transport...
    label VARCHAR(160) NOT NULL,
    max_amount INTEGER NULL,
    cycle_code VARCHAR(10) NULL,
    legal_basis VARCHAR(255) NULL,             -- texte ou procès-verbal d'assemblée de l'APEE
    valid_from VARCHAR(10) NOT NULL,
    valid_to VARCHAR(10) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_fee_catalog_kind ON se_fee_catalog (tenant_id, establishment_id, fee_kind);

CREATE TABLE se_report_snapshots (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    council_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    payload TEXT NOT NULL,                     -- JSON figé : moyennes, rangs, appréciations, décision
    payload_hash CHAR(64) NOT NULL,
    document_id VARCHAR(40) NULL,
    rectifies_id CHAR(26) NULL,                -- RG-BUL-3
    rectified_reason VARCHAR(255) NULL,
    decided_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_report_snapshots_person ON se_report_snapshots (tenant_id, person_id, period_id);

CREATE TABLE se_practical_evaluations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    sheet_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    criteria_scores TEXT NOT NULL,             -- JSON [{criterion, max_points, observed}]
    raw_total DECIMAL(5,2) NOT NULL,
    serious_safety_breach INTEGER NOT NULL DEFAULT 0,
    final_score DECIMAL(5,2) NOT NULL,         -- plafonné selon le paramètre secondary.workshop.safety_cap
    assessment_id VARCHAR(40) NULL,            -- épreuve du socle alimentée
    evaluated_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX se_practical_evaluations_one ON se_practical_evaluations (tenant_id, session_id, person_id);
```

## 3. Autres tables (même en-tête : `id`, `tenant_id`, `establishment_id`, `created_at`, `updated_at` si modifiable)
| Module | Table | Colonnes clés | Index unique / remarque |
|--------|-------|---------------|-------------------------|
| Setup | se_room_profiles | room_id, room_kind (classroom, lab, workshop), specialty_stream_id, safety_notes | (tenant, room_id) ; `workshop` seulement si le module `workshops` est actif |
| Enrolment | se_preregistrations | person_id, target_level_code, stream_id, channel (online, desk), entry_test_score DECIMAL(5,2), status, documents_checked TEXT | test d'entrée : PRI |
| Enrolment | se_transfers | person_id, direction (in, out), other_school_label, exeat_document_id, national_id_checked INTEGER, file_ref, status | — |
| Enrolment | se_orientations | person_id, academic_year_id, wishes TEXT, counsellor_opinion, council_opinion, proposed_stream_id, final_stream_id, interview_on | (tenant, person, year) ; module `orientation` |
| CarteScolaire | se_cs_imports | import_kind (national_ids, payments), file_name, file_hash CHAR(64), rows_total, rows_ok, rows_rejected, imported_by | (tenant, estab, file_hash) |
| CarteScolaire | se_cs_discrepancies | person_id NULL, national_id NULL, discrepancy_kind (missing_remote, missing_local, identity), detail, resolved_at, resolved_by | — |
| CarteScolaire | se_cs_exports | export_kind (year_end, roster), academic_year_id, file_hash, document_id, exported_by | — |
| Fees | se_fee_requests | fee_kind, label, amount, justification, requested_by, status (pending, accepted, refused), decided_by | RG-FIN-2, journal ; module `fees_public_catalog` |
| Fees | se_payment_plans | person_id, invoice_id, installments TEXT, approved_by, status | module `fees_private` |
| Syllabus | se_progressions | subject_id, level_code, stream_id, academic_year_id, total_hours DECIMAL(6,2), status | (tenant, estab, subject, level, stream, year) |
| Syllabus | se_progression_lessons | progression_id, sort_order, title, planned_hours DECIMAL(5,2), planned_week INTEGER | — |
| Syllabus | se_lesson_logs | structure_id, subject_id, slot_id, session_date, lesson_id, content, homework, hours DECIMAL(4,2), signed_by, signed_at, client_uuid | (tenant, client_uuid) |
| Syllabus | se_lesson_visas | structure_id, subject_id, visa_role (censor, head_of_dept), visa_on, remark, visa_by | — |
| Discipline | se_conduct_proposals | person_id, period_id, unjustified_hours DECIMAL(6,2), proposed_kind, scale_version, sanction_id NULL, status | (tenant, person, period, proposed_kind) |
| Discipline | se_discipline_councils | person_id, convened_on, hearing_on, members TEXT, facts, decision_kind, decision_reason, minutes_document_id, notification_document_id, status | — |
| Discipline | se_class_exclusions | person_id, session_id, slot_id, reason, reported_by | repli si le socle n'ajoute pas de statut d'appel (SS10) |
| Grades | se_common_tests | level_code, subject_id, period_id, organised_by, assessment_ids TEXT | — |
| Grades | se_subject_appraisals | person_id, period_id, subject_id, appraisal_text, author_person_id | (tenant, person, period, subject) |
| Grades | se_appraisal_bank | scope (subject, general), language_code, min_average DECIMAL(5,2), text_value | tranches en données, jamais en code |
| Councils | se_decision_appeals | person_id, council_id, lodged_on, deadline_on, grounds, decision, decided_by, decision_reason | — |
| OfficialExams | se_exam_sessions | exam_code, academic_year_id, stream_id, registration_deadline, fee_deadline, export_format_ref | (tenant, estab, exam, year, stream) |
| OfficialExams | se_exam_candidates | session_id, person_id, optional_subjects TEXT, photo_ok, national_id_ok, fee_status (seen, missing), documents_ok, status (draft, ready, blocked, exported) | (tenant, session, person) |
| OfficialExams | se_exam_results | candidate_id, outcome (passed, failed, absent), distinction_label, subject_grades TEXT, imported_from | (tenant, candidate) |
| Workshops | se_workshops | stream_id, room_id, label, capacity, chief_person_id | module `workshops` |
| Workshops | se_workstations | workshop_id, label, machine INTEGER, ppe_required TEXT, status | — |
| Workshops | se_workshop_sessions | workshop_id, structure_id (groupe), sheet_id, session_date, teacher_person_id, attendance TEXT (poste ou observation, RG-ATL-1), client_uuid | — |
| Workshops | se_practical_sheets | stream_id, title, objective, materials TEXT, tools TEXT, safety_rules TEXT, criteria TEXT, version_no | — |
| Workshops | se_material_items | workshop_id, label, unit_label, unit_cost INTEGER, stock_qty DECIMAL(10,2) | — |
| Workshops | se_material_moves | item_id, move_kind (in, out), quantity DECIMAL(10,2), session_id NULL, unit_cost INTEGER, moved_by | — |
| Workshops | se_safety_events | workshop_id, event_kind (incident, ppe_check, machine_check), person_id NULL, workstation_id NULL, description, occurred_at | — |
| Workshops | se_companies | label, sector, address, contact_name, contact_phone | module `internships` |
| Workshops | se_internships | person_id, company_id, tutor_name, starts_on, ends_on, agreement_document_id, tutor_appraisal TEXT, report_received_on, score DECIMAL(5,2), assessment_id | — |
| Workshops | se_internship_visits | internship_id, visitor_person_id, visited_on, notes | — |
| Staff | se_staff_profiles | staff_id, employment_status (civil_servant, state_contract, private_employee, hourly), grade_label, payroll_ref | (tenant, staff_id) |
| Staff | se_payslips | staff_id, month_ref VARCHAR(7), gross_amount, deductions TEXT, net_amount, status (draft, validated, paid), validated_by, document_id | (tenant, estab, staff, month_ref) ; module `payroll_simple` ; jamais pour un fonctionnaire |
| Services | se_boarding_rooms | label, capacity, supervisor_person_id | module `boarding` |
| Services | se_service_subscriptions | person_id, service_kind (boarding, canteen, transport), room_or_line, stop_label, starts_on, ends_on, invoice_id | — |
| Services | se_infirmary_visits | person_id, visited_at, reason_code, pe_exemption_until, parent_called INTEGER, nurse_person_id, notes_encrypted TEXT | module `infirmary` ; santé : infirmier seul |
| Services | se_library_items | title, author_label, isbn, copies INTEGER | module `library` |
| Services | se_library_loans | item_id, person_id, loaned_on, due_on, returned_on | — |
| Notifications | se_notification_log | notification_code (NOT-01…), person_id, ref_id, sent_at, channel | (tenant, code, person, ref_id) : idempotence |

**Total : 51 tables `se_*`** (les 10 de §1 et §2 en SQL, 41 décrites en §3). Les tests `MysqlCompatibilityTest` et `SecondaryConventionsTest` vérifient préfixe, colonnes obligatoires et mots réservés.

## 4. Paramètres (pas de table)
Aucun de ces paramètres n'a de valeur officielle livrée : vide, ou valeur d'exemple étiquetée « exemple (à confirmer) ».
| Paramètre | Rôle |
|---|---|
| `secondary.type_profiles.<code>` (7 codes) | profil de type par défaut : cycles, enseignements, propriété, modules, vocabulaire, examens proposés |
| `secondary.referentials.*` | niveaux, séries, spécialités, matières, examens (listes de la plateforme) |
| `secondary.discipline.scale` | barème des heures d'absence et sanctions proposées (RG-PRE-1) |
| `secondary.workshop.*` | critères de la grille pratique, base de la note, plafond de sécurité |
| `secondary.promotion.*` | seuils de passage, bande d'examen par le conseil, redoublement, âge limite, seuil de pratique |
| `secondary.admission.age_limits` | âge d'admission par niveau (RG-INS-2) |
| `secondary.carte_scolaire.columns.*`, `secondary.carte_scolaire.year_end_deadline` | formats de fichiers et date limite |
| `secondary.grades.letter_scale` | table note et lettre [EN] |
| `secondary.appeal_days` | délai de recours |
| `secondary.blocking.*` | blocages de remise de documents [fees_private] |
| `secondary.sequences_per_trimester`, `secondary.trimesters` | découpage de l'année |
| `billing.allocation_kind_order`, `billing.allocation_sibling_order` | affectation des paiements (SS7) |
| `assessment.step`, `RuleSet::scale`, `rounding`, `round_each_step` | pas de saisie, barème, arrondi |
