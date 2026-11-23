-- Contrat de vacation (DOC-24) : document émis depuis l'état approuvé. Une colonne par ALTER TABLE (portable MySQL 8 et SQLite).

ALTER TABLE hi_vacation_statements ADD COLUMN contract_document_id VARCHAR(40) NULL;
