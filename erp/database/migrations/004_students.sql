-- Module 4 : eleves, parents, inscriptions, admissions.
-- Toutes ces tables portent deleted_at : le schema ne prevoit aucune
-- suppression physique, l'historique scolaire devant rester consultable.

CREATE TABLE students (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    matricule VARCHAR(50) NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    date_of_birth DATE NOT NULL,
    place_of_birth VARCHAR(255) NULL,
    gender VARCHAR(10) NOT NULL,
    nationality VARCHAR(100) NOT NULL DEFAULT 'Camerounaise',
    photo_url VARCHAR(255) NULL,
    blood_group VARCHAR(10) NULL,
    allergies TEXT NULL,
    handicap TEXT NULL,
    emergency_contact VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    user_id CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT students_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT students_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
);

CREATE UNIQUE INDEX students_tenant_matricule_unique ON students (tenant_id, matricule);
CREATE INDEX students_tenant_name_index ON students (tenant_id, last_name, first_name);

CREATE TABLE parents (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    whatsapp VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    profession VARCHAR(255) NULL,
    address VARCHAR(255) NULL,
    relationship VARCHAR(20) NOT NULL,
    user_id CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT parents_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT parents_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
);

CREATE INDEX parents_tenant_index ON parents (tenant_id);

-- La relation est portee par le lien : un meme parent peut etre pere d'un
-- eleve et tuteur d'un autre.
CREATE TABLE student_parents (
    student_id CHAR(36) NOT NULL,
    parent_id CHAR(36) NOT NULL,
    relationship VARCHAR(20) NOT NULL,
    PRIMARY KEY (student_id, parent_id),
    CONSTRAINT student_parents_student_fk FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
    CONSTRAINT student_parents_parent_fk FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE CASCADE
);

CREATE TABLE enrollments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    classroom_id CHAR(36) NOT NULL,
    academic_year_id CHAR(36) NOT NULL,
    enrollment_date DATETIME NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'NEW',
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    regime VARCHAR(20) NOT NULL DEFAULT 'EXTERNAL',
    is_repeater TINYINT NOT NULL DEFAULT 0,
    previous_school VARCHAR(255) NULL,
    previous_average DECIMAL(5,2) NULL,
    documents TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT enrollments_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT enrollments_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT enrollments_classroom_fk FOREIGN KEY (classroom_id) REFERENCES classrooms (id),
    CONSTRAINT enrollments_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
);

CREATE INDEX enrollments_classroom_index ON enrollments (tenant_id, classroom_id, status);
CREATE INDEX enrollments_student_index ON enrollments (tenant_id, student_id, academic_year_id);

CREATE TABLE admission_applications (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    applicant_name VARCHAR(255) NOT NULL,
    applicant_info TEXT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'DOSSIER',
    score DECIMAL(5,2) NULL,
    rank_position INT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    academic_year_id CHAR(36) NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT admissions_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT admissions_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
);

CREATE INDEX admissions_status_index ON admission_applications (tenant_id, status);

-- Compteur atomique de matricules, incremente dans la meme transaction que la
-- creation de l'eleve : ni trou, ni doublon.
CREATE TABLE matricule_sequences (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    year VARCHAR(10) NOT NULL,
    last_number INT NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT matricule_sequences_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX matricule_sequences_tenant_year_unique ON matricule_sequences (tenant_id, year);
