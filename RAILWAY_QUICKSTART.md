# 🚀 Railway Quickstart - SCHOLARIS ERP

## Variables à configurer IMMÉDIATEMENT

Allez dans **Railway > Votre Service Backend > Settings > Variables**

### 1️⃣ Base de données

Si vous avez créé un service PostgreSQL dans Railway :
- **Méthode automatique** : Cliquez sur **+ New Variable** > **Add a reference** > Sélectionnez votre PostgreSQL > `DATABASE_URL`
- **Méthode manuelle** : Copiez l'URL depuis votre service PostgreSQL

Si DATABASE_URL n'est pas configurée, l'app crash immédiatement.

### 2️⃣ Secrets JWT (obligatoires)

Générez des secrets sécurisés :

```powershell
# Sur Windows
.\scripts\generate-railway-vars.ps1
```

Ou manuellement, ajoutez ces variables :

```
JWT_ACCESS_SECRET=<générez-un-secret-aléatoire-de-32-caractères>
JWT_REFRESH_SECRET=<générez-un-autre-secret-différent>
```

**Exemple de secrets sécurisés** (à remplacer par les vôtres) :
```
JWT_ACCESS_SECRET=ScHoL4r1s-JWT-4cc3ss-S3cr3t-2026-P0sT9r3s-S3cUr3!
JWT_REFRESH_SECRET=ScHoL4r1s-JWT-R3fr3sh-S3cr3t-2026-P0sT9r3s-S3cUr3!
```

### 3️⃣ CORS (recommandé)

```
CORS_ORIGIN=*
```

Pour la production, remplacez `*` par l'URL de votre frontend Next.js.

### 4️⃣ Communication (optionnels - l'app démarre sans)

```
BREVO_API_KEY=<votre-clé-brevo>
AFRICAS_TALKING_API_KEY=<votre-clé>
AFRICAS_TALKING_USERNAME=<votre-username>
WHATSAPP_ACCESS_TOKEN=<votre-token>
WHATSAPP_PHONE_NUMBER_ID=<votre-phone-id>
```

## Redéployer

Après avoir ajouté les variables :
1. Allez dans **Deployments**
2. Cliquez sur **⋮** (trois points) du dernier déploiement
3. Sélectionnez **Redeploy**

## Vérifier le succès

Dans les **logs du déploiement**, vous devriez voir :

```
✅ Application NestJS créée
✅ ConfigService chargé
🎯 Écoute sur le port 3001...
✅ SCHOLARIS API démarrée avec succès
```

Si vous voyez `❌ ERREUR FATALE`, le log indique quelle variable manque.

## Healthcheck

Railway vérifie automatiquement `/api/health`. Si le healthcheck passe, votre service est en ligne ✅

## Seed de la base de données

Une fois l'app démarrée avec succès :

```bash
railway run npm run db:seed --workspace=@scholaris/prisma
```

Crée le super admin :
- Email : `admin@scholaris.dev`
- Mot de passe : `ChangeMe123!`

## Problèmes courants

| Erreur | Solution |
|--------|----------|
| `Environment variable not found: DATABASE_URL` | Liez le service PostgreSQL dans Variables |
| `Error: Cannot find module bcrypt` | Redéployez (le Dockerfile rebuild bcrypt) |
| `JWT_ACCESS_SECRET manquante` | Ajoutez la variable JWT (voir ci-dessus) |
| Healthcheck timeout | Normal au premier déploiement (migration ~2 min) |

## Support

Consultez [RAILWAY_DEPLOY.md](RAILWAY_DEPLOY.md) pour le guide complet.
