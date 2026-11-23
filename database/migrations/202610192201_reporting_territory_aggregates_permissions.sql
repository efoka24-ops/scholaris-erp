-- PP4 (T613) : lecture des agrégats masqués par territoire (GET /dashboards/territories/{territory}), permission du socle
-- Reporting accordée aux rôles publics qui lisent les agrégats (public_schools.aggregates.read) : R01 à R07 et R19.
INSERT INTO role_permissions (role, permission) VALUES ('pub_cabinet', 'reporting.territory_aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_exam_directorate', 'reporting.territory_aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_planning', 'reporting.territory_aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_inspection_general', 'reporting.territory_aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_regional_delegate', 'reporting.territory_aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'reporting.territory_aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'reporting.territory_aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_technical_partner', 'reporting.territory_aggregates.read');
