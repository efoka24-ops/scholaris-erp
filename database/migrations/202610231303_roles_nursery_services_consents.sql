-- Maternelle privée (N-002) : permissions des services (garderie, cantine, transport) et du recueil du consentement photo.
-- Miroir exact de src/Modules/Apps/Nursery/Policies/RoleMatrix.php et de web/src/views/nursery-private/rolePermissions.ts.
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.service.manage');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.service.attend');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.consent.manage');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'nursery.service.manage');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'nursery.consent.manage');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.service.attend');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_assistant', 'nursery.service.attend');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_cantine', 'nursery.service.attend');
