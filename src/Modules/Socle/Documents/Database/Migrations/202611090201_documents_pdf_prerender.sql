-- Documents (C-118, NF-S2) : PDF des bulletins pré-rendus par la file (documents:process-jobs) dans le stockage des PDF.
-- pdf_claimed_at : verrou d'un processus de rendu (repris s'il est abandonné) ; pdf_rendered_at et pdf_profile : rendu stocké.
ALTER TABLE documents ADD COLUMN pdf_claimed_at VARCHAR(32) NULL;
ALTER TABLE documents ADD COLUMN pdf_rendered_at VARCHAR(32) NULL;
ALTER TABLE documents ADD COLUMN pdf_profile VARCHAR(10) NULL;
CREATE INDEX idx_documents_pdf_render ON documents (category, status, pdf_rendered_at);
