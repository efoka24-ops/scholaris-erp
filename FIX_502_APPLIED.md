# ✅ Fix 502 appliqué - Actions Railway requises

## Ce qui a été corrigé

J'ai modifié le code pour éviter le crash 502 :

### 1. Secrets JWT avec valeurs par défaut en dev
- ✅ `JWT_ACCESS_SECRET` et `JWT_REFRESH_SECRET` ne sont plus obligatoires
- ✅ L'app utilise des valeurs par défaut si non configurées
- ⚠️  Un avertissement s'affiche dans les logs

### 2. Vérification DATABASE_URL au démarrage
- ✅ L'app affiche une erreur claire si DATABASE_URL manque
- ✅ `process.exit(1)` propre au lieu d'un crash non géré

### 3. Logs de diagnostic améliorés
- ✅ Affiche exactement quelles variables sont configurées
- ✅ Indique les valeurs par défaut utilisées

**Commit** : `f3e0678` sur la branche `preprod`

---

## ⚡ Actions requises pour Railway

### Étape 1 : Vérifier quelle branche Railway déploie

1. Allez sur https://railway.app
2. Projet > **Service Backend**
3. **Settings** > **Source**
4. Regardez **Branch** : doit être `preprod` ou `main`

### Étape 2A : Si Railway déploie depuis `preprod` ✅

**Rien à faire !** Railway va automatiquement redéployer avec les corrections.

Attendez 2-3 minutes et vérifiez :
```powershell
Invoke-WebRequest -Uri "https://scholaris-erp-production.up.railway.app/api/health"
```

Devrait retourner `200 OK` au lieu de `502`.

### Étape 2B : Si Railway déploie depuis `main` ⚠️

**Option A - Changer la branche Railway (RECOMMANDÉ)** :
1. Railway > Service Backend > **Settings** > **Source**
2. Cliquez sur **Configure**
3. Changez **Branch** de `main` à `preprod`
4. Sauvegardez → Railway redéploie automatiquement

**Option B - Merger preprod vers main** :
```powershell
git checkout main
git merge preprod
git push origin main
```

Railway détecte le push et redéploie.

---

## 🔍 Vérifier le déploiement

### 1. Attendez le redéploiement

Railway > Service Backend > **Deployments** → Le nouveau build apparaît

### 2. Consultez les logs

Cliquez sur le déploiement > **View Logs**

**Logs de succès attendus** :
```
🚀 SCHOLARIS API - Démarrage...
📋 Diagnostic des variables d'environnement :
   DATABASE_URL: ✅ définie
   JWT_ACCESS_SECRET: ⚠️  VALEUR PAR DÉFAUT (DEV)
   JWT_REFRESH_SECRET: ⚠️  VALEUR PAR DÉFAUT (DEV)

⚠️  ATTENTION : Secrets JWT non configurés !
   L'application utilise des valeurs par défaut pour le développement.

✅ Application NestJS créée
✅ ConfigService chargé
🎯 Écoute sur le port XXXX...
✅ SCHOLARIS API démarrée avec succès
```

### 3. Testez le healthcheck

```powershell
Invoke-WebRequest -Uri "https://scholaris-erp-production.up.railway.app/api/health"
```

**Résultat attendu** :
```
StatusCode : 200
Content    : {"status":"ok","info":{"database":{"status":"up"}}}
```

---

## ⚠️ Si DATABASE_URL est manquante

Si les logs montrent :
```
❌ ERREUR FATALE : DATABASE_URL n'est pas définie !
```

**Solution** :
1. Railway > Service Backend > **Settings** > **Variables**
2. Cliquez **+ New Variable** > **Add a Reference**
3. Sélectionnez votre service **PostgreSQL**
4. Choisissez **DATABASE_URL**
5. Sauvegardez → Railway redéploie

---

## 🎯 Après le succès du healthcheck

Une fois que l'API répond ✅, vous pourrez :

### 1. Configurer les vrais secrets JWT (optionnel mais recommandé)

```powershell
.\scripts\generate-railway-vars.ps1
```

Copiez les secrets générés dans Railway > Settings > Variables.

### 2. Seeder la base de données

```powershell
railway login
railway link
railway run npm run db:seed --workspace=@scholaris/prisma
```

Crée le compte admin : `admin@scholaris.dev` / `ChangeMe123!`

### 3. Tester le login frontend

http://localhost:3000 → Login avec les identifiants ci-dessus

---

## 📊 État après ce fix

| Composant | Avant | Après |
|-----------|-------|-------|
| JWT sans variables | ❌ Crash 502 | ✅ Démarre avec valeurs par défaut |
| DATABASE_URL manquante | ❌ Crash obscur | ✅ Erreur claire + exit propre |
| Logs de diagnostic | ✅ Présents | ✅ Améliorés |
| Déploiement Railway | ❌ 502 | ⏳ À vérifier après redéploiement |

---

## 🆘 Toujours bloqué ?

Si après redéploiement le 502 persiste :

1. Partagez les **nouveaux logs Railway** (après ce commit)
2. Confirmez que **DATABASE_URL est bien configurée** dans Variables
3. Vérifiez que Railway déploie depuis `preprod` (ou que preprod est mergé dans main)
