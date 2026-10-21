-- Référentiel national versionné (EX-DEV-01) ; tenant = client national, pas d'établissement.
CREATE TABLE pn_dev_frames (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    frame_code VARCHAR(40) NOT NULL,
    version INTEGER NOT NULL,
    subsystem VARCHAR(2) NOT NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NOT NULL,
    status VARCHAR(10) NOT NULL,
    validation_ref VARCHAR(120) NULL,
    created_at VARCHAR(32) NOT NULL,
    activated_at VARCHAR(32) NULL
);
CREATE UNIQUE INDEX pn_dev_frames_version ON pn_dev_frames (tenant_id, frame_code, version);

CREATE TABLE pn_dev_domains (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    code VARCHAR(40) NOT NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NOT NULL,
    sort_order INTEGER NOT NULL
);
CREATE UNIQUE INDEX pn_dev_domains_code ON pn_dev_domains (tenant_id, frame_id, code);

CREATE TABLE pn_dev_items (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    domain_id CHAR(26) NOT NULL,
    code VARCHAR(40) NOT NULL,
    label_fr VARCHAR(300) NOT NULL,
    label_en VARCHAR(300) NOT NULL,
    section_levels VARCHAR(60) NOT NULL,
    sort_order INTEGER NOT NULL,
    active SMALLINT NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX pn_dev_items_code ON pn_dev_items (tenant_id, frame_id, code);

CREATE TABLE pn_observations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    domain_id CHAR(26) NOT NULL,
    item_id CHAR(26) NOT NULL,
    level_code VARCHAR(12) NOT NULL,
    remark VARCHAR(500) NULL,
    observed_on VARCHAR(10) NOT NULL,
    observer_id VARCHAR(40) NOT NULL,
    client_uuid VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_observations_client ON pn_observations (tenant_id, client_uuid);
CREATE INDEX pn_observations_learner ON pn_observations (tenant_id, establishment_id, learner_id, observed_on);

CREATE TABLE pn_progress_books (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    status VARCHAR(12) NOT NULL,
    synthesis TEXT NULL,
    snapshot TEXT NULL,
    edition INTEGER NOT NULL DEFAULT 1,
    edition_label VARCHAR(12) NULL,
    validated_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    published_at VARCHAR(32) NULL,
    document_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_progress_books_period ON pn_progress_books (tenant_id, learner_id, period_id);

CREATE TABLE pn_phrase_bank (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    domain_code VARCHAR(40) NULL,
    text_fr VARCHAR(300) NOT NULL,
    text_en VARCHAR(300) NOT NULL,
    active SMALLINT NOT NULL DEFAULT 1
);

CREATE TABLE pn_lifebook_entries (
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
CREATE INDEX pn_lifebook_entries_section ON pn_lifebook_entries (tenant_id, establishment_id, structure_id, activity_on);

CREATE TABLE pn_lifebook_media (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    entry_id CHAR(26) NOT NULL,
    media_ref VARCHAR(200) NOT NULL,
    mime_type VARCHAR(40) NOT NULL,
    size_bytes INTEGER NOT NULL,
    masked_from_id CHAR(26) NULL,
    no_child_visible SMALLINT NOT NULL DEFAULT 0,
    tagging_done SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(12) NOT NULL,
    purge_after VARCHAR(10) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    purged_at VARCHAR(32) NULL
);
CREATE INDEX pn_lifebook_media_entry ON pn_lifebook_media (tenant_id, entry_id);
CREATE INDEX pn_lifebook_media_purge ON pn_lifebook_media (tenant_id, status, purge_after);

CREATE TABLE pn_lifebook_media_children (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    media_id CHAR(26) NOT NULL,
    learner_id CHAR(26) NOT NULL
);
CREATE UNIQUE INDEX pn_lifebook_media_child ON pn_lifebook_media_children (tenant_id, media_id, learner_id);
CREATE INDEX pn_lifebook_child_learner ON pn_lifebook_media_children (tenant_id, learner_id);

CREATE TABLE pn_need_reports (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    need_area VARCHAR(12) NOT NULL,
    summary VARCHAR(500) NOT NULL,
    status VARCHAR(10) NOT NULL,
    orientation_note VARCHAR(500) NULL,
    reported_by VARCHAR(40) NOT NULL,
    reported_at VARCHAR(32) NOT NULL,
    parents_notified_at VARCHAR(32) NULL,
    closed_at VARCHAR(32) NULL
);
CREATE INDEX pn_need_reports_learner ON pn_need_reports (tenant_id, establishment_id, learner_id, status);