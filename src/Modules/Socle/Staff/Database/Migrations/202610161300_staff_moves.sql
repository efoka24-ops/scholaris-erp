-- Mutations du personnel entre établissements du même client (PP19) : historique daté, une ligne par mutation.
CREATE TABLE staff_moves (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    from_establishment_id VARCHAR(40) NOT NULL,
    to_establishment_id VARCHAR(40) NOT NULL,
    effective_on VARCHAR(10) NOT NULL,
    reason VARCHAR(500) NULL,
    ended_assignments INTEGER NOT NULL DEFAULT 0,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX staff_moves_staff ON staff_moves (tenant_id, staff_id, effective_on);
