-- Changement de mot de passe obligatoire a la premiere connexion, et lien
-- d'activation a duree limitee.
--
-- Un mot de passe provisoire envoye en clair par courrier reste en circulation
-- tant que personne ne le change. "must_change_password" force ce changement
-- avant tout autre usage du compte : la session reste ouverte, mais restreinte
-- a l'ecran de changement de mot de passe.
--
-- Le lien d'activation offre une seconde voie, plus sure qu'un mot de passe
-- lu au telephone : le titulaire choisit lui-meme son mot de passe, via un
-- jeton a usage unique et a duree de vie courte. Le jeton n'est jamais stocke
-- en clair, seulement son hachage : une fuite de la table ne permettrait pas
-- de rejouer un lien.

-- La colonne "must_change_password" est posee par 014_governance.sql, qui a ete
-- livre en meme temps depuis une autre branche et deja applique en production.
-- La rajouter ici ferait echouer la migration sur un doublon de colonne. Elle
-- n'est donc pas redeclaree : la reprendre casserait les installations
-- existantes pour ne rien apporter aux nouvelles.

CREATE TABLE account_activation_tokens (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT aat_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
);

CREATE UNIQUE INDEX aat_token_hash_unique ON account_activation_tokens (token_hash);
CREATE INDEX aat_user_index ON account_activation_tokens (user_id);
