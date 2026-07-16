# ─── Stage 1 : Build ──────────────────────────────────────────────────────────
FROM node:20-slim AS builder

RUN apt-get update -y && apt-get install -y openssl && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Manifestes racine (couche Docker mise en cache séparément)
COPY package.json package-lock.json turbo.json tsconfig.base.json ./

# Manifestes des workspaces nécessaires au build web
COPY apps/web/package.json              ./apps/web/
COPY packages/prisma/package.json       ./packages/prisma/
COPY packages/shared/package.json       ./packages/shared/
COPY packages/ui/package.json           ./packages/ui/

RUN npm ci --ignore-scripts

# Code source
COPY apps/web/        ./apps/web/
COPY packages/        ./packages/

# Client Prisma (types partagés par @scholaris/shared le cas échéant)
RUN npx prisma generate --schema=packages/prisma/prisma/schema.prisma

RUN npm run build --workspace=@scholaris/shared
RUN npm run build --workspace=@scholaris/ui --if-present

# Build Next.js en mode "standalone" (nécessite next.config.js: output: "standalone")
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build --workspace=@scholaris/web

# ─── Stage 2 : Production ─────────────────────────────────────────────────────
FROM node:20-slim AS production

WORKDIR /app

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

# Sortie "standalone" Next.js : serveur node autonome + node_modules minimal
COPY --from=builder /app/apps/web/.next/standalone ./
COPY --from=builder /app/apps/web/.next/static      ./apps/web/.next/static
COPY --from=builder /app/apps/web/public            ./apps/web/public

EXPOSE 3000

WORKDIR /app/apps/web

CMD ["node", "server.js"]
