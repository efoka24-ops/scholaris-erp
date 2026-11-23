-- Primaire privé, examen de fin de cycle (EX-PRI-42 ; Q11) : préparation du CEP (francophone) et du FSLC (anglophone) pour les élèves
-- de l'école. Sessions (examen paramétré, centre et sous-centre de composition), candidats (numéro, fiche d'inscription aux champs
-- paramétrés, retrait conservé, résultat officiel SAISI tel que publié : admis, ajourné, absent). Aucun barème ni note officielle
-- n'est inventé : les épreuves et coefficients du CEP ne sont pas publiés (recherche-minedub §3).
-- Portable MySQL 8 / SQLite : ULID, dates ISO 8601 en texte, aucune clé étrangère, aucun mot réservé.

CREATE TABLE pri_exam_sessions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    exam_code VARCHAR(20) NOT NULL,
    label VARCHAR(160) NOT NULL,
    level_structure_id VARCHAR(40) NOT NULL,
    centre_label VARCHAR(160) NULL,
    sub_centre_label VARCHAR(160) NULL,
    registration_opens_on VARCHAR(10) NULL,
    registration_closes_on VARCHAR(10) NULL,
    written_on VARCHAR(10) NULL,
    fee_code VARCHAR(40) NULL,
    status VARCHAR(12) NOT NULL,
    results_published_at VARCHAR(32) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_exam_sessions_cell ON pri_exam_sessions (tenant_id, establishment_id, academic_year_id, exam_code, level_structure_id);

CREATE TABLE pri_exam_candidates (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    enrollment_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NULL,
    candidate_number VARCHAR(40) NULL,
    sub_centre_label VARCHAR(160) NULL,
    registration_status VARCHAR(12) NOT NULL,
    fields_json TEXT NULL,
    outcome VARCHAR(12) NOT NULL DEFAULT 'pending',
    outcome_detail VARCHAR(160) NULL,
    invoice_id VARCHAR(40) NULL,
    withdrawal_reason VARCHAR(255) NULL,
    withdrawn_at VARCHAR(32) NULL,
    recorded_by VARCHAR(40) NULL,
    recorded_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_exam_candidates_cell ON pri_exam_candidates (tenant_id, establishment_id, session_id, person_id);
CREATE INDEX pri_exam_candidates_number ON pri_exam_candidates (tenant_id, establishment_id, session_id, candidate_number);
