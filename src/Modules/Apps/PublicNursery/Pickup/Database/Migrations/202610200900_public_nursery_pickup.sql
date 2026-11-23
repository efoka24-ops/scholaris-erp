-- Journal unique des arrivées, remises, refus et alertes (source de DOC-19 et du contrôle O7).
CREATE TABLE pn_gate_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NULL,
    school_day VARCHAR(10) NOT NULL,
    event_type VARCHAR(24) NOT NULL,
    pickup_id CHAR(26) NULL,
    code_id CHAR(26) NULL,
    declared_name VARCHAR(160) NULL,
    restriction_id CHAR(26) NULL,
    stale_authorization SMALLINT NOT NULL DEFAULT 0,
    origin VARCHAR(10) NOT NULL,
    client_uuid VARCHAR(40) NULL,
    occurred_at VARCHAR(32) NOT NULL,
    recorded_at VARCHAR(32) NOT NULL,
    actor_id VARCHAR(40) NOT NULL,
    remark VARCHAR(500) NULL
);
CREATE UNIQUE INDEX pn_gate_events_client ON pn_gate_events (tenant_id, client_uuid);
CREATE INDEX pn_gate_events_day ON pn_gate_events (tenant_id, establishment_id, school_day, learner_id);

CREATE TABLE pn_late_alerts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    school_day VARCHAR(10) NOT NULL,
    alert_level VARCHAR(10) NOT NULL,
    sent_at VARCHAR(32) NOT NULL,
    delivery_ref VARCHAR(40) NULL,
    resolved_at VARCHAR(32) NULL
);
CREATE UNIQUE INDEX pn_late_alerts_once ON pn_late_alerts (tenant_id, learner_id, school_day, alert_level);

CREATE TABLE pn_custody_restrictions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    restricted_name VARCHAR(160) NOT NULL,
    restricted_person_id CHAR(26) NULL,
    restricted_pickup_id CHAR(26) NULL,
    nature VARCHAR(20) NOT NULL,
    decision_reference VARCHAR(120) NOT NULL,
    decision_document_ref VARCHAR(120) NULL,
    valid_from VARCHAR(10) NOT NULL,
    valid_to VARCHAR(10) NULL,
    status VARCHAR(10) NOT NULL,
    recorded_by VARCHAR(40) NOT NULL,
    lifted_reason VARCHAR(500) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX pn_custody_learner ON pn_custody_restrictions (tenant_id, establishment_id, learner_id, status);

CREATE TABLE pn_pickup_codes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    pickup_id CHAR(26) NOT NULL,
    code_hash VARCHAR(128) NOT NULL,
    valid_on VARCHAR(10) NOT NULL,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(10) NOT NULL,
    used_event_id CHAR(26) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    used_at VARCHAR(32) NULL
);
CREATE INDEX pn_pickup_codes_day ON pn_pickup_codes (tenant_id, establishment_id, valid_on, learner_id);

CREATE TABLE pn_pickup_cards (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    pickup_id CHAR(26) NOT NULL,
    document_id VARCHAR(40) NOT NULL,
    status VARCHAR(10) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    revoked_at VARCHAR(32) NULL
);
CREATE INDEX pn_pickup_cards_pickup ON pn_pickup_cards (tenant_id, pickup_id);

CREATE TABLE pn_sync_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    batch_uuid VARCHAR(40) NOT NULL,
    batch_kind VARCHAR(16) NOT NULL,
    user_id VARCHAR(40) NOT NULL,
    device_ref VARCHAR(80) NULL,
    package_generated_at VARCHAR(32) NULL,
    items_count INTEGER NOT NULL,
    accepted_count INTEGER NOT NULL,
    duplicate_count INTEGER NOT NULL,
    rejected_count INTEGER NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    received_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_sync_batches_uuid ON pn_sync_batches (tenant_id, batch_uuid);