-- Circuits de validation configurables (W-001) : surcharges des valeurs par défaut déclarées par les applications, et journal
-- de chaque modification. Trois niveaux : platform (plancher posé pour tous les clients, tenant_id = 'platform'), client (toute
-- l'organisation, establishment_id vide) et establishment. Une ligne = l'état courant d'un circuit à un niveau ; supprimer le
-- réglage (retour au défaut) retire la ligne mais le journal garde la trace (avant, après, auteur, date, motif).
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), aucune clé étrangère.

CREATE TABLE workflow_overrides (
    id CHAR(26) NOT NULL PRIMARY KEY,
    scope_level VARCHAR(16) NOT NULL,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    app_key VARCHAR(40) NOT NULL,
    circuit_key VARCHAR(60) NOT NULL,
    doc_json TEXT NOT NULL,
    version_no INTEGER NOT NULL,
    change_reason VARCHAR(500) NOT NULL,
    updated_by VARCHAR(40) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX workflow_overrides_target ON workflow_overrides (scope_level, tenant_id, establishment_id, app_key, circuit_key);

CREATE TABLE workflow_changes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    scope_level VARCHAR(16) NOT NULL,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    app_key VARCHAR(40) NOT NULL,
    circuit_key VARCHAR(60) NOT NULL,
    version_no INTEGER NOT NULL,
    change_kind VARCHAR(8) NOT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    change_reason VARCHAR(500) NOT NULL,
    changed_by VARCHAR(40) NOT NULL,
    changed_at VARCHAR(32) NOT NULL
);

CREATE INDEX workflow_changes_target ON workflow_changes (scope_level, tenant_id, establishment_id, app_key, circuit_key, version_no);
