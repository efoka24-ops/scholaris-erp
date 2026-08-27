-- Autorise un modele de communication systeme (tenant_id NULL), edite par le
-- Super Admin et lu par SystemTemplates comme repli commun a tous les
-- etablissements. SQLite ne sachant pas modifier une colonne existante, la
-- table est reconstruite pour ce moteur ; MySQL se contente d'un ALTER.

-- @mysql
ALTER TABLE communication_templates MODIFY tenant_id CHAR(36) NULL;

-- @sqlite
DROP INDEX IF EXISTS comm_templates_tenant_code_unique;

-- @sqlite
CREATE TABLE communication_templates_new (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    subject_fr VARCHAR(255) NULL,
    subject_en VARCHAR(255) NULL,
    body_fr TEXT NOT NULL,
    body_en TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT comm_templates_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

-- @sqlite
INSERT INTO communication_templates_new SELECT * FROM communication_templates;

-- @sqlite
DROP TABLE communication_templates;

-- @sqlite
ALTER TABLE communication_templates_new RENAME TO communication_templates;

CREATE UNIQUE INDEX comm_templates_tenant_code_unique ON communication_templates (tenant_id, code);
