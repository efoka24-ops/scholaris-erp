# SCHOLARIS ERP v2.0 — Phase 0 (socle du monorepo)

Monorepo Turborepo posé selon le Guide de développement §0.A/0.B : `apps/api` (NestJS),
`apps/web` (Next.js 14), `packages/prisma|shared|ui`.

## Prérequis

- Node.js ≥ 20, npm ≥ 10
- Docker Desktop (PostgreSQL 16 + Redis 7) — **non installé sur cette machine au moment de
  la génération de ce scaffold**. Les commandes ci-dessous supposent que Docker est
  disponible sur la machine qui les exécute.

## Installation

```bash
npm install
cp .env.example .env
cp apps/web/.env.local.example apps/web/.env.local

docker-compose up -d          # PostgreSQL 16 + Redis 7
npm run db:generate           # génère le client Prisma (@scholaris/prisma)
npm run db:migrate            # applique le schéma (nomme la migration, ex: "init")
npm run db:seed               # crée le tenant démo + le super admin
```

## Développement

```bash
npm run dev          # turbo run dev → apps/api sur :3001, apps/web sur :3000
```

## Les 10 tests de validation Phase 0 (§0.B)

| # | Test | Commande | Statut à la génération de ce scaffold |
|---|------|----------|----------------------------------------|
| 1 | Postgres/Redis démarrent | `docker-compose up -d` | ⏳ Docker non installé ici — à valider sur une machine avec Docker |
| 2 | API sur :3001 | `npm run start:dev --workspace=@scholaris/api` | ⏳ nécessite Postgres/Redis |
| 3 | Web sur :3000 | `npm run dev --workspace=@scholaris/web` | ✅ démarre indépendamment de la base |
| 4 | Migrations appliquées | `npm run db:migrate` | ⏳ nécessite Postgres |
| 5 | Seed super admin | `npm run db:seed` | ⏳ nécessite Postgres |
| 6 | Login → JWT | `POST http://localhost:3001/api/auth/login` | ⏳ nécessite 1, 4, 5 |
| 7 | `GET /api/health` | `curl http://localhost:3001/api/health` | ⏳ nécessite 1 |
| 8 | Page de login affichée | `http://localhost:3000` | ✅ (redirige `/` → `/login`) |
| 9 | Swagger | `http://localhost:3001/api/docs` | ⏳ nécessite l'API démarrée |
| 10 | Build complet | `npm run build` | À vérifier après `npm install` |

Sur cette machine, Docker n'étant pas installé, les tests 1, 2, 4, 5, 6, 7, 9 n'ont pas pu
être exécutés en conditions réelles — le code est en place et prêt à être validé dès que
Docker Desktop (ou un Postgres/Redis accessibles) est disponible.

## Identifiants du seed

| Champ | Valeur (par défaut, voir `.env`) |
|---|---|
| Email | `admin@scholaris.dev` |
| Mot de passe | `ChangeMe123!` |
| Tenant | `DEMO` — Établissement Démo |

## Choix d'architecture Phase 0 (à connaître avant le Module 1)

- **Multi-tenancy** : middleware Prisma (`$use`) dans `apps/api/src/prisma/prisma.service.ts`,
  pas de Row-Level Security PostgreSQL pour l'instant (suit la convention §0.3 du guide :
  "middleware global"). Le contexte tenant/user courant est porté par
  `RequestContextService` (AsyncLocalStorage), peuplé par `JwtAccessStrategy`.
- **Soft delete** : même mécanisme, sur `Tenant` et `User` (seuls modèles avec `deletedAt`
  en Phase 0).
- **JWT en cookies httpOnly** : l'API NestJS renvoie des tokens JSON classiques ; c'est
  `apps/web` qui les transforme en cookies httpOnly via ses propres Route Handlers
  (`app/api/auth/login|refresh|logout|me`), jamais exposés au JS du navigateur.
- **RBAC** : `Role`/`Permission`/`RolePermission`/`UserRole` en base, permissions résolues
  à la connexion et embarquées dans le JWT (`permissions: string[]`), vérifiées par
  `PermissionsGuard` + `@RequirePermissions(...)`.
- **Hors scope Phase 0** (explicitement réservé au Module 1, §1.3/§1.6) : `register`, MFA,
  forgot/reset password, verrouillage après 5 échecs de connexion, écrans
  `/settings/users`, `/settings/establishment`, `/settings/calculation-engine`, journal
  d'audit exploité en interface, tests Playwright de ces parcours.

## Structure

```
scholaris-erp/
├── apps/
│   ├── api/     NestJS — Prisma module, Auth (login/refresh/me), Health, RBAC
│   └── web/     Next.js 14 — login, layout dashboard, AuthProvider/TenantProvider
├── packages/
│   ├── prisma/  schéma centralisé + seed
│   ├── shared/  types, constantes RBAC, schémas Zod (réutilisés en frontend)
│   └── ui/      cn() + preset Tailwind (les composants Shadcn vivent dans apps/web)
└── turbo.json
```
