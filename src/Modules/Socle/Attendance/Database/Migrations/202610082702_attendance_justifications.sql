-- S08 Justificatifs d'absence : dépôt puis validation ou refus par la vie scolaire.

CREATE TABLE absence_justifications (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    absence_from VARCHAR(10) NOT NULL,
    absence_to VARCHAR(10) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    document_ref VARCHAR(80) NULL,
    status VARCHAR(10) NOT NULL,
    submitted_by VARCHAR(40) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    decision_note VARCHAR(255) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX absence_justifications_person ON absence_justifications (tenant_id, establishment_id, person_id);
CREATE INDEX absence_justifications_status ON absence_justifications (tenant_id, establishment_id, status);
