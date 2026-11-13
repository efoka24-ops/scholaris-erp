-- PP7 (T608) : code personnel à 6 chiffres par utilisateur sur un appareil partagé (haché, jamais en clair). Cinq échecs
-- consécutifs verrouillent l'appareil (locked_at) : il ne soumet plus de lot et reçoit l'ordre d'effacer sa base locale.
-- Écart voulu à socle-reuse.md (identity_device_pins) : la table vit dans Sync, propriétaire des appareils.
-- Portable MySQL 8 / SQLite : une colonne par ALTER TABLE, dates ISO en texte.
ALTER TABLE sync_devices ADD COLUMN failed_unlocks INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sync_devices ADD COLUMN locked_at VARCHAR(32) NULL;

CREATE TABLE sync_device_pins (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    device_id CHAR(26) NOT NULL,
    user_id VARCHAR(40) NOT NULL,
    pin_hash VARCHAR(255) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX sync_device_pins_user ON sync_device_pins (tenant_id, device_id, user_id);
