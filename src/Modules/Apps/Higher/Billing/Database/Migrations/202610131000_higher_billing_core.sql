-- Application Supérieur (IPES), module FIN : grilles de frais par école, filière, niveau et mode (EX-FIN-01), comptes de scolarité
-- (une facture du socle par étudiant, année et filière), échéanciers négociés (EX-FIN-02), bourses et réductions (EX-FIN-03),
-- tiers payeurs (EX-FIN-04) et journal des paiements passés par l'application. Portable MySQL 8 / SQLite, aucune clé étrangère.

CREATE TABLE hi_fee_grids (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NULL,
    level_id VARCHAR(40) NULL,
    study_mode VARCHAR(10) NULL,
    label VARCHAR(160) NOT NULL,
    fees_json TEXT NOT NULL,
    terms_json TEXT NOT NULL,
    status VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_fee_grids_scope ON hi_fee_grids (tenant_id, establishment_id, academic_year_id, school_id);

CREATE TABLE hi_student_accounts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    enrolment_ref VARCHAR(40) NULL,
    school_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NULL,
    level_id VARCHAR(40) NULL,
    study_mode VARCHAR(10) NULL,
    grid_id CHAR(26) NOT NULL,
    plan_id CHAR(26) NULL,
    sponsor_id CHAR(26) NULL,
    invoice_id VARCHAR(40) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_student_accounts_unique ON hi_student_accounts (tenant_id, establishment_id, academic_year_id, person_id, program_id);
CREATE INDEX hi_student_accounts_person ON hi_student_accounts (tenant_id, person_id);
CREATE INDEX hi_student_accounts_sponsor ON hi_student_accounts (tenant_id, sponsor_id);

CREATE TABLE hi_payment_plans (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NULL,
    terms_json TEXT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status VARCHAR(12) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_payment_plans_person ON hi_payment_plans (tenant_id, establishment_id, person_id);

CREATE TABLE hi_scholarships (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    account_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NULL,
    scholarship_kind VARCHAR(20) NOT NULL,
    calc_mode VARCHAR(10) NOT NULL,
    rate_value INTEGER NOT NULL,
    applies_to VARCHAR(20) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status VARCHAR(12) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_scholarships_account ON hi_scholarships (tenant_id, account_id);

CREATE TABLE hi_sponsors (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    sponsor_kind VARCHAR(20) NOT NULL,
    name VARCHAR(160) NOT NULL,
    reference VARCHAR(60) NULL,
    contact VARCHAR(160) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_sponsors_scope ON hi_sponsors (tenant_id, establishment_id);

CREATE TABLE hi_payment_log (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    account_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    payment_id VARCHAR(40) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    status VARCHAR(12) NOT NULL,
    receipt_id VARCHAR(40) NULL,
    receipt_number VARCHAR(60) NULL,
    recorded_by VARCHAR(40) NULL,
    recorded_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_payment_log_payment ON hi_payment_log (tenant_id, payment_id);
CREATE INDEX hi_payment_log_account ON hi_payment_log (tenant_id, account_id);
