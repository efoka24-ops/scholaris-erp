-- Messaging (S05) : modèles de messages bilingues. Les modèles de la plateforme portent tenant_id = 'platform'.

CREATE TABLE message_templates (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    template_key VARCHAR(60) NOT NULL,
    locale VARCHAR(5) NOT NULL,
    channel VARCHAR(10) NOT NULL,
    subject VARCHAR(200) NULL,
    body TEXT NOT NULL,
    variables TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX message_templates_unique ON message_templates (tenant_id, establishment_id, template_key, locale, channel);

INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES
('01J9T0000000000000000PLT01', 'platform', 'platform', 'payment_receipt', 'fr', 'sms', NULL, 'Reçu :receipt_number : :amount FCFA bien reçus. Merci. :establishment_name', '["receipt_number","amount","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00'),
('01J9T0000000000000000PLT02', 'platform', 'platform', 'payment_receipt', 'en', 'sms', NULL, 'Receipt :receipt_number : :amount FCFA received. Thank you. :establishment_name', '["receipt_number","amount","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00'),
('01J9T0000000000000000PLT03', 'platform', 'platform', 'payment_reminder', 'fr', 'sms', NULL, 'Bonjour :first_name, il reste :balance FCFA à régler pour :child_name avant le :due_date. :establishment_name', '["first_name","balance","child_name","due_date","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00'),
('01J9T0000000000000000PLT04', 'platform', 'platform', 'payment_reminder', 'en', 'sms', NULL, 'Hello :first_name, :balance FCFA remain due for :child_name before :due_date. :establishment_name', '["first_name","balance","child_name","due_date","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00'),
('01J9T0000000000000000PLT05', 'platform', 'platform', 'absence_notice', 'fr', 'sms', NULL, ':child_name est absent(e) le :absence_date. :establishment_name', '["child_name","absence_date","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00'),
('01J9T0000000000000000PLT06', 'platform', 'platform', 'absence_notice', 'en', 'sms', NULL, ':child_name was absent on :absence_date. :establishment_name', '["child_name","absence_date","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00'),
('01J9T0000000000000000PLT07', 'platform', 'platform', 'family_payment_reference', 'fr', 'sms', NULL, 'Votre référence de paiement famille : :payment_reference. :establishment_name', '["payment_reference","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00'),
('01J9T0000000000000000PLT08', 'platform', 'platform', 'family_payment_reference', 'en', 'sms', NULL, 'Your family payment reference : :payment_reference. :establishment_name', '["payment_reference","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00'),
('01J9T0000000000000000PLT09', 'platform', 'platform', 'mobile_payment_request', 'fr', 'sms', NULL, 'Demande de paiement de :amount FCFA, référence :payment_reference. Validez sur votre téléphone. :establishment_name', '["amount","payment_reference","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00'),
('01J9T0000000000000000PLT10', 'platform', 'platform', 'mobile_payment_request', 'en', 'sms', NULL, 'Payment request of :amount FCFA, reference :payment_reference. Confirm on your phone. :establishment_name', '["amount","payment_reference","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00')
