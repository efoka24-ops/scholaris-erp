-- APEE (association des parents d'élèves et des enseignants) des écoles primaires publiques (T650, T651, T655) : comptes de l'association,
-- SÉPARÉS de la scolarité. Aucune table de ce module n'est lue par l'inscription, la présence, l'évaluation ni les documents d'élève
-- (RG-ELV-1, RG-APE-1) ; aucune contribution n'est exigible au primaire public (circulaire MINFI-MINEDUB, visa du 7 mars 2022) :
-- la cotisation est volontaire. Une famille est repérée par l'élève pour lequel la contribution est versée (`family_ref`).
CREATE TABLE pub_apee_years (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    year_label VARCHAR(9) NOT NULL,
    amount_per_pupil INTEGER NOT NULL,
    voted_on VARCHAR(10) NOT NULL,
    minutes_ref VARCHAR(255) NULL,
    expected_pupils INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_apee_years_year ON pub_apee_years (tenant_id, establishment_id, year_label);

CREATE TABLE pub_apee_entries (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    apee_year_id CHAR(26) NOT NULL,
    direction VARCHAR(8) NOT NULL,
    category VARCHAR(32) NOT NULL,
    amount INTEGER NOT NULL,
    method VARCHAR(16) NULL,
    operator_ref VARCHAR(80) NULL,
    payer_person_id VARCHAR(40) NULL,
    family_ref VARCHAR(40) NULL,
    label VARCHAR(200) NULL,
    proof_ref VARCHAR(255) NULL,
    entry_state VARCHAR(12) NOT NULL,
    receipt_document_id CHAR(26) NULL,
    receipt_number VARCHAR(60) NULL,
    client_uuid VARCHAR(40) NULL,
    entered_by VARCHAR(40) NOT NULL,
    entered_on VARCHAR(10) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pub_apee_entries_year ON pub_apee_entries (tenant_id, apee_year_id, direction);
CREATE INDEX pub_apee_entries_family ON pub_apee_entries (tenant_id, family_ref);
CREATE UNIQUE INDEX pub_apee_entries_uuid ON pub_apee_entries (tenant_id, client_uuid);

CREATE TABLE pub_apee_exemptions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    apee_year_id CHAR(26) NOT NULL,
    family_ref VARCHAR(40) NOT NULL,
    reason_code VARCHAR(32) NOT NULL,
    decided_by VARCHAR(40) NOT NULL,
    decided_on VARCHAR(10) NOT NULL
);
CREATE UNIQUE INDEX pub_apee_exemptions_family ON pub_apee_exemptions (tenant_id, apee_year_id, family_ref);

CREATE TABLE pub_supply_receipts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    source VARCHAR(16) NOT NULL,
    slip_ref VARCHAR(60) NULL,
    received_on VARCHAR(10) NOT NULL,
    deliverer_name VARCHAR(120) NULL,
    document_id CHAR(26) NULL,
    recorded_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pub_supply_receipts_school ON pub_supply_receipts (tenant_id, establishment_id, received_on);

CREATE TABLE pub_supply_lines (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    receipt_id CHAR(26) NOT NULL,
    item_label VARCHAR(120) NOT NULL,
    quantity INTEGER NOT NULL,
    structure_id VARCHAR(40) NULL
);
CREATE INDEX pub_supply_lines_receipt ON pub_supply_lines (tenant_id, receipt_id);
