-- S04 Frais : catalogue et grilles. SQL portable (MySQL 8 et SQLite). Montants entiers en XAF, dates ISO 8601.

CREATE TABLE fee_items (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    name VARCHAR(160) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX fee_items_scope ON fee_items (tenant_id, establishment_id);

CREATE TABLE fee_schedules (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NULL,
    level VARCHAR(60) NULL,
    track VARCHAR(60) NULL,
    fee_item_id CHAR(26) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    terms TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX fee_schedules_scope ON fee_schedules (tenant_id, establishment_id, academic_year_id);
