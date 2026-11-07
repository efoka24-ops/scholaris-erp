-- PP3 (T607) : journal des consultations nominatives (NamedAccessJournal). Une ligne par consultation, jamais modifiée :
-- qui (actor_id), quoi (record_type, record_id), où (establishment_id), pourquoi (purpose, obligatoire), quand (accessed_at).
-- Le directeur de l'établissement consulté lit ce journal (GET /audit/named-access) ; le journal Audit chaîné reçoit aussi l'événement.
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO en texte, aucun index sur TEXT.
CREATE TABLE identity_named_access_journal (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    actor_id VARCHAR(64) NOT NULL,
    record_type VARCHAR(64) NOT NULL,
    record_id VARCHAR(64) NOT NULL,
    purpose VARCHAR(500) NOT NULL,
    ip VARCHAR(64) NULL,
    device VARCHAR(255) NULL,
    accessed_at VARCHAR(32) NOT NULL
);

CREATE INDEX identity_named_access_by_establishment ON identity_named_access_journal (tenant_id, establishment_id, id);

INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'identity.named_access.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'identity.named_access.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'identity.named_access.read');
