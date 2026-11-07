-- Maternelle privée : suivi du développement SANS NOTE (EX-MAT-16 à 19, RG-MAT-1). Aucune colonne de note, de moyenne, de total ni de rang.
-- Référentiel d'observation versionné : lignes `platform` (livrées par la plateforme, marquées à valider) ou `tenant` (copie adaptée par l'école).
CREATE TABLE nu_dev_frames (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    scope VARCHAR(10) NOT NULL,
    origin VARCHAR(10) NOT NULL,
    frame_code VARCHAR(40) NOT NULL,
    version INTEGER NOT NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NOT NULL,
    status VARCHAR(10) NOT NULL,
    validation_ref VARCHAR(120) NULL,
    copied_from CHAR(26) NULL,
    created_at VARCHAR(32) NOT NULL,
    activated_at VARCHAR(32) NULL
);
CREATE UNIQUE INDEX nu_dev_frames_version ON nu_dev_frames (tenant_id, establishment_id, frame_code, version);

CREATE TABLE nu_dev_domains (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    code VARCHAR(40) NOT NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NOT NULL,
    sort_order INTEGER NOT NULL
);
CREATE UNIQUE INDEX nu_dev_domains_code ON nu_dev_domains (tenant_id, establishment_id, frame_id, code);

CREATE TABLE nu_dev_items (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    domain_id CHAR(26) NOT NULL,
    code VARCHAR(40) NOT NULL,
    label_fr VARCHAR(300) NOT NULL,
    label_en VARCHAR(300) NOT NULL,
    sort_order INTEGER NOT NULL,
    active SMALLINT NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX nu_dev_items_code ON nu_dev_items (tenant_id, establishment_id, frame_id, code);

-- Observation d'un enfant : un item, un niveau parmi ceux de nursery.dev.levels, une remarque en termes positifs. Jamais modifiée.
CREATE TABLE nu_observations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    domain_id CHAR(26) NOT NULL,
    item_id CHAR(26) NOT NULL,
    level_code VARCHAR(20) NOT NULL,
    remark VARCHAR(500) NULL,
    observed_on VARCHAR(10) NOT NULL,
    observer_id VARCHAR(40) NOT NULL,
    client_uuid VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX nu_observations_client ON nu_observations (tenant_id, client_uuid);
CREATE INDEX nu_observations_learner ON nu_observations (tenant_id, establishment_id, learner_id, observed_on);
CREATE INDEX nu_observations_section ON nu_observations (tenant_id, establishment_id, structure_id, observed_on);

-- Carnet de suivi trimestriel : brouillon, soumis, validé par la directrice, publié aux parents (figé).
CREATE TABLE nu_progress_books (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    status VARCHAR(12) NOT NULL,
    content TEXT NULL,
    snapshot TEXT NULL,
    author_id VARCHAR(40) NOT NULL,
    submitted_at VARCHAR(32) NULL,
    validated_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    published_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX nu_progress_books_period ON nu_progress_books (tenant_id, establishment_id, learner_id, period_id);
CREATE INDEX nu_progress_books_state ON nu_progress_books (tenant_id, establishment_id, structure_id, status);

-- Cahier de vie : publications de la section, avec médias étiquetés et filtrés par consentement photo à chaque lecture.
CREATE TABLE nu_lifebook_entries (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NULL,
    activity_on VARCHAR(10) NOT NULL,
    author_id VARCHAR(40) NOT NULL,
    status VARCHAR(10) NOT NULL,
    published_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX nu_lifebook_entries_section ON nu_lifebook_entries (tenant_id, establishment_id, structure_id, activity_on);

CREATE TABLE nu_lifebook_media (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    entry_id CHAR(26) NOT NULL,
    media_ref VARCHAR(80) NOT NULL,
    mime_type VARCHAR(40) NOT NULL,
    size_bytes INTEGER NOT NULL,
    no_child_visible SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX nu_lifebook_media_entry ON nu_lifebook_media (tenant_id, establishment_id, entry_id);

CREATE TABLE nu_lifebook_media_children (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    media_id CHAR(26) NOT NULL,
    learner_id CHAR(26) NOT NULL
);
CREATE UNIQUE INDEX nu_lifebook_media_child ON nu_lifebook_media_children (tenant_id, media_id, learner_id);
CREATE INDEX nu_lifebook_child_learner ON nu_lifebook_media_children (tenant_id, establishment_id, learner_id);

-- Besoin particulier : signalement sans diagnostic (description courte), statut ouvert, orienté, clos.
CREATE TABLE nu_need_reports (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    need_area VARCHAR(12) NOT NULL,
    summary VARCHAR(500) NOT NULL,
    status VARCHAR(10) NOT NULL,
    orientation_note VARCHAR(500) NULL,
    reported_by VARCHAR(40) NOT NULL,
    reported_at VARCHAR(32) NOT NULL,
    parents_notified_at VARCHAR(32) NULL,
    closed_at VARCHAR(32) NULL
);
CREATE INDEX nu_need_reports_learner ON nu_need_reports (tenant_id, establishment_id, learner_id, status);
