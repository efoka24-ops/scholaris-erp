-- Référentiel national versionné (EX-EVA-01) et calendrier des unités d'apprentissage (EX-EVA-02) de la Primaire publique (T660, T661).
-- Une nouvelle version d'une discipline est une nouvelle ligne (jamais de réécriture) ; les unités sont rattachées à un trimestre du socle.
CREATE TABLE pp_frames (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    subsystem VARCHAR(16) NOT NULL,
    level_code VARCHAR(16) NOT NULL,
    discipline_code VARCHAR(40) NOT NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NOT NULL,
    version INTEGER NOT NULL,
    valid_from VARCHAR(10) NOT NULL,
    is_key_discipline INTEGER NOT NULL,
    source_ref VARCHAR(200) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_frames_version ON pp_frames (tenant_id, subsystem, level_code, discipline_code, version);

CREATE TABLE pp_criteria (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    code VARCHAR(8) NOT NULL,
    label_fr VARCHAR(200) NOT NULL,
    label_en VARCHAR(200) NOT NULL,
    criterion_kind VARCHAR(12) NOT NULL,
    sort_order INTEGER NOT NULL
);
CREATE INDEX pp_criteria_frame ON pp_criteria (tenant_id, frame_id);

CREATE TABLE pp_learning_units (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    year_label VARCHAR(9) NOT NULL,
    level_code VARCHAR(16) NOT NULL,
    unit_no INTEGER NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NOT NULL,
    evaluation_week_from VARCHAR(10) NOT NULL,
    is_final_integration INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_units_no ON pp_learning_units (tenant_id, year_label, level_code, unit_no);
