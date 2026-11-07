-- Photo du registre papier d'une inscription groupee (C-105, EX-ELV-07) : fichier televerse puis range dans le magasin de medias
-- (espace public-schools), la ligne ne garde que la reference opaque. Une photo est jointe a un seul lot.
CREATE TABLE pub_register_photos (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    file_path VARCHAR(80) NOT NULL,
    uploaded_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pub_register_photos_school ON pub_register_photos (tenant_id, establishment_id, created_at);
ALTER TABLE pub_registration_batches ADD COLUMN register_photo_id CHAR(26) NULL;
CREATE UNIQUE INDEX pub_registration_batches_photo ON pub_registration_batches (tenant_id, register_photo_id);
