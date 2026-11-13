-- C-001 : connexion simple. Mot de passe provisoire émis par l'administrateur (changement obligatoire à la première connexion)
-- et preuve de « confirmation par mot de passe » (10 minutes) enregistrée sur la session, à côté de la preuve de 2FA.
-- Une colonne par ALTER TABLE (portable MySQL 8 et SQLite). Aucune donnée existante n'est modifiée.
ALTER TABLE users ADD COLUMN must_change_password SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE access_tokens ADD COLUMN password_confirmed_at VARCHAR(32) NULL;
