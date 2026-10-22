-- Droit dédié à la validation des annulations de paiement « par la direction » (W-002). Il ne sert que si l'administrateur désigne
-- la direction comme validatrice dans le circuit billing_reversal ; sans réglage, la validation reste portée par billing.reversal.approve.
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'billing.reversal.approve.direction');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'billing.reversal.approve.direction');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'billing.reversal.approve.direction');
