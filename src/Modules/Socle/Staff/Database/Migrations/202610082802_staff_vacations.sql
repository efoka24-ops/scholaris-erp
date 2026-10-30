-- Vacations : taux par categorie, contrats, heures realisees, releves mensuels. Montants entiers en XAF.

CREATE TABLE vacation_rates (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    category VARCHAR(40) NOT NULL,
    hourly_rate INTEGER NOT NULL,
    monthly_cap_minutes INTEGER NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX vacation_rates_category ON vacation_rates (tenant_id, establishment_id, category);

CREATE TABLE vacation_contracts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    reference VARCHAR(60) NOT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NOT NULL,
    hourly_rate INTEGER NULL,
    max_minutes INTEGER NULL,
    status VARCHAR(20) NOT NULL,
    approval_id CHAR(26) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX vacation_contracts_staff ON vacation_contracts (tenant_id, staff_id, status);
CREATE INDEX vacation_contracts_establishment ON vacation_contracts (tenant_id, establishment_id, status);

CREATE TABLE vacation_hours (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    assignment_id CHAR(26) NULL,
    contract_id CHAR(26) NULL,
    slot_id VARCHAR(40) NULL,
    held_on VARCHAR(10) NOT NULL,
    minutes INTEGER NOT NULL,
    session_kind VARCHAR(8) NOT NULL,
    content VARCHAR(500) NULL,
    status VARCHAR(12) NOT NULL,
    source VARCHAR(12) NOT NULL,
    declared_by VARCHAR(40) NULL,
    teacher_signed_at VARCHAR(32) NULL,
    validated_by VARCHAR(40) NULL,
    countersigned_at VARCHAR(32) NULL,
    rejection_reason VARCHAR(255) NULL,
    statement_id CHAR(26) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX vacation_hours_slot ON vacation_hours (tenant_id, staff_id, slot_id, held_on);
CREATE INDEX vacation_hours_staff ON vacation_hours (tenant_id, establishment_id, staff_id, held_on);
CREATE INDEX vacation_hours_status ON vacation_hours (tenant_id, establishment_id, status);

CREATE TABLE vacation_statements (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    contract_id CHAR(26) NOT NULL,
    period CHAR(7) NOT NULL,
    minutes_total INTEGER NOT NULL,
    hetd_milli INTEGER NOT NULL,
    hourly_rate INTEGER NOT NULL,
    gross_amount INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL,
    approval_id CHAR(26) NULL,
    validated_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    paid_on VARCHAR(10) NULL,
    payment_reference VARCHAR(80) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX vacation_statements_month ON vacation_statements (tenant_id, establishment_id, staff_id, period);
CREATE INDEX vacation_statements_status ON vacation_statements (tenant_id, establishment_id, status);
