-- Personnel non enseignant (E3-EF2) : catégorie du membre (teaching, administrative, service, maintenance) et heures mensuelles
-- saisies pour le personnel qui n'enseigne pas (ses heures ne viennent pas de l'appel), validées par le chef d'établissement.

ALTER TABLE se_staff_profiles ADD COLUMN staff_category VARCHAR(20) NULL;

CREATE TABLE se_staff_monthly_hours (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    staff_profile_id CHAR(26) NOT NULL,
    month_ref VARCHAR(7) NOT NULL,
    minutes INTEGER NOT NULL,
    note VARCHAR(255) NULL,
    status VARCHAR(12) NOT NULL,
    declared_by VARCHAR(40) NOT NULL,
    validated_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_staff_monthly_hours_member ON se_staff_monthly_hours (tenant_id, establishment_id, staff_profile_id, month_ref);
