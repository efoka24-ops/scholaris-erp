#!/usr/bin/env bash
# Sauvegarde quotidienne de la base PostgreSQL SCHOLARIS, compressée et envoyée vers un
# stockage S3-compatible (MinIO en auto-hébergé, ou AWS S3 / autre selon la config).
#
# Prérequis :
#   - pg_dump (client PostgreSQL 16 installé sur l'hôte, ou exécuté via `docker exec`)
#   - `mc` (MinIO Client, https://min.io/docs/minio/linux/reference/minio-mc.html) OU
#     `aws` CLI configuré, selon le backend choisi ci-dessous.
#
# Variables d'environnement attendues (voir .env.production.example) :
#   DATABASE_URL, BACKUP_S3_BUCKET, BACKUP_S3_ENDPOINT (MinIO), AWS_ACCESS_KEY_ID,
#   AWS_SECRET_ACCESS_KEY (ou MINIO_ROOT_USER/MINIO_ROOT_PASSWORD)
#
# Exemple de crontab (sauvegarde quotidienne à 2h du matin, rétention gérée côté bucket) :
#   0 2 * * * cd /opt/scholaris-erp && ./scripts/backup-db.sh >> /var/log/scholaris-backup.log 2>&1

set -euo pipefail

TIMESTAMP="$(date +%Y-%m-%d_%H%M%S)"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
BACKUP_FILE="${BACKUP_DIR}/scholaris_${TIMESTAMP}.sql.gz"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"

mkdir -p "${BACKUP_DIR}"

if [ -z "${DATABASE_URL:-}" ]; then
  echo "ERREUR : DATABASE_URL n'est pas définie." >&2
  exit 1
fi

echo "→ Dump PostgreSQL vers ${BACKUP_FILE}"
pg_dump "${DATABASE_URL}" | gzip -9 > "${BACKUP_FILE}"
echo "✔ Dump local terminé ($(du -h "${BACKUP_FILE}" | cut -f1))"

# ─── Option A : upload vers MinIO (mc) ────────────────────────────────────────
# mc alias set scholaris-minio "${BACKUP_S3_ENDPOINT}" "${MINIO_ROOT_USER}" "${MINIO_ROOT_PASSWORD}"
# mc cp "${BACKUP_FILE}" "scholaris-minio/${BACKUP_S3_BUCKET}/db-backups/"

# ─── Option B : upload vers AWS S3 (ou compatible avec --endpoint-url) ───────
# aws s3 cp "${BACKUP_FILE}" "s3://${BACKUP_S3_BUCKET}/db-backups/" \
#   ${BACKUP_S3_ENDPOINT:+--endpoint-url "${BACKUP_S3_ENDPOINT}"}

if command -v mc >/dev/null 2>&1 && [ -n "${BACKUP_S3_BUCKET:-}" ]; then
  echo "→ Upload vers MinIO (bucket: ${BACKUP_S3_BUCKET})"
  mc cp "${BACKUP_FILE}" "scholaris-minio/${BACKUP_S3_BUCKET}/db-backups/" || \
    echo "⚠ Upload MinIO échoué — le dump local est conservé dans ${BACKUP_DIR}" >&2
elif command -v aws >/dev/null 2>&1 && [ -n "${BACKUP_S3_BUCKET:-}" ]; then
  echo "→ Upload vers S3 (bucket: ${BACKUP_S3_BUCKET})"
  aws s3 cp "${BACKUP_FILE}" "s3://${BACKUP_S3_BUCKET}/db-backups/" || \
    echo "⚠ Upload S3 échoué — le dump local est conservé dans ${BACKUP_DIR}" >&2
else
  echo "ℹ Ni 'mc' ni 'aws' configurés avec BACKUP_S3_BUCKET : dump conservé localement uniquement."
fi

# Nettoyage des sauvegardes locales de plus de RETENTION_DAYS jours
find "${BACKUP_DIR}" -name "scholaris_*.sql.gz" -mtime "+${RETENTION_DAYS}" -delete 2>/dev/null || true

echo "✔ Sauvegarde terminée."
