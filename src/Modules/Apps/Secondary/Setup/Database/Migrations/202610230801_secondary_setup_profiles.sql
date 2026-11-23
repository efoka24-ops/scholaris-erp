-- Application secondaire, sous-module Setup : profil d'établissement (data-model §1) et journal de ses changements.
-- Le profil de type (paramètre de plateforme secondary.type_profiles.<code>) est COPIÉ ici à l'ouverture ; la copie est ensuite
-- la seule source des cycles, enseignements et modules de l'établissement (RG-SEC-2). Un module coupé après usage passe dans
-- read_only_modules (lecture seule, rien n'est supprimé, D10). Chaque changement écrit une ligne de se_profile_changes avec motif.
-- Portable MySQL 8 et SQLite : pas de clé étrangère, pas d'AUTO_INCREMENT, aucun mot réservé.

CREATE TABLE se_establishment_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subtype VARCHAR(24) NOT NULL,
    profile_version INTEGER NOT NULL,
    ownership VARCHAR(20) NOT NULL,
    official_code VARCHAR(40) NULL,
    network_label VARCHAR(160) NULL,
    sections TEXT NOT NULL,
    cycles TEXT NOT NULL,
    teaching_kinds TEXT NOT NULL,
    modules TEXT NOT NULL,
    read_only_modules TEXT NOT NULL,
    head_title VARCHAR(20) NULL,
    region_label VARCHAR(120) NULL,
    division_label VARCHAR(120) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_establishment_profiles_one ON se_establishment_profiles (tenant_id, establishment_id);

CREATE TABLE se_profile_changes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    change_kind VARCHAR(20) NOT NULL,
    previous_value TEXT NULL,
    new_value TEXT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    changed_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_profile_changes_estab ON se_profile_changes (tenant_id, establishment_id, created_at);
