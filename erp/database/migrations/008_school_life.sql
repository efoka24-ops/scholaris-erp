-- Modules 9 a 18 : emplois du temps, presences, discipline, sante, vie
-- scolaire, bibliotheque, transport, cantine, patrimoine, RH.

CREATE TABLE timetable_slots (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    academic_year_id CHAR(36) NOT NULL,
    classroom_id CHAR(36) NOT NULL,
    subject_id CHAR(36) NOT NULL,
    teacher_id CHAR(36) NOT NULL,
    room_id CHAR(36) NULL,
    day_of_week VARCHAR(10) NOT NULL,
    start_time VARCHAR(10) NOT NULL,
    end_time VARCHAR(10) NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT ts_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT ts_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
    CONSTRAINT ts_classroom_fk FOREIGN KEY (classroom_id) REFERENCES classrooms (id),
    CONSTRAINT ts_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects (id),
    CONSTRAINT ts_teacher_fk FOREIGN KEY (teacher_id) REFERENCES users (id),
    CONSTRAINT ts_room_fk FOREIGN KEY (room_id) REFERENCES rooms (id)
);

CREATE INDEX ts_classroom_day_index ON timetable_slots (tenant_id, classroom_id, day_of_week);

CREATE TABLE attendances (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    classroom_id CHAR(36) NOT NULL,
    date DATE NOT NULL,
    status VARCHAR(20) NOT NULL,
    reason TEXT NULL,
    justified_by VARCHAR(255) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT att_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT att_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT att_classroom_fk FOREIGN KEY (classroom_id) REFERENCES classrooms (id)
);

CREATE INDEX att_classroom_date_index ON attendances (tenant_id, classroom_id, date);

CREATE TABLE discipline_incidents (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    reported_by CHAR(36) NOT NULL,
    type VARCHAR(30) NOT NULL,
    description TEXT NOT NULL,
    date DATE NOT NULL,
    sanction VARCHAR(30) NULL,
    sanction_details TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT di_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT di_student_fk FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT di_reporter_fk FOREIGN KEY (reported_by) REFERENCES users (id)
);

CREATE INDEX di_student_index ON discipline_incidents (tenant_id, student_id);

CREATE TABLE health_records (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    blood_type VARCHAR(10) NULL,
    allergies TEXT NULL,
    chronic_diseases TEXT NULL,
    medications TEXT NULL,
    vaccinations TEXT NULL,
    emergency_contact VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT hr_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT hr_student_fk FOREIGN KEY (student_id) REFERENCES students (id)
);

CREATE UNIQUE INDEX hr_student_unique ON health_records (student_id);

CREATE TABLE clubs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    supervisor_id CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT clubs_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT clubs_supervisor_fk FOREIGN KEY (supervisor_id) REFERENCES users (id)
);

CREATE TABLE club_members (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    club_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    joined_at DATETIME NULL,
    CONSTRAINT cm_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT cm_club_fk FOREIGN KEY (club_id) REFERENCES clubs (id) ON DELETE CASCADE,
    CONSTRAINT cm_student_fk FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX cm_club_student_unique ON club_members (club_id, student_id);

CREATE TABLE school_events (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    location VARCHAR(255) NULL,
    organizer_id CHAR(36) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT se_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT se_organizer_fk FOREIGN KEY (organizer_id) REFERENCES users (id)
);

CREATE TABLE library_books (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NULL,
    isbn VARCHAR(32) NULL,
    category VARCHAR(100) NULL,
    quantity INT NOT NULL DEFAULT 1,
    available INT NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT lb_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE TABLE library_borrows (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    book_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    borrow_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT lbo_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT lbo_book_fk FOREIGN KEY (book_id) REFERENCES library_books (id),
    CONSTRAINT lbo_student_fk FOREIGN KEY (student_id) REFERENCES students (id)
);

CREATE INDEX lbo_open_index ON library_borrows (tenant_id, return_date);

CREATE TABLE transport_vehicles (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    capacity INT NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT tv_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE TABLE transport_routes (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    vehicle_id CHAR(36) NULL,
    stops TEXT NULL,
    schedule TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT tr_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT tr_vehicle_fk FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles (id)
);

CREATE TABLE transport_subscriptions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    route_id CHAR(36) NOT NULL,
    student_id CHAR(36) NOT NULL,
    stop_name VARCHAR(255) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT tsub_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT tsub_route_fk FOREIGN KEY (route_id) REFERENCES transport_routes (id),
    CONSTRAINT tsub_student_fk FOREIGN KEY (student_id) REFERENCES students (id)
);

CREATE TABLE catering_menus (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    date DATE NOT NULL,
    meal VARCHAR(50) NOT NULL,
    items TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT cmenu_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE TABLE assets (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(30) NOT NULL,
    acquisition_date DATE NULL,
    acquisition_value DECIMAL(14,2) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'ACTIF',
    location VARCHAR(255) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT assets_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE TABLE asset_maintenances (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    asset_id CHAR(36) NOT NULL,
    date DATE NOT NULL,
    description TEXT NOT NULL,
    cost DECIMAL(14,2) NULL,
    technician VARCHAR(255) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT am_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT am_asset_fk FOREIGN KEY (asset_id) REFERENCES assets (id)
);

CREATE TABLE employees (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    user_id CHAR(36) NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    position VARCHAR(255) NOT NULL,
    department VARCHAR(255) NULL,
    hire_date DATE NOT NULL,
    salary DECIMAL(14,2) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT emp_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT emp_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
);

CREATE TABLE leave_requests (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    employee_id CHAR(36) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT lr_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT lr_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id)
);
