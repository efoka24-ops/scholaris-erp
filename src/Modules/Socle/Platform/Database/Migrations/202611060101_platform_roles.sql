-- C-112 : rôles de la plateforme (client technique « platform ») et permissions fines `platform.*`.
-- Rôles de niveau client, sensibles (double authentification toujours exigée pour un membre de la plateforme), jamais
-- attribuables par une route d'un client : seul un administrateur de plateforme les attribue (écran Administrateurs).
--  - platform_support : clients, utilisateurs, journaux, messages ; réémettre un code, forcer une déconnexion, message à une personne ;
--  - platform_finance : paiements, déclarations, régularisation, prix, indicateurs financiers ; aucun utilisateur ;
--  - platform_auditor : lecture seule de tout et exports, aucune action.
-- Aucune permission métier d'un client (élèves, notes, factures) : la plateforme ne voit pas les données d'un client.

INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('platform_support', 'tenant', 1, 'Support de la plateforme', 'Platform support');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('platform_finance', 'tenant', 1, 'Finance de la plateforme', 'Platform finance');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('platform_auditor', 'tenant', 1, 'Auditeur de la plateforme', 'Platform auditor');

-- Nouvelles permissions de l'administrateur de plateforme (il conserve toutes les autres).
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.settings.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.catalog.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.admins.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.health.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.kpi.finance');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.users.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.users.logout');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.users.access_code');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.audit.export');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.messages.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.messages.retry');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.messages.send');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.messages.broadcast');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.regularisation.manage');

INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.access');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.dashboard.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.health.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.clients.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.catalog.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.requests.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.users.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.users.logout');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.users.access_code');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.audit.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.messages.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.messages.retry');
INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.messages.send');

INSERT INTO role_permissions (role, permission) VALUES ('platform_finance', 'platform.access');
INSERT INTO role_permissions (role, permission) VALUES ('platform_finance', 'platform.dashboard.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_finance', 'platform.kpi.finance');
INSERT INTO role_permissions (role, permission) VALUES ('platform_finance', 'platform.clients.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_finance', 'platform.catalog.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_finance', 'platform.payments.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_finance', 'platform.payments.manage');
INSERT INTO role_permissions (role, permission) VALUES ('platform_finance', 'platform.pricing.manage');
INSERT INTO role_permissions (role, permission) VALUES ('platform_finance', 'platform.regularisation.manage');

INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.access');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.dashboard.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.kpi.finance');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.health.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.clients.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.catalog.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.requests.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.payments.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.settings.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.users.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.admins.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.audit.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.audit.export');
INSERT INTO role_permissions (role, permission) VALUES ('platform_auditor', 'platform.messages.read');
