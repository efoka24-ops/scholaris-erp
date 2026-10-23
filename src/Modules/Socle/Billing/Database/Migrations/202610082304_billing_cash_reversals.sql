-- S04 Caisse, annulations, relances et rapprochements.

CREATE TABLE cash_sessions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    register_no INTEGER NOT NULL,
    cashier_id VARCHAR(40) NOT NULL,
    opened_at VARCHAR(32) NOT NULL,
    float_xaf INTEGER NOT NULL,
    status VARCHAR(10) NOT NULL,
    closed_at VARCHAR(32) NULL,
    expected_xaf INTEGER NULL,
    counted_xaf INTEGER NULL,
    variance_xaf INTEGER NULL,
    variance_user_id VARCHAR(40) NULL,
    note VARCHAR(255) NULL
);

CREATE INDEX cash_sessions_scope ON cash_sessions (tenant_id, establishment_id, status);

CREATE TABLE cash_deposits (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    reference VARCHAR(80) NOT NULL,
    deposited_by VARCHAR(40) NOT NULL,
    deposited_at VARCHAR(32) NOT NULL
);

CREATE INDEX cash_deposits_session ON cash_deposits (tenant_id, session_id);

CREATE TABLE reversals (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    payment_id CHAR(26) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(12) NOT NULL,
    approval_id CHAR(26) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    approved_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    decided_at VARCHAR(32) NULL
);

CREATE INDEX reversals_payment ON reversals (tenant_id, payment_id);
CREATE INDEX reversals_scope ON reversals (tenant_id, establishment_id, status);

CREATE TABLE reminders (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    installment_id CHAR(26) NOT NULL,
    offset_days INTEGER NOT NULL,
    due_on VARCHAR(10) NOT NULL,
    status VARCHAR(12) NOT NULL,
    sent_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX reminders_unique ON reminders (tenant_id, installment_id, offset_days);

CREATE TABLE reconciliations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    source VARCHAR(160) NOT NULL,
    imported_by VARCHAR(40) NOT NULL,
    imported_at VARCHAR(32) NOT NULL,
    lines_total INTEGER NOT NULL,
    matched INTEGER NOT NULL,
    unmatched INTEGER NOT NULL
);

CREATE INDEX reconciliations_scope ON reconciliations (tenant_id, establishment_id);

CREATE TABLE reconciliation_lines (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    reconciliation_id CHAR(26) NOT NULL,
    line_date VARCHAR(32) NOT NULL,
    operator_reference VARCHAR(80) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    phone VARCHAR(30) NULL,
    family_reference VARCHAR(30) NULL,
    status VARCHAR(14) NOT NULL,
    payment_id CHAR(26) NULL
);

CREATE INDEX reconciliation_lines_parent ON reconciliation_lines (tenant_id, reconciliation_id);
