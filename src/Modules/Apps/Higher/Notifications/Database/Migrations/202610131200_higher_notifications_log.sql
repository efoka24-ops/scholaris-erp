-- Application Supérieur (IPES), notifications NOT-01 à NOT-11 : journal des envois demandés au Messenger du socle (statut rendu,
-- motif de blocage) et dédoublonnage des événements (une relance J-7, J+1 ou J+15 par tranche et par destinataire).

CREATE TABLE hi_notification_log (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    notification_code VARCHAR(10) NOT NULL,
    template_key VARCHAR(60) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    recipient_id VARCHAR(40) NOT NULL,
    dedupe_key VARCHAR(120) NULL,
    delivery_status VARCHAR(12) NOT NULL,
    reason VARCHAR(255) NULL,
    message_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_notification_log_dedupe ON hi_notification_log (tenant_id, establishment_id, dedupe_key, recipient_id);
CREATE INDEX hi_notification_log_person ON hi_notification_log (tenant_id, establishment_id, person_id);
