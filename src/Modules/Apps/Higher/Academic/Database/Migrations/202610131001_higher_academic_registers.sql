-- Supérieur, registres (EX-ACA-03 à 07) : autorisations MINESUP, tutelles par année, règlement des études, calendrier,
-- alertes d'expiration envoyées. La validité, les niveaux couverts et la pièce d'une autorisation restent dans le registre
-- du socle (Organisation\Contracts\AuthorizationRegistry) : hi_authorizations n'en porte que le rattachement IPES.

CREATE TABLE hi_authorizations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    department_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    decree_date VARCHAR(32) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_authorizations_program ON hi_authorizations (tenant_id, establishment_id, program_id);

-- Une université de tutelle par filière LMD et par année académique (RG-ORG-8) : une autre année = une autre ligne.
CREATE TABLE hi_tutelages (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    department_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    university_name VARCHAR(160) NOT NULL,
    convention_reference VARCHAR(80) NULL,
    convention_document_id VARCHAR(40) NULL,
    convention_starts_on VARCHAR(32) NULL,
    convention_ends_on VARCHAR(32) NULL,
    approved_programme_ref VARCHAR(120) NULL,
    contacts TEXT NOT NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_tutelages_program_year ON hi_tutelages (tenant_id, establishment_id, program_id, academic_year_id);

-- Règlement des études par école, régime et année (EX-ACA-05) ; figé à l'ouverture des inscriptions (RG-ACA-3).
CREATE TABLE hi_regulations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    regime VARCHAR(12) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    version INTEGER NOT NULL,
    parameters TEXT NOT NULL,
    status VARCHAR(12) NOT NULL,
    frozen_at VARCHAR(32) NULL,
    frozen_by VARCHAR(40) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_regulations_scope ON hi_regulations (tenant_id, establishment_id, school_id, regime, academic_year_id);

-- Trace de chaque modification d'un règlement figé (directeur académique, double authentification récente).
CREATE TABLE hi_regulation_revisions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    regulation_id CHAR(26) NOT NULL,
    version INTEGER NOT NULL,
    previous_parameters TEXT NOT NULL,
    new_parameters TEXT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    amended_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_regulation_revisions_regulation ON hi_regulation_revisions (tenant_id, establishment_id, regulation_id);

-- Calendrier académique par école et par année (EX-ACA-06).
CREATE TABLE hi_calendar_entries (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    entry_kind VARCHAR(20) NOT NULL,
    label VARCHAR(160) NOT NULL,
    starts_on VARCHAR(32) NOT NULL,
    ends_on VARCHAR(32) NOT NULL,
    semester_ordinal INTEGER NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_calendar_entries_school_year ON hi_calendar_entries (tenant_id, establishment_id, school_id, academic_year_id);

-- Alertes d'expiration déjà envoyées (EX-ACA-07) : un seuil n'est envoyé qu'une fois par échéance.
CREATE TABLE hi_expiry_alerts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subject_type VARCHAR(20) NOT NULL,
    subject_id CHAR(26) NOT NULL,
    expires_on VARCHAR(32) NOT NULL,
    threshold_days INTEGER NOT NULL,
    recipients_count INTEGER NOT NULL,
    delivery_status VARCHAR(12) NOT NULL,
    sent_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_expiry_alerts_once ON hi_expiry_alerts (tenant_id, establishment_id, subject_type, subject_id, expires_on, threshold_days);
