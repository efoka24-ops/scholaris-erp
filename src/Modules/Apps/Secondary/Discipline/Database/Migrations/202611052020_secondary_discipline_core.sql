-- T864 (spec 005, lot E2-B) : discipline du secondaire. L'appel, les justificatifs et les sanctions du socle restent au socle (D1) :
-- ces tables portent seulement ce que le socle ne porte pas : propositions du barème (RG-PRE-1, RG-PRE-2), conseils de discipline
-- (RG-PRE-3, procès-verbal), exclusions de cours (Q14) et journal des notifications aux familles (NOT-03, NOT-06).

CREATE TABLE se_conduct_proposals (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NULL,
    period_from VARCHAR(10) NOT NULL,
    period_to VARCHAR(10) NOT NULL,
    period_label VARCHAR(80) NOT NULL,
    unjustified_hours DECIMAL(6,2) NOT NULL,
    proposed_kind VARCHAR(40) NOT NULL,
    proposed_label VARCHAR(160) NOT NULL,
    decision_level VARCHAR(40) NOT NULL,
    scale_version VARCHAR(40) NOT NULL,
    repeat_offence SMALLINT NOT NULL,
    status VARCHAR(12) NOT NULL,
    proposed_by VARCHAR(40) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    decision_reason VARCHAR(1000) NULL,
    council_id CHAR(26) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_conduct_proposals_once ON se_conduct_proposals (tenant_id, establishment_id, person_id, period_from, period_to, proposed_kind);
CREATE INDEX se_conduct_proposals_status ON se_conduct_proposals (tenant_id, establishment_id, status);

CREATE TABLE se_discipline_councils (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NOT NULL,
    proposal_id CHAR(26) NULL,
    facts VARCHAR(2000) NOT NULL,
    convened_on VARCHAR(10) NOT NULL,
    hearing_on VARCHAR(10) NOT NULL,
    hearing_time VARCHAR(5) NOT NULL,
    place VARCHAR(160) NOT NULL,
    members TEXT NOT NULL,
    status VARCHAR(12) NOT NULL,
    decision_kind VARCHAR(40) NULL,
    decision_reason VARCHAR(1000) NULL,
    minutes_text TEXT NULL,
    student_heard SMALLINT NOT NULL,
    parents_heard SMALLINT NOT NULL,
    exclusion_days INTEGER NULL,
    convened_by VARCHAR(40) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_discipline_councils_person ON se_discipline_councils (tenant_id, establishment_id, person_id);

CREATE TABLE se_class_exclusions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NOT NULL,
    session_date VARCHAR(10) NOT NULL,
    hour_label VARCHAR(20) NOT NULL,
    session_id VARCHAR(40) NULL,
    reason VARCHAR(500) NOT NULL,
    reported_by VARCHAR(40) NOT NULL,
    client_uuid VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_class_exclusions_client ON se_class_exclusions (tenant_id, establishment_id, client_uuid);
CREATE INDEX se_class_exclusions_day ON se_class_exclusions (tenant_id, establishment_id, class_id, session_date);

CREATE TABLE se_discipline_notices (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    notice_code VARCHAR(10) NOT NULL,
    event_ref VARCHAR(40) NOT NULL,
    recipient_person_id VARCHAR(40) NOT NULL,
    template_key VARCHAR(80) NOT NULL,
    delivery_status VARCHAR(20) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_discipline_notices_once ON se_discipline_notices (tenant_id, establishment_id, notice_code, event_ref, recipient_person_id);
