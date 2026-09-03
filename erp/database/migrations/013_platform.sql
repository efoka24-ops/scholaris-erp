-- Administration de la plateforme : localisation, suivi des demandes,
-- notifications.
--
-- Trois manques constates a l'usage.
--
-- Le demandeur d'une ouverture d'etablissement ne recevait rien : ni accuse de
-- reception, ni reponse. Il ne pouvait que rappeler. Une reference et une
-- table de notifications permettent de lui repondre et de garder trace de ce
-- qui lui a ete envoye.
--
-- Aucun etablissement ne portait sa region : impossible de savoir ou se situe
-- le parc, donc de le representer sur une carte.
--
-- Enfin, la colonne "status" designe le statut juridique de l'ecole (publique
-- ou privee) et ne pouvait pas servir a la suspendre. La suspension est une
-- decision de la plateforme, distincte : elle merite sa propre colonne.

ALTER TABLE tenants ADD COLUMN region VARCHAR(60) NULL;
ALTER TABLE tenants ADD COLUMN city VARCHAR(120) NULL;
ALTER TABLE tenants ADD COLUMN platform_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE';

ALTER TABLE establishment_requests ADD COLUMN region VARCHAR(60) NULL;
ALTER TABLE establishment_requests ADD COLUMN city VARCHAR(120) NULL;
-- Reference courte, communiquee au demandeur : c'est par elle qu'il suit son
-- dossier sans avoir de compte.
ALTER TABLE establishment_requests ADD COLUMN reference VARCHAR(20) NULL;

CREATE INDEX establishment_requests_reference_index ON establishment_requests (reference);

-- Courriers sortants.
--
-- Ecrire d'abord, envoyer ensuite : un hebergement mutualise peut refuser ou
-- perdre un envoi sans prevenir. La trace en base permet de savoir ce qui a ete
-- adresse a qui, de le reprendre, et de le relire depuis l'administration.
CREATE TABLE notifications (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'EMAIL',
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    -- Ce qui a motive l'envoi, pour retrouver tous les courriers d'un dossier.
    context_type VARCHAR(60) NULL,
    context_id CHAR(36) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    error TEXT NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL
);

CREATE INDEX notifications_context_index ON notifications (context_type, context_id);
CREATE INDEX notifications_status_index ON notifications (status, created_at);
