-- Modules 22, 23, 24, 37, 39, 40, 42 et 43 : comptabilite, achats, stocks,
-- gestion electronique des documents, rendez-vous, administration publique,
-- paie et objectifs.
--
-- Un seul fichier parce que ces domaines se referencent : une commande
-- d'achat alimente le stock, une paie produit une ecriture comptable. Les
-- separer en huit migrations aurait impose un ordre de creation des cles
-- etrangeres sans le rendre visible.

-- === Module 22 : comptabilite (plan SYSCOHADA) ==========================
--
-- Le plan comptable est propre a chaque etablissement : deux ecoles n'ont pas
-- le meme detail de comptes, le code n'est donc unique que par tenant.

CREATE TABLE ledger_accounts (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    account_class SMALLINT NOT NULL,
    nature VARCHAR(20) NOT NULL DEFAULT 'BILAN',
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT lacc_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX lacc_code_unique ON ledger_accounts (tenant_id, code);
CREATE INDEX lacc_class_index ON ledger_accounts (tenant_id, account_class);

-- Une ecriture n'est modifiable que tant qu'elle est au brouillard. Une fois
-- validee (posted = 1) elle ne bouge plus : c'est ce qui distingue une
-- comptabilite d'un tableur, et un controle s'appuie dessus.
CREATE TABLE ledger_entries (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    reference VARCHAR(40) NOT NULL,
    journal VARCHAR(20) NOT NULL DEFAULT 'OD',
    entry_date DATE NOT NULL,
    label VARCHAR(255) NOT NULL,
    academic_year_id CHAR(36) NULL,
    posted SMALLINT NOT NULL DEFAULT 0,
    posted_at DATETIME NULL,
    source VARCHAR(30) NULL,
    source_id CHAR(36) NULL,
    created_by CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT lent_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT lent_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
    CONSTRAINT lent_user_fk FOREIGN KEY (created_by) REFERENCES users (id)
);

CREATE UNIQUE INDEX lent_reference_unique ON ledger_entries (tenant_id, reference);
CREATE INDEX lent_date_index ON ledger_entries (tenant_id, entry_date);
CREATE INDEX lent_source_index ON ledger_entries (tenant_id, source, source_id);

CREATE TABLE ledger_lines (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    entry_id CHAR(36) NOT NULL,
    account_id CHAR(36) NOT NULL,
    label VARCHAR(255) NULL,
    debit DECIMAL(14,2) NOT NULL DEFAULT 0,
    credit DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT llin_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT llin_entry_fk FOREIGN KEY (entry_id) REFERENCES ledger_entries (id),
    CONSTRAINT llin_account_fk FOREIGN KEY (account_id) REFERENCES ledger_accounts (id)
);

CREATE INDEX llin_entry_index ON ledger_lines (tenant_id, entry_id);
CREATE INDEX llin_account_index ON ledger_lines (tenant_id, account_id);

CREATE TABLE budget_lines (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    academic_year_id CHAR(36) NULL,
    account_id CHAR(36) NULL,
    label VARCHAR(255) NOT NULL,
    direction VARCHAR(10) NOT NULL DEFAULT 'CHARGE',
    planned_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT blin_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT blin_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
    CONSTRAINT blin_account_fk FOREIGN KEY (account_id) REFERENCES ledger_accounts (id)
);

CREATE INDEX blin_year_index ON budget_lines (tenant_id, academic_year_id);

-- === Module 24 : stocks =================================================
--
-- Declare avant les achats : une ligne de commande peut pointer vers un
-- article, et la cle etrangere exige que la table existe deja.

CREATE TABLE stock_items (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'unite',
    quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
    min_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    location VARCHAR(255) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT sitm_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX sitm_code_unique ON stock_items (tenant_id, code);
CREATE INDEX sitm_category_index ON stock_items (tenant_id, category);

-- Chaque mouvement est conserve : le stock affiche est un solde, mais c'est
-- le journal des mouvements qui permet d'expliquer un ecart d'inventaire.
CREATE TABLE stock_movements (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    item_id CHAR(36) NOT NULL,
    direction VARCHAR(10) NOT NULL,
    quantity DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NULL,
    reference VARCHAR(60) NULL,
    moved_on DATE NOT NULL,
    created_by CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT smov_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT smov_item_fk FOREIGN KEY (item_id) REFERENCES stock_items (id),
    CONSTRAINT smov_user_fk FOREIGN KEY (created_by) REFERENCES users (id)
);

CREATE INDEX smov_item_index ON stock_movements (tenant_id, item_id, moved_on);

-- === Module 23 : achats et fournisseurs =================================

CREATE TABLE suppliers (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    contact_name VARCHAR(255) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(255) NULL,
    address VARCHAR(255) NULL,
    tax_number VARCHAR(60) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT supp_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE INDEX supp_name_index ON suppliers (tenant_id, name);

-- Le circuit d'une commande : DEMANDE -> APPROUVEE -> RECUE, ou REFUSEE.
-- L'approbation et la reception sont datees et nominatives, parce qu'une
-- depense doit pouvoir etre rattachee a qui l'a engagee.
CREATE TABLE purchase_orders (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    reference VARCHAR(40) NOT NULL,
    supplier_id CHAR(36) NULL,
    subject VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'DEMANDE',
    ordered_on DATE NOT NULL,
    expected_on DATE NULL,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    requested_by CHAR(36) NULL,
    approved_by CHAR(36) NULL,
    approved_at DATETIME NULL,
    received_at DATETIME NULL,
    decision_note VARCHAR(255) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT pord_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT pord_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT pord_requester_fk FOREIGN KEY (requested_by) REFERENCES users (id),
    CONSTRAINT pord_approver_fk FOREIGN KEY (approved_by) REFERENCES users (id)
);

CREATE UNIQUE INDEX pord_reference_unique ON purchase_orders (tenant_id, reference);
CREATE INDEX pord_status_index ON purchase_orders (tenant_id, status);

CREATE TABLE purchase_order_lines (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    order_id CHAR(36) NOT NULL,
    item_id CHAR(36) NULL,
    label VARCHAR(255) NOT NULL,
    quantity DECIMAL(14,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT poli_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT poli_order_fk FOREIGN KEY (order_id) REFERENCES purchase_orders (id),
    CONSTRAINT poli_item_fk FOREIGN KEY (item_id) REFERENCES stock_items (id)
);

CREATE INDEX poli_order_index ON purchase_order_lines (tenant_id, order_id);

-- === Module 42 : paie ===================================================

CREATE TABLE payroll_periods (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    label VARCHAR(60) NOT NULL,
    year SMALLINT NOT NULL,
    month SMALLINT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'OUVERTE',
    closed_at DATETIME NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT pper_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX pper_month_unique ON payroll_periods (tenant_id, year, month);

-- Les cotisations sont stockees en clair plutot que recalculees a l'affichage :
-- un bulletin doit rester identique a ce qui a ete verse, meme si le taux CNPS
-- change l'annee suivante.
CREATE TABLE payslips (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    period_id CHAR(36) NOT NULL,
    employee_id CHAR(36) NOT NULL,
    base_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
    gross_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    cnps_employee DECIMAL(14,2) NOT NULL DEFAULT 0,
    cnps_employer DECIMAL(14,2) NOT NULL DEFAULT 0,
    income_tax DECIMAL(14,2) NOT NULL DEFAULT 0,
    other_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'BROUILLON',
    paid_on DATE NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT psli_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT psli_period_fk FOREIGN KEY (period_id) REFERENCES payroll_periods (id),
    CONSTRAINT psli_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id)
);

CREATE UNIQUE INDEX psli_employee_unique ON payslips (tenant_id, period_id, employee_id);

CREATE TABLE payslip_lines (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    payslip_id CHAR(36) NOT NULL,
    label VARCHAR(255) NOT NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'GAIN',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT plli_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT plli_payslip_fk FOREIGN KEY (payslip_id) REFERENCES payslips (id)
);

CREATE INDEX plli_payslip_index ON payslip_lines (tenant_id, payslip_id);

-- === Module 37 : gestion electronique des documents =====================
--
-- Le fichier est pose hors de la racine web et n'est jamais servi
-- directement : il transite par une action qui verifie l'etablissement et la
-- permission. Un chemin devinable ne doit pas suffire a lire une piece.

CREATE TABLE documents (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'AUTRE',
    description VARCHAR(500) NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(120) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    checksum VARCHAR(64) NULL,
    visibility VARCHAR(20) NOT NULL DEFAULT 'INTERNE',
    related_type VARCHAR(40) NULL,
    related_id CHAR(36) NULL,
    uploaded_by CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT docu_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT docu_user_fk FOREIGN KEY (uploaded_by) REFERENCES users (id)
);

CREATE INDEX docu_category_index ON documents (tenant_id, category);
CREATE INDEX docu_related_index ON documents (tenant_id, related_type, related_id);

-- === Module 39 : rendez-vous ============================================

CREATE TABLE appointments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    staff_id CHAR(36) NOT NULL,
    requester_id CHAR(36) NULL,
    requester_name VARCHAR(255) NOT NULL,
    student_id CHAR(36) NULL,
    subject VARCHAR(255) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    duration_minutes SMALLINT NOT NULL DEFAULT 30,
    location VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'DEMANDE',
    notes TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT appt_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT appt_staff_fk FOREIGN KEY (staff_id) REFERENCES users (id),
    CONSTRAINT appt_requester_fk FOREIGN KEY (requester_id) REFERENCES users (id),
    CONSTRAINT appt_student_fk FOREIGN KEY (student_id) REFERENCES students (id)
);

CREATE INDEX appt_staff_index ON appointments (tenant_id, staff_id, scheduled_at);
CREATE INDEX appt_status_index ON appointments (tenant_id, status);

-- === Module 40 : administration publique ================================
--
-- Actes de carriere et notes de service. Le numero de reference est unique
-- par etablissement : un acte se cite par son numero, il ne peut pas y en
-- avoir deux.

CREATE TABLE staff_decisions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    employee_id CHAR(36) NOT NULL,
    kind VARCHAR(30) NOT NULL,
    reference VARCHAR(60) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    content TEXT NULL,
    decided_on DATE NOT NULL,
    effective_on DATE NULL,
    signed_by VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PROJET',
    created_by CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT sdec_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT sdec_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT sdec_user_fk FOREIGN KEY (created_by) REFERENCES users (id)
);

CREATE UNIQUE INDEX sdec_reference_unique ON staff_decisions (tenant_id, reference);
CREATE INDEX sdec_employee_index ON staff_decisions (tenant_id, employee_id);

CREATE TABLE service_notes (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    reference VARCHAR(60) NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    audience VARCHAR(30) NOT NULL DEFAULT 'TOUS',
    published_on DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PROJET',
    created_by CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT snot_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT snot_user_fk FOREIGN KEY (created_by) REFERENCES users (id)
);

CREATE UNIQUE INDEX snot_reference_unique ON service_notes (tenant_id, reference);

-- === Module 43 : objectifs et performances ==============================
--
-- La valeur atteinte est relevee, pas calculee : un indicateur peut porter
-- sur une donnee que l'application ne detient pas, comme un taux de reussite
-- au BEPC publie par le ministere.

CREATE TABLE objectives (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    academic_year_id CHAR(36) NULL,
    scope VARCHAR(30) NOT NULL DEFAULT 'ETABLISSEMENT',
    label VARCHAR(255) NOT NULL,
    indicator VARCHAR(255) NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'pourcent',
    target_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    due_on DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'EN_COURS',
    owner_id CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT objv_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT objv_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
    CONSTRAINT objv_owner_fk FOREIGN KEY (owner_id) REFERENCES users (id)
);

CREATE INDEX objv_year_index ON objectives (tenant_id, academic_year_id);
CREATE INDEX objv_status_index ON objectives (tenant_id, status);
