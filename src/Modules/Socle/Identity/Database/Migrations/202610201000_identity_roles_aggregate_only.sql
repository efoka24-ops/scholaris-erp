-- PP2 (T606) : rôles de supervision à agrégats seuls. Un rôle marqué ne reçoit jamais une permission nominative (*.named.*),
-- même si une ligne de role_permissions la lui accorde par erreur (défense en profondeur, TST-05).
-- Rôles marqués (matrice du CDC public) : R01 cabinet, R03 planification, R05 délégué régional, R19 partenaire technique.
-- Portable MySQL 8 / SQLite : une colonne par ALTER TABLE ; mise à jour sans effet si le rôle n'est pas encore semé.
ALTER TABLE roles ADD COLUMN aggregate_only SMALLINT NOT NULL DEFAULT 0;

UPDATE roles SET aggregate_only = 1 WHERE code IN ('pub_cabinet', 'pub_planning', 'pub_regional_delegate', 'pub_technical_partner');
