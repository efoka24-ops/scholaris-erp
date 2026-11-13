-- Examens nationaux et tutelle (EXN, TUT) : permissions manquantes aux rôles IPES, à reporter dans Policies/RoleMatrix.php.
-- API-17 : l'export de la liste nationale revient à R07 (scolarité) et R02 (direction générale, déjà dotée).
-- EX-TUT-01/02 : les transmissions à la tutelle (listes, notes, relevés, PV, dossiers de diplôme) sont préparées par R07 ;
-- R03 (directeur académique) les gère déjà par higher.tutelage.manage.

INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.national_list.export');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.tutelage.transmit');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.tutelage.transmit');

-- Alertes au directeur général (RG-EXN-2, T332) : export de la liste nationale et modification après export.
INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES
('01M4TKMG0NV2MM7KN6YWZAEX00', 'platform', 'platform', 'higher.national_list_exported', 'fr', 'email', 'Liste des candidats à l''examen national exportée', 'La liste des candidats :label (réf. :reference) vient d''être exportée : :details.', '["label","reference","details"]', '2026-10-15T00:00:00+01:00', '2026-10-15T00:00:00+01:00'),
('01M4TKMG0NV2MM7KN6YWZAEX01', 'platform', 'platform', 'higher.national_list_exported', 'en', 'email', 'National examination candidate list exported', 'The candidate list :label (ref. :reference) has just been exported: :details.', '["label","reference","details"]', '2026-10-15T00:00:00+01:00', '2026-10-15T00:00:00+01:00'),
('01M4TKMG0NV2MM7KN6YWZAEX02', 'platform', 'platform', 'higher.national_list_changed', 'fr', 'email', 'Liste nationale modifiée après export', 'La liste des candidats :label (réf. :reference) a été modifiée après son export : :details.', '["label","reference","details"]', '2026-10-15T00:00:00+01:00', '2026-10-15T00:00:00+01:00'),
('01M4TKMG0NV2MM7KN6YWZAEX03', 'platform', 'platform', 'higher.national_list_changed', 'en', 'email', 'National list changed after export', 'The candidate list :label (ref. :reference) was changed after its export: :details.', '["label","reference","details"]', '2026-10-15T00:00:00+01:00', '2026-10-15T00:00:00+01:00');
