-- Clôture d'année : traçabilité du report des soldes (une ligne par facture reportée ; idempotence par facture source).

CREATE TABLE balance_carry_overs (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    from_year_id VARCHAR(40) NOT NULL,
    to_year_id VARCHAR(40) NOT NULL,
    source_invoice_id CHAR(26) NOT NULL,
    new_invoice_id CHAR(26) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX balance_carry_overs_source ON balance_carry_overs (tenant_id, source_invoice_id);
CREATE INDEX balance_carry_overs_scope ON balance_carry_overs (tenant_id, establishment_id, to_year_id);
