-- Inscription groupée à partir d'un registre papier (EX-ELV-07, T631) : lot saisi par le directeur ou l'agent de saisie de l'inspection
-- (R20), photo du registre jointe (référence d'une pièce du magasin de médias), résultat par ligne. Idempotent par client_uuid.
CREATE TABLE pub_registration_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    client_uuid VARCHAR(40) NOT NULL,
    register_photo_ref VARCHAR(255) NULL,
    register_label VARCHAR(160) NULL,
    entered_by VARCHAR(40) NOT NULL,
    entered_by_proxy INTEGER NOT NULL,
    rows_count INTEGER NOT NULL,
    created_count INTEGER NOT NULL,
    results TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_registration_batches_uuid ON pub_registration_batches (tenant_id, client_uuid);
CREATE INDEX pub_registration_batches_school ON pub_registration_batches (tenant_id, establishment_id, created_at);
