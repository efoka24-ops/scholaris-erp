# SCHOLARIS ERP v2.0

ERP de gestion scolaire (Cameroun). Monorepo Turborepo : `apps/api` (NestJS), `apps/web`
(Next.js 14), `packages/prisma|shared|ui`. Modules livrés à date : socle (auth JWT, RBAC,
multi-tenancy, health), structure pédagogique, matières/UE/EC, inscriptions & admissions,
gestion financière, saisie des notes & moteur de calcul, bulletins, communication
multicanal (email/SMS/WhatsApp), emplois du temps, présences, discipline, vie scolaire,
bibliothèque, transport, restauration, immobilisations, RH.

## Sommaire

- [Installation locale](#installation-locale)
- [Développement](#développement)
- [Tests](#tests)
- [Déploiement de production](#déploiement-de-production)
- [Variables d'environnement](#variables-denvironnement)
- [Choix d'architecture](#choix-darchitecture)
- [Structure du monorepo](#structure-du-monorepo)

## Installation locale

Prérequis : Node.js ≥ 20, npm ≥ 10, Docker Desktop.

```bash
npm install
cp .env.example .env
cp apps/web/.env.local.example apps/web/.env.local   # si présent

docker-compose up -d          # PostgreSQL 16 + Redis 7 (dev uniquement)
npm run db:generate           # génère le client Prisma (@scholaris/prisma)
npm run db:migrate            # applique le schéma
npm run db:seed               # crée le tenant démo + le super admin (upsert, sans risque à rejouer)
```

## Développement

```bash
npm run dev          # turbo run dev → apps/api sur :3001, apps/web sur :3000
```

- API : http://localhost:3001/api
- Health : http://localhost:3001/api/health
- Métriques Prometheus : http://localhost:3001/api/metrics
- Swagger : http://localhost:3001/api/docs
- Web : http://localhost:3000

### Identifiants du seed (dev)

| Champ | Valeur (voir `.env`) |
|---|---|
| Email | `admin@scholaris.dev` |
| Mot de passe | `ChangeMe123!` |
| Tenant | `DEMO` — Établissement Démo |

## Tests

```bash
npm run test                                     # tests unitaires (tous les workspaces)
npm run test:e2e --workspace=@scholaris/api       # tests e2e API (Jest + Supertest)
npx playwright test --config=apps/web/playwright.config.ts   # tests e2e web (Playwright)

# Test de charge (k6) — nécessite k6 installé (https://k6.io/docs/get-started/installation/)
k6 run load-tests.js

# Tests de sécurité (Playwright) — injections, auth bypass, headers, etc.
npx playwright test apps/web/e2e/security/security-tests.spec.ts
```

Ces mêmes étapes (lint, build, tests unitaires, tests e2e API) tournent automatiquement
sur chaque Pull Request via `.github/workflows/ci.yml`.

## Déploiement de production

### Railway (déploiement actuel, en production)

Le déploiement de production réel de SCHOLARIS ERP tourne sur **Railway**, configuré via :

- `railway.json` — build Docker depuis le `Dockerfile` racine, healthcheck sur `/api/health`.
- `nixpacks.toml` — configuration Nixpacks alternative (build sans Docker).

Railway surveille la branche `main` et **redéploie automatiquement** à chaque push — aucune
action manuelle supplémentaire n'est nécessaire. Les variables d'environnement de
production sont configurées directement dans Railway (Settings > Variables), pas via un
fichier `.env` commité. `apps/api/src/main.ts` gère explicitement le port fourni par
Railway (`process.env.PORT`) et l'écoute sur `0.0.0.0` — ne pas modifier ce fichier hors
ajouts strictement additifs.

Le workflow `.github/workflows/deploy.yml` build et publie les images Docker (api + web)
sur GitHub Container Registry (ghcr.io) à chaque push sur `main` — utile pour l'archivage
et le déploiement auto-hébergé ci-dessous, **mais ne déclenche pas** de déploiement
Railway (Railway le fait déjà seul).

### Déploiement auto-hébergé (VPS / serveur dédié)

Alternative complète avec reverse proxy nginx, MinIO (stockage S3-compatible pour logos,
documents élèves, PDF bulletins/reçus) et TLS via Let's Encrypt :

```bash
cp .env.production.example .env.production   # renseigner les vraies valeurs, ne pas committer
docker compose -f docker-compose.production.yml up -d --build

# Première application des migrations (si non gérée automatiquement par le conteneur api) :
./scripts/migrate-production.sh
```

Services : `postgres` (16-alpine), `redis` (7-alpine), `minio` (stockage fichiers),
`api` (Dockerfile racine), `web` (`apps/web/Dockerfile`, build Next.js standalone),
`nginx` (reverse proxy, TLS, gzip, cache `_next/static`, headers de sécurité — voir
`nginx/nginx.conf` et `nginx/conf.d/scholaris.conf`).

### Sauvegardes

`scripts/backup-db.sh` : `pg_dump` quotidien compressé, upload optionnel vers MinIO/S3.
Exemple de crontab dans le script (2h du matin, rétention 30 jours par défaut).

### Monitoring

- `GET /api/metrics` (apps/api) expose des métriques au format Prometheus (compteur de
  requêtes HTTP, histogramme de latence, uptime du process) — voir
  `apps/api/src/modules/metrics/`.
- `monitoring/prometheus.yml` : config de scrape prête à brancher sur une instance
  Prometheus existante.
- `monitoring/grafana-dashboard.json` : dashboard minimal importable dans Grafana
  (requêtes/s, taux d'erreur 5xx, latence p95, uptime).

## Variables d'environnement

- `.env.example` — développement local (docker-compose.yml).
- `.env.production.example` — production (documente aussi les clés MinIO/backup et les
  intégrations Module 8 : Brevo, Africa's Talking, WhatsApp Cloud API).

En production Railway, ces variables sont définies dans le dashboard Railway, pas dans un
fichier commité.

## Choix d'architecture

- **Multi-tenancy** : middleware Prisma (`$use`) dans `apps/api/src/prisma/prisma.service.ts`,
  pas de Row-Level Security PostgreSQL. Le contexte tenant/user courant est porté par
  `RequestContextService` (AsyncLocalStorage), peuplé par `JwtAccessStrategy`.
- **Soft delete** : même mécanisme, notamment sur `Tenant` et `User`.
- **JWT en cookies httpOnly** : l'API NestJS renvoie des tokens JSON classiques ; c'est
  `apps/web` qui les transforme en cookies httpOnly via ses propres Route Handlers
  (`app/api/auth/login|refresh|logout|me`), jamais exposés au JS du navigateur.
- **RBAC** : `Role`/`Permission`/`RolePermission`/`UserRole` en base, permissions résolues
  à la connexion et embarquées dans le JWT (`permissions: string[]`), vérifiées par
  `PermissionsGuard` + `@RequirePermissions(...)`.
- **Seed idempotent** : `packages/prisma/src/seed.ts` utilise `upsert` pour le tenant, les
  permissions, le rôle SUPER_ADMIN et l'utilisateur admin — rejouable sans danger en
  production (ne réinitialise jamais les mots de passe ou données existantes).

## Structure du monorepo

```
scholaris-erp/
├── apps/
│   ├── api/     NestJS — modules métier (auth, RBAC, structure, finance, notes, etc.)
│   │            + /api/health (probe Railway) et /api/metrics (Prometheus)
│   └── web/     Next.js 14 — app router, dashboard, e2e (Playwright, incl. security)
├── packages/
│   ├── prisma/  schéma centralisé + seed (upsert, sans danger en production)
│   ├── shared/  types, constantes RBAC, schémas Zod partagés
│   └── ui/      cn() + preset Tailwind
├── nginx/                       reverse proxy prod (auto-hébergé)
├── monitoring/                  config Prometheus + dashboard Grafana
├── scripts/                     migrate-production.sh, backup-db.sh (+ scripts Railway existants)
├── .github/workflows/           ci.yml, deploy.yml
├── Dockerfile                   image apps/api (utilisée par Railway ET docker-compose.production.yml)
├── apps/web/Dockerfile          image apps/web (Next.js standalone)
├── docker-compose.yml           dev local (postgres + redis)
├── docker-compose.production.yml  stack complète auto-hébergée
├── load-tests.js                test de charge k6
└── railway.json / nixpacks.toml   configuration du déploiement Railway (production actuelle)
```
