-- Fermeture d'un établissement par l'administrateur de plateforme : les données restent, l'établissement n'est plus
-- proposé aux utilisateurs (annuaire). Réouverture possible. Portable MySQL 8 et SQLite.
ALTER TABLE establishments ADD COLUMN closed_at VARCHAR(32) NULL;
