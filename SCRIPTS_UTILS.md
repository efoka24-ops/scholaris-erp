# 🔧 SCRIPTS UTILES CRÉÉS

Ce document liste tous les scripts utilitaires créés pour la gestion de la base de données.

## 📝 Scripts disponibles

### 1. et 2. `create-roles.ts` / `assign-roles.ts` — **SUPPRIMÉS**

⚠️ Ces deux scripts autonomes ont été supprimés (fix-rbac-roles). La création
des 7 rôles métier (Directeur, Censeur, Enseignant, Intendant, Secrétaire,
Parent, Élève) est désormais intégrée directement dans `packages/prisma/src/seed.ts`
(fonction `main()`, tableau `BUSINESS_ROLES`). Un seul `npx prisma db seed`
crée maintenant le tenant, le SUPER_ADMIN et les 7 rôles métier avec leurs
permissions à jour — plus besoin d'exécuter un script séparé, ce qui éliminait
le risque de dérive entre le seed et les rôles.

L'assignation d'un rôle métier à un utilisateur précis reste à faire via
`PUT /api/users/:id/roles` (permission `users:assign-roles`, portée par le
rôle Directeur) ou directement en base pour les comptes de démonstration.

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

**⚠️ Note** : Ce script ne crée PAS les rôles métier — ils sont créés par le seed (voir ci-dessus).

---

## 🔄 Workflow complet

Pour initialiser une base de données vide :

```bash
# 1. Exécuter le seed (crée le tenant, SUPER_ADMIN et les 7 rôles métier)
cd packages/prisma
npm run db:seed

# 2. Revenir à la racine
cd ../..

# 3. Peupler les données de test
npx tsx populate-test-data.ts

# 4. Vérifier
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
**Solution** : Vérifiez qu'un rôle est assigné à l'utilisateur (`PUT /api/users/:id/roles`)

### Problème : "Rôle XXXX introuvable"
**Solution** : Relancez `npx prisma db seed` (les 7 rôles métier sont créés par le seed)

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
