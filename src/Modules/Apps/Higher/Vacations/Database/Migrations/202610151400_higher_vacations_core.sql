-- Supérieur, paiement des vacations (EX-ENS-06 état, EX-ENS-07 paiement, RG-ENS-1 à 5, TST-11) : état mensuel par enseignant et par
-- département, construit à partir des séances validées par le chef de département ; HETD par nature (coefficients du socle, D14), taux
-- par grade et niveau (D15), retenues paramétrables (aucune par défaut), validation école puis direction, paiement par le comptable.
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), montants entiers XAF, aucune clé étrangère,
-- aucun AUTO_INCREMENT, aucun mot réservé.

CREATE TABLE hi_vacation_statements (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    staff_id VARCHAR(40) NULL,
    period_month VARCHAR(7) NOT NULL,
    grade VARCHAR(60) NULL,
    staff_kind VARCHAR(20) NULL,
    coef_cm INTEGER NOT NULL,
    coef_td INTEGER NOT NULL,
    coef_tp INTEGER NOT NULL,
    coef_other INTEGER NOT NULL,
    cm_minutes INTEGER NOT NULL,
    td_minutes INTEGER NOT NULL,
    tp_minutes INTEGER NOT NULL,
    other_minutes INTEGER NOT NULL,
    cm_hetd_milli INTEGER NOT NULL,
    td_hetd_milli INTEGER NOT NULL,
    tp_hetd_milli INTEGER NOT NULL,
    other_hetd_milli INTEGER NOT NULL,
    hetd_milli INTEGER NOT NULL,
    hourly_rate INTEGER NULL,
    gross_amount INTEGER NOT NULL,
    deductions TEXT NOT NULL,
    deductions_amount INTEGER NOT NULL,
    net_amount INTEGER NOT NULL,
    excluded_minutes INTEGER NOT NULL DEFAULT 0,
    pending_sessions INTEGER NOT NULL DEFAULT 0,
    hold_reasons TEXT NULL,
    status VARCHAR(20) NOT NULL,
    prepared_by VARCHAR(40) NOT NULL,
    prepared_at VARCHAR(32) NOT NULL,
    school_validated_by VARCHAR(40) NULL,
    school_validated_at VARCHAR(32) NULL,
    approved_by VARCHAR(40) NULL,
    approved_at VARCHAR(32) NULL,
    rejected_by VARCHAR(40) NULL,
    rejected_at VARCHAR(32) NULL,
    rejection_reason VARCHAR(500) NULL,
    payment_method VARCHAR(20) NULL,
    payment_reference VARCHAR(80) NULL,
    paid_on VARCHAR(32) NULL,
    paid_by VARCHAR(40) NULL,
    paid_at VARCHAR(32) NULL,
    statement_document_id VARCHAR(40) NULL,
    payslip_document_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_vacation_statements_month ON hi_vacation_statements (tenant_id, establishment_id, department_id, person_id, period_month);

CREATE INDEX hi_vacation_statements_period ON hi_vacation_statements (tenant_id, establishment_id, period_month, status);

CREATE INDEX hi_vacation_statements_person ON hi_vacation_statements (tenant_id, establishment_id, person_id);

-- Lignes de l'état : une par séance validée ; `payable` = 0 si un contrôle RG-ENS-1/4 la refuse (motif conservé).
CREATE TABLE hi_vacation_statement_lines (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    statement_id CHAR(26) NOT NULL,
    session_log_id VARCHAR(40) NOT NULL,
    assignment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    level_label VARCHAR(80) NULL,
    group_id VARCHAR(40) NOT NULL,
    element_id VARCHAR(40) NOT NULL,
    element_code VARCHAR(30) NOT NULL,
    element_title VARCHAR(160) NOT NULL,
    session_kind VARCHAR(10) NOT NULL,
    held_on VARCHAR(32) NOT NULL,
    minutes INTEGER NOT NULL,
    hetd_milli INTEGER NOT NULL,
    hourly_rate INTEGER NULL,
    payable INTEGER NOT NULL,
    refusal TEXT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_vacation_statement_lines_statement ON hi_vacation_statement_lines (tenant_id, establishment_id, statement_id);
