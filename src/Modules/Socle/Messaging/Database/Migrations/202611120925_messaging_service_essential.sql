-- Messaging (décision du 12 novembre 2026, D-BUL) : message de SERVICE essentiel (ex. SMS « bulletin disponible »), choisi par
-- l'application selon son paramètre. Il part sans consentement recueilli mais respecte la désinscription et le consentement révoqué.
ALTER TABLE messages ADD COLUMN essential_service SMALLINT NOT NULL DEFAULT 0;
