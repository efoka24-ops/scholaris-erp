-- Maternelle publique : contribution exigible (circulaire du MINEDUB sur l'exécution du budget de fonctionnement des écoles
-- maternelles et primaires publiques, visa MINFI du 7 mars 2022 ; arrêté interministériel n° 20/B1/1464/MINEFI/MINEDUC/CAB du
-- 31 mai 1996). Perçue en une tranche à la rentrée par le directeur, CONTRE REÇU (document numéroté du module Documents) ; la souche
-- (cette ligne) est conservée pour les contrôles, y compris après annulation. Le montant est celui du paramètre au jour de l'encaissement.
CREATE TABLE pn_contributions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NULL,
    amount_xaf INTEGER NOT NULL,
    payer_name VARCHAR(160) NOT NULL,
    paid_on VARCHAR(10) NOT NULL,
    receipt_number VARCHAR(80) NOT NULL,
    document_id VARCHAR(40) NOT NULL,
    verification_token VARCHAR(64) NOT NULL,
    quotas_json TEXT NOT NULL,
    status VARCHAR(10) NOT NULL,
    collected_by VARCHAR(40) NOT NULL,
    cancel_reason VARCHAR(255) NULL,
    cancelled_by VARCHAR(40) NULL,
    cancelled_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pn_contributions_learner ON pn_contributions (tenant_id, establishment_id, learner_id, academic_year_id);
CREATE INDEX pn_contributions_day ON pn_contributions (tenant_id, establishment_id, paid_on)
