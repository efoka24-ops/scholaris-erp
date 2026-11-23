-- Type d'établissement choisi à la demande d'accès (secondaire : leg, lycee_technique, ces, cetic, college_prive,
-- lycee_prive, autre) ; liste fermée contrôlée par le serveur. Portable MySQL 8 et SQLite.
ALTER TABLE platform_access_requests ADD COLUMN establishment_subtype VARCHAR(24) NULL;
