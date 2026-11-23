-- Back-office de l'administrateur de plateforme : cycle de vie des clients, archivage des demandes, paiements manuels.
-- Portable MySQL 8 et SQLite : une colonne par ALTER TABLE, dates ISO 8601 en texte, aucune clé étrangère.

-- Fiche client de la plateforme (contact, ville, région, état). État : active, suspended, archived.
ALTER TABLE platform_clients ADD COLUMN client_state VARCHAR(16) NOT NULL DEFAULT 'active';
ALTER TABLE platform_clients ADD COLUMN city VARCHAR(80) NULL;
ALTER TABLE platform_clients ADD COLUMN region VARCHAR(80) NULL;
ALTER TABLE platform_clients ADD COLUMN state_reason VARCHAR(500) NULL;
ALTER TABLE platform_clients ADD COLUMN suspended_at VARCHAR(32) NULL;
ALTER TABLE platform_clients ADD COLUMN archived_at VARCHAR(32) NULL;

-- Demandes d'accès archivées (refusées ou de test) : retirées des files, conservées et tracées.
ALTER TABLE platform_access_requests ADD COLUMN archived_at VARCHAR(32) NULL;
ALTER TABLE platform_access_requests ADD COLUMN archived_by VARCHAR(40) NULL;
ALTER TABLE platform_access_requests ADD COLUMN archive_reason VARCHAR(500) NULL;

-- Paiements manuels (espèces, virement) et annulation motivée.
ALTER TABLE platform_payments ADD COLUMN payment_note VARCHAR(500) NULL;
ALTER TABLE platform_payments ADD COLUMN cancelled_at VARCHAR(32) NULL;
ALTER TABLE platform_payments ADD COLUMN cancelled_by VARCHAR(40) NULL;
ALTER TABLE platform_payments ADD COLUMN cancel_reason VARCHAR(500) NULL;

-- Permissions du back-office (rôle platform_admin uniquement ; aucune permission métier).
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.clients.manage');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.admins.manage');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.payments.manage');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.audit.read');
