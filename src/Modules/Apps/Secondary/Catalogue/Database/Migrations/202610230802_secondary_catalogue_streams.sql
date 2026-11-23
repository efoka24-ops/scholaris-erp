-- Application secondaire, sous-module Catalogue : TABLE DE RÉFÉRENCE des séries (enseignement général) et spécialités (technique).
-- Clé d'une ligne : (portée, section, code, cycle) ; un même code peut exister dans les deux cycles (ESF, PA, PV, CERAM...).
-- Portée : tenant_id = establishment_id = '*' pour une ligne de la PLATEFORME (catalogue MINESEC ou ajout de la plateforme) ;
-- client et établissement réels pour une ligne ajoutée par un établissement (source obligatoire). Un établissement ne modifie
-- jamais une ligne de la plateforme : il la masque ou la renomme pour lui seul (se_stream_ref_overrides). Chaque ajout, masquage,
-- affichage ou changement de libellé écrit une ligne d'historique (se_stream_ref_changes) avec auteur, date et motif.
-- Portable MySQL 8 et SQLite : pas de clé étrangère, pas d'AUTO_INCREMENT, aucun mot réservé.

CREATE TABLE se_stream_refs (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    section_code VARCHAR(4) NOT NULL,
    code VARCHAR(20) NOT NULL,
    cycle_code VARCHAR(10) NOT NULL,
    stream_kind VARCHAR(12) NOT NULL,
    teaching_kind VARCHAR(12) NOT NULL,
    track_code VARCHAR(8) NOT NULL,
    field_label VARCHAR(120) NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NULL,
    origin VARCHAR(16) NOT NULL,
    source_ref VARCHAR(255) NULL,
    confirmation VARCHAR(16) NOT NULL,
    hidden INTEGER NOT NULL DEFAULT 0,
    version_no INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_stream_refs_key ON se_stream_refs (tenant_id, establishment_id, section_code, code, cycle_code);
CREATE INDEX se_stream_refs_filter ON se_stream_refs (tenant_id, establishment_id, cycle_code, teaching_kind);

CREATE TABLE se_stream_ref_overrides (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    stream_ref_id CHAR(26) NOT NULL,
    hidden INTEGER NOT NULL DEFAULT 0,
    label_fr VARCHAR(160) NULL,
    label_en VARCHAR(160) NULL,
    version_no INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_stream_ref_overrides_one ON se_stream_ref_overrides (tenant_id, establishment_id, stream_ref_id);

CREATE TABLE se_stream_ref_changes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    stream_ref_id CHAR(26) NOT NULL,
    change_kind VARCHAR(16) NOT NULL,
    previous_value TEXT NULL,
    new_value TEXT NOT NULL,
    reason VARCHAR(255) NULL,
    changed_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_stream_ref_changes_ref ON se_stream_ref_changes (stream_ref_id, created_at);
CREATE INDEX se_stream_ref_changes_scope ON se_stream_ref_changes (tenant_id, establishment_id, created_at);
