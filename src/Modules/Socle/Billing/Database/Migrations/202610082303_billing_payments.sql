-- S04 Paiements : références famille, paiements, affectations, reçus, événements de webhook.

CREATE TABLE payment_references (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    guarantor_id VARCHAR(40) NOT NULL,
    reference VARCHAR(30) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX payment_references_reference ON payment_references (reference);
CREATE UNIQUE INDEX payment_references_guarantor ON payment_references (tenant_id, guarantor_id);

CREATE TABLE payments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    payer_reference_id CHAR(26) NULL,
    learner_id VARCHAR(40) NULL,
    invoice_id CHAR(26) NULL,
    channel VARCHAR(20) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    credit_xaf INTEGER NOT NULL,
    status VARCHAR(12) NOT NULL,
    external_reference VARCHAR(80) NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    payer_phone VARCHAR(30) NULL,
    cash_session_id CHAR(26) NULL,
    received_by VARCHAR(40) NULL,
    received_at VARCHAR(32) NOT NULL,
    reconciled_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX payments_idempotency ON payments (tenant_id, idempotency_key);
CREATE UNIQUE INDEX payments_external ON payments (tenant_id, external_reference);
CREATE INDEX payments_journal ON payments (tenant_id, establishment_id, received_at);
CREATE INDEX payments_reference ON payments (tenant_id, payer_reference_id);

CREATE TABLE allocations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    payment_id CHAR(26) NOT NULL,
    installment_id CHAR(26) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    rule_version VARCHAR(60) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX allocations_payment ON allocations (tenant_id, payment_id);
CREATE INDEX allocations_installment ON allocations (tenant_id, installment_id);

CREATE TABLE receipts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    payment_id CHAR(26) NOT NULL,
    year INTEGER NOT NULL,
    seq_no INTEGER NOT NULL,
    number VARCHAR(40) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    status VARCHAR(12) NOT NULL,
    issued_at VARCHAR(32) NOT NULL,
    pdf_path VARCHAR(255) NULL,
    sms_sent_at VARCHAR(32) NULL
);

CREATE UNIQUE INDEX receipts_sequence ON receipts (tenant_id, establishment_id, year, seq_no);
CREATE UNIQUE INDEX receipts_number ON receipts (tenant_id, establishment_id, number);
CREATE UNIQUE INDEX receipts_payment ON receipts (tenant_id, payment_id);

CREATE TABLE webhook_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    provider VARCHAR(40) NOT NULL,
    event_id VARCHAR(120) NOT NULL,
    tenant_id VARCHAR(40) NULL,
    outcome VARCHAR(20) NOT NULL,
    payment_id CHAR(26) NULL,
    received_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX webhook_events_event ON webhook_events (provider, event_id);
