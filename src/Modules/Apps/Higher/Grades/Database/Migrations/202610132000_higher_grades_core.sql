-- Supérieur, notes (NOT, EX-NOT-01 à 09, RG-NOT-1 à 5). Le socle n'expose aucun contrat d'écriture ni de lecture des notes
-- (Assessment\Contracts ne couvre que le catalogue, le calcul et les résultats publiés) : l'IPES porte donc ses évaluations par EC,
-- ses notes versionnées, leur historique en ajout seul, les corrections à double validation et les requêtes d'étudiants.
-- Les paramètres de comportement sont ceux du moteur du socle (assessment.step, absence_codes, lifecycle, correction.chain,
-- segregate_lock), lus avec les valeurs IPES par défaut.

CREATE TABLE hi_grade_assessments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    curriculum_id VARCHAR(40) NOT NULL,
    unit_id VARCHAR(40) NOT NULL,
    element_id VARCHAR(40) NOT NULL,
    element_code VARCHAR(20) NOT NULL,
    element_title VARCHAR(160) NOT NULL,
    semester_ordinal INTEGER NOT NULL,
    kind VARCHAR(12) NOT NULL,
    exam_session VARCHAR(12) NOT NULL,
    label VARCHAR(120) NOT NULL,
    held_on VARCHAR(32) NULL,
    entry_due_on VARCHAR(32) NULL,
    max_score DECIMAL(5,2) NOT NULL,
    weight_percent INTEGER NOT NULL,
    score_step DECIMAL(4,2) NOT NULL,
    anonymous INTEGER NOT NULL DEFAULT 0,
    anonymity_lifted_at VARCHAR(32) NULL,
    anonymity_lifted_by VARCHAR(40) NULL,
    status VARCHAR(16) NOT NULL,
    status_trail TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    last_reminded_on VARCHAR(32) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_grade_assessments_element ON hi_grade_assessments (tenant_id, establishment_id, element_id, group_id);
CREATE INDEX hi_grade_assessments_curriculum ON hi_grade_assessments (tenant_id, establishment_id, curriculum_id);
CREATE INDEX hi_grade_assessments_status ON hi_grade_assessments (tenant_id, establishment_id, status);

CREATE TABLE hi_grade_marks (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    assessment_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    enrolment_id VARCHAR(40) NULL,
    group_id VARCHAR(40) NULL,
    anonymous_code VARCHAR(16) NULL,
    score DECIMAL(5,2) NULL,
    absence_code VARCHAR(12) NULL,
    version INTEGER NOT NULL DEFAULT 0,
    entered_by VARCHAR(40) NULL,
    entered_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_grade_marks_cell ON hi_grade_marks (tenant_id, establishment_id, assessment_id, person_id);
CREATE UNIQUE INDEX hi_grade_marks_anonymous ON hi_grade_marks (tenant_id, establishment_id, assessment_id, anonymous_code);
CREATE INDEX hi_grade_marks_person ON hi_grade_marks (tenant_id, establishment_id, person_id);

-- Historique en ajout seul (RG-NOT-2) : une ligne par saisie, import, synchronisation ou correction ; jamais modifiée.
CREATE TABLE hi_grade_mark_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    mark_id CHAR(26) NOT NULL,
    assessment_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    event_kind VARCHAR(16) NOT NULL,
    old_score DECIMAL(5,2) NULL,
    old_absence VARCHAR(12) NULL,
    new_score DECIMAL(5,2) NULL,
    new_absence VARCHAR(12) NULL,
    mark_version INTEGER NOT NULL,
    actor_id VARCHAR(40) NOT NULL,
    reason VARCHAR(500) NULL,
    correction_id CHAR(26) NULL,
    client_uuid VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_grade_mark_events_mark ON hi_grade_mark_events (tenant_id, establishment_id, mark_id);
CREATE UNIQUE INDEX hi_grade_mark_events_uuid ON hi_grade_mark_events (tenant_id, establishment_id, client_uuid);

-- Corrections d'une note verrouillée (EX-NOT-06) : nouvelle valeur liée, motif, pièce, chaîne de validations.
CREATE TABLE hi_grade_corrections (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    mark_id CHAR(26) NOT NULL,
    assessment_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    old_score DECIMAL(5,2) NULL,
    old_absence VARCHAR(12) NULL,
    new_score DECIMAL(5,2) NULL,
    new_absence VARCHAR(12) NULL,
    reason VARCHAR(500) NOT NULL,
    attachment_ref VARCHAR(255) NOT NULL,
    query_id CHAR(26) NULL,
    requested_by VARCHAR(40) NOT NULL,
    status VARCHAR(12) NOT NULL,
    approval_step INTEGER NOT NULL DEFAULT 0,
    approval_trail TEXT NULL,
    decided_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_grade_corrections_status ON hi_grade_corrections (tenant_id, establishment_id, status);
CREATE INDEX hi_grade_corrections_mark ON hi_grade_corrections (tenant_id, establishment_id, mark_id);

-- Requête de l'étudiant sur une note publiée (EX-NOT-07, défaut 7 jours), réponse du chef de département (DOC-19).
CREATE TABLE hi_grade_queries (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    mark_id CHAR(26) NOT NULL,
    assessment_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    status VARCHAR(12) NOT NULL,
    response VARCHAR(1000) NULL,
    responded_by VARCHAR(40) NULL,
    responded_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_grade_queries_person ON hi_grade_queries (tenant_id, establishment_id, person_id);
CREATE INDEX hi_grade_queries_status ON hi_grade_queries (tenant_id, establishment_id, status);
