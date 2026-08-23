-- Separation de l'administrateur de plateforme et de l'administrateur d'ecole.
--
-- Un Super Admin administre la plateforme entiere : il n'appartient a aucun
-- etablissement. Il ne doit pas etre confondu avec l'administrateur d'une
-- ecole (souvent le directeur), qui lui est rattache a son etablissement.
--
-- users.tenant_id devient donc nullable. Les installations neuves obtiennent
-- deja la colonne nullable par 001_core.sql ; cette migration ne sert qu'aux
-- bases deja creees avec la contrainte NOT NULL.
--
-- SQLite ne sait pas modifier une colonne existante, mais il n'en a pas besoin :
-- ses bases sont recreees a partir des migrations, donc deja nullables. La
-- modification ne concerne que MySQL, d'ou le marqueur ci-dessous.

-- @mysql
ALTER TABLE users MODIFY tenant_id CHAR(36) NULL;

-- Detache le Super Admin de son etablissement d'origine : il etait rattache a
-- l'etablissement de demonstration faute de colonne nullable.
UPDATE users SET tenant_id = NULL
WHERE id IN (
    SELECT user_id FROM user_roles
    INNER JOIN roles ON roles.id = user_roles.role_id
    WHERE roles.name = 'SUPER_ADMIN' AND roles.tenant_id IS NULL
);
