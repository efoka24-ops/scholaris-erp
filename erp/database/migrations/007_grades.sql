-- Modules 5 et 6 : notes, moteur de calcul, bulletins.
-- grades -> grade_calculations (moyenne matiere sur une periode)
--        -> period_results (moyenne generale, rang, deliberation)
--        -> annual_results (bilan annuel) -> bulletins.

CREATE TABLE grades (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    subject_id CHAR(36) NULL,
    course_element_id CHAR(36) NULL,
    period_id CHAR(36) NOT NULL,
    teacher_id CHAR(36) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'TEST',
    value DECIMAL(5,2) NULL,
    max_value DECIMAL(5,2) NOT NULL DEFAULT 20,
    weight DECIMAL(5,2) NOT NULL DEFAULT 1,
    date DATETIME NULL,
    comment TEXT NULL,
    is_absent TINYINT NOT NULL DEFAULT 0,
    is_justified TINYINT NOT NULL DEFAULT 0,
    is_locked TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT grades_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT grades_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT grades_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects (id),
    CONSTRAINT grades_ce_fk FOREIGN KEY (course_element_id) REFERENCES course_elements (id),
    CONSTRAINT grades_period_fk FOREIGN KEY (period_id) REFERENCES periods (id),
    CONSTRAINT grades_teacher_fk FOREIGN KEY (teacher_id) REFERENCES users (id)
);

CREATE INDEX grades_student_period_index ON grades (tenant_id, student_id, period_id);
CREATE INDEX grades_subject_period_index ON grades (tenant_id, subject_id, period_id);
CREATE INDEX grades_lock_index ON grades (tenant_id, period_id, is_locked);

-- classroom_id est denormalise depuis l'inscription active au moment du calcul,
-- pour classer les eleves sans re-parcourir les inscriptions.
CREATE TABLE grade_calculations (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    period_id CHAR(36) NOT NULL,
    subject_id CHAR(36) NULL,
    course_element_id CHAR(36) NULL,
    classroom_id CHAR(36) NOT NULL,
    calculated_average DECIMAL(5,2) NOT NULL,
    coefficient DECIMAL(5,2) NOT NULL DEFAULT 1,
    weighted_total DECIMAL(8,2) NOT NULL,
    rank_position INT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT gc_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT gc_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT gc_period_fk FOREIGN KEY (period_id) REFERENCES periods (id),
    CONSTRAINT gc_classroom_fk FOREIGN KEY (classroom_id) REFERENCES classrooms (id)
);

CREATE INDEX gc_classroom_period_index ON grade_calculations (tenant_id, classroom_id, period_id);

CREATE TABLE period_results (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    period_id CHAR(36) NOT NULL,
    classroom_id CHAR(36) NOT NULL,
    general_average DECIMAL(5,2) NOT NULL,
    rank_position INT NULL,
    total_students INT NULL,
    mention VARCHAR(50) NULL,
    decision VARCHAR(255) NULL,
    observations TEXT NULL,
    teacher_comment TEXT NULL,
    is_published TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT pr_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT pr_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT pr_period_fk FOREIGN KEY (period_id) REFERENCES periods (id),
    CONSTRAINT pr_classroom_fk FOREIGN KEY (classroom_id) REFERENCES classrooms (id)
);

CREATE UNIQUE INDEX pr_student_period_unique ON period_results (tenant_id, student_id, period_id);

CREATE TABLE annual_results (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    classroom_id CHAR(36) NOT NULL,
    academic_year_id CHAR(36) NOT NULL,
    annual_average DECIMAL(5,2) NOT NULL,
    rank_position INT NULL,
    mention VARCHAR(50) NULL,
    decision VARCHAR(20) NULL,
    credits_validated INT NULL,
    gpa DECIMAL(4,2) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT ar_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT ar_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT ar_classroom_fk FOREIGN KEY (classroom_id) REFERENCES classrooms (id),
    CONSTRAINT ar_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
);

CREATE UNIQUE INDEX ar_student_year_unique ON annual_results (tenant_id, student_id, academic_year_id);

-- data fige les notes au moment de l'emission : un recalcul ulterieur ne
-- modifie pas un bulletin deja remis a la famille.
CREATE TABLE bulletins (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    period_id CHAR(36) NOT NULL,
    classroom_id CHAR(36) NOT NULL,
    verification_code VARCHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    pdf_url VARCHAR(255) NULL,
    data TEXT NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT bulletins_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT bulletins_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT bulletins_period_fk FOREIGN KEY (period_id) REFERENCES periods (id),
    CONSTRAINT bulletins_classroom_fk FOREIGN KEY (classroom_id) REFERENCES classrooms (id)
);

CREATE UNIQUE INDEX bulletins_verification_unique ON bulletins (verification_code);
CREATE UNIQUE INDEX bulletins_student_period_unique ON bulletins (tenant_id, student_id, period_id);
