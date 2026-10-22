-- Primaire privé, vie de classe (EX-PRI-38) : cahier de liaison / journal de classe (leçons, devoirs, mots aux familles) et accusés de
-- lecture des parents. Portable MySQL 8 / SQLite : ULID, dates ISO 8601 en texte, aucune clé étrangère.

CREATE TABLE pri_class_journal (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    entry_date VARCHAR(10) NOT NULL,
    entry_kind VARCHAR(12) NOT NULL,
    subject_id CHAR(26) NULL,
    author_person_id VARCHAR(40) NULL,
    author_user_id VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    visible_to_parents INTEGER NOT NULL DEFAULT 1,
    published_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pri_class_journal_class ON pri_class_journal (tenant_id, establishment_id, class_structure_id, entry_date);

CREATE TABLE pri_journal_receipts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    entry_id CHAR(26) NOT NULL,
    guardian_person_id VARCHAR(40) NOT NULL,
    seen_at VARCHAR(32) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_journal_receipts_unique ON pri_journal_receipts (tenant_id, entry_id, guardian_person_id);
