-- Supérieur, inscriptions (INS, EX-INS-01 à 08) : dossier étudiant et matricule, inscriptions administratives et pédagogiques,
-- contrat pédagogique, décisions de passage de l'année N, dérogations, journal des changements.
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), montants entiers XAF, aucune clé étrangère.

-- Dossier étudiant (EX-INS-01) : un par personne et établissement, un seul matricule définitif (RG-INS-1, RG-ORG-6), photo, garant,
-- consentement de partage des notes et absences avec le garant (RG-INS-7).
CREATE TABLE hi_students (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    matricule VARCHAR(40) NULL,
    matricule_assigned_at VARCHAR(32) NULL,
    school_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    photo_ref VARCHAR(80) NULL,
    guarantor_person_id VARCHAR(40) NULL,
    share_with_guarantor INTEGER NOT NULL DEFAULT 0,
    consent_recorded_by VARCHAR(40) NULL,
    consent_recorded_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_students_person ON hi_students (tenant_id, establishment_id, person_id);

CREATE UNIQUE INDEX hi_students_matricule ON hi_students (tenant_id, establishment_id, matricule);

-- Compteurs de matricule par clé (année, école, filière) : jamais décrémentés, un numéro n'est jamais réattribué (RG-INS-1).
CREATE TABLE hi_matricule_sequences (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    sequence_key VARCHAR(60) NOT NULL,
    last_number INTEGER NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_matricule_sequences_key ON hi_matricule_sequences (tenant_id, establishment_id, sequence_key);

-- Inscription administrative d'une année dans une filière et un niveau (extension de l'inscription du socle, `enrollment_id`) :
-- version de maquette mémorisée (RG-MAQ-1), rang de l'inscription dans le niveau (RG-INS-5), réserve financière (RG-INS-2),
-- statuts EX-INS-04, abandon et remboursement (RG-INS-6). Double cursus : une inscription vivante par filière et par année.
CREATE TABLE hi_enrolments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    student_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    enrollment_id VARCHAR(40) NULL,
    application_id CHAR(26) NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    department_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    level_id CHAR(26) NOT NULL,
    group_id CHAR(26) NULL,
    study_mode VARCHAR(16) NOT NULL,
    enrolment_kind VARCHAR(16) NOT NULL,
    curriculum_id CHAR(26) NULL,
    curriculum_version INTEGER NULL,
    attempt_number INTEGER NOT NULL,
    derogation_id CHAR(26) NULL,
    status VARCHAR(16) NOT NULL,
    status_reason VARCHAR(500) NULL,
    financial_hold INTEGER NOT NULL DEFAULT 0,
    financial_hold_by VARCHAR(40) NULL,
    financial_hold_reason VARCHAR(500) NULL,
    validated_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    withdrawn_on VARCHAR(32) NULL,
    refund_percent INTEGER NULL,
    refund_xaf INTEGER NULL,
    card_document_id VARCHAR(40) NULL,
    form_document_id VARCHAR(40) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_enrolments_person ON hi_enrolments (tenant_id, establishment_id, person_id, academic_year_id);

CREATE INDEX hi_enrolments_level ON hi_enrolments (tenant_id, establishment_id, level_id, academic_year_id);

-- Contrat pédagogique (EX-INS-02, T421) : version de maquette, choix d'UE optionnelles, statut brouillon ou arrêté.
CREATE TABLE hi_learning_contracts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    enrolment_id CHAR(26) NOT NULL,
    curriculum_id CHAR(26) NULL,
    curriculum_version INTEGER NULL,
    status VARCHAR(12) NOT NULL,
    finalized_by VARCHAR(40) NULL,
    finalized_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_learning_contracts_enrolment ON hi_learning_contracts (tenant_id, establishment_id, enrolment_id);

-- UE du contrat : origine normale, optionnelle (choisie), dette (UE non acquise de l'année N, inscrite d'office) ou capitalisée
-- (acquise, reportée sans être repassée) (RG-INS-4).
CREATE TABLE hi_learning_contract_items (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    contract_id CHAR(26) NOT NULL,
    enrolment_id CHAR(26) NOT NULL,
    curriculum_unit_id CHAR(26) NULL,
    unit_code VARCHAR(40) NOT NULL,
    unit_title VARCHAR(160) NOT NULL,
    credits INTEGER NOT NULL,
    semester_ordinal INTEGER NULL,
    item_origin VARCHAR(12) NOT NULL,
    choice_group VARCHAR(20) NULL,
    source_level_id CHAR(26) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_learning_contract_items_unit ON hi_learning_contract_items (tenant_id, establishment_id, contract_id, unit_code, item_origin);

-- Décisions de passage de l'année N (DEL ou reprise) : condition de la réinscription (RG-INS-3), UE acquises et manquantes (RG-INS-4).
CREATE TABLE hi_progression_decisions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    level_id CHAR(26) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    decision VARCHAR(24) NOT NULL,
    acquired_units_json TEXT NOT NULL,
    missing_units_json TEXT NOT NULL,
    recorded_by VARCHAR(40) NULL,
    recorded_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_progression_decisions_year ON hi_progression_decisions (tenant_id, establishment_id, person_id, program_id, academic_year_id);

-- Dérogations du directeur d'école (R04) : inscription au-delà de la limite d'inscriptions consécutives dans un niveau (RG-INS-5).
CREATE TABLE hi_enrolment_derogations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    level_id CHAR(26) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    derogation_kind VARCHAR(24) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    granted_by VARCHAR(40) NOT NULL,
    granted_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_enrolment_derogations_key ON hi_enrolment_derogations (tenant_id, establishment_id, person_id, level_id, academic_year_id, derogation_kind);

-- Journal des événements d'inscription : validation, réserve financière, changements de statut, de filière, de groupe, de mode, consentement.
CREATE TABLE hi_enrolment_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    enrolment_id CHAR(26) NULL,
    person_id VARCHAR(40) NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    from_value VARCHAR(80) NULL,
    to_value VARCHAR(80) NULL,
    reason VARCHAR(500) NULL,
    actor_id VARCHAR(40) NULL,
    occurred_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_enrolment_events_enrolment ON hi_enrolment_events (tenant_id, establishment_id, enrolment_id);
