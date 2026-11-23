-- Fermeture exceptionnelle de l'ecole (C-106, NOT-14) : jour declare par la direction, motif, nombre de parents prevenus. Un seul enregistrement
-- par ecole et par jour (la declaration est idempotente, les parents ne sont prevenus qu'une fois). La veille de non-recuperation ne
-- s'applique pas un jour de fermeture.
CREATE TABLE pn_closure_days (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    closure_on VARCHAR(10) NOT NULL,
    closure_reason VARCHAR(300) NOT NULL,
    declared_by VARCHAR(40) NOT NULL,
    notified_count INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_closure_days_day ON pn_closure_days (tenant_id, establishment_id, closure_on)
