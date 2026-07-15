# Déploiement Railway — Guide Complet

## Prérequis

1. Projet Railway créé
2. Service backend créé (connecté à ce repo GitHub)
3. Service PostgreSQL ajouté au projet

---

## Configuration Obligatoire

### Variables d'environnement (Settings > Variables du service backend)

| Variable | Valeur | Requis | Description |
|---|---|---|---|
| `DATABASE_URL` | *(auto-liée si PostgreSQL dans le projet)* | ✅ | Connexion PostgreSQL |
| `JWT_ACCESS_SECRET` | `scholaris-jwt-access-secret-prod-2026!` | ✅ | Secret JWT (min 32 chars) |
| `JWT_REFRESH_SECRET` | `scholaris-jwt-refresh-secret-prod-2026!` | ✅ | Secret JWT refresh (min 32 chars) |
| `CORS_ORIGIN` | `*` | ✅ | Origine autorisée (ou URL frontend) |
| `REDIS_URL` | *(optionnel, service Redis si disponible)* | ❌ | Cache Redis |

**⚠️ CRITIQUE** : Sans `JWT_ACCESS_SECRET` et `JWT_REFRESH_SECRET`, l'app **crashe au boot** et le healthcheck échouera toujours.

---

## Étapes de Configuration

### 1. Ajouter PostgreSQL au projet

- Dashboard Railway > Add Service > Database > PostgreSQL
- Railway génère automatiquement `DATABASE_URL` et la lie au service backend

### 2. Configurer les variables JWT

**Settings > Variables du service backend > New Variable** :

```
JWT_ACCESS_SECRET=scholaris-jwt-access-secret-prod-2026!
JWT_REFRESH_SECRET=scholaris-jwt-refresh-secret-prod-2026!
CORS_ORIGIN=*
```

### 3. Vérifier les Settings de Build

**Settings > Builder** :
- Builder: `Dockerfile`
- Dockerfile Path: `Dockerfile`
- Root Directory: *(vide ou `/`)*

**Settings > Deploy** :
- Health Check Path: `/api/health`
- Health Check Timeout: `120` (2 minutes)

### 4. Redéployer

Après avoir ajouté les variables, redéployer le service :
- Deployments > ⋮ > Redeploy

---

## Initialisation de la Base de Données

Au premier déploiement, le conteneur exécute automatiquement :
```bash
npx prisma migrate deploy --schema=/app/packages/prisma/prisma/schema.prisma
```

Cela crée toutes les tables. Ensuite, pour créer le super admin :

### Option 1 : Via Railway CLI

```bash
railway run npm run db:seed --workspace=@scholaris/prisma
```

### Option 2 : Via psql direct

```bash
psql $DATABASE_URL -c "INSERT INTO \"User\" (id, email, \"passwordHash\", \"firstName\", \"lastName\", status, \"tenantId\") VALUES (gen_random_uuid(), 'admin@scholaris.dev', '\$2b\$12\$...', 'Super', 'Admin', 'ACTIVE', (SELECT id FROM \"Tenant\" LIMIT 1));"
```

---

## Diagnostic des Erreurs

### Healthcheck échoue continuellement

**Cause probable** : variables JWT manquantes → app crash au boot.

**Vérification** :
1. Deployments > Logs > vérifier les erreurs au démarrage
2. Chercher : `JWT_ACCESS_SECRET` ou `getOrThrow`
3. Si présent → ajouter la variable manquante

### "Migration failed"

**Cause** : `DATABASE_URL` incorrecte ou PostgreSQL inaccessible.

**Solution** :
- Vérifier que PostgreSQL est dans le même projet Railway
- Vérifier que `DATABASE_URL` est liée (Settings > Variables > Reference)

### Build échoue

**Cause** : erreur TypeScript ou dépendances manquantes.

**Solution** :
- Vérifier les logs de build
- Localement : `npm run build`

---

## URLs après Déploiement

- API : `https://ton-service.up.railway.app/api/health`
- Swagger : `https://ton-service.up.railway.app/api/docs`
- Login : `POST https://ton-service.up.railway.app/api/auth/login`

---

## Identifiants par Défaut (après seed)

| Champ | Valeur |
|---|---|
| Email | `admin@scholaris.dev` |
| Mot de passe | `ChangeMe123!` |
| Tenant | `DEMO` |

**⚠️ À changer immédiatement en production.**
