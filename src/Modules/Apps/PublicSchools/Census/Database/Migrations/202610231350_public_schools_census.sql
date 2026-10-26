-- Questionnaire statistique annuel des écoles publiques (STA, T652, T653) : pré-rempli depuis les données de l'école, contrôles de
-- cohérence, circuit directeur > inspection > délégation départementale > délégation régionale > planification (contrat
-- ApprovalWorkflow du socle, PP5), gel à la validation régionale (RG-STA-1), saisie pour le compte de l'école par l'agent de
-- l'inspection (RG-STA-2). Une correction après gel est une nouvelle version motivée ; aucune donnée nominative.
CREATE TABLE pub_census_forms (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    year_label VARCHAR(9) NOT NULL,
    campaign VARCHAR(12) NOT NULL,
    version INTEGER NOT NULL,
    form_state VARCHAR(16) NOT NULL,
    current_step VARCHAR(16) NULL,
    payload TEXT NOT NULL,
    check_results TEXT NULL,
    entered_by_proxy INTEGER NOT NULL,
    correction_reason VARCHAR(500) NULL,
    return_reason VARCHAR(500) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_census_version ON pub_census_forms (tenant_id, establishment_id, year_label, campaign, version);
CREATE INDEX pub_census_state ON pub_census_forms (tenant_id, form_state, current_step);
