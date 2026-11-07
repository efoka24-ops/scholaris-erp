-- Maternelle privée : passage de fin d'année (MQ-09, MQ-24, EX-MAT-21, 22). Proposition (section suivante ou primaire), avis de
-- l'éducatrice, décision de la direction avec motif ; fiche de liaison signée vers l'école primaire. Aucune règle officielle codée :
-- le maintien exige un motif, et une demande des parents si `nursery.transition.retain_requires_parent_request` est vrai.
CREATE TABLE nu_transition_decisions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    from_structure_id VARCHAR(40) NOT NULL,
    target_structure_id VARCHAR(40) NULL,
    proposal VARCHAR(16) NOT NULL,
    decision VARCHAR(16) NULL,
    educator_opinion VARCHAR(500) NULL,
    educator_id VARCHAR(40) NULL,
    decision_reason VARCHAR(500) NULL,
    parent_request_ref VARCHAR(120) NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    applied_at VARCHAR(32) NULL,
    status VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX nu_transition_once ON nu_transition_decisions (tenant_id, learner_id, academic_year_id);

-- Fiche de liaison : aucune colonne de restriction de garde ni d'allergie (minimisation).
CREATE TABLE nu_liaison_sheets (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    path_summary VARCHAR(1000) NULL,
    strengths VARCHAR(2000) NULL,
    needs VARCHAR(1000) NULL,
    destination VARCHAR(160) NULL,
    status VARCHAR(10) NOT NULL,
    document_id VARCHAR(40) NULL,
    signed_by VARCHAR(40) NULL,
    signed_at VARCHAR(32) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX nu_liaison_once ON nu_liaison_sheets (tenant_id, learner_id, academic_year_id)
