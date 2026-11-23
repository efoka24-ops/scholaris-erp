-- Messaging (PP11) : catégorie portée par la livraison (base du plafond annuel de SMS standard par élève).
ALTER TABLE message_deliveries ADD COLUMN category VARCHAR(12) NOT NULL DEFAULT 'standard';

CREATE INDEX message_deliveries_cap ON message_deliveries (tenant_id, recipient_id, channel, category, sent_at);
