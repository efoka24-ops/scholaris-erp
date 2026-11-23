-- Maternelle privée : registre des sections. Une section est une classe du socle (Organisation) ; ce registre ajoute le libellé de
-- l'école, l'ordre d'affichage et le sous-système (documents bilingues). `id` est l'identifiant de la classe du socle.
CREATE TABLE nu_sections (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    label VARCHAR(160) NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    subsystem VARCHAR(10) NOT NULL DEFAULT 'fr',
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX nu_sections_scope ON nu_sections (tenant_id, establishment_id, sort_order);
