# 🔧 SCRIPTS UTILES CRÉÉS

Ce document liste tous les scripts utilitaires créés pour la gestion de la base de données.

## 📝 Scripts disponibles

### 1. `create-roles.ts` - Création des rôles métier

Crée les 7 rôles métier avec leurs permissions assignées :
- Directeur (37 permissions)
- Censeur (18 permissions)
- Enseignant (15 permissions)
- Intendant (13 permissions)
- Secrétaire (21 permissions)
- Parent (5 permissions)
- Élève (3 permissions)

**Usage :**
```bash
npx tsx create-roles.ts
```

**Résultat :**
```
🎉 7 rôles créés avec succès !
```

---

### 2. `assign-roles.ts` - Assignment des rôles aux utilisateurs

Assigne les rôles métier aux 5 utilisateurs de test.

**Usage :**
```bash
npx tsx assign-roles.ts
```

**Résultat :**
```
✅ Tous les rôles ont été assignés !
directeur@demo.scholaris.cm: Directeur (37 perms)
censeur@demo.scholaris.cm: Censeur (18 perms)
...
```

---

### 3. `check-roles.ts` - Vérification des rôles

Affiche la liste de tous les rôles et leur nombre de permissions.

**Usage :**
```bash
npx tsx check-roles.ts
```

**Résultat :**
```
📋 Rôles dans la base de données:
  - SUPER_ADMIN: 79 permissions
  - Directeur: 37 permissions
  - Censeur: 18 permissions
  ...
```

---

### 4. `check-user-roles.ts` - Vérification des utilisateurs

Affiche les rôles assignés aux utilisateurs de test.

**Usage :**
```bash
npx tsx check-user-roles.ts
```

**Résultat :**
```
👤 Utilisateurs et leurs rôles:
  directeur@demo.scholaris.cm: Directeur
  censeur@demo.scholaris.cm: Censeur
  ...
```

---

### 5. `populate-test-data.ts` - Population de données de test

Crée la structure pédagogique complète et les utilisateurs de test.

**Usage :**
```bash
npx tsx populate-test-data.ts
```

**Ce qu'il crée :**
- 3 cycles (Primaire, Secondaire, Supérieur)
- 3 programmes (Série A, C, D)
- 3 niveaux (6ème, 5ème, Terminale)
- 3 salles (A101, A102, LAB-PHY)
- 3 classes (6ème A, 6ème B, Terminale C1)
- 5 matières (Math, Français, Anglais, Physique, SVT)
- 1 année académique 2026-2027
- 2 périodes (Séquence 1 & 2)
- 6 utilisateurs (admin, directeur, censeur, enseignant, intendant, secrétaire)

**⚠️ Note** : Ce script ne crée PAS les rôles métier. Exécutez `create-roles.ts` puis `assign-roles.ts` après.

---

## 🔄 Workflow complet

Pour initialiser une base de données vide :

```bash
# 1. Exécuter le seed (crée le tenant et SUPER_ADMIN)
cd packages/prisma
npm run db:seed

# 2. Revenir à la racine
cd ../..

# 3. Créer les rôles métier
npx tsx create-roles.ts

# 4. Peupler les données de test
npx tsx populate-test-data.ts

# 5. Assigner les rôles aux utilisateurs
npx tsx assign-roles.ts

# 6. Vérifier
npx tsx check-roles.ts
npx tsx check-user-roles.ts
```

---

## 📊 État actuel de la base

✅ **Rôles créés** : 8 rôles (SUPER_ADMIN + 7 métier)
✅ **Utilisateurs créés** : 6 comptes
✅ **Rôles assignés** : 5 utilisateurs ont leur rôle
✅ **Structure pédagogique** : Cycles, programmes, niveaux, classes, salles, matières
✅ **Année académique** : 2026-2027 (ACTIVE)
✅ **Périodes** : Séquence 1 (OPEN), Séquence 2 (CLOSED)

---

## 🐛 Dépannage

### Problème : "0 permission(s) résolue(s)"
**Solution** : Exécutez `assign-roles.ts`

### Problème : "Rôle XXXX introuvable"
**Solution** : Exécutez `create-roles.ts`

### Problème : "Utilisateur introuvable"
**Solution** : Exécutez `populate-test-data.ts`

### Problème : Erreurs de champs Prisma
**Solution** : Vérifiez que les enums correspondent au schéma (SALLE_CLASSE pas "classroom", ACTIVE pas "active", etc.)

---

## 🔐 Variable d'environnement DATABASE_URL

Pour tous les scripts :

```bash
$env:DATABASE_URL="postgresql://postgres:vEYAbrLvYJvXHVmLWXkteXYZfKUEEnmg@tokaido.proxy.rlwy.net:58913/railway?connection_limit=20&pool_timeout=20"
```

Ou ajoutez-la dans `.env` à la racine du projet.
