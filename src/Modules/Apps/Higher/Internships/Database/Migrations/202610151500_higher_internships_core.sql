-- Supérieur, stages et soutenances (STG, EX-STG-01 à 06, RG-STG-1/2) : répertoire d'entreprises d'accueil, conventions tripartites
-- (DOC-20) et leur cycle, suivi (visites, rapports, fiche d'évaluation DOC-23), attestation de fin de stage, dépôt du mémoire,
-- soutenances programmées (jury, salle, horaire ; convocation DOC-21 et NOT-13), note et PV de soutenance (DOC-22).
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), aucune clé étrangère, aucun mot réservé.
-- La note est stockée en centièmes (grade_hundredths : 1450 = 14,50/20) pour éviter tout arrondi flottant.

-- Répertoire des entreprises d'accueil (EX-STG-01).
CREATE TABLE hi_internship_companies (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    sector VARCHAR(120) NULL,
    city VARCHAR(120) NULL,
    address VARCHAR(255) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(160) NULL,
    contact_name VARCHAR(160) NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_internship_companies_name ON hi_internship_companies (tenant_id, establishment_id, name);

-- Convention de stage d'une inscription (statuts : draft, pending_signatures, signed, completed, cancelled).
CREATE TABLE hi_internships (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    enrolment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NULL,
    company_id CHAR(26) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    starts_on VARCHAR(32) NOT NULL,
    ends_on VARCHAR(32) NOT NULL,
    company_tutor_name VARCHAR(160) NOT NULL,
    company_tutor_email VARCHAR(160) NULL,
    company_tutor_phone VARCHAR(40) NULL,
    supervisor_person_id VARCHAR(40) NOT NULL,
    supervisor_name VARCHAR(160) NOT NULL,
    status VARCHAR(20) NOT NULL,
    convention_document_id VARCHAR(40) NULL,
    student_signed_on VARCHAR(32) NULL,
    company_signed_on VARCHAR(32) NULL,
    school_signed_on VARCHAR(32) NULL,
    school_signed_by VARCHAR(40) NULL,
    subject_validated_at VARCHAR(32) NULL,
    subject_validated_by VARCHAR(40) NULL,
    completion_received_on VARCHAR(32) NULL,
    completion_ref VARCHAR(120) NULL,
    thesis_title VARCHAR(255) NULL,
    thesis_ref VARCHAR(255) NULL,
    thesis_deposited_at VARCHAR(32) NULL,
    cancel_reason VARCHAR(500) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_internships_person ON hi_internships (tenant_id, establishment_id, person_id);
CREATE INDEX hi_internships_program ON hi_internships (tenant_id, establishment_id, program_id, status);

-- Suivi du stage (EX-STG-03) : visite de l'encadreur, rapport d'étape, fiche d'évaluation du tuteur (note /20, DOC-23).
CREATE TABLE hi_internship_followups (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    internship_id CHAR(26) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    occurred_on VARCHAR(32) NOT NULL,
    summary TEXT NOT NULL,
    score_hundredths INTEGER NULL,
    document_id VARCHAR(40) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_internship_followups_internship ON hi_internship_followups (tenant_id, establishment_id, internship_id);

-- Soutenance (API-18, EX-STG-05/06) : statuts scheduled, convened, graded, closed, cancelled.
CREATE TABLE hi_defenses (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    internship_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    held_on VARCHAR(32) NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    room VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL,
    grade_hundredths INTEGER NULL,
    remarks VARCHAR(1000) NULL,
    graded_by VARCHAR(40) NULL,
    graded_at VARCHAR(32) NULL,
    convocation_document_id VARCHAR(40) NULL,
    convened_at VARCHAR(32) NULL,
    minutes_document_id VARCHAR(40) NULL,
    closed_at VARCHAR(32) NULL,
    cancel_reason VARCHAR(500) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_defenses_slot ON hi_defenses (tenant_id, establishment_id, held_on);
CREATE INDEX hi_defenses_internship ON hi_defenses (tenant_id, establishment_id, internship_id);

-- Jury d'une soutenance (RG-STG-2) : president, supervisor (l'encadreur, jamais président), member.
CREATE TABLE hi_defense_jury_members (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    defense_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    jury_role VARCHAR(20) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_defense_jury_members_defense ON hi_defense_jury_members (tenant_id, establishment_id, defense_id);
CREATE INDEX hi_defense_jury_members_person ON hi_defense_jury_members (tenant_id, establishment_id, person_id);
