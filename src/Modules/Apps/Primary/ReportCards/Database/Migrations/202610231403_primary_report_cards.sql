-- Primaire privé, bulletins (EX-PRI-26, 28, RG-PRI-5) : un bulletin par élève et par conseil validé (trimestriel, et récapitulatif
-- annuel au dernier trimestre), émis par le socle Documents (numéro, QR, contenu figé). Le contenu affiché aux familles est la copie
-- figée `snapshot` (JSON). Une correction produit une révision (rectificatif du socle) ; l'ancienne ligne passe à « superseded ».
-- Portable MySQL 8 / SQLite : ULID, dates ISO 8601 en texte, aucune clé étrangère, aucun mot réservé.

CREATE TABLE pri_report_cards (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    council_id CHAR(26) NOT NULL,
    line_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    card_kind VARCHAR(12) NOT NULL,
    document_type VARCHAR(40) NOT NULL,
    locale VARCHAR(5) NOT NULL,
    revision INTEGER NOT NULL DEFAULT 1,
    status VARCHAR(12) NOT NULL,
    document_id VARCHAR(40) NULL,
    document_number VARCHAR(80) NULL,
    verification_token VARCHAR(80) NULL,
    snapshot TEXT NULL,
    error_message VARCHAR(500) NULL,
    issued_by VARCHAR(40) NULL,
    issued_at VARCHAR(32) NULL,
    published_by VARCHAR(40) NULL,
    published_at VARCHAR(32) NULL,
    notified_count INTEGER NOT NULL DEFAULT 0,
    superseded_by CHAR(26) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pri_report_cards_council ON pri_report_cards (tenant_id, establishment_id, council_id, status);
CREATE INDEX pri_report_cards_person ON pri_report_cards (tenant_id, establishment_id, person_id, status);
CREATE INDEX pri_report_cards_year ON pri_report_cards (tenant_id, establishment_id, academic_year_id, status);
