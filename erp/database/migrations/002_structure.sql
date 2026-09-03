-- Module 2 : structure pedagogique.
-- Cycle -> (Filiere optionnelle) -> Niveau -> Classe -> Groupe.
--
-- La colonne d'ordre s'appelle sort_order et non order, et la table des groupes
-- class_groups et non groups : "order" et "groups" sont des mots reserves de
-- MySQL 8, et les eviter dispense d'echapper le moindre identifiant.

CREATE TABLE cycles (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    CONSTRAINT cycles_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX cycles_tenant_code_unique ON cycles (tenant_id, code);

CREATE TABLE departments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    head_teacher_id CHAR(36) NULL,
    created_at DATETIME NULL,
    CONSTRAINT departments_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT departments_head_fk FOREIGN KEY (head_teacher_id) REFERENCES users (id)
);

CREATE UNIQUE INDEX departments_tenant_code_unique ON departments (tenant_id, code);

CREATE TABLE programs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    cycle_id CHAR(36) NOT NULL,
    department_id CHAR(36) NULL,
    created_at DATETIME NULL,
    CONSTRAINT programs_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT programs_cycle_fk FOREIGN KEY (cycle_id) REFERENCES cycles (id),
    CONSTRAINT programs_department_fk FOREIGN KEY (department_id) REFERENCES departments (id)
);

CREATE UNIQUE INDEX programs_tenant_code_unique ON programs (tenant_id, code);

-- cycle_id est porte directement, en plus de program_id : un niveau sans
-- filiere (college) reste ainsi rattache a son cycle.
CREATE TABLE levels (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    cycle_id CHAR(36) NOT NULL,
    program_id CHAR(36) NULL,
    created_at DATETIME NULL,
    CONSTRAINT levels_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT levels_cycle_fk FOREIGN KEY (cycle_id) REFERENCES cycles (id),
    CONSTRAINT levels_program_fk FOREIGN KEY (program_id) REFERENCES programs (id)
);

CREATE UNIQUE INDEX levels_tenant_code_unique ON levels (tenant_id, code);

CREATE TABLE rooms (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'SALLE_CLASSE',
    capacity INT NULL,
    building VARCHAR(255) NULL,
    floor VARCHAR(50) NULL,
    equipment TEXT NULL,
    created_at DATETIME NULL,
    CONSTRAINT rooms_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX rooms_tenant_code_unique ON rooms (tenant_id, code);

CREATE TABLE classrooms (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    capacity INT NOT NULL DEFAULT 0,
    level_id CHAR(36) NOT NULL,
    main_teacher_id CHAR(36) NULL,
    room_id CHAR(36) NULL,
    section VARCHAR(20) NOT NULL DEFAULT 'FRANCOPHONE',
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT classrooms_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT classrooms_level_fk FOREIGN KEY (level_id) REFERENCES levels (id),
    CONSTRAINT classrooms_teacher_fk FOREIGN KEY (main_teacher_id) REFERENCES users (id),
    CONSTRAINT classrooms_room_fk FOREIGN KEY (room_id) REFERENCES rooms (id)
);

CREATE UNIQUE INDEX classrooms_tenant_code_unique ON classrooms (tenant_id, code);

CREATE TABLE class_groups (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(20) NOT NULL,
    classroom_id CHAR(36) NOT NULL,
    created_at DATETIME NULL,
    CONSTRAINT class_groups_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT class_groups_classroom_fk FOREIGN KEY (classroom_id) REFERENCES classrooms (id)
);

CREATE UNIQUE INDEX class_groups_tenant_classroom_code_unique ON class_groups (tenant_id, classroom_id, code);
