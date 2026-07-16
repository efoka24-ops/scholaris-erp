#!/usr/bin/env bash
# Applique les migrations Prisma en production (docker-compose.production.yml auto-hébergé).
#
# IMPORTANT : utilise `prisma migrate deploy` (jamais `migrate dev`) — n'invente pas de
# nouvelle migration, applique uniquement celles déjà commitées dans
# packages/prisma/prisma/migrations/. Idempotent : peut être relancé sans risque.
#
# Sur Railway, ce même appel est déjà fait automatiquement au démarrage du conteneur
# (voir la commande CMD du Dockerfile racine et nixpacks.toml) — ce script est destiné
# au déploiement auto-hébergé (docker-compose.production.yml) ou à une exécution manuelle.

set -euo pipefail

SCHEMA_PATH="packages/prisma/prisma/schema.prisma"

if [ -z "${DATABASE_URL:-}" ]; then
  echo "ERREUR : DATABASE_URL n'est pas définie dans l'environnement." >&2
  echo "  Chargez .env.production avant d'exécuter ce script, ex :" >&2
  echo "  set -a; source .env.production; set +a; ./scripts/migrate-production.sh" >&2
  exit 1
fi

echo "→ Application des migrations Prisma (production) sur ${DATABASE_URL%%@*}@***"
npx prisma migrate deploy --schema="${SCHEMA_PATH}"

echo "✔ Migrations appliquées avec succès."

# Exécution via docker-compose (alternative), depuis le conteneur api déjà construit :
#   docker compose -f docker-compose.production.yml run --rm api \
#     npx prisma migrate deploy --schema=/app/packages/prisma/prisma/schema.prisma
