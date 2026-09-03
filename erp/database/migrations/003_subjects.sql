-- Module 3 : matieres, unites d'enseignement et elements constitutifs.
-- Matieres simples pour le primaire et le secondaire ; UE -> EC pour le
-- superieur LMD. Une affectation relie un enseignant a une matiere OU a un EC.

CREATE TABLE subjects (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    coefficient DECIMAL(5,2) NOT NULL DEFAULT 1,
    weekly_hours INT NOT NULL DEFAULT 0,
    category VARCHAR(20) NOT NULL,
    is_eliminatory TINYINT NOT NULL DEFAULT 0,
    eliminatory_threshold DECIMAL(5,2) NOT NULL DEFAULT 0,
    level_ids TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT subjects_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX subjects_tenant_code_unique ON subjects (tenant_id, code);

CREATE TABLE teaching_units (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    credits INT NOT NULL DEFAULT 0,
    semester INT NOT NULL DEFAULT 1,
    is_fundamental TINYINT NOT NULL DEFAULT 0,
    department_id CHAR(36) NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT teaching_units_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT teaching_units_department_fk FOREIGN KEY (department_id) REFERENCES departments (id)
);

CREATE UNIQUE INDEX teaching_units_tenant_code_unique ON teaching_units (tenant_id, code);

CREATE TABLE course_elements (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    credits INT NOT NULL DEFAULT 0,
    hours_cm INT NOT NULL DEFAULT 0,
    hours_td INT NOT NULL DEFAULT 0,
    hours_tp INT NOT NULL DEFAULT 0,
    coefficient DECIMAL(5,2) NOT NULL DEFAULT 1,
    teaching_unit_id CHAR(36) NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT course_elements_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT course_elements_unit_fk FOREIGN KEY (teaching_unit_id) REFERENCES teaching_units (id)
);

CREATE UNIQUE INDEX course_elements_tenant_code_unique ON course_elements (tenant_id, code);

-- Exactement l'un de subject_id et course_element_id est renseigne. Ni MySQL ni
-- SQLite n'appliquent l'unicite quand une colonne vaut NULL : le controle reste
-- applicatif, ces index couvrent les acces concurrents usuels.
CREATE TABLE subject_assignments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    subject_id CHAR(36) NULL,
    course_element_id CHAR(36) NULL,
    teacher_id CHAR(36) NOT NULL,
    classroom_id CHAR(36) NOT NULL,
    academic_year_id CHAR(36) NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT subject_assignments_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT subject_assignments_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects (id),
    CONSTRAINT subject_assignments_ce_fk FOREIGN KEY (course_element_id) REFERENCES course_elements (id),
    CONSTRAINT subject_assignments_teacher_fk FOREIGN KEY (teacher_id) REFERENCES users (id),
    CONSTRAINT subject_assignments_classroom_fk FOREIGN KEY (classroom_id) REFERENCES classrooms (id),
    CONSTRAINT subject_assignments_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
);

CREATE UNIQUE INDEX sa_subject_unique ON subject_assignments (tenant_id, subject_id, classroom_id, academic_year_id);
CREATE UNIQUE INDEX sa_ce_unique ON subject_assignments (tenant_id, course_element_id, classroom_id, academic_year_id);
