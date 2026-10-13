-- Conges et absences du personnel : demandes (validation par Approvals) et soldes par type et par annee.

CREATE TABLE leave_requests (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    leave_type VARCHAR(20) NOT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NOT NULL,
    days INTEGER NOT NULL,
    reason VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL,
    approval_id CHAR(26) NULL,
    requested_by VARCHAR(40) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX leave_requests_staff ON leave_requests (tenant_id, staff_id, status);
CREATE INDEX leave_requests_establishment ON leave_requests (tenant_id, establishment_id, starts_on);

CREATE TABLE leave_balances (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    leave_type VARCHAR(20) NOT NULL,
    leave_year INTEGER NOT NULL,
    entitled_days INTEGER NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX leave_balances_unique ON leave_balances (tenant_id, staff_id, leave_type, leave_year);
