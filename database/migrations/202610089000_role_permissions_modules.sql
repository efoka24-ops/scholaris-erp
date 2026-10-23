-- Permissions des modules People (S03), Billing (S04), Messaging (S05) et Documents (S06) par rôle.
-- Migration du noyau : les modules n'écrivent pas dans les tables d'Identity, ce fichier les rapproche en un seul endroit.
-- Les anciennes permissions « finance.* » du seed d'Identity restent, inutilisées par ces modules.
-- Un enseignant n'a ni people.read ni billing.* : il ne voit que ses classes (TeachingScope).

INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'people.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'people.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'billing.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'billing.reversal.approve');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'messaging.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'messaging.send');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'messaging.templates.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'documents.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'documents.issue');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'documents.revoke');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'documents.templates.manage');

INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'people.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'billing.reversal.approve');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'messaging.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'messaging.send');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'messaging.templates.manage');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'documents.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'documents.issue');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'documents.revoke');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'documents.templates.manage');

INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'people.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'people.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'people.health.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'billing.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'billing.reversal.approve');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'messaging.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'messaging.send');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'messaging.templates.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'documents.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'documents.issue');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'documents.revoke');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'documents.templates.manage');

INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'people.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'billing.manage');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'billing.reversal.approve');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'messaging.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'messaging.send');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'documents.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'documents.issue');

INSERT INTO role_permissions (role, permission) VALUES ('caissier', 'people.read');
INSERT INTO role_permissions (role, permission) VALUES ('caissier', 'billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('caissier', 'billing.cash');
INSERT INTO role_permissions (role, permission) VALUES ('caissier', 'billing.reversal.request');
INSERT INTO role_permissions (role, permission) VALUES ('caissier', 'documents.read');

INSERT INTO role_permissions (role, permission) VALUES ('parent', 'documents.request');

INSERT INTO role_permissions (role, permission) VALUES ('apprenant', 'documents.request');
