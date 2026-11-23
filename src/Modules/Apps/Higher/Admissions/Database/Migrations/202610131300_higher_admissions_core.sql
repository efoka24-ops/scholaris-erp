-- Supérieur, admissions (ADM, EX-ADM-01 à 08) : campagnes, candidatures, pièces, décisions, concours, équivalences de transfert.
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), montants entiers XAF, aucune clé étrangère,
-- aucun mot réservé (seats, score_hundredths, rank_position, list_kind...).

-- Campagne par filière et niveau (EX-ADM-01) : dates, places, frais de dossier, pièces exigées et prérequis (JSON), mode d'admission.
CREATE TABLE hi_campaigns (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    department_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    level_id CHAR(26) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    opens_on VARCHAR(32) NOT NULL,
    closes_on VARCHAR(32) NOT NULL,
    seats INTEGER NOT NULL,
    application_fee_xaf INTEGER NOT NULL,
    required_documents_json TEXT NOT NULL,
    prerequisites_json TEXT NOT NULL,
    selection_mode VARCHAR(12) NOT NULL,
    jury_json TEXT NULL,
    status VARCHAR(12) NOT NULL,
    cancelled_reason VARCHAR(500) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_campaigns_level ON hi_campaigns (tenant_id, establishment_id, level_id, academic_year_id);

CREATE INDEX hi_campaigns_school ON hi_campaigns (tenant_id, establishment_id, school_id);

-- Candidature (EX-ADM-02) : en ligne ou au guichet, diplôme déclaré (prérequis EX-ADM-03), frais de dossier (facture et paiement du
-- socle, D4), décision (EX-ADM-06), « admis sous réserve » jusqu'au conditional_until (RG-ADM-2), classement (EX-ADM-04/05).
CREATE TABLE hi_applications (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    campaign_id CHAR(26) NOT NULL,
    school_id CHAR(26) NOT NULL,
    department_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    level_id CHAR(26) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    reference VARCHAR(40) NOT NULL,
    channel VARCHAR(12) NOT NULL,
    application_kind VARCHAR(12) NOT NULL,
    origin_institution VARCHAR(160) NULL,
    diploma VARCHAR(16) NOT NULL,
    diploma_series VARCHAR(20) NULL,
    diploma_year INTEGER NULL,
    diploma_mention VARCHAR(16) NULL,
    status VARCHAR(16) NOT NULL,
    fee_invoice_id VARCHAR(40) NULL,
    fee_payment_id VARCHAR(40) NULL,
    fee_status VARCHAR(12) NOT NULL,
    fee_receipt_number VARCHAR(60) NULL,
    fee_refund_due INTEGER NOT NULL DEFAULT 0,
    score_hundredths INTEGER NULL,
    rank_position INTEGER NULL,
    list_kind VARCHAR(12) NULL,
    decision_reason VARCHAR(500) NULL,
    conditional_until VARCHAR(32) NULL,
    over_capacity INTEGER NOT NULL DEFAULT 0,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    acknowledgement_document_id VARCHAR(40) NULL,
    letter_document_id VARCHAR(40) NULL,
    enrolment_id CHAR(26) NULL,
    submitted_at VARCHAR(32) NULL,
    completed_at VARCHAR(32) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_applications_reference ON hi_applications (tenant_id, establishment_id, reference);

CREATE UNIQUE INDEX hi_applications_person ON hi_applications (tenant_id, establishment_id, campaign_id, person_id);

CREATE INDEX hi_applications_status ON hi_applications (tenant_id, establishment_id, status, conditional_until);

-- Pièces de la candidature : référence du document numérisé (socle Documents ou fichier), vérification par la scolarité (RG-ADM-1).
CREATE TABLE hi_application_documents (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    application_id CHAR(26) NOT NULL,
    requirement_code VARCHAR(40) NOT NULL,
    label VARCHAR(160) NOT NULL,
    blocking INTEGER NOT NULL DEFAULT 0,
    proves_prerequisite INTEGER NOT NULL DEFAULT 0,
    document_ref VARCHAR(80) NULL,
    status VARCHAR(12) NOT NULL,
    rejection_reason VARCHAR(500) NULL,
    verified_by VARCHAR(40) NULL,
    verified_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_application_documents_code ON hi_application_documents (tenant_id, establishment_id, application_id, requirement_code);

-- Historique des décisions motivées (EX-ADM-06), y compris l'expiration automatique (RG-ADM-2) et le dépassement de places (RG-ADM-3).
CREATE TABLE hi_admission_decisions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    application_id CHAR(26) NOT NULL,
    decision VARCHAR(16) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    over_capacity INTEGER NOT NULL DEFAULT 0,
    over_capacity_reason VARCHAR(500) NULL,
    conditional_until VARCHAR(32) NULL,
    decision_source VARCHAR(12) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_admission_decisions_application ON hi_admission_decisions (tenant_id, establishment_id, application_id);

-- Concours (EX-ADM-05, Should) : épreuve (date, centre, salle) et convocations (numéro de table, DOC-02).
CREATE TABLE hi_contest_sessions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    campaign_id CHAR(26) NOT NULL,
    school_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    contest_date VARCHAR(32) NOT NULL,
    center_name VARCHAR(160) NOT NULL,
    room_name VARCHAR(80) NOT NULL,
    room_capacity INTEGER NOT NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE TABLE hi_contest_seats (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    application_id CHAR(26) NOT NULL,
    seat_number VARCHAR(20) NOT NULL,
    convocation_document_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_contest_seats_application ON hi_contest_seats (tenant_id, establishment_id, session_id, application_id);

-- Transfert (EX-ADM-07, Should) : équivalences d'UE décidées par la commission, reportées comme UE capitalisées à l'inscription.
CREATE TABLE hi_transfer_equivalences (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    application_id CHAR(26) NOT NULL,
    origin_unit_code VARCHAR(40) NOT NULL,
    origin_unit_title VARCHAR(160) NOT NULL,
    origin_credits INTEGER NOT NULL,
    origin_grade_hundredths INTEGER NULL,
    unit_code VARCHAR(40) NOT NULL,
    granted INTEGER NOT NULL,
    commission_reference VARCHAR(80) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_transfer_equivalences_application ON hi_transfer_equivalences (tenant_id, establishment_id, application_id);
