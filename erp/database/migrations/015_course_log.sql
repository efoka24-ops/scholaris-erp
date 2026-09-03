-- Module 8bis : cahier de textes numerique. Une entree par seance de cours,
-- rattachee a une classe, une matiere et l'enseignant qui l'a tenue.

CREATE TABLE course_log_entries (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    classroom_id CHAR(36) NOT NULL,
    subject_id CHAR(36) NOT NULL,
    teacher_id CHAR(36) NOT NULL,
    date DATE NOT NULL,
    content TEXT NOT NULL,
    homework TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT cle_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT cle_classroom_fk FOREIGN KEY (classroom_id) REFERENCES classrooms (id),
    CONSTRAINT cle_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects (id),
    CONSTRAINT cle_teacher_fk FOREIGN KEY (teacher_id) REFERENCES users (id)
);

CREATE INDEX cle_classroom_date_index ON course_log_entries (tenant_id, classroom_id, date);
CREATE INDEX cle_teacher_index ON course_log_entries (tenant_id, teacher_id);
