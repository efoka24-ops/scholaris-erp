-- RAPPORT-MAIL (16 novembre 2026) : motif de chaque echec d'envoi d'un rapport planifie, par destinataire.
-- Liste JSON de couples person_id et reason (20 destinataires au plus), NULL pour les executions anterieures.

ALTER TABLE report_runs ADD COLUMN failures TEXT NULL;
