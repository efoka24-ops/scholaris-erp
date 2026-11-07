-- Décisions de passage et fiches de liaison vers le primaire (TRA).
CREATE TABLE pn_transition_decisions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    from_level VARCHAR(8) NOT NULL,
    proposal VARCHAR(12) NOT NULL,
    decision VARCHAR(12) NULL,
    educator_opinion VARCHAR(500) NULL,
    parent_request_ref VARCHAR(120) NULL,
    parent_request_on VARCHAR(10) NULL,
    inspector_opinion VARCHAR(12) NULL,
    inspector_id VARCHAR(40) NULL,
    inspector_on VARCHAR(10) NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    status VARCHAR(10) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_transition_year ON pn_transition_decisions (tenant_id, learner_id, school_year);

CREATE TABLE pn_liaison_sheets (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    path_summary TEXT NULL,
    strengths TEXT NULL,
    needs TEXT NULL,
    status VARCHAR(10) NOT NULL,
    signed_by VARCHAR(40) NULL,
    signed_at VARCHAR(32) NULL,
    document_id VARCHAR(40) NULL,
    target_establishment_id VARCHAR(40) NULL,
    sent_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_liaison_year ON pn_liaison_sheets (tenant_id, learner_id, school_year);