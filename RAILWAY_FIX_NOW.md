# 🚨 ACTION REQUISE - Healthcheck Railway échoue

## Diagnostic du problème

Basé sur les logs précédents, votre déploiement échoue pour **2 raisons** :

### ❌ 1. DATABASE_URL manquante
```
Error: Environment variable not found: DATABASE_URL
```

### ❌ 2. bcrypt module natif incompatible (CORRIGÉ)
```
Error: Cannot find module '/app/node_modules/bcrypt/lib/binding/napi-v3/bcrypt_lib.node'
```
✅ **Résolu** dans le commit `5d03579` - le Dockerfile rebuild maintenant bcrypt pour Linux

---

## ✅ Solution - Configurer les variables Railway

### Étape 1 : Ouvrir Railway Dashboard

1. Allez sur https://railway.app
2. Ouvrez votre projet SCHOLARIS ERP
3. Cliquez sur votre **service backend** (pas PostgreSQL)
4. Allez dans **Settings** > **Variables**

### Étape 2 : Ajouter DATABASE_URL

**Option A - Référence automatique (RECOMMANDÉ)** :
1. Cliquez **+ New Variable**
2. Sélectionnez **Add a Reference**
3. Choisissez votre service **PostgreSQL**
4. Sélectionnez **DATABASE_URL** dans la liste

**Option B - Copie manuelle** :
1. Allez dans votre service PostgreSQL
2. Onglet **Variables** > Copiez la valeur de `DATABASE_URL`
3. Revenez au service backend > **+ New Variable**
4. Nom : `DATABASE_URL`
5. Valeur : Collez l'URL copiée

### Étape 3 : Générer et ajouter les secrets JWT

Sur votre machine locale :

```powershell
cd C:\Users\YCXL3291\scholaris-erp
.\scripts\generate-railway-vars.ps1
```

Le script affiche :
```
JWT_ACCESS_SECRET=<secret-généré-aléatoirement>
JWT_REFRESH_SECRET=<autre-secret-généré>
CORS_ORIGIN=*
```

**Copiez ces 3 lignes.**

Dans Railway > Service Backend > Settings > Variables :
1. Cliquez **+ New Variable** 3 fois
2. Collez chaque paire nom=valeur

### Étape 4 : Vérifier que tout est configuré

Dans **Railway > Service Backend > Settings > Variables**, vous devez avoir **AU MINIMUM** :

- ✅ `DATABASE_URL` (référence ou valeur manuelle)
- ✅ `JWT_ACCESS_SECRET` (valeur générée)
- ✅ `JWT_REFRESH_SECRET` (valeur générée)
- ✅ `CORS_ORIGIN` = `*`

### Étape 5 : Redéployer

Railway redéploie **automatiquement** quand vous ajoutez des variables.

Sinon, forcez manuellement :
1. Allez dans **Deployments**
2. Cliquez sur **⋮** (trois points) du dernier déploiement
3. Sélectionnez **Redeploy**

---

## 🔍 Vérifier que ça fonctionne

### Logs de succès attendus

Dans **Railway > Deployments > Dernier déploiement > View Logs**, cherchez :

```
Prisma schema loaded from ../../packages/prisma/prisma/schema.prisma
Datasource "db": PostgreSQL database

Migration XXX marked as applied.
🚀 SCHOLARIS API - Démarrage...
✅ Application NestJS créée
✅ ConfigService chargé
🎯 Écoute sur le port 3001...
✅ SCHOLARIS API démarrée avec succès
📍 Health: http://localhost:3001/api/health
📚 Swagger: http://localhost:3001/api/docs
```

### Healthcheck réussi

Après ~30 secondes, le healthcheck doit passer :

```
====================
Starting Healthcheck
====================

Path: /api/health
Attempt #1 succeeded ✅
```

---

## ❌ Si ça échoue encore

### Vérifier les logs

Dans **Railway > Deployments > View Logs**, cherchez :

- `❌ ERREUR FATALE` → Le log dit quelle variable manque
- `Environment variable not found` → Variable non configurée
- `Cannot find module` → Problème de build (devrait être corrigé)

### Exécuter le diagnostic local

```powershell
.\scripts\railway-diagnostic.ps1 -Verbose
```

Vérifie que tous les fichiers sont présents et que les commits sont poussés.

### Variables supplémentaires optionnelles

Si vous voulez activer les communications :

```
BREVO_API_KEY=<votre-clé>
AFRICAS_TALKING_API_KEY=<votre-clé>
AFRICAS_TALKING_USERNAME=<votre-username>
WHATSAPP_ACCESS_TOKEN=<votre-token>
WHATSAPP_PHONE_NUMBER_ID=<votre-id>
```

**Note** : L'app démarre **sans** ces variables, elles sont optionnelles.

---

## 📚 Ressources

- [RAILWAY_CHECKLIST.md](RAILWAY_CHECKLIST.md) - Checklist complète étape par étape
- [RAILWAY_QUICKSTART.md](RAILWAY_QUICKSTART.md) - Guide de démarrage rapide
- [RAILWAY_DEPLOY.md](RAILWAY_DEPLOY.md) - Documentation détaillée avec diagnostics

---

## 🆘 Besoin d'aide ?

Si après avoir suivi ces étapes le healthcheck échoue encore :

1. Partagez les **logs complets du container** (pas juste le healthcheck)
2. Vérifiez que le commit `5d03579` (bcrypt fix) est bien déployé
3. Vérifiez que les 4 variables obligatoires sont présentes dans Railway

Le message d'erreur dans les logs dira **exactement** quel est le problème.
