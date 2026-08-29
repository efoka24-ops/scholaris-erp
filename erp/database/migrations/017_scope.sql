-- Perimetre d'un compte : ministere, region, departement, etablissement.
--
-- L'habilitation reposait jusqu'ici sur deux niveaux seulement — un role, une
-- permission — et sur l'appartenance a un etablissement. Cela suffit pour une
-- ecole, pas pour une plateforme nationale : un delegue regional a exactement
-- les memes permissions de lecture qu'un administrateur national, ce qui les
-- distingue est l'etendue sur laquelle elles s'exercent.
--
-- Le perimetre est donc porte par le compte, a cote du role :
--
--   Utilisateur  ->  Role  ->  Permission  ->  Perimetre
--
-- PLATFORM  : toute la plateforme (Super Admin)
-- REGION    : les etablissements d'une region
-- DEPARTMENT: ceux d'un departement
-- TENANT    : un seul etablissement — cas de tous les comptes scolaires,
--             deja porte par users.tenant_id, conserve ici pour l'uniformite.

ALTER TABLE users ADD COLUMN scope_type VARCHAR(20) NULL;
ALTER TABLE users ADD COLUMN scope_value VARCHAR(120) NULL;

CREATE INDEX users_scope_index ON users (scope_type, scope_value);

-- Decoupage administratif de l'etablissement.
--
-- La region existait seule ; le departement et l'arrondissement manquaient, et
-- sans eux aucune delegation departementale ne peut etre servie ni aucun
-- rapport ministeriel decoupe au bon niveau.

ALTER TABLE tenants ADD COLUMN department VARCHAR(120) NULL;
ALTER TABLE tenants ADD COLUMN district VARCHAR(120) NULL;

CREATE INDEX tenants_area_index ON tenants (region, department);
