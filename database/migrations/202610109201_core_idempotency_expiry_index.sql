-- Purge des clés d'idempotence expirées (DELETE ... WHERE expires_at < ?) à chaque réservation : sans index, parcours
-- complet de la table (P4 de la recette). Index simple sur une colonne entière, portable MySQL 8 et SQLite.

CREATE INDEX idempotency_keys_expires_at ON idempotency_keys (expires_at);
