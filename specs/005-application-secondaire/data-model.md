# Modèle de données : application Secondaire

Date : 2026-10-15. Préfixe **`se_`** (celui du CDC). Conventions ([HANDOVER](../HANDOVER.md) §3) : SQL portable MySQL 8 + SQLite ; `id CHAR(26)` ULID (`Scholaris\Core\Support\Ulid`) ; `tenant_id VARCHAR(40)` et `establishment_id VARCHAR(40)` sur **toutes** les tables ; dates ISO `VARCHAR(32)` (jours `VARCHAR(10)`) ; montants entiers XAF `INTEGER` ; notes `DECIMAL(5,2)` ; pas de clé étrangère, pas d'AUTO_INCREMENT, pas d'ENGINE ; aucun index sur TEXT/JSON ; aucun mot réservé MySQL (`rank`, `order`, `key`, `range`, `groups`, `condition`, `option`, `match`, `lines`, `rows`, `usage`, `interval`, `release`… → `rank_in_class`, `sort_order`, `ref_key`, `optional_subjects`, `matched_person_id`). Références vers le socle : identifiants opaques `VARCHAR(40)` (person_id, structure_id, period_id, staff_id, document_id, invoice_id), jamais lus en SQL.

**Restent dans le socle (décision D1)** : personnes, inscriptions, notes (`assessments`, `grades`, `grade_corrections`), résultats (`results`), conseils (`class_councils`, `council_lines`), présences, incidents, sanctions, frais, factures, paiements, documents, salles (`rooms`), créneaux. Les entités `se_students`, `se_enrolments`, `se_assessments`, `se_marks`, `se_absences`, `se_sanctions`, `se_period_results`, `se_council_decisions` du CDC sont remplacées par les tables du socle + les profils ci-dessous.

## 1. SQL des tables centrales

```sql
-- Setup : 202611010100_secondary_setup_core.sql
CREATE TABLE se_establishment_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_type VARCHAR(20) NOT NULL,          -- lycee_general, ces, lycee_technique, cetic, polyvalent
    ownership VARCHAR(20) NOT NULL,            -- public, private_secular, private_faith
    official_code VARCHAR(40) NULL,
    network_label VARCHAR(160) NULL,
    sections TEXT NOT NULL,                    -- JSON ["fr","en"]
    cycles TEXT NOT NULL,                      -- JSON ["first","second"]
    teaching_kinds TEXT NOT NULL,              -- JSON ["general","technical"]
    region_label VARCHAR(120) NULL,
    division_label VARCHAR(120) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX se_establishment_profiles_one ON se_establishment_profiles (tenant_id, establishment_id);

CREATE TABLE se_streams (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    stream_kind VARCHAR(12) NOT NULL,          -- series, specialty
    code VARCHAR(20) NOT NULL,                 -- A4, C, D, TI, ELEC...
    label VARCHAR(160) NOT NULL,
    cycle_code VARCHAR(10) NOT NULL,
    section_code VARCHAR(4) NOT NULL,
    authorization_id VARCHAR(40) NULL,         -- Organisation authorizations [PRI]
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
    cycle_code VARCHAR(10) NOT NULL,
    level_code VARCHAR(20) NOT NULL,           -- 6e, form1, 1A_tech, 2nde, l6...
    stream_id CHAR(26) NULL,
    exam_code VARCHAR(20) NULL,                -- bepc, cap, proba, bac, bt, gce_ol, gce_al
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
    locked INTEGER NOT NULL DEFAULT 0,         -- RG-ORG-4
    source_ref VARCHAR(120) NULL,              -- texte MINESEC
    version_no INTEGER NOT NULL,
    valid_from VARCHAR(10) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_subject_coefficients_lookup ON se_subject_coefficients (tenant_id, establishment_id, level_code, version_no);

-- Enrolment : 202611010200_secondary_enrolment_core.sql
CREATE TABLE se_student_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    national_id VARCHAR(40) NULL,              -- matricule cartescolaire.cm, jamais dans une URL
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

-- CarteScolaire : 202611010300_secondary_cartescolaire.sql
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

-- Fees : 202611010400_secondary_fees.sql
CREATE TABLE se_fee_catalog (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,     -- '*' = tout le client
    fee_kind VARCHAR(20) NOT NULL,             -- apee, boarding, canteen, transport...
    label VARCHAR(160) NOT NULL,
    max_amount INTEGER NULL,
    cycle_code VARCHAR(10) NULL,
    legal_basis VARCHAR(255) NULL,             -- texte ou PV d'assemblée APEE
    valid_from VARCHAR(10) NOT NULL,
    valid_to VARCHAR(10) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_fee_catalog_kind ON se_fee_catalog (tenant_id, establishment_id, fee_kind);

-- Councils : 202611010900_secondary_councils.sql
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

-- Workshops : 202611011100_secondary_workshops.sql
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
    final_score DECIMAL(5,2) NOT NULL,         -- plafonné (secondary.workshop.safety_cap)
    assessment_id VARCHAR(40) NULL,            -- épreuve du socle alimentée
    evaluated_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX se_practical_evaluations_one ON se_practical_evaluations (tenant_id, session_id, person_id);
```

## 2. Autres tables (même en-tête : `id`, `tenant_id`, `establishment_id`, `created_at`, `updated_at` si modifiable)
| Module | Table | Colonnes clés | Index unique / remarque |
|--------|-------|---------------|-------------------------|
| Setup | se_room_profiles | room_id, room_kind (classroom, lab, workshop), specialty_stream_id, safety_notes | (tenant, room_id) |
| Enrolment | se_preregistrations | person_id, target_level_code, stream_id, channel (online, desk), entry_test_score DECIMAL(5,2), status, documents_checked TEXT | — |
| Enrolment | se_transfers | person_id, direction (in, out), other_school_label, exeat_document_id, national_id_checked INTEGER, file_ref, status | — |
| Enrolment | se_orientations | person_id, academic_year_id, wishes TEXT, counsellor_opinion, council_opinion, proposed_stream_id, final_stream_id, interview_on | (tenant, person, year) |
| CarteScolaire | se_cs_imports | import_kind (national_ids, payments), file_name, file_hash CHAR(64), rows_total, rows_ok, rows_rejected, imported_by | (tenant, estab, file_hash) |
| CarteScolaire | se_cs_discrepancies | person_id NULL, national_id NULL, discrepancy_kind (missing_remote, missing_local, identity), detail, resolved_at, resolved_by | — |
| CarteScolaire | se_cs_exports | export_kind (year_end, roster), academic_year_id, file_hash, document_id, exported_by | — |
| Fees | se_fee_requests | fee_kind, label, amount, justification, requested_by, status (pending, accepted, refused), decided_by | RG-FIN-2, journal |
| Fees | se_payment_plans | person_id, invoice_id, installments TEXT, approved_by, status | — |
| Syllabus | se_progressions | subject_id, level_code, stream_id, academic_year_id, total_hours DECIMAL(6,2), status | (tenant, estab, subject, level, stream, year) |
| Syllabus | se_progression_lessons | progression_id, sort_order, title, planned_hours DECIMAL(5,2), planned_week INTEGER | — |
| Syllabus | se_lesson_logs | structure_id, subject_id, slot_id, session_date, lesson_id, content, homework, hours DECIMAL(4,2), signed_by, signed_at, client_uuid | (tenant, client_uuid) |
| Syllabus | se_lesson_visas | structure_id, subject_id, visa_role (censor, head_of_dept), visa_on, remark, visa_by | — |
| Discipline | se_conduct_proposals | person_id, period_id, unjustified_hours DECIMAL(6,2), proposed_kind, scale_version, sanction_id NULL, status | (tenant, person, period, proposed_kind) |
| Discipline | se_discipline_councils | person_id, convened_on, hearing_on, members TEXT, facts, decision_kind, decision_reason, minutes_document_id, notification_document_id, status | — |
| Discipline | se_class_exclusions | person_id, session_id, slot_id, reason, reported_by | repli si SS10 absent |
| Grades | se_common_tests | level_code, subject_id, period_id, organised_by, assessment_ids TEXT | — |
| Grades | se_subject_appraisals | person_id, period_id, subject_id, appraisal_text, author_person_id | (tenant, person, period, subject) |
| Grades | se_appraisal_bank | scope (subject, general), language_code, min_average DECIMAL(5,2), text_value | — |
| Councils | se_decision_appeals | person_id, council_id, lodged_on, deadline_on, grounds, decision, decided_by, decision_reason | — |
| OfficialExams | se_exam_sessions | exam_code, academic_year_id, stream_id, registration_deadline, fee_deadline, export_format_ref | (tenant, estab, exam, year, stream) |
| OfficialExams | se_exam_candidates | session_id, person_id, optional_subjects TEXT, photo_ok, national_id_ok, fee_status (seen, missing), documents_ok, status (draft, ready, blocked, exported) | (tenant, session, person) |
| OfficialExams | se_exam_results | candidate_id, outcome (passed, failed, absent), distinction_label, subject_grades TEXT, imported_from | (tenant, candidate) |
| Workshops | se_workshops | stream_id, room_id, label, capacity, chief_person_id | — |
| Workshops | se_workstations | workshop_id, label, machine INTEGER, ppe_required TEXT, status | — |
| Workshops | se_workshop_sessions | workshop_id, structure_id (groupe), sheet_id, session_date, teacher_person_id, attendance TEXT (poste ou observation, RG-ATL-1), client_uuid | — |
| Workshops | se_practical_sheets | stream_id, title, objective, materials TEXT, tools TEXT, safety_rules TEXT, criteria TEXT, version_no | — |
| Workshops | se_material_items | workshop_id, label, unit_label, unit_cost INTEGER, stock_qty DECIMAL(10,2) | — |
| Workshops | se_material_moves | item_id, move_kind (in, out), quantity DECIMAL(10,2), session_id NULL, unit_cost INTEGER, moved_by | — |
| Workshops | se_safety_events | workshop_id, event_kind (incident, ppe_check, machine_check), person_id NULL, workstation_id NULL, description, occurred_at | — |
| Workshops | se_companies | label, sector, address, contact_name, contact_phone | — |
| Workshops | se_internships | person_id, company_id, tutor_name, starts_on, ends_on, agreement_document_id, tutor_appraisal TEXT, report_received_on, score DECIMAL(5,2), assessment_id | — |
| Workshops | se_internship_visits | internship_id, visitor_person_id, visited_on, notes | — |
| Staff | se_staff_profiles | staff_id, employment_status (civil_servant, state_contract, private_employee, hourly), grade_label, payroll_ref | (tenant, staff_id) |
| Staff | se_payslips | staff_id, month_ref VARCHAR(7), gross_amount, deductions TEXT, net_amount, status (draft, validated, paid), validated_by, document_id | (tenant, estab, staff, month_ref) |
| Services | se_boarding_rooms | label, capacity, supervisor_person_id | — |
| Services | se_service_subscriptions | person_id, service_kind (boarding, canteen, transport), room_or_line, stop_label, starts_on, ends_on, invoice_id | — |
| Services | se_infirmary_visits | person_id, visited_at, reason_code, pe_exemption_until, parent_called INTEGER, nurse_person_id, notes_encrypted TEXT | santé : infirmier seul |
| Services | se_library_items | title, author_label, isbn, copies INTEGER | — |
| Services | se_library_loans | item_id, person_id, loaned_on, due_on, returned_on | — |
| Notifications | se_notification_log | notification_code (NOT-01…), person_id, ref_id, sent_at, channel | (tenant, code, person, ref_id) : idempotence |

**Total : 50 tables `se_*`** (9 en SQL ci-dessus, 41 décrites). Migrations nommées `AAAAMMJJHHMM_secondary_<module>_*.sql`, une colonne par `ALTER TABLE` pour toute évolution ; `MysqlCompatibilityTest` et `SecondaryConventionsTest` vérifient préfixe, colonnes obligatoires et mots réservés.

## 3. Paramètres (pas de table)
`secondary.discipline.scale` (barème RG-PRE-1), `secondary.workshop.safety_cap` (8), `secondary.promotion.*` (10, 9, 8 pratique), `secondary.admission.age_limits` (RG-INS-2), `secondary.carte_scolaire.columns.*`, `secondary.carte_scolaire.year_end_deadline`, `secondary.grades.letter_scale` [EN], `secondary.appeal_days` (EX-PAS-04), `secondary.referentials.*` (niveaux, séries, spécialités, examens), `secondary.blocking.*` [PRI] (EX-FIN-06).
