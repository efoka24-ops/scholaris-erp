-- Type et modèles de démonstration « attestation_simple » (catalogue plateforme, client '*').
-- Les modèles Bulletin et Relevé sont fournis par les applications.

INSERT INTO document_types (id, tenant_id, establishment_id, code, label, category, type_letter, number_template, qr_enabled, blockable, variables, created_at, updated_at)
VALUES ('01JZ0000000000000000000001', '*', '', 'attestation_simple', 'Attestation de scolarité', 'certificate', 'A', '{CODE}-{AA}-{LETTRE}-{N6}', 1, 0, '["academic_year","class_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00');

INSERT INTO document_templates (id, tenant_id, establishment_id, type_code, locale, html, version, validated_at, created_by, created_at)
VALUES ('01JZ0000000000000000000002', '*', '', 'attestation_simple', 'fr', '<html><body><h1>{{establishment_name}}</h1><h2>Attestation de scolarité</h2><p>Nous soussignés attestons que <strong>{{holder_name}}</strong> est régulièrement inscrit(e) en classe de {{class_name}} pour l''année scolaire {{academic_year}}.</p><p>Fait le {{issued_on}}.</p><div>{{signature}}{{stamp}}</div><p>Numéro : {{number}}</p><div>{{qr}}</div><p>Vérification : {{verification_url}}</p></body></html>', 1, '2026-10-08T00:00:00+01:00', NULL, '2026-10-08T00:00:00+01:00');

INSERT INTO document_templates (id, tenant_id, establishment_id, type_code, locale, html, version, validated_at, created_by, created_at)
VALUES ('01JZ0000000000000000000003', '*', '', 'attestation_simple', 'en', '<html><body><h1>{{establishment_name}}</h1><h2>School attendance certificate</h2><p>We certify that <strong>{{holder_name}}</strong> is duly enrolled in class {{class_name}} for the school year {{academic_year}}.</p><p>Issued on {{issued_on}}.</p><div>{{signature}}{{stamp}}</div><p>Number: {{number}}</p><div>{{qr}}</div><p>Verification: {{verification_url}}</p></body></html>', 1, '2026-10-08T00:00:00+01:00', NULL, '2026-10-08T00:00:00+01:00')
