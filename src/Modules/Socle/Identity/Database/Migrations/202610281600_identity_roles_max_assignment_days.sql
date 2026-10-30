-- SS13 (T809) : durée maximale d'une affectation de rôle, en jours (NULL = sans limite). Un rôle borné exige une date de fin
-- (ends_at) au plus à cette durée de la date de début. L'expiration elle-même existe déjà : une affectation dont ends_at est
-- passée est ignorée par la résolution des permissions (PermissionResolver, PermissionScopes, RoleDirectory).
-- Portable MySQL 8 / SQLite : une colonne par ALTER TABLE.
ALTER TABLE roles ADD COLUMN max_assignment_days INTEGER NULL;
