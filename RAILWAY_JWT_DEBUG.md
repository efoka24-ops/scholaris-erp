# 🔴 ERREUR : JWT_ACCESS_SECRET manquante dans Railway

## Le problème

Vos logs Railway montrent :
```
error: Configuration key "JWT_ACCESS_SECRET" does not exist
```

Même si vous avez configuré la variable dans Railway, **l'application ne la voit pas**.

---

## 🔍 Diagnostic : Vérifier que la variable est dans le BON service

### ⚠️ ERREUR FRÉQUENTE : Variables dans le mauvais service

Railway a **plusieurs services** dans votre projet :
- 🐘 **PostgreSQL** (service de base de données)
- 🚀 **Votre API Backend** (service applicatif)

Les variables doivent être dans **le service Backend**, PAS dans PostgreSQL !

### Comment vérifier

1. Allez sur https://railway.app
2. Ouvrez votre projet SCHOLARIS
3. Vous voyez plusieurs "cartes" :
   - Une carte **PostgreSQL**
   - Une carte **scholaris-erp** ou **api** ou **backend**

4. **Cliquez sur la carte du SERVICE BACKEND** (pas PostgreSQL !)

5. Allez dans **Settings** > **Variables**

6. **Vérifiez que vous voyez** :
   ```
   JWT_ACCESS_SECRET = <votre-valeur>
   JWT_REFRESH_SECRET = <votre-valeur>
   DATABASE_URL = ${{Postgres.DATABASE_URL}} (référence)
   CORS_ORIGIN = *
   ```

### ❌ Si les variables ne sont PAS là

Vous avez probablement configuré les variables dans le **mauvais service** (PostgreSQL au lieu de Backend).

**Solution** :
1. Dans le **service Backend** > Settings > Variables
2. Cliquez **+ New Variable**
3. Ajoutez :
   ```
   JWT_ACCESS_SECRET = <générez un secret>
   JWT_REFRESH_SECRET = <générez un autre secret>
   CORS_ORIGIN = *
   ```

### ✅ Si les variables SONT là mais l'erreur persiste

Railway n'a peut-être pas redéployé avec les nouvelles variables.

**Solution** :
1. Allez dans **Deployments**
2. Cliquez sur **⋮** (trois points) du dernier déploiement
3. Sélectionnez **Redeploy**
4. Attendez le nouveau déploiement
5. Vérifiez les logs

---

## 🛠️ Générer des secrets JWT sécurisés

Si vous n'avez pas encore de secrets :

### Windows PowerShell
```powershell
cd C:\Users\YCXL3291\scholaris-erp
.\scripts\generate-railway-vars.ps1
```

### Manuellement
Générez 2 chaînes aléatoires de 32+ caractères :

```
JWT_ACCESS_SECRET=ScH0l4r1s-Acc3ss-S3cr3t-Pr0d-2026-R4nD0m-K3y!
JWT_REFRESH_SECRET=ScH0l4r1s-R3fr3sh-S3cr3t-Pr0d-2026-R4nD0m-K3y!
```

⚠️ **NE COPIEZ PAS ces exemples !** Générez vos propres secrets uniques.

---

## 📋 Checklist complète Railway

Dans **Service Backend** > Settings > Variables, vous DEVEZ avoir :

- [ ] `DATABASE_URL` → Référence vers `${{Postgres.DATABASE_URL}}`
- [ ] `JWT_ACCESS_SECRET` → Secret aléatoire de 32+ caractères
- [ ] `JWT_REFRESH_SECRET` → Autre secret différent de 32+ caractères
- [ ] `CORS_ORIGIN` → `*` (ou URL de votre frontend)

**AUCUNE autre variable n'est obligatoire pour que l'app démarre.**

---

## 🔬 Voir ce que Railway voit vraiment

Après le prochain déploiement, les logs vont maintenant afficher :

```
🚀 SCHOLARIS API - Démarrage...
📋 Diagnostic des variables d'environnement :
   NODE_ENV: production
   PORT: 3001
   DATABASE_URL: ✅ définie
   JWT_ACCESS_SECRET: ✅ définie  ← DOIT être ✅
   JWT_REFRESH_SECRET: ✅ définie  ← DOIT être ✅
   CORS_ORIGIN: *
```

Si vous voyez `❌ MANQUANTE` pour JWT_ACCESS_SECRET, alors Railway ne transmet **vraiment pas** la variable à l'app.

---

## 🆘 Si rien ne fonctionne

### Vérification ultime : Railway CLI

```bash
# Installer Railway CLI si pas fait
npm i -g @railway/cli

# Se connecter
railway login

# Lier ce projet
railway link

# Voir TOUTES les variables du service
railway variables

# Tester en local avec les variables Railway
railway run npm run start:dev --workspace=@scholaris/api
```

Si `railway variables` ne montre **pas** `JWT_ACCESS_SECRET`, alors la variable n'existe vraiment pas dans Railway.

### Recréer le service si nécessaire

En dernier recours :
1. Supprimez le service Backend dans Railway (PAS PostgreSQL !)
2. Créez un nouveau service depuis GitHub
3. Configurez les 4 variables obligatoires
4. Déployez

---

## 📞 Informations à fournir pour le support

Si le problème persiste après avoir vérifié tout cela, fournissez :

1. **Capture d'écran** de Railway > Service Backend > Settings > Variables
2. **Logs complets** du dernier déploiement (depuis "Starting Container")
3. Résultat de `railway variables` depuis votre terminal local
4. Confirmation que vous êtes bien dans le **service Backend** et pas PostgreSQL

---

## 🎯 Résultat attendu

Après configuration correcte, les logs doivent montrer :

```
🚀 SCHOLARIS API - Démarrage...
📋 Diagnostic des variables d'environnement :
   DATABASE_URL: ✅ définie
   JWT_ACCESS_SECRET: ✅ définie
   JWT_REFRESH_SECRET: ✅ définie
✅ Application NestJS créée
✅ ConfigService chargé
🎯 Écoute sur le port 3001...
✅ SCHOLARIS API démarrée avec succès
```

Healthcheck passe dans les 30 secondes suivantes.
