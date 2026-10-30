CREATE TABLE pub_teacher_presence (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    day_on VARCHAR(10) NOT NULL,
    presence VARCHAR(16) NOT NULL,
    arrived_at VARCHAR(5) NULL,
    left_at VARCHAR(5) NULL,
    reason VARCHAR(255) NULL,
    proof_path VARCHAR(255) NULL,
    declared_by VARCHAR(40) NOT NULL,
    client_uuid CHAR(36) NULL,
    checked_by VARCHAR(40) NULL,
    checked_at VARCHAR(32) NULL,
    check_result VARCHAR(16) NULL
);
CREATE UNIQUE INDEX pub_teacher_presence_day ON pub_teacher_presence (tenant_id, staff_id, day_on);
CREATE UNIQUE INDEX pub_teacher_presence_client ON pub_teacher_presence (tenant_id, client_uuid);

CREATE TABLE pub_uncovered_classes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id CHAR(26) NOT NULL,
    day_on VARCHAR(10) NOT NULL
);
CREATE UNIQUE INDEX pub_uncovered_day ON pub_uncovered_classes (tenant_id, structure_id, day_on);
