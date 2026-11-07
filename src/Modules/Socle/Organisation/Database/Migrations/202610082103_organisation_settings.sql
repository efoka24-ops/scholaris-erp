-- Organisation (S02) : paramètres à trois niveaux (plateforme, client, établissement).
-- Niveau plateforme : tenant_id = 'platform'. Niveau client : establishment_id = ''.
-- L'historique des modifications est tenu par le journal d'audit (audit_log).

CREATE TABLE settings (
    id CHAR(26) NOT NULL PRIMARY KEY,
    scope_type VARCHAR(16) NOT NULL,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL DEFAULT '',
    setting_key VARCHAR(100) NOT NULL,
    value_json TEXT NOT NULL,
    updated_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX settings_scope_key ON settings (scope_type, tenant_id, establishment_id, setting_key);
