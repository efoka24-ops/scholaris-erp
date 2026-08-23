-- Journal des operations venues du mode hors-ligne.
--
-- Un appareil sans reseau met ses enregistrements en attente et les rejoue au
-- retour de la connexion. Un rejeu peut se produire plusieurs fois : coupure
-- pendant l'envoi, onglet rouvert, deuxieme appareil. Chaque operation porte
-- donc un jeton genere par le client ; le serveur refuse de l'appliquer deux
-- fois. Sans cela, une classe pointee hors-ligne serait pointee en double au
-- retour du reseau.

CREATE TABLE sync_operations (
    token CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NULL,
    user_id CHAR(36) NULL,
    path VARCHAR(255) NOT NULL,
    -- Ou renvoyer le client qui rejoue une operation deja appliquee, pour
    -- qu'il retrouve le meme ecran que la premiere fois.
    redirect_to VARCHAR(255) NULL,
    applied_at DATETIME NOT NULL
);

CREATE INDEX sync_operations_applied_index ON sync_operations (applied_at);
