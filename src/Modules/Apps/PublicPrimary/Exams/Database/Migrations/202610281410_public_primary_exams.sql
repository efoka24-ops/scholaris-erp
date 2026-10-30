-- Examens et entrée au secondaire de la Primaire publique (EXA, T680 à T685) : sessions nationales (CEP, FSLC, entrée en 6e, Common
-- Entrance), liste de chaque école (circuit directeur, inspection, délégation, gel : RG-EXA-2), candidats avec contrôle d'état civil
-- (RG-EXA-1), centres et sous-centres, jurys et vacations (décret 2010/1738, montants en paramètres), résultats officiels importés
-- (API-10), attestations DOC-16 (jamais de diplôme : RG-EXA-3), dossiers signés de transfert vers le secondaire (Q18).
CREATE TABLE pp_exam_sessions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    exam_code VARCHAR(20) NOT NULL,
    year_label VARCHAR(9) NOT NULL,
    label VARCHAR(160) NOT NULL,
    subsystem VARCHAR(16) NOT NULL,
    level_codes VARCHAR(80) NOT NULL,
    exam_kind VARCHAR(16) NOT NULL,
    written_on VARCHAR(10) NULL,
    session_state VARCHAR(20) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_exam_sessions_code ON pp_exam_sessions (tenant_id, exam_code, year_label);

CREATE TABLE pp_exam_lists (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    list_state VARCHAR(16) NOT NULL,
    current_step VARCHAR(16) NULL,
    return_reason VARCHAR(500) NULL,
    frozen_at VARCHAR(32) NULL,
    frozen_by VARCHAR(40) NULL,
    amendments_count INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_exam_lists_school ON pp_exam_lists (tenant_id, session_id, establishment_id);

CREATE TABLE pp_exam_candidates (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    list_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NULL,
    level_code VARCHAR(16) NULL,
    candidate_state VARCHAR(16) NOT NULL,
    blocked_reason VARCHAR(40) NULL,
    added_after_freeze INTEGER NOT NULL,
    added_reason VARCHAR(500) NULL,
    withdrawn_reason VARCHAR(500) NULL,
    candidate_number VARCHAR(40) NULL,
    centre_id CHAR(26) NULL,
    sub_centre_id CHAR(26) NULL,
    outcome VARCHAR(12) NULL,
    was_present INTEGER NULL,
    result_imported_at VARCHAR(32) NULL,
    result_published_at VARCHAR(32) NULL,
    attestation_state VARCHAR(12) NULL,
    attestation_document_id CHAR(26) NULL,
    attestation_signed_by VARCHAR(40) NULL,
    attestation_signed_at VARCHAR(32) NULL,
    birth_act_alerted_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_exam_candidates_person ON pp_exam_candidates (tenant_id, session_id, person_id);
CREATE INDEX pp_exam_candidates_list ON pp_exam_candidates (tenant_id, list_id);
CREATE INDEX pp_exam_candidates_number ON pp_exam_candidates (tenant_id, session_id, candidate_number);

CREATE TABLE pp_exam_centres (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    territory_id VARCHAR(40) NOT NULL,
    parent_centre_id CHAR(26) NULL,
    code VARCHAR(40) NOT NULL,
    label VARCHAR(160) NOT NULL,
    host_establishment_id VARCHAR(40) NULL,
    capacity INTEGER NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_exam_centres_code ON pp_exam_centres (tenant_id, session_id, code);

CREATE TABLE pp_exam_officials (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    centre_id CHAR(26) NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    staff_ref VARCHAR(60) NULL,
    function_code VARCHAR(40) NOT NULL,
    half_days INTEGER NOT NULL,
    copies_marked INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pp_exam_officials_centre ON pp_exam_officials (tenant_id, session_id, centre_id);

CREATE TABLE pp_exam_transfers (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    candidate_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    dossier TEXT NOT NULL,
    signature VARCHAR(128) NOT NULL,
    target_label VARCHAR(160) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pp_exam_transfers_candidate ON pp_exam_transfers (tenant_id, candidate_id);
