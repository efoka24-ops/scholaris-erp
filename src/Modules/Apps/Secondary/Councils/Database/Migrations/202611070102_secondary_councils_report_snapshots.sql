-- Instantanés des variables de bulletin du secondaire (T904) : valeurs `secondary_*` produites au moment de l'émission par le
-- fournisseur assessment.report_card_variables.secondary, une ligne par conseil et par élève (la dernière production l'emporte).
-- Portable MySQL 8 et SQLite ; cloisonné par client et établissement.

CREATE TABLE se_report_snapshots (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    council_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    variables TEXT NOT NULL,
    built_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_report_snapshots_line ON se_report_snapshots (tenant_id, establishment_id, council_id, person_id);
