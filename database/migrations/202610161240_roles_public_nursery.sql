-- T712 : rôles propres à la Maternelle publique (R11, R12) et leurs permissions public_nursery.*.
-- Les rôles communs du socle sont semés ailleurs ; R14 n'est pas un compte et n'a donc pas de rôle.
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pn_educator', 'class', 0, 'Éducatrice ou enseignant de maternelle', 'Nursery educator / teacher');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pn_assistant', 'class', 0, 'Aide maternelle', 'Nursery assistant');

INSERT INTO role_permissions (role, permission) VALUES ('pn_educator', 'public_nursery.pickup.handover');
INSERT INTO role_permissions (role, permission) VALUES ('pn_educator', 'public_nursery.dev.observe');
INSERT INTO role_permissions (role, permission) VALUES ('pn_educator', 'public_nursery.lifebook.write');
INSERT INTO role_permissions (role, permission) VALUES ('pn_educator', 'public_nursery.need.report');
INSERT INTO role_permissions (role, permission) VALUES ('pn_educator', 'public_nursery.transition.opinion');

INSERT INTO role_permissions (role, permission) VALUES ('pn_assistant', 'public_nursery.pickup.handover');
INSERT INTO role_permissions (role, permission) VALUES ('pn_assistant', 'public_nursery.arrival.record');