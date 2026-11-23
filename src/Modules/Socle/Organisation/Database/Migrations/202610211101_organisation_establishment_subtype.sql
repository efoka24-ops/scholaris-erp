-- Type d'établissement du secondaire, repris de la demande d'accès à l'acceptation. Portable MySQL 8 et SQLite.
ALTER TABLE establishments ADD COLUMN establishment_subtype VARCHAR(24) NULL;
