-- Module 7 : gestion financiere.
--
-- Les montants sont en DECIMAL(14,2) et non en flottant : un flottant binaire
-- ne represente pas exactement les decimales, et des sommes de paiements
-- finiraient par ne plus tomber juste sur le solde d'une facture.

CREATE TABLE fee_structures (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    level_id CHAR(36) NULL,
    academic_year_id CHAR(36) NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fee_structures_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fee_structures_level_fk FOREIGN KEY (level_id) REFERENCES levels (id),
    CONSTRAINT fee_structures_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
);

CREATE INDEX fee_structures_lookup_index ON fee_structures (tenant_id, academic_year_id, level_id);

-- Echeancier indicatif : le paiement se fait contre le solde global de la
-- facture, jamais tranche par tranche.
CREATE TABLE fee_installments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    fee_structure_id CHAR(36) NOT NULL,
    label VARCHAR(255) NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    due_date DATE NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fee_installments_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fee_installments_structure_fk FOREIGN KEY (fee_structure_id) REFERENCES fee_structures (id)
);

CREATE INDEX fee_installments_structure_index ON fee_installments (tenant_id, fee_structure_id);

CREATE TABLE invoices (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    enrollment_id CHAR(36) NOT NULL,
    fee_structure_id CHAR(36) NOT NULL,
    academic_year_id CHAR(36) NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT invoices_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT invoices_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT invoices_enrollment_fk FOREIGN KEY (enrollment_id) REFERENCES enrollments (id),
    CONSTRAINT invoices_structure_fk FOREIGN KEY (fee_structure_id) REFERENCES fee_structures (id),
    CONSTRAINT invoices_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
);

CREATE INDEX invoices_student_index ON invoices (tenant_id, student_id, status);
CREATE INDEX invoices_enrollment_index ON invoices (tenant_id, enrollment_id);

CREATE TABLE payments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    invoice_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    method VARCHAR(30) NOT NULL,
    reference VARCHAR(255) NULL,
    receipt_number VARCHAR(50) NOT NULL,
    paid_at DATETIME NULL,
    received_by CHAR(36) NULL,
    notes TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT payments_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT payments_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT payments_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT payments_user_fk FOREIGN KEY (received_by) REFERENCES users (id)
);

CREATE UNIQUE INDEX payments_receipt_unique ON payments (tenant_id, receipt_number);
CREATE INDEX payments_invoice_index ON payments (tenant_id, invoice_id);

CREATE TABLE discounts (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NULL,
    invoice_id CHAR(36) NULL,
    type VARCHAR(20) NOT NULL,
    value DECIMAL(14,2) NOT NULL,
    reason VARCHAR(255) NULL,
    approved_by CHAR(36) NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT discounts_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT discounts_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT discounts_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT discounts_user_fk FOREIGN KEY (approved_by) REFERENCES users (id)
);

CREATE INDEX discounts_student_index ON discounts (tenant_id, student_id);

CREATE TABLE receipt_sequences (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    year VARCHAR(10) NOT NULL,
    last_number INT NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT receipt_sequences_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX receipt_sequences_tenant_year_unique ON receipt_sequences (tenant_id, year);

-- Passerelles Mobile Money (CAMOO, apisungku). Volontairement denormalisee,
-- sans contrainte vers students ni invoices, pour rester isolee du reste.
CREATE TABLE payment_transactions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    gateway_id VARCHAR(255) NULL,
    external_reference VARCHAR(255) NULL,
    amount DECIMAL(14,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'XAF',
    phone_number VARCHAR(50) NOT NULL,
    network VARCHAR(50) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
    fees DECIMAL(14,2) NULL,
    net_amount DECIMAL(14,2) NULL,
    student_id CHAR(36) NULL,
    invoice_id CHAR(36) NULL,
    raw_response TEXT NULL,
    notified_at DATETIME NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);

CREATE INDEX payment_transactions_tenant_index ON payment_transactions (tenant_id);
CREATE INDEX payment_transactions_gateway_index ON payment_transactions (gateway_id);
