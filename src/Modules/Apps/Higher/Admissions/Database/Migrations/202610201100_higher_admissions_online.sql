-- Supérieur, admissions : candidature en ligne (EX-ADM-02, canal « online »).
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), aucune clé étrangère.

-- Lien public partageable « Candidater » d'un établissement : jeton non devinable (aucune énumération des instituts), désactivable
-- et renouvelable par la scolarité. Une seule ligne par établissement ; le jeton est unique sur toute la plateforme.
CREATE TABLE hi_public_links (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    slug VARCHAR(40) NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_public_links_slug ON hi_public_links (slug);

CREATE UNIQUE INDEX hi_public_links_establishment ON hi_public_links (tenant_id, establishment_id);

-- Pièce téléversée par le candidat : chemin opaque du stockage privé (AttachmentStore), type réel contrôlé et taille.
ALTER TABLE hi_application_documents ADD COLUMN file_path VARCHAR(255) NULL;

ALTER TABLE hi_application_documents ADD COLUMN file_mime VARCHAR(60) NULL;

ALTER TABLE hi_application_documents ADD COLUMN file_size INTEGER NULL;
