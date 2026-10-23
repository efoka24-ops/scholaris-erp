-- Extensions IPES de Billing (S10, S13, D4). Colonnes facultatives : les lignes existantes gardent leur comportement.
-- Code stable d'un frais (unique par établissement quand il est renseigné ; NULL reste permis plusieurs fois).
ALTER TABLE fee_items ADD COLUMN code VARCHAR(40) NULL;
CREATE UNIQUE INDEX fee_items_code ON fee_items (tenant_id, establishment_id, code);

-- Code du frais recopié sur la ligne de facture (règles de l'application : frais d'examen, quitus).
ALTER TABLE invoice_lines ADD COLUMN fee_code VARCHAR(40) NULL;

-- Nature de la facture (NULL = inscription ; « charges » = facture libre, par exemple un candidat sans inscription)
-- et payeur tiers (personne ou organisme) au nom duquel la facture est établie.
ALTER TABLE invoices ADD COLUMN invoice_kind VARCHAR(20) NULL;
ALTER TABLE invoices ADD COLUMN payer_kind VARCHAR(20) NULL;
ALTER TABLE invoices ADD COLUMN payer_name VARCHAR(160) NULL;
ALTER TABLE invoices ADD COLUMN payer_ref VARCHAR(60) NULL;
