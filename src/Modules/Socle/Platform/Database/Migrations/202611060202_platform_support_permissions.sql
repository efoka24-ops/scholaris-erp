-- Platform (C-113) : boîte du support. Lecture, réponse, statut et assignation des conversations de support
-- (jamais les conversations internes d'un établissement) ; ouverture vers les administrateurs d'un client réservée
-- à l'administrateur de plateforme. Le rôle platform_support (C-112) reçoit la boîte du support sans l'ouverture.

INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.support.manage');

INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.support.open');

INSERT INTO role_permissions (role, permission) VALUES ('platform_support', 'platform.support.manage');
