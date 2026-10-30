# Modèle de données : modules communs (`pub_`) et Primaire publique (`pp_`)

Date : 2026-10-15. Conventions (HANDOVER §3, `tests/Architecture/MysqlCompatibilityTest.php`) : SQL portable MySQL 8 + SQLite ; ULID `CHAR(26)` ; `tenant_id VARCHAR(40)` sur toute table, `establishment_id VARCHAR(40)` sur les tables d'école ; dates ISO `VARCHAR(32)` (jour seul `VARCHAR(10)`) ; montants entiers XAF ; booléens `INTEGER` 0/1 ; pas de FK, d'AUTO_INCREMENT, d'ENGINE ; une colonne par `ALTER TABLE` ; aucun index sur TEXT ; aucun mot réservé (`sort_order`, `starts_on`, `ends_on`, `level_code`, `year_label`…). Tables du socle demandées : voir [socle-reuse.md](socle-reuse.md) (PP1, PP4-PP8, PP10-PP12, PP16). Préfixe `pn_` réservé à la Maternelle (aligné avec `maternelle/couverture-socle.md`).

Correspondance avec le CDC (« Modèle de données propre ») : `pu_territories` → socle `organisation_territory_versions` + PP1 ; `pu_schools` → `pub_schools` ; `pu_infrastructures` → `pub_infrastructure_years` ; `pu_pupils` → socle `people` + PP8 + `pub_pupil_profiles` ; `pu_enrolments` → socle `enrollments` + `pub_transfers` ; `pu_teacher_presence` → `pub_teacher_presence` ; `pu_competency_frames` → `pp_frames`, `pp_criteria` ; `pu_criterion_checks` → `pp_criterion_checks` ; `pu_mentions` → `pp_mentions` ; `pu_promotion_decisions` → `pp_promotion_decisions` ; `pu_exam_candidates` → `pp_exam_candidates` ; `pu_apee_entries` → `pub_apee_entries` ; `pu_census_forms` → `pub_census_forms`.

## 1. Modules communs (`Apps/PublicSchools`)
```sql
-- ECO
CREATE TABLE pub_schools (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  official_code VARCHAR(40) NOT NULL, creation_act_ref VARCHAR(120) NULL, creation_act_date VARCHAR(10) NULL,
  subsystem VARCHAR(16) NOT NULL,            -- francophone | anglophone | bilingual (Q5)
  cycle VARCHAR(16) NOT NULL,                -- nursery | primary | group
  school_group_ref CHAR(26) NULL,            -- groupe scolaire : même valeur pour la maternelle et le primaire liés
  status VARCHAR(16) NOT NULL,               -- open | closed | suspended
  latitude VARCHAR(20) NULL, longitude VARCHAR(20) NULL,
  phone VARCHAR(30) NULL, email VARCHAR(160) NULL,
  created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL);
CREATE UNIQUE INDEX pub_schools_establishment ON pub_schools (tenant_id, establishment_id);
CREATE UNIQUE INDEX pub_schools_code ON pub_schools (tenant_id, official_code);

CREATE TABLE pub_infrastructure_years (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  year_label VARCHAR(9) NOT NULL, version INTEGER NOT NULL, state VARCHAR(16) NOT NULL, -- draft | closed
  desks_count INTEGER NOT NULL, water_points INTEGER NOT NULL, latrines_girls INTEGER NOT NULL, latrines_boys INTEGER NOT NULL,
  has_electricity INTEGER NOT NULL, has_fence INTEGER NOT NULL, has_canteen INTEGER NOT NULL, has_library INTEGER NOT NULL,
  network_observed VARCHAR(16) NULL, digital_equipment TEXT NULL, correction_reason VARCHAR(500) NULL,
  closed_by VARCHAR(40) NULL, closed_at VARCHAR(32) NULL, created_at VARCHAR(32) NOT NULL);
CREATE UNIQUE INDEX pub_infra_year ON pub_infrastructure_years (tenant_id, establishment_id, year_label, version);

CREATE TABLE pub_classrooms (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  infrastructure_year_id CHAR(26) NOT NULL, label VARCHAR(60) NOT NULL, material VARCHAR(20) NOT NULL,
  room_state VARCHAR(16) NOT NULL, in_use INTEGER NOT NULL);
CREATE INDEX pub_classrooms_year ON pub_classrooms (tenant_id, infrastructure_year_id);

CREATE TABLE pub_school_photos (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  file_path VARCHAR(255) NOT NULL, latitude VARCHAR(20) NULL, longitude VARCHAR(20) NULL,
  taken_at VARCHAR(32) NOT NULL, visit_id CHAR(26) NULL, taken_by VARCHAR(40) NOT NULL);

-- ELV / CLA
CREATE TABLE pub_pupil_profiles (                 -- complément minimal de people (minimisation)
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, person_id CHAR(26) NOT NULL,
  birth_act_ref VARCHAR(80) NULL, birth_act_verified INTEGER NOT NULL, home_language VARCHAR(40) NULL,
  is_displaced INTEGER NOT NULL, is_refugee INTEGER NOT NULL, is_orphan INTEGER NOT NULL,
  accommodation_type VARCHAR(40) NULL,           -- type d'aménagement seulement, jamais de diagnostic
  updated_at VARCHAR(32) NOT NULL);
CREATE UNIQUE INDEX pub_pupil_profiles_person ON pub_pupil_profiles (tenant_id, person_id);

CREATE TABLE pub_class_profiles (                 -- extension d'une structure-classe du socle
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  structure_id CHAR(26) NOT NULL, level_codes VARCHAR(80) NOT NULL,  -- plusieurs niveaux = classe jumelée
  shift VARCHAR(12) NOT NULL,                     -- full_day | morning | afternoon (double flux)
  homeroom_staff_id CHAR(26) NULL, homeroom_from VARCHAR(10) NULL);
CREATE UNIQUE INDEX pub_class_profiles_structure ON pub_class_profiles (tenant_id, structure_id);

CREATE TABLE pub_transfers (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, person_id CHAR(26) NOT NULL,
  from_establishment_id VARCHAR(40) NOT NULL, to_establishment_id VARCHAR(40) NOT NULL,
  state VARCHAR(16) NOT NULL,                     -- requested | accepted | refused | completed
  requested_by VARCHAR(40) NOT NULL, requested_at VARCHAR(32) NOT NULL,
  decided_by VARCHAR(40) NULL, decided_at VARCHAR(32) NULL, arrival_on VARCHAR(10) NULL,
  refusal_reason VARCHAR(500) NULL, exeat_document_id CHAR(26) NULL);
CREATE INDEX pub_transfers_to ON pub_transfers (tenant_id, to_establishment_id, state);

CREATE TABLE pub_dropouts (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  person_id CHAR(26) NOT NULL, kind VARCHAR(12) NOT NULL, -- declared | presumed
  detected_on VARCHAR(10) NOT NULL, family_contacted_at VARCHAR(32) NULL, outcome VARCHAR(16) NULL);

-- PRE
CREATE TABLE pub_teacher_presence (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  staff_id CHAR(26) NOT NULL, day_on VARCHAR(10) NOT NULL, presence VARCHAR(16) NOT NULL, -- present | absent | permission | mission
  arrived_at VARCHAR(5) NULL, left_at VARCHAR(5) NULL, reason VARCHAR(255) NULL, proof_path VARCHAR(255) NULL,
  declared_by VARCHAR(40) NOT NULL, client_uuid CHAR(36) NULL,
  checked_by VARCHAR(40) NULL, checked_at VARCHAR(32) NULL, check_result VARCHAR(16) NULL);
CREATE UNIQUE INDEX pub_teacher_presence_day ON pub_teacher_presence (tenant_id, staff_id, day_on);

CREATE TABLE pub_uncovered_classes (              -- RG-PRE-2 « classe sans enseignant »
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  structure_id CHAR(26) NOT NULL, day_on VARCHAR(10) NOT NULL);
CREATE UNIQUE INDEX pub_uncovered_day ON pub_uncovered_classes (tenant_id, structure_id, day_on);

-- PER
CREATE TABLE pub_staff_profiles (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, staff_id CHAR(26) NOT NULL,
  public_status VARCHAR(20) NOT NULL,             -- civil_servant | contract | parents_teacher | volunteer
  civil_service_number VARCHAR(40) NULL, contract_number VARCHAR(40) NULL,
  professional_diploma VARCHAR(80) NULL, service_start_on VARCHAR(10) NULL);
CREATE UNIQUE INDEX pub_staff_profiles_staff ON pub_staff_profiles (tenant_id, staff_id);
CREATE TABLE pub_trainings (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, staff_id CHAR(26) NOT NULL,
  title VARCHAR(160) NOT NULL, starts_on VARCHAR(10) NOT NULL, ends_on VARCHAR(10) NULL, organiser VARCHAR(160) NULL);

-- APE (aucun lien avec enrollments ni Billing)
CREATE TABLE pub_apee_years (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  year_label VARCHAR(9) NOT NULL, amount_per_pupil INTEGER NOT NULL, voted_on VARCHAR(10) NOT NULL,
  minutes_path VARCHAR(255) NULL, expected_pupils INTEGER NOT NULL);
CREATE UNIQUE INDEX pub_apee_years_year ON pub_apee_years (tenant_id, establishment_id, year_label);
CREATE TABLE pub_apee_entries (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  apee_year_id CHAR(26) NOT NULL, direction VARCHAR(8) NOT NULL,   -- income | expense
  category VARCHAR(32) NOT NULL,          -- contribution | donation | parents_teacher | maintenance | supplies | mock_exam | other
  amount INTEGER NOT NULL, method VARCHAR(16) NULL, payer_person_id CHAR(26) NULL, family_ref CHAR(26) NULL,
  proof_path VARCHAR(255) NULL, entry_state VARCHAR(12) NOT NULL,  -- recorded | pending (dépense sans pièce)
  receipt_document_id CHAR(26) NULL, client_uuid CHAR(36) NULL, entered_by VARCHAR(40) NOT NULL, entered_on VARCHAR(10) NOT NULL);
CREATE INDEX pub_apee_entries_year ON pub_apee_entries (tenant_id, apee_year_id, direction);
CREATE TABLE pub_apee_exemptions (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, apee_year_id CHAR(26) NOT NULL,
  family_ref CHAR(26) NOT NULL, reason_code VARCHAR(32) NOT NULL, decided_by VARCHAR(40) NOT NULL, decided_on VARCHAR(10) NOT NULL);
CREATE TABLE pub_supply_receipts (                -- paquet minimum et dons en nature (DOC-27)
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  source VARCHAR(16) NOT NULL, slip_ref VARCHAR(60) NULL, received_on VARCHAR(10) NOT NULL, deliverer_name VARCHAR(120) NULL);
CREATE TABLE pub_supply_lines (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, receipt_id CHAR(26) NOT NULL,
  item_label VARCHAR(120) NOT NULL, quantity INTEGER NOT NULL, structure_id CHAR(26) NULL);

-- STA
CREATE TABLE pub_census_forms (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  year_label VARCHAR(9) NOT NULL, campaign VARCHAR(12) NOT NULL,  -- start_of_year | end_of_year
  version INTEGER NOT NULL, form_state VARCHAR(16) NOT NULL, payload TEXT NOT NULL, check_results TEXT NULL,
  workflow_id CHAR(26) NULL, entered_by_proxy INTEGER NOT NULL, correction_reason VARCHAR(500) NULL,
  created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL);
CREATE UNIQUE INDEX pub_census_version ON pub_census_forms (tenant_id, establishment_id, year_label, campaign, version);

-- INS
CREATE TABLE pub_visit_grids (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, code VARCHAR(40) NOT NULL, version INTEGER NOT NULL,
  items TEXT NOT NULL, active INTEGER NOT NULL);
CREATE TABLE pub_visits (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  inspector_id VARCHAR(40) NOT NULL, planned_on VARCHAR(10) NOT NULL, done_on VARCHAR(10) NULL,
  structure_id CHAR(26) NULL, teacher_staff_id CHAR(26) NULL, grid_id CHAR(26) NULL, answers TEXT NULL,
  counted_present INTEGER NULL, registry_count INTEGER NULL, report_document_id CHAR(26) NULL, client_uuid CHAR(36) NULL);
CREATE INDEX pub_visits_school ON pub_visits (tenant_id, establishment_id, planned_on);
CREATE TABLE pub_recommendations (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, visit_id CHAR(26) NOT NULL,
  label VARCHAR(500) NOT NULL, followup_state VARCHAR(16) NOT NULL, checked_visit_id CHAR(26) NULL);
CREATE TABLE pub_fee_reports (                     -- EX-INS-05 frais exigé illégalement
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  reported_on VARCHAR(10) NOT NULL, description VARCHAR(1000) NOT NULL, report_state VARCHAR(16) NOT NULL, handled_by VARCHAR(40) NULL);

-- CAN
CREATE TABLE pub_meals (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  structure_id CHAR(26) NOT NULL, day_on VARCHAR(10) NOT NULL, served_count INTEGER NOT NULL);
CREATE TABLE pub_health_campaigns (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  campaign_kind VARCHAR(20) NOT NULL, held_on VARCHAR(10) NOT NULL, girls_count INTEGER NOT NULL, boys_count INTEGER NOT NULL,
  consents_count INTEGER NOT NULL);
```

## 2. Primaire publique (`Apps/PublicPrimary`)
```sql
CREATE TABLE pp_frames (                           -- référentiel national versionné
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, subsystem VARCHAR(16) NOT NULL,
  level_code VARCHAR(16) NOT NULL, discipline_code VARCHAR(32) NOT NULL, label_fr VARCHAR(160) NOT NULL,
  label_en VARCHAR(160) NOT NULL, version INTEGER NOT NULL, valid_from VARCHAR(10) NOT NULL, is_key_discipline INTEGER NOT NULL);
CREATE UNIQUE INDEX pp_frames_version ON pp_frames (tenant_id, subsystem, level_code, discipline_code, version);
CREATE TABLE pp_criteria (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, frame_id CHAR(26) NOT NULL,
  code VARCHAR(8) NOT NULL, label_fr VARCHAR(200) NOT NULL, label_en VARCHAR(200) NOT NULL,
  criterion_kind VARCHAR(12) NOT NULL, sort_order INTEGER NOT NULL);  -- minimal | perfection
CREATE TABLE pp_learning_units (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, year_label VARCHAR(9) NOT NULL,
  level_code VARCHAR(16) NOT NULL, unit_no INTEGER NOT NULL, period_id CHAR(26) NOT NULL,
  starts_on VARCHAR(10) NOT NULL, ends_on VARCHAR(10) NOT NULL, evaluation_week_from VARCHAR(10) NOT NULL, is_final_integration INTEGER NOT NULL);
CREATE UNIQUE INDEX pp_units_no ON pp_learning_units (tenant_id, year_label, level_code, unit_no);
CREATE TABLE pp_criterion_checks (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  unit_id CHAR(26) NOT NULL, structure_id CHAR(26) NOT NULL, person_id CHAR(26) NOT NULL, criterion_id CHAR(26) NOT NULL,
  occasion_no INTEGER NOT NULL, outcome VARCHAR(8) NOT NULL,   -- success | fail | absent
  entered_by VARCHAR(40) NOT NULL, client_uuid CHAR(36) NULL, entered_at VARCHAR(32) NOT NULL);
CREATE UNIQUE INDEX pp_checks_cell ON pp_criterion_checks (tenant_id, unit_id, person_id, criterion_id, occasion_no);
CREATE TABLE pp_mentions (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  person_id CHAR(26) NOT NULL, frame_id CHAR(26) NOT NULL, scope_kind VARCHAR(10) NOT NULL, -- unit | trimester | year
  scope_ref CHAR(26) NOT NULL, mention VARCHAR(12) NOT NULL,  -- A | ECA | NA | not_assessed
  perfection_mastered INTEGER NOT NULL, rule_version VARCHAR(40) NOT NULL, overridden_reason VARCHAR(500) NULL,
  published INTEGER NOT NULL, computed_at VARCHAR(32) NOT NULL);
CREATE UNIQUE INDEX pp_mentions_scope ON pp_mentions (tenant_id, person_id, frame_id, scope_kind, scope_ref);
CREATE TABLE pp_remediations (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  unit_id CHAR(26) NOT NULL, person_id CHAR(26) NOT NULL, criterion_id CHAR(26) NOT NULL, remediation_state VARCHAR(12) NOT NULL);
CREATE TABLE pp_report_cards (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  person_id CHAR(26) NOT NULL, card_kind VARCHAR(12) NOT NULL, scope_ref CHAR(26) NOT NULL,
  appraisal VARCHAR(1000) NULL, card_state VARCHAR(12) NOT NULL,  -- draft | validated | published | rectified
  document_id CHAR(26) NULL, published_by VARCHAR(40) NULL, published_at VARCHAR(32) NULL);
CREATE TABLE pp_appraisal_phrases (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, locale VARCHAR(5) NOT NULL, phrase VARCHAR(300) NOT NULL);
CREATE TABLE pp_promotion_decisions (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  year_label VARCHAR(9) NOT NULL, person_id CHAR(26) NOT NULL, proposal VARCHAR(24) NOT NULL,
  decision VARCHAR(24) NULL, reason VARCHAR(1000) NULL, policy_version VARCHAR(40) NOT NULL,
  inspector_opinion_approval_id CHAR(26) NULL, appeal_until VARCHAR(10) NULL, appeal_state VARCHAR(12) NULL, council_id CHAR(26) NULL);
CREATE UNIQUE INDEX pp_promotion_person ON pp_promotion_decisions (tenant_id, year_label, person_id);
CREATE TABLE pp_councils (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  year_label VARCHAR(9) NOT NULL, held_on VARCHAR(10) NOT NULL, attendees TEXT NOT NULL, minutes_document_id CHAR(26) NULL);
CREATE TABLE pp_exam_sessions (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, exam_code VARCHAR(16) NOT NULL, -- cep | fslc | sixth | common_entrance
  year_label VARCHAR(9) NOT NULL, list_format VARCHAR(40) NULL, workflow_id CHAR(26) NULL, frozen_at VARCHAR(32) NULL);
CREATE TABLE pp_exam_candidates (
  id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
  session_id CHAR(26) NOT NULL, person_id CHAR(26) NOT NULL, candidate_state VARCHAR(16) NOT NULL,
  blocked_reason VARCHAR(40) NULL, added_after_freeze_reason VARCHAR(500) NULL,
  outcome VARCHAR(12) NULL, was_present INTEGER NULL, attestation_document_id CHAR(26) NULL);
CREATE UNIQUE INDEX pp_candidates_person ON pp_exam_candidates (tenant_id, session_id, person_id);
```

## 3. Volumétrie (NF-P1)
3,8 M élèves ; `pp_criterion_checks` ≈ 3,8 M × ~3 disciplines clés × 3 critères × 3 occasions × 8 unités ≈ 800 M lignes/an à l'échelle nationale si toutes les disciplines sont saisies : **inacceptable en mutualisé**. Au pilote (≈ 50 écoles, 15 000 élèves) ≈ 3 M lignes/an : acceptable. Pour l'extension, stocker une ligne par élève × critère × unité avec `outcomes VARCHAR(8)` (« 110 ») au lieu d'une ligne par occasion (÷ 3), et archiver par année (Q19). Choix à valider au passage à l'échelle ; le contrat d'API reste inchangé.
