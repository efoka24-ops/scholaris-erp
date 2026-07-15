# ✅ Railway Deployment Checklist - SCHOLARIS ERP

## 📋 Checklist avant déploiement

### ☑️ Étape 1 : Service PostgreSQL créé dans Railway
- [ ] Service PostgreSQL créé dans le même projet Railway
- [ ] Base de données démarrée et accessible

### ☑️ Étape 2 : Variables d'environnement configurées

Dans **Railway > Service Backend > Settings > Variables** :

#### Variables OBLIGATOIRES (l'app crash sans elles) :

- [ ] **DATABASE_URL**
  - Méthode 1 (recommandée) : Cliquez "Add a Reference" > Sélectionnez PostgreSQL > DATABASE_URL
  - Méthode 2 : Copiez manuellement l'URL depuis le service PostgreSQL
  
- [ ] **JWT_ACCESS_SECRET**
  - Générez avec `.\scripts\generate-railway-vars.ps1`
  - Ou créez un secret aléatoire de 32+ caractères
  
- [ ] **JWT_REFRESH_SECRET**
  - Générez avec `.\scripts\generate-railway-vars.ps1`
  - Ou créez un autre secret différent du premier

#### Variables RECOMMANDÉES :

- [ ] **CORS_ORIGIN** = `*` (ou l'URL de votre frontend)

#### Variables OPTIONNELLES (l'app démarre sans) :

- [ ] **BREVO_API_KEY** (pour emails via Brevo)
- [ ] **AFRICAS_TALKING_API_KEY** (pour SMS)
- [ ] **AFRICAS_TALKING_USERNAME** (pour SMS)
- [ ] **WHATSAPP_ACCESS_TOKEN** (pour WhatsApp)
- [ ] **WHATSAPP_PHONE_NUMBER_ID** (pour WhatsApp)

### ☑️ Étape 3 : Vérification du déploiement

Après configuration des variables, Railway redéploie automatiquement.

Dans **Railway > Deployments > Dernier déploiement > View Logs**, vérifiez :

#### ✅ Succès - Vous devriez voir :
```
Prisma schema loaded from ../../packages/prisma/prisma/schema.prisma
Datasource "db": PostgreSQL database

Migration ... marked as applied.
🚀 SCHOLARIS API - Démarrage...
✅ Application NestJS créée
✅ ConfigService chargé
🎯 Écoute sur le port 3001...
✅ SCHOLARIS API démarrée avec succès
📍 Health: http://localhost:3001/api/health
```

#### ❌ Échec - Si vous voyez ces erreurs :

| Erreur dans les logs | Cause | Solution |
|---------------------|-------|----------|
| `Environment variable not found: DATABASE_URL` | DATABASE_URL non configurée | Ajoutez la référence PostgreSQL dans Variables |
| `❌ ERREUR FATALE ... JWT_ACCESS_SECRET` | Secret JWT manquant | Ajoutez JWT_ACCESS_SECRET dans Variables |
| `❌ ERREUR FATALE ... JWT_REFRESH_SECRET` | Secret JWT manquant | Ajoutez JWT_REFRESH_SECRET dans Variables |
| `Cannot find module bcrypt` | bcrypt pas recompilé (bug ancien) | Devrait être corrigé dans le dernier commit |
| `Migration skipped` suivi d'erreur Prisma | DATABASE_URL invalide | Vérifiez l'URL PostgreSQL |

### ☑️ Étape 4 : Seed de la base de données

Une fois le healthcheck ✅ :

```bash
railway link  # Si pas encore fait
railway run npm run db:seed --workspace=@scholaris/prisma
```

Crée le compte super admin :
- 📧 Email : `admin@scholaris.dev`
- 🔑 Mot de passe : `ChangeMe123!`

---

## 🔧 Commandes utiles Railway CLI

```bash
# Lier ce projet au service Railway
railway link

# Voir les logs en temps réel
railway logs

# Exécuter une commande dans l'environnement Railway
railway run <commande>

# Voir les variables d'environnement
railway variables

# Redéployer manuellement
railway up
```

---

## 🆘 Dépannage

### Le healthcheck échoue toujours

1. **Vérifiez les logs de démarrage** (pas les logs healthcheck)
   - Railway > Deployments > Dernier déploiement > View Logs
   - Cherchez les lignes avec `❌ ERREUR FATALE` ou erreurs Node.js

2. **Variables manquantes ?**
   - Vérifiez que DATABASE_URL, JWT_ACCESS_SECRET, JWT_REFRESH_SECRET sont bien configurées
   - Dans Railway > Settings > Variables, toutes les variables obligatoires doivent être présentes

3. **Build échoue ?**
   - Vérifiez que le dernier commit (avec bcrypt rebuild) a bien été déployé
   - Le commit doit être `fix: rebuild bcrypt native bindings for Linux` (5d03579)

4. **PostgreSQL pas prêt ?**
   - Vérifiez que le service PostgreSQL est démarré
   - Testez la connexion avec `railway run npx prisma migrate status`

### Le build réussit mais l'app crash au démarrage

C'est presque toujours une **variable d'environnement manquante**. Les nouveaux logs détaillés vous diront exactement laquelle.

---

## 📞 Support

- [RAILWAY_QUICKSTART.md](RAILWAY_QUICKSTART.md) - Guide rapide
- [RAILWAY_DEPLOY.md](RAILWAY_DEPLOY.md) - Guide complet avec diagnostics
