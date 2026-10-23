-- Élèves des écoles publiques (ELV, CLA) : registre d'inscription (idempotence, rapprochement de doublons, identifiant), séquence de
-- l'identifiant national, historique daté du titulaire de classe, extension des transferts et des abandons, listes trimestrielles
-- des élèves sans acte de naissance (DOC-07). Portable MySQL 8 / SQLite. Aucun champ APEE (RG-ELV-1).

CREATE TABLE pub_pupil_registrations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    learner_number VARCHAR(40) NULL,
    client_uuid CHAR(36) NULL,
    name_key VARCHAR(200) NOT NULL,
    birth_date VARCHAR(10) NOT NULL,
    sex CHAR(1) NOT NULL,
    warnings VARCHAR(255) NULL,
    override_reason VARCHAR(500) NULL,
    registered_by VARCHAR(40) NOT NULL,
    registered_on VARCHAR(10) NOT NULL
);
CREATE UNIQUE INDEX pub_pupil_registrations_person ON pub_pupil_registrations (tenant_id, person_id);
CREATE UNIQUE INDEX pub_pupil_registrations_client ON pub_pupil_registrations (tenant_id, client_uuid);
CREATE UNIQUE INDEX pub_pupil_registrations_number ON pub_pupil_registrations (tenant_id, learner_number);
CREATE INDEX pub_pupil_registrations_identity ON pub_pupil_registrations (tenant_id, name_key, birth_date);

CREATE TABLE pub_learner_sequences (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    scope_key VARCHAR(40) NOT NULL,
    current_number INTEGER NOT NULL
);
CREATE UNIQUE INDEX pub_learner_sequences_scope ON pub_learner_sequences (tenant_id, scope_key);

CREATE TABLE pub_class_homerooms (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id CHAR(26) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NULL,
    assigned_by VARCHAR(40) NOT NULL
);
CREATE INDEX pub_class_homerooms_class ON pub_class_homerooms (tenant_id, structure_id, starts_on);

ALTER TABLE pub_transfers ADD COLUMN to_structure_id CHAR(26) NULL;

ALTER TABLE pub_dropouts ADD COLUMN declared_by VARCHAR(40) NULL;
ALTER TABLE pub_dropouts ADD COLUMN note VARCHAR(255) NULL;
ALTER TABLE pub_dropouts ADD COLUMN resolved_on VARCHAR(10) NULL;
CREATE INDEX pub_dropouts_pupil ON pub_dropouts (tenant_id, establishment_id, person_id);

CREATE TABLE pub_birth_act_lists (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    quarter_label VARCHAR(8) NOT NULL,
    pupil_count INTEGER NOT NULL,
    generated_by VARCHAR(40) NOT NULL,
    generated_on VARCHAR(10) NOT NULL,
    transmitted_on VARCHAR(10) NULL
);
CREATE UNIQUE INDEX pub_birth_act_lists_quarter ON pub_birth_act_lists (tenant_id, establishment_id, quarter_label);
