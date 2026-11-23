-- Maternelle publique (N-002) : droits non semés dans la matrice des rôles publics. Le directeur d'école publique (R09), qui dirige
-- aussi la section maternelle d'un groupe scolaire, gère la contribution exigible de la maternelle (circulaire MINEDUB, visa MINFI du
-- 7 mars 2022 : perçue par le directeur contre reçu) et lit le registre des arrivées et sorties (DOC-19, contrôle des remises).
-- L'enseignant du primaire (R10) ne reçoit rien : le registre de sortie relève de l'éducatrice et de l'aide maternelle (R11, R12),
-- dont les rôles sont semés par la migration de la maternelle publique.
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_nursery.contribution.manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_nursery.register.read');
