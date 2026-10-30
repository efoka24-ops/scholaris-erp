-- Contacts, relations et identifiants d'une personne. Aucune cle etrangere. Dates ISO 8601 (Africa/Douala).

CREATE TABLE person_contacts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    kind VARCHAR(10) NOT NULL,
    label VARCHAR(60) NULL,
    value VARCHAR(255) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX person_contacts_person ON person_contacts (tenant_id, person_id);

CREATE TABLE person_relations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    related_person_id CHAR(26) NOT NULL,
    kind VARCHAR(30) NOT NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX person_relations_unique ON person_relations (tenant_id, person_id, related_person_id, kind);
CREATE INDEX person_relations_related ON person_relations (tenant_id, related_person_id);

CREATE TABLE person_identifiers (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    kind VARCHAR(10) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL DEFAULT '',
    value VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX person_identifiers_unique ON person_identifiers (tenant_id, kind, establishment_id, value);
CREATE INDEX person_identifiers_person ON person_identifiers (tenant_id, person_id);
