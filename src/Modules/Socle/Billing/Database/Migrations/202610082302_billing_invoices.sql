-- S04 Facturation : factures, lignes, échéances (tranches) et remises.

CREATE TABLE invoices (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    enrollment_id VARCHAR(40) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NULL,
    status VARCHAR(12) NOT NULL,
    gross_xaf INTEGER NOT NULL,
    discount_xaf INTEGER NOT NULL,
    net_xaf INTEGER NOT NULL,
    paid_xaf INTEGER NOT NULL,
    balance_xaf INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX invoices_enrollment ON invoices (tenant_id, enrollment_id);
CREATE INDEX invoices_learner ON invoices (tenant_id, learner_id);
CREATE INDEX invoices_establishment ON invoices (tenant_id, establishment_id);

CREATE TABLE invoice_lines (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    invoice_id CHAR(26) NOT NULL,
    fee_item_id CHAR(26) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    label VARCHAR(160) NOT NULL,
    gross_xaf INTEGER NOT NULL,
    discount_xaf INTEGER NOT NULL,
    net_xaf INTEGER NOT NULL
);

CREATE INDEX invoice_lines_invoice ON invoice_lines (tenant_id, invoice_id);

CREATE TABLE installments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    invoice_id CHAR(26) NOT NULL,
    line_id CHAR(26) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    seq_no INTEGER NOT NULL,
    label VARCHAR(160) NOT NULL,
    due_on VARCHAR(10) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    paid_xaf INTEGER NOT NULL
);

CREATE INDEX installments_invoice ON installments (tenant_id, invoice_id);
CREATE INDEX installments_learner ON installments (tenant_id, learner_id);
CREATE INDEX installments_due ON installments (tenant_id, establishment_id, due_on);

CREATE TABLE discounts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    invoice_id CHAR(26) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    mode VARCHAR(10) NOT NULL,
    rate_value INTEGER NOT NULL,
    applies_to VARCHAR(20) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    status VARCHAR(12) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    approval_id CHAR(26) NULL,
    requested_by VARCHAR(40) NOT NULL,
    approved_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX discounts_invoice ON discounts (tenant_id, invoice_id);
