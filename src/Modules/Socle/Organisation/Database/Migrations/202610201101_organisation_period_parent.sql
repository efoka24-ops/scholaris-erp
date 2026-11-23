ALTER TABLE periods ADD COLUMN parent_id CHAR(26) NULL;
CREATE INDEX periods_parent ON periods (tenant_id, establishment_id, parent_id);
