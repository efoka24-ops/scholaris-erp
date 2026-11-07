-- Droits du socle Billing pour les rôles du secondaire (proposition E1-B, T830), au moindre privilège, sur le modèle des rôles
-- communs comptable et caissier : l'intendant prépare la facturation (/fee-items, échéanciers) et approuve les annulations ;
-- le caissier encaisse et DEMANDE une annulation (séparation des tâches) ; chef d'établissement et promoteur lisent seulement.
-- La tenue du catalogue des frais publics reste à secondary.profile.manage (administrateur fonctionnel), jamais à l'intendant
-- (RG-FIN-2). Le chef d'établissement valide les échéanciers quand il n'y a pas de promoteur (secondary.fees.validate).

INSERT INTO role_permissions (role, permission) VALUES ('sec_intendant', 'billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_intendant', 'billing.manage');
INSERT INTO role_permissions (role, permission) VALUES ('sec_intendant', 'billing.reversal.approve');
INSERT INTO role_permissions (role, permission) VALUES ('sec_caissier', 'billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_caissier', 'billing.cash');
INSERT INTO role_permissions (role, permission) VALUES ('sec_caissier', 'billing.reversal.request');
INSERT INTO role_permissions (role, permission) VALUES ('sec_chef_etablissement', 'billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_chef_etablissement', 'secondary.fees.validate');
INSERT INTO role_permissions (role, permission) VALUES ('sec_promoteur', 'billing.read');
