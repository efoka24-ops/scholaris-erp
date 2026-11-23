-- Messaging (PP10, RG-COM-2) : catégorie « essential » portée par les modèles de la plateforme, et désinscription d'une
-- personne des messages non essentiels. Un message essentiel (sécurité, fermeture) ignore la désinscription ; un message
-- standard y est soumis. Seuls les modèles de la plateforme peuvent être essentiels : aucun établissement ne peut classer
-- un message en « essential ».

ALTER TABLE message_templates ADD COLUMN category VARCHAR(12) NOT NULL DEFAULT 'standard';

UPDATE message_templates SET category = 'essential' WHERE tenant_id = 'platform' AND template_key IN ('pn.not06.parent', 'pn.not06.director', 'pn.not07.parent', 'pn.not07.director', 'pn.pickup_code');

CREATE TABLE messaging_opt_outs (
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    channel VARCHAR(10) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (tenant_id, person_id, channel)
);
