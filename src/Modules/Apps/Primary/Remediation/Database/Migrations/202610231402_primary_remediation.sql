-- Primaire privé, remédiation (arrêté conjoint MINEDUB-MINESEC du 14 août 2026 : registre de remédiation et fiche de suivi
-- individualisée, outils de traçabilité obligatoires ; au moins 1 h par semaine et par discipline fondamentale, valeur paramétrée).
-- Registre : séances de remédiation d'une classe et élèves présents. Fiche : difficultés, actions et revues d'un élève.
-- Portable MySQL 8 / SQLite : ULID, dates ISO 8601 en texte, aucune clé étrangère, aucun mot réservé.

CREATE TABLE pri_remediation_sessions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NULL,
    subject_code VARCHAR(40) NULL,
    subject_label VARCHAR(160) NOT NULL,
    held_on VARCHAR(10) NOT NULL,
    duration_minutes INTEGER NOT NULL,
    remediation_form VARCHAR(30) NOT NULL,
    objective VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    recorded_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pri_remediation_sessions_class ON pri_remediation_sessions (tenant_id, establishment_id, class_structure_id, held_on);

CREATE TABLE pri_remediation_participants (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_remediation_participants_cell ON pri_remediation_participants (tenant_id, establishment_id, session_id, person_id);
CREATE INDEX pri_remediation_participants_person ON pri_remediation_participants (tenant_id, establishment_id, person_id);

CREATE TABLE pri_remediation_plans (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NULL,
    subject_code VARCHAR(40) NULL,
    subject_label VARCHAR(160) NOT NULL,
    difficulties TEXT NOT NULL,
    actions TEXT NOT NULL,
    status VARCHAR(12) NOT NULL,
    opened_on VARCHAR(10) NOT NULL,
    closed_on VARCHAR(10) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pri_remediation_plans_person ON pri_remediation_plans (tenant_id, establishment_id, person_id);
CREATE INDEX pri_remediation_plans_class ON pri_remediation_plans (tenant_id, establishment_id, class_structure_id, status);

CREATE TABLE pri_remediation_reviews (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    plan_id CHAR(26) NOT NULL,
    reviewed_on VARCHAR(10) NOT NULL,
    progress_note VARCHAR(500) NOT NULL,
    status_after VARCHAR(12) NOT NULL,
    reviewed_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pri_remediation_reviews_plan ON pri_remediation_reviews (tenant_id, establishment_id, plan_id);
