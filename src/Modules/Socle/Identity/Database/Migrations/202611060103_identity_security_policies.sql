-- C-112 point 6 : règles de sécurité réglables par le super administrateur de la plateforme (back-office → Réglages).
-- Durée d'une session (heures, sans « rester connecté ») ; la double authentification par rôle est portée par roles.is_sensitive.
-- Portable MySQL 8 / SQLite : clé => valeur, dates ISO 8601 en texte.

CREATE TABLE identity_security_policies (
    policy_key VARCHAR(60) NOT NULL PRIMARY KEY,
    policy_value VARCHAR(255) NOT NULL,
    updated_by VARCHAR(40) NULL,
    updated_at VARCHAR(32) NOT NULL
);
