-- Examens officiels (CEP, BEPC, Probatoire, BAC) et demandes de creation
-- d'etablissement.

CREATE TABLE official_exams (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(20) NOT NULL,
    level_id CHAR(36) NULL,
    academic_year_id CHAR(36) NOT NULL,
    registration_start DATETIME NOT NULL,
    registration_end DATETIME NOT NULL,
    exam_start DATETIME NULL,
    exam_end DATETIME NULL,
    fee_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    min_age INT NULL,
    max_age INT NULL,
    required_sequences INT NOT NULL DEFAULT 0,
    pass_mark DECIMAL(5,2) NOT NULL DEFAULT 10,
    oral_min_mark DECIMAL(5,2) NULL,
    is_official TINYINT NOT NULL DEFAULT 1,
    config_json TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT oe_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX oe_unique ON official_exams (tenant_id, academic_year_id, code, name);

CREATE TABLE exam_registrations (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    exam_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    registration_number VARCHAR(50) NOT NULL,
    center_code VARCHAR(50) NULL,
    center_name VARCHAR(255) NULL,
    series VARCHAR(50) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    fee_paid TINYINT NOT NULL DEFAULT 0,
    average DECIMAL(5,2) NULL,
    mention VARCHAR(50) NULL,
    rank_position INT NULL,
    registered_at DATETIME NULL,
    validated_by CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT er_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT er_exam_fk FOREIGN KEY (exam_id) REFERENCES official_exams (id) ON DELETE CASCADE,
    CONSTRAINT er_student_fk FOREIGN KEY (student_id) REFERENCES students (id)
);

CREATE UNIQUE INDEX er_exam_student_unique ON exam_registrations (exam_id, student_id);

CREATE TABLE exam_results (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    registration_id CHAR(36) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    coefficient DECIMAL(5,2) NOT NULL DEFAULT 1,
    mark DECIMAL(5,2) NULL,
    is_absent TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    CONSTRAINT exr_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT exr_registration_fk FOREIGN KEY (registration_id) REFERENCES exam_registrations (id) ON DELETE CASCADE
);

CREATE INDEX exr_registration_index ON exam_results (registration_id);

-- Volontairement hors scope d'etablissement : la demande est deposee avant que
-- le moindre etablissement n'existe.
CREATE TABLE establishment_requests (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) NOT NULL,
    type VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PUBLIC',
    address VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    director_first_name VARCHAR(255) NOT NULL,
    director_last_name VARCHAR(255) NOT NULL,
    director_email VARCHAR(255) NOT NULL,
    director_phone VARCHAR(50) NULL,
    request_status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    rejection_reason TEXT NULL,
    created_tenant_id CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);

CREATE INDEX establishment_requests_status_index ON establishment_requests (request_status);

-- Sessions applicatives, si le stockage fichier venait a etre indisponible.
CREATE TABLE sessions (
    id VARCHAR(128) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload TEXT NOT NULL,
    last_activity INT NOT NULL
);

CREATE INDEX sessions_last_activity_index ON sessions (last_activity);
