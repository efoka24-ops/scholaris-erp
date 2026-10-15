-- Supérieur, présences et discipline (PRE) : l'appel, les justificatifs et le calcul par EC restent au socle (S08, D19-D21) ;
-- l'application garde les alertes envoyées (NOT-07), les levées d'interdiction de composer, les listes de non-autorisés transmises au
-- service des examens, les épreuves de remplacement après absence justifiée, les sanctions et les conseils de discipline (RG-PRE-2).
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), aucune clé étrangère, aucun mot réservé.

-- Alertes d'assiduité envoyées (NOT-07 à 25 %), une par étudiant, EC et niveau d'alerte.
CREATE TABLE hi_attendance_alerts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    element_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NOT NULL,
    alert_level VARCHAR(12) NOT NULL,
    rate_permille INTEGER NOT NULL,
    sent_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_attendance_alerts_once ON hi_attendance_alerts (tenant_id, establishment_id, person_id, element_id, alert_level);

-- Levée tracée de l'interdiction de composer un EC (dérogation du directeur d'école).
CREATE TABLE hi_barred_lifts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    element_id VARCHAR(40) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    document_ref VARCHAR(80) NULL,
    lifted_by VARCHAR(40) NOT NULL,
    lifted_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_barred_lifts_once ON hi_barred_lifts (tenant_id, establishment_id, person_id, element_id);

-- Liste des non-autorisés à composer d'un EC et d'un groupe, transmise au service des examens (EX-PRE-05).
CREATE TABLE hi_barred_lists (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    element_id VARCHAR(40) NOT NULL,
    threshold_percent INTEGER NOT NULL,
    entries TEXT NOT NULL,
    barred_count INTEGER NOT NULL,
    transmitted_by VARCHAR(40) NOT NULL,
    transmitted_at VARCHAR(32) NOT NULL,
    received_by VARCHAR(40) NULL,
    received_at VARCHAR(32) NULL
);

CREATE INDEX hi_barred_lists_element ON hi_barred_lists (tenant_id, establishment_id, element_id, group_id);

-- Épreuve de remplacement après une absence justifiée à l'examen (ABJ, RG-NOT-5, T425).
CREATE TABLE hi_replacement_exams (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    element_id VARCHAR(40) NOT NULL,
    absence_date VARCHAR(32) NOT NULL,
    justification_ref VARCHAR(80) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    exam_date VARCHAR(32) NULL,
    start_time VARCHAR(5) NULL,
    end_time VARCHAR(5) NULL,
    room_id VARCHAR(40) NULL,
    status VARCHAR(12) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    scheduled_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_replacement_exams_once ON hi_replacement_exams (tenant_id, establishment_id, person_id, element_id, absence_date);

-- Conseil de discipline (EX-PRE-04) : convocation, membres, PV signé (empreinte SHA-256), décision ; exclusion définitive (RG-PRE-2).
CREATE TABLE hi_discipline_councils (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NULL,
    person_id VARCHAR(40) NOT NULL,
    enrolment_id VARCHAR(40) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    incident_ref VARCHAR(80) NULL,
    hearing_date VARCHAR(32) NOT NULL,
    members TEXT NOT NULL,
    minutes_text TEXT NULL,
    decision VARCHAR(30) NULL,
    decision_reason VARCHAR(1000) NULL,
    exclusion_from VARCHAR(32) NULL,
    exclusion_to VARCHAR(32) NULL,
    status VARCHAR(12) NOT NULL,
    minutes_hash VARCHAR(64) NULL,
    signed_by VARCHAR(40) NULL,
    signed_at VARCHAR(32) NULL,
    sanction_id CHAR(26) NULL,
    enrolment_excluded INTEGER NOT NULL DEFAULT 0,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_discipline_councils_person ON hi_discipline_councils (tenant_id, establishment_id, person_id);

-- Sanctions de l'IPES (EX-PRE-04) : avertissement, blâme, exclusion temporaire, exclusion définitive (seulement après PV signé),
-- notification DOC-31.
CREATE TABLE hi_sanctions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NULL,
    person_id VARCHAR(40) NOT NULL,
    enrolment_id VARCHAR(40) NOT NULL,
    sanction_kind VARCHAR(30) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    starts_on VARCHAR(32) NULL,
    ends_on VARCHAR(32) NULL,
    council_id CHAR(26) NULL,
    status VARCHAR(12) NOT NULL,
    document_id VARCHAR(40) NULL,
    document_number VARCHAR(40) NULL,
    decided_by VARCHAR(40) NOT NULL,
    decided_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_sanctions_person ON hi_sanctions (tenant_id, establishment_id, person_id);
