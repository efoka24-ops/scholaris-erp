-- Registre des réclamations sur les résultats (objectif O1 du CDC : réclamations fondées sur une erreur de calcul, cible 0).
-- Une réclamation est reçue puis close avec une issue : calculation_error (fondée, erreur de calcul), other_error (fondée, autre
-- cause : saisie, absence mal comptée) ou unfounded. Rien n'est supprimé.
CREATE TABLE se_result_claims (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NULL,
    class_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    subject_label VARCHAR(160) NULL,
    claim_text VARCHAR(500) NOT NULL,
    received_on VARCHAR(10) NOT NULL,
    status VARCHAR(12) NOT NULL,
    outcome VARCHAR(20) NULL,
    outcome_note VARCHAR(500) NULL,
    recorded_by VARCHAR(40) NOT NULL,
    closed_by VARCHAR(40) NULL,
    closed_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_result_claims_year ON se_result_claims (tenant_id, establishment_id, academic_year_id, status);
CREATE INDEX se_result_claims_class ON se_result_claims (tenant_id, establishment_id, class_id);
