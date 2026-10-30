-- Défaut D2 (recette) : paiement en libre-service depuis les portails famille et apprenant.
-- billing.self.pay  : émettre SA référence famille et lancer un paiement mobile money de SES propres factures ou références ;
-- billing.self.read : lire SES références, SES paiements et SES reçus.
-- Aucun autre droit de facturation : le contrôle d'objet (la référence, l'apprenant, la facture ou le reçu appartient à la
-- personne connectée ou à un apprenant dont elle est garant) est appliqué par Billing, refus 404 sinon.

INSERT INTO role_permissions (role, permission) VALUES ('parent', 'billing.self.pay');
INSERT INTO role_permissions (role, permission) VALUES ('parent', 'billing.self.read');
INSERT INTO role_permissions (role, permission) VALUES ('apprenant', 'billing.self.pay');
INSERT INTO role_permissions (role, permission) VALUES ('apprenant', 'billing.self.read');
