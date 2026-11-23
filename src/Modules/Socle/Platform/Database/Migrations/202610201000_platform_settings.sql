-- Réglages de la plateforme modifiables par le super admin (back-office → Réglages), clé => valeur.
-- Premier réglage : durée de validité des codes d'accès créés depuis le back-office (access_code_ttl_minutes, 24 h par défaut).

CREATE TABLE platform_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by VARCHAR(40) NULL,
    updated_at VARCHAR(32) NOT NULL
);

INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.settings.manage');
