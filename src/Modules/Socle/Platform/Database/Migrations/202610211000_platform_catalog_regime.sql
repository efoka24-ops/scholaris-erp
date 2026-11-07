-- Catalogue par niveau et par régime : une application par type d'établissement (supérieur, maternelle, primaire,
-- secondaire), le régime (privé ou public) désignant l'application technique à ouvrir. `sector` porte le régime,
-- `listed` = 0 retire une ligne du catalogue présenté (module commun des écoles publiques, qui n'est pas un produit).
-- Portable MySQL 8 et SQLite : une colonne par ALTER TABLE.

ALTER TABLE platform_applications ADD COLUMN sector VARCHAR(10) NOT NULL DEFAULT 'private';
ALTER TABLE platform_applications ADD COLUMN listed TINYINT NOT NULL DEFAULT 1;

UPDATE platform_applications SET sector = 'public' WHERE app_key IN ('public_nursery', 'public_primary', 'public_schools');
UPDATE platform_applications SET listed = 0 WHERE app_key = 'public_schools';

UPDATE platform_applications SET label = 'École maternelle' WHERE app_key IN ('nursery', 'public_nursery');
UPDATE platform_applications SET label = 'École primaire' WHERE app_key IN ('primary', 'public_primary');
