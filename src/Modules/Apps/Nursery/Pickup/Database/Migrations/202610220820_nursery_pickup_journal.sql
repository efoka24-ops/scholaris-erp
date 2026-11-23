-- Maternelle privée : sortie sécurisée (EX-MAT-09 à 15). Journal unique des arrivées, remises, refus et maintiens ; alertes de
-- non-récupération ; restrictions de garde ; codes du jour (haché, jamais en clair) ; demandes d'ajout d'un autorisé par un parent.
-- Les personnes autorisées restent dans la table du socle `authorized_pickups` (source unique), lue par son contrat.
CREATE TABLE nu_gate_events (
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
CREATE UNIQUE INDEX nu_gate_events_client ON nu_gate_events (tenant_id, client_uuid);
CREATE INDEX nu_gate_events_day ON nu_gate_events (tenant_id, establishment_id, school_day, learner_id);

CREATE TABLE nu_late_alerts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    school_day VARCHAR(10) NOT NULL,
    alert_level VARCHAR(10) NOT NULL,
    sent_at VARCHAR(32) NOT NULL,
    resolved_at VARCHAR(32) NULL
);
CREATE UNIQUE INDEX nu_late_alerts_once ON nu_late_alerts (tenant_id, learner_id, school_day, alert_level);
CREATE INDEX nu_late_alerts_day ON nu_late_alerts (tenant_id, establishment_id, school_day);

CREATE TABLE nu_custody_restrictions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    restricted_name VARCHAR(160) NOT NULL,
    restricted_person_id CHAR(26) NULL,
    restricted_pickup_id CHAR(26) NULL,
    nature VARCHAR(20) NOT NULL,
    decision_reference VARCHAR(120) NOT NULL,
    valid_from VARCHAR(10) NOT NULL,
    valid_to VARCHAR(10) NULL,
    status VARCHAR(10) NOT NULL,
    recorded_by VARCHAR(40) NOT NULL,
    lifted_reason VARCHAR(500) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX nu_custody_learner ON nu_custody_restrictions (tenant_id, establishment_id, learner_id, status);

CREATE TABLE nu_pickup_codes (
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
CREATE INDEX nu_pickup_codes_day ON nu_pickup_codes (tenant_id, establishment_id, valid_on, learner_id);

CREATE TABLE nu_pickup_requests (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    payload TEXT NOT NULL,
    status VARCHAR(10) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    pickup_id CHAR(26) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX nu_pickup_requests_state ON nu_pickup_requests (tenant_id, establishment_id, status);
