-- Messaging (S05) : comptage des SMS par établissement et par mois, annonces d'établissement.

CREATE TABLE sms_usage (
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    usage_month CHAR(7) NOT NULL,
    sms_count INTEGER NOT NULL DEFAULT 0,
    updated_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (tenant_id, establishment_id, usage_month)
);

CREATE TABLE announcements (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    created_by VARCHAR(40) NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    published_at VARCHAR(32) NOT NULL,
    expires_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX announcements_scope ON announcements (tenant_id, establishment_id, id)
