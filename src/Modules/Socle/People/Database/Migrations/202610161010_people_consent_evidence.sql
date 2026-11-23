-- Preuve de consentement écrit : référence libre de 80 caractères max, nullable pour les consentements standards.

ALTER TABLE consents ADD COLUMN evidence_ref VARCHAR(80) NULL;