-- Passage de classe et conseil des maîtres de la Primaire publique (T665) : propositions selon la politique nationale versionnée
-- (RG-PAS-1), décisions motivées du conseil, avis de l'inspecteur au deuxième redoublement dans le même niveau (RG-PAS-2), recours (EX-PAS-03).
CREATE TABLE pp_councils (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    year_label VARCHAR(9) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    held_on VARCHAR(10) NOT NULL,
    attendees TEXT NOT NULL,
    minutes_document_id CHAR(26) NULL,
    recorded_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pp_councils_class ON pp_councils (tenant_id, establishment_id, year_label, structure_id);

CREATE TABLE pp_promotion_decisions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    year_label VARCHAR(9) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    level_code VARCHAR(16) NOT NULL,
    proposal VARCHAR(24) NOT NULL,
    key_na_count INTEGER NOT NULL,
    decision VARCHAR(24) NULL,
    decision_state VARCHAR(16) NOT NULL,
    reason VARCHAR(1000) NULL,
    policy_version VARCHAR(40) NOT NULL,
    inspector_opinion_approval_id CHAR(26) NULL,
    inspector_opinion VARCHAR(16) NULL,
    inspector_comment VARCHAR(500) NULL,
    appeal_until VARCHAR(10) NULL,
    appeal_state VARCHAR(12) NULL,
    appeal_reason VARCHAR(1000) NULL,
    council_id CHAR(26) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_promotion_person ON pp_promotion_decisions (tenant_id, year_label, person_id);
CREATE INDEX pp_promotion_class ON pp_promotion_decisions (tenant_id, establishment_id, year_label, structure_id);
