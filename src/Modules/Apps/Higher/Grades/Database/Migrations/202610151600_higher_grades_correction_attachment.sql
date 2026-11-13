-- Pièce jointe téléversée d'une demande de correction (API-12) : fichier stocké par le dépôt du socle (AttachmentStore,
-- stockage privé par client), chemin opaque, type réel et taille. attachment_ref garde le nom affiché (ou la référence
-- d'une pièce papier pour les demandes sans fichier). Une colonne par ALTER TABLE (portable MySQL 8 et SQLite).

ALTER TABLE hi_grade_corrections ADD COLUMN attachment_path VARCHAR(255) NULL;
ALTER TABLE hi_grade_corrections ADD COLUMN attachment_mime VARCHAR(60) NULL;
ALTER TABLE hi_grade_corrections ADD COLUMN attachment_size INTEGER NULL;
