# 🚨 Backend Railway ne répond pas (502)

## Problème détecté

Votre backend à l'adresse `https://scholaris-erp-production.up.railway.app/api/health` retourne :

```json
{
  "status": "error",
  "code": 502,
  "message": "Application failed to respond"
}
```

Cela signifie que le service Railway **ne tourne pas** ou a **crashé**.

---

## ✅ Solutions à essayer

### Solution 1 : Vérifier l'état du déploiement

1. Allez sur https://railway.app
2. Ouvrez votre projet > **Service Backend**
3. Onglet **Deployments**
4. Vérifiez le dernier déploiement :
   - ✅ **Active** avec healthcheck passé → Service OK (ne devrait pas être le cas vu l'erreur)
   - ❌ **Failed** ou **Crashed** → Le service a crashé
   - 🔄 **Building** → Le build est en cours

### Solution 2 : Consulter les logs

Dans **Railway > Service Backend > Deployments > Dernier déploiement > View Logs**, cherchez :

**Erreurs à surveiller** :
```
❌ ERREUR FATALE AU DÉMARRAGE
Configuration key "JWT_ACCESS_SECRET" does not exist
Environment variable not found: DATABASE_URL
Cannot find module bcrypt
```

Si vous voyez une de ces erreurs, consultez les guides de dépannage :
- [RAILWAY_JWT_DEBUG.md](RAILWAY_JWT_DEBUG.md) - Variables JWT manquantes
- [RAILWAY_FIX_NOW.md](RAILWAY_FIX_NOW.md) - Guide d'urgence
- [RAILWAY_CHECKLIST.md](RAILWAY_CHECKLIST.md) - Checklist complète

### Solution 3 : Vérifier les variables d'environnement

Dans **Railway > Service Backend > Settings > Variables**, vérifiez que vous avez **AU MINIMUM** :

- `DATABASE_URL` → Référence vers PostgreSQL (`${{Postgres.DATABASE_URL}}`)
- `JWT_ACCESS_SECRET` → Secret aléatoire de 32+ caractères
- `JWT_REFRESH_SECRET` → Autre secret différent
- `CORS_ORIGIN` → `*`

Si une variable manque, ajoutez-la et Railway redéploiera automatiquement.

### Solution 4 : Redéployer manuellement

Si tout semble correct mais le service ne répond pas :

1. **Railway > Service Backend > Deployments**
2. Cliquez sur **⋮** (trois points) du dernier déploiement
3. Sélectionnez **Redeploy**
4. Attendez ~2 minutes que le déploiement se termine
5. Vérifiez le healthcheck : doit passer ✅

---

## 🔍 Diagnostic rapide

### Test 1 : Healthcheck Railway

```powershell
Invoke-WebRequest -Uri "https://scholaris-erp-production.up.railway.app/api/health" -UseBasicParsing
```

**Résultat attendu** :
```
StatusCode: 200
Content: {"status":"ok","info":{"database":{"status":"up"}}}
```

**Résultat actuel** :
```
StatusCode: 502
Content: {"status":"error","code":502,"message":"Application failed to respond"}
```

### Test 2 : Vérifier que le domaine est bien configuré

Dans **Railway > Service Backend > Settings > Public Networking** :
- Le domaine doit être : `scholaris-erp-production.up.railway.app`
- Si vide ou différent, générez un nouveau domaine

---

## 📊 État du système

| Composant | État | Action |
|-----------|------|--------|
| **Backend Railway** | ❌ Ne répond pas (502) | Consulter les logs Railway |
| **Frontend Next.js** | ✅ Configuré | Pointe vers `scholaris-erp-production.up.railway.app` |
| **Base de données** | ❓ Inconnue | Vérifier via Prisma Studio si accessible |
| **Variables ENV** | ❓ À vérifier | Railway > Settings > Variables |

---

## 🎯 Prochaine étape

**1. Consultez les logs Railway** pour voir pourquoi le service ne démarre pas :

```
Railway > Service Backend > Deployments > View Logs
```

**2. Partagez les erreurs que vous voyez** dans les logs pour que je puisse vous aider à les corriger.

**3. Une fois le service actif**, on pourra :
- Seeder la base de données
- Tester le login avec `admin@scholaris.dev`

---

## 💡 Rappel des commits récents

Les derniers déploiements devraient inclure :
- ✅ Bcrypt rebuild pour Linux (`5d03579`)
- ✅ Diagnostic des variables d'env (`519aa4b`)
- ✅ ConfigModule charge .env depuis la racine (`aa6e031`)

Si votre déploiement Railway n'a pas ces commits, faites :
```powershell
git push origin preprod:main  # Si Railway déploie depuis main
```
