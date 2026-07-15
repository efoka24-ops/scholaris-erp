# 🔑 Guide de Connexion - SCHOLARIS ERP

## Problème actuel

Vous voyez **"Identifiants invalides"** car :

1. ❌ Le frontend pointe vers un placeholder au lieu de votre backend Railway réel
2. ❌ La base de données Railway n'a pas encore été seedée avec le compte admin

---

## ✅ Solution en 3 étapes

### Étape 1 : Configurer l'URL Railway

**Option A - Script automatique (RECOMMANDÉ)** :

```powershell
.\scripts\configure-frontend-railway.ps1
```

Le script vous demandera l'URL de votre backend Railway.

**Option B - Manuel** :

1. Trouvez l'URL Railway :
   - Allez sur https://railway.app
   - Projet > Service Backend > Settings > **Public Networking**
   - Si aucune URL n'existe, cliquez **Generate Domain**
   - Copiez le domaine (ex: `scholaris-api-production.up.railway.app`)

2. Éditez `apps/web/.env.local` :
   ```env
   NEST_API_URL="https://VOTRE-URL-RAILWAY.up.railway.app/api"
   ```

3. Redémarrez Next.js :
   - Ctrl+C dans le terminal Next.js
   - `npm run dev --workspace=@scholaris/web`

---

### Étape 2 : Seeder la base de données Railway

**Installez Railway CLI** (si pas encore fait) :

```powershell
npm install -g @railway/cli
```

**Liez le projet et seedez** :

```powershell
railway login
railway link
railway run npm run db:seed --workspace=@scholaris/prisma
```

Cela crée le compte super admin dans la base Railway.

---

### Étape 3 : Tester le login

1. Allez sur http://localhost:3000
2. Vous serez redirigé vers `/login`
3. Connectez-vous avec :
   - **Email** : `admin@scholaris.dev`
   - **Mot de passe** : `ChangeMe123!`

Si tout est configuré correctement, vous serez connecté et redirigé vers `/dashboard`.

---

## 🔍 Diagnostics

### Le login retourne toujours "Identifiants invalides"

**Vérifiez que le seed a fonctionné** :

```powershell
railway run npx prisma studio --schema=packages/prisma/prisma/schema.prisma
```

Ouvrez Prisma Studio (http://localhost:5555) et vérifiez que :
- La table `User` contient `admin@scholaris.dev`
- La table `Tenant` contient un établissement

### Le frontend ne communique pas avec Railway

**Vérifiez les logs du terminal Next.js** :

Si vous voyez `POST /api/auth/login 500`, le frontend n'arrive pas à joindre Railway.

**Testez manuellement** :

```powershell
Invoke-WebRequest -Uri "https://VOTRE-URL.up.railway.app/api/health"
```

Devrait retourner `200` avec `{"status":"ok"}`.

### Railway ne répond pas

**Vérifiez que le service est démarré** :

Railway > Service Backend > Deployments → Le dernier déploiement doit être **Active** avec healthcheck ✅

---

## 📝 Résumé des identifiants

### Admin créé par le seed

- Email : `admin@scholaris.dev`
- Password : `ChangeMe123!`
- Tenant : `DEMO` (établissement de démonstration)
- Permissions : Toutes (super admin)

### Variables Railway requises

Le backend Railway **DOIT** avoir ces variables (Railway > Service > Settings > Variables) :

- `DATABASE_URL` → Référence PostgreSQL
- `JWT_ACCESS_SECRET` → Secret généré
- `JWT_REFRESH_SECRET` → Autre secret
- `CORS_ORIGIN` → `*` (ou URL frontend en prod)

Si une variable manque, le healthcheck échoue.

---

## 🆘 Aide

Si après ces 3 étapes le problème persiste :

1. Partagez les logs du terminal Next.js
2. Partagez les logs Railway (Deployments > View Logs)
3. Confirmez que vous avez bien suivi les 3 étapes ci-dessus
