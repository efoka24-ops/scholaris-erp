-- S09 Résultats, conseils de classe, délibérations et file de génération des bulletins.

CREATE TABLE results (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    period_label VARCHAR(80) NOT NULL,
    average DECIMAL(7,2) NULL,
    rank_in_class INTEGER NULL,
    class_size INTEGER NULL,
    credits_earned INTEGER NULL,
    credits_total INTEGER NULL,
    mention VARCHAR(60) NULL,
    proposal VARCHAR(60) NULL,
    decision VARCHAR(60) NULL,
    rule_version INTEGER NOT NULL,
    details TEXT NOT NULL,
    status VARCHAR(12) NOT NULL,
    computed_at VARCHAR(32) NOT NULL,
    published_at VARCHAR(32) NULL
);

CREATE UNIQUE INDEX results_cell ON results (tenant_id, establishment_id, person_id, period_id);
CREATE INDEX results_structure ON results (tenant_id, establishment_id, structure_id, period_id);

CREATE TABLE class_councils (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    period_label VARCHAR(80) NOT NULL,
    status VARCHAR(12) NOT NULL,
    held_on VARCHAR(10) NULL,
    rule_version INTEGER NOT NULL,
    summary TEXT NOT NULL,
    minutes TEXT NULL,
    minutes_document_id VARCHAR(40) NULL,
    minutes_status VARCHAR(20) NULL,
    created_by VARCHAR(40) NOT NULL,
    validated_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    validated_at VARCHAR(32) NULL
);

CREATE UNIQUE INDEX class_councils_cell ON class_councils (tenant_id, establishment_id, structure_id, period_id);

CREATE TABLE council_lines (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    council_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    average DECIMAL(7,2) NULL,
    rank_in_class INTEGER NULL,
    unjustified_absences INTEGER NOT NULL,
    conduct VARCHAR(60) NULL,
    proposal VARCHAR(60) NULL,
    decision VARCHAR(60) NULL,
    reason VARCHAR(255) NULL,
    appraisal VARCHAR(255) NULL
);

CREATE INDEX council_lines_council ON council_lines (tenant_id, council_id);

CREATE TABLE deliberations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    period_label VARCHAR(80) NOT NULL,
    status VARCHAR(12) NOT NULL,
    president_id VARCHAR(40) NOT NULL,
    members_expected INTEGER NOT NULL,
    members_present TEXT NOT NULL,
    quorum_reached INTEGER NOT NULL,
    rule_version INTEGER NOT NULL,
    summary TEXT NOT NULL,
    minutes TEXT NULL,
    minutes_document_id VARCHAR(40) NULL,
    minutes_status VARCHAR(20) NULL,
    signatures TEXT NULL,
    reopen_reason VARCHAR(255) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    closed_at VARCHAR(32) NULL
);

CREATE UNIQUE INDEX deliberations_cell ON deliberations (tenant_id, establishment_id, structure_id, period_id);

CREATE TABLE deliberation_lines (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    deliberation_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    average DECIMAL(7,2) NULL,
    credits_earned INTEGER NOT NULL,
    credits_total INTEGER NOT NULL,
    unit_averages TEXT NOT NULL,
    proposal VARCHAR(60) NULL,
    decision VARCHAR(60) NULL,
    reason VARCHAR(255) NULL
);

CREATE INDEX deliberation_lines_parent ON deliberation_lines (tenant_id, deliberation_id);

CREATE TABLE report_card_requests (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    council_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    status VARCHAR(12) NOT NULL,
    document_id VARCHAR(40) NULL,
    error_message VARCHAR(255) NULL,
    created_at VARCHAR(32) NOT NULL,
    processed_at VARCHAR(32) NULL
);

CREATE UNIQUE INDEX report_card_requests_cell ON report_card_requests (tenant_id, council_id, person_id);
