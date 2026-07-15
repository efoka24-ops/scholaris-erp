# ─── Stage 1 : Build ──────────────────────────────────────────────────────────
FROM node:20-slim AS builder

# OpenSSL requis par le runtime Prisma
RUN apt-get update -y && apt-get install -y openssl && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Manifestes racine (couche Docker mise en cache séparément)
COPY package.json package-lock.json turbo.json tsconfig.base.json ./

# Manifestes des workspaces (couche séparée)
COPY apps/api/package.json              ./apps/api/
COPY packages/prisma/package.json       ./packages/prisma/
COPY packages/shared/package.json       ./packages/shared/
COPY packages/ui/package.json           ./packages/ui/

# Installation complète (dev + prod) nécessaire pour les outils de build
RUN npm ci --ignore-scripts

# Code source
COPY apps/api/        ./apps/api/
COPY packages/        ./packages/

# Génération du client Prisma pour Linux (remplace le binaire Windows du repo)
RUN npx prisma generate --schema=packages/prisma/prisma/schema.prisma

# Compilation de @scholaris/shared → packages/shared/dist/
RUN npm run build --workspace=@scholaris/shared

# Compilation de l'API NestJS (tsc, pas webpack)
RUN npm run build --workspace=@scholaris/api

# ─── Stage 2 : Production ─────────────────────────────────────────────────────
FROM node:20-slim AS production

RUN apt-get update -y && apt-get install -y openssl && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Manifestes pour npm ci --omit=dev
COPY package.json package-lock.json ./
COPY apps/api/package.json              ./apps/api/
COPY packages/prisma/package.json       ./packages/prisma/
COPY packages/shared/package.json       ./packages/shared/
COPY packages/ui/package.json           ./packages/ui/

# Dépendances de production uniquement (prisma CLI est en dependencies de @scholaris/prisma)
RUN npm ci --omit=dev --ignore-scripts

# Artefacts compilés depuis le stage builder
COPY --from=builder /app/apps/api/dist              ./apps/api/dist
COPY --from=builder /app/packages/prisma/generated  ./packages/prisma/generated
COPY --from=builder /app/packages/prisma/prisma     ./packages/prisma/prisma
COPY --from=builder /app/packages/shared/dist       ./packages/shared/dist

ENV NODE_ENV=production
EXPOSE 3001

WORKDIR /app/apps/api

# Migration puis démarrage
CMD ["sh", "-c", "/app/node_modules/.bin/prisma migrate deploy --schema=/app/packages/prisma/prisma/schema.prisma && node dist/main"]
