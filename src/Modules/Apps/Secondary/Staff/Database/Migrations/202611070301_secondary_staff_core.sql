-- Personnel du secondaire (T911, T912) : statut d'emploi par établissement, statuts éligibles à la paie simplifiée (module
-- payroll_simple, jamais un fonctionnaire) et bulletins de paie (net = brut - retenues saisies, aucun taux légal calculé).

CREATE TABLE se_staff_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    staff_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    employment_status VARCHAR(30) NOT NULL,
    grade_label VARCHAR(80) NULL,
    payroll_ref VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_staff_profiles_member ON se_staff_profiles (tenant_id, establishment_id, staff_id);

CREATE TABLE se_staff_payroll_rules (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    eligible_statuses TEXT NOT NULL,
    updated_by VARCHAR(40) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_staff_payroll_rules_school ON se_staff_payroll_rules (tenant_id, establishment_id);

CREATE TABLE se_payslips (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    staff_profile_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    month_ref VARCHAR(7) NOT NULL,
    gross_amount INTEGER NOT NULL,
    deductions_json TEXT NOT NULL,
    deductions_amount INTEGER NOT NULL,
    net_amount INTEGER NOT NULL,
    status VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    validated_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    paid_by VARCHAR(40) NULL,
    paid_on VARCHAR(10) NULL,
    document_id CHAR(26) NULL,
    document_number VARCHAR(60) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_payslips_member_month ON se_payslips (tenant_id, establishment_id, staff_profile_id, month_ref);
CREATE INDEX se_payslips_school_month ON se_payslips (tenant_id, establishment_id, month_ref, status);
