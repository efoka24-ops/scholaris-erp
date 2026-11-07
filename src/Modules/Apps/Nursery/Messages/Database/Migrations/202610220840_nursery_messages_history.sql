-- Maternelle privée : historique des messages envoyés aux parents d'une ou plusieurs sections (EX-MAT-25). Le texte part par la messagerie du
-- socle (consentement et plages horaires respectés) ; cette table garde le sujet, le texte, la portée et les comptes d'envoi.
CREATE TABLE nu_messages (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subject VARCHAR(160) NOT NULL,
    body VARCHAR(1000) NOT NULL,
    section_ids TEXT NOT NULL,
    sent_by VARCHAR(40) NOT NULL,
    recipients INTEGER NOT NULL,
    skipped INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX nu_messages_school ON nu_messages (tenant_id, establishment_id, created_at);
