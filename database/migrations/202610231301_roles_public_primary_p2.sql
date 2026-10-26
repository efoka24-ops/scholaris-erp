-- Lot P2 du primaire public (T650 à T668) : deux droits complémentaires, sans toucher à 202610152351_roles_public_schools.sql.
-- 1. public_primary.referential.manage : le référentiel national et le calendrier des unités se règlent au niveau national (RG-TER-6) :
--    Inspection générale (R04) et administrateur fonctionnel (R21) ; l'école le lit sans le modifier.
-- 2. documents.read (socle Documents) : le directeur (R09) et le bureau de l'APEE (R15) téléchargent les PDF émis pour leur école
--    (bulletins, PV, cahier d'appel, synthèse de présence ; reçus et état financier de l'APEE) par /documents/{id}/preview.
INSERT INTO role_permissions (role, permission) VALUES ('pub_inspection_general', 'public_primary.referential.manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_functional_admin', 'public_primary.referential.manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'documents.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_apee_board', 'documents.read');
