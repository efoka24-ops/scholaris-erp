-- Passage de fin d'année du secondaire (T905, organisation.closure.promotion.secondary) : à la clôture d'une année, une ligne par
-- élève inscrit dit ce que le conseil de fin d'année et l'orientation ont décidé (passage, redoublement, exclusion) et la classe
-- d'accueil proposée pour l'année suivante. La réinscription reste un acte de la scolarité : rien n'est inscrit d'office.
-- Idempotent : une ligne par élève et par année close ; une ligne déjà appliquée n'est plus modifiée.

CREATE TABLE se_promotion_plans (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    from_year_id VARCHAR(40) NOT NULL,
    to_year_id VARCHAR(40) NOT NULL,
    from_class_id VARCHAR(40) NOT NULL,
    outcome VARCHAR(16) NOT NULL,
    target_class_id VARCHAR(40) NULL,
    stream_id VARCHAR(40) NULL,
    basis VARCHAR(20) NOT NULL,
    plan_status VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_promotion_plans_person ON se_promotion_plans (tenant_id, establishment_id, person_id, from_year_id);
CREATE INDEX se_promotion_plans_target ON se_promotion_plans (tenant_id, establishment_id, to_year_id, target_class_id);
