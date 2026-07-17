import { existsSync, mkdirSync, unlinkSync } from "fs";
import { writeFile } from "fs/promises";
import { join, resolve } from "path";
import { randomUUID } from "crypto";

/**
 * Stockage disque des pièces jointes de pré-inscription (bulletins de
 * l'ancien établissement). Pas de MinIO/S3 provisionné pour l'instant — les
 * fichiers vivent sur le volume Railway attaché au service API (voir
 * docker-compose.production.yml / config Railway). Migration vers un stockage
 * objet S3-compatible possible plus tard sans changer l'API publique de ce
 * module (uniquement `save`/`resolvePath`/`remove`).
 */

export const ALLOWED_DOCUMENT_MIME_TYPES = ["application/pdf", "image/jpeg", "image/png"];
export const MAX_DOCUMENT_SIZE_BYTES = 5 * 1024 * 1024; // 5 Mo par fichier
export const MAX_DOCUMENTS_PER_APPLICATION = 5;

const UPLOADS_ROOT = resolve(process.env.UPLOADS_DIR ?? join(process.cwd(), "uploads"));

export interface StoredDocumentMeta {
  fileName: string;
  originalName: string;
  mimeType: string;
  size: number;
  uploadedAt: string;
}

function admissionDir(applicationId: string): string {
  return join(UPLOADS_ROOT, "admissions", applicationId);
}

export async function saveAdmissionDocument(
  applicationId: string,
  file: Express.Multer.File,
): Promise<StoredDocumentMeta> {
  const dir = admissionDir(applicationId);
  if (!existsSync(dir)) {
    mkdirSync(dir, { recursive: true });
  }
  const extension = file.originalname.includes(".") ? file.originalname.split(".").pop() : "";
  const fileName = `${randomUUID()}${extension ? `.${extension}` : ""}`;
  await writeFile(join(dir, fileName), file.buffer);

  return {
    fileName,
    originalName: file.originalname,
    mimeType: file.mimetype,
    size: file.size,
    uploadedAt: new Date().toISOString(),
  };
}

/** Résout un chemin absolu sûr pour un fichier déjà enregistré (pas de traversal possible : fileName est un UUID généré par nous). */
export function resolveAdmissionDocumentPath(applicationId: string, fileName: string): string {
  if (!/^[a-f0-9-]+(\.[a-zA-Z0-9]+)?$/.test(fileName)) {
    throw new Error("Nom de fichier invalide");
  }
  return join(admissionDir(applicationId), fileName);
}

export function removeAdmissionDocument(applicationId: string, fileName: string): void {
  const path = resolveAdmissionDocumentPath(applicationId, fileName);
  if (existsSync(path)) {
    unlinkSync(path);
  }
}
