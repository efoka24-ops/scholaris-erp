-- Journal des notifications du secondaire envoyées par les déclencheurs (NOT-01, NOT-09 au lot E1) : une ligne par notification,
-- événement et destinataire, pour ne jamais envoyer deux fois le même message (rejeu d'une validation d'inscription, reçu rejoué).
CREATE TABLE se_notification_log (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    notification_code VARCHAR(8) NOT NULL,
    event_ref VARCHAR(80) NOT NULL,
    recipient_person_id VARCHAR(40) NOT NULL,
    template_key VARCHAR(60) NOT NULL,
    delivery_status VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX ux_se_notification_log_event ON se_notification_log (tenant_id, establishment_id, notification_code, event_ref, recipient_person_id);
