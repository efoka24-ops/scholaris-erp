-- Ministere de tutelle d'un etablissement.
--
-- La hierarchie de pilotage compte un niveau de plus que la geographie :
--
--   SUPER ADMIN -> MINISTERE -> REGION -> DEPARTEMENT -> ETABLISSEMENT
--
-- Un delegue regional couvre un territoire ; un administrateur de ministere
-- couvre une tutelle, qui traverse toutes les regions mais ne concerne qu'une
-- partie des etablissements de chacune. Les deux decoupages se croisent sans
-- se confondre, et aucun ne se deduit de l'autre : un lycee technique et une
-- ecole primaire de la meme ville ne relevent pas du meme ministere.

ALTER TABLE tenants ADD COLUMN ministry VARCHAR(20) NULL;

CREATE INDEX tenants_ministry_index ON tenants (ministry);
