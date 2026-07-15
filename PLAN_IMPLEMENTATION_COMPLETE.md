# 📋 PLAN D'IMPLÉMENTATION COMPLÈTE - SCHOLARIS ERP

**Date** : 2026-07-15  
**Objectif** : Implémenter toutes les fonctionnalités métier actuellement en placeholder  
**Estimation totale** : **40-60 heures** de développement  

---

## 🎯 MODULES À IMPLÉMENTER (17 modules)

### ✅ DÉJÀ FONCTIONNELS (6 modules)
1. ✅ **Authentification** - Login/logout
2. ✅ **Dashboard** - Widgets basiques
3. ✅ **Structure pédagogique** - CRUD cycles/programmes/niveaux/classes
4. ✅ **Matières** - CRUD sujets
5. ✅ **Élèves** - Liste et détails
6. ✅ **Années académiques** - CRUD

### ❌ À IMPLÉMENTER (17 modules)

#### **PRIORITÉ 1 - CRITIQUE (6-8h)**
7. ⏳ **Utilisateurs & Rôles** (2h)
   - CRUD utilisateurs
   - CRUD rôles
   - Assignment permissions
   - Activation/suspension comptes
   
8. ⏳ **Établissement** (1h)
   - Configuration générale
   - Paramètres établissement
   - Logo, coordonnées
   
9. ⏳ **Moteur de calcul** (2h)
   - Configuration par établissement
   - Types d'évaluation
   - Pondérations, seuils
   - Règles d'absence
   - Paramètres LMD

10. ⏳ **Saisie des notes** (2h)
    - Interface saisie notes
    - Formulaire par classe/matière
    - Validation notes
    - Historique modifications

11. ⏳ **Bulletins** (1h)
    - Génération PDF
    - Téléchargement batch
    - Envoi email parents

#### **PRIORITÉ 2 - HAUTE (8-10h)**
12. ⏳ **Inscriptions** (2h)
    - Liste complète avec filtres
    - Statuts (actif, suspendu, annulé)
    - Types (nouveau, redoublant, transfert)

13. ⏳ **Présences** (2h)
    - Appel de classe
    - Marquer présences/absences/retards
    - Justifications
    - Calcul taux présence

14. ⏳ **Discipline** (1.5h)
    - CRUD incidents
    - Types incidents
    - Sanctions
    - Historique élève

15. ⏳ **Santé scolaire** (1.5h)
    - Dossiers médicaux
    - Allergies, vaccinations
    - Traitements
    - Interventions

16. ⏳ **Templates Communication** (1h)
    - CRUD templates
    - Variables dynamiques
    - Aperçu

#### **PRIORITÉ 3 - MOYENNE (10-12h)**
17. ⏳ **RH & Paie** (3h)
    - CRUD employés
    - Génération bulletins paie
    - Gestion congés
    - Workflow approbation

18. ⏳ **Patrimoine** (2h)
    - Inventaire (CRUD biens)
    - Catégories
    - Maintenance
    - Historique réparations

19. ⏳ **Clubs & Activités** (2h)
    - CRUD clubs
    - CRUD événements
    - Inscriptions membres
    - Calendrier

20. ⏳ **Bibliothèque** (2h)
    - CRUD livres
    - Recherche catalogue
    - CRUD emprunts
    - Alertes retards

21. ⏳ **Transport** (1.5h)
    - CRUD circuits
    - CRUD véhicules
    - Abonnements élèves

22. ⏳ **Cantine & Internat** (1.5h)
    - CRUD menus
    - Abonnements cantine
    - Attribution dortoirs

#### **PRIORITÉ 4 - BASSE (4-6h)**
23. ⏳ **Emplois du temps** (2h)
    - Grille horaire
    - Gestion créneaux
    - Détection conflits

24. ⏳ **Structure étendue** (2h)
    - Niveaux primaire (SIL → CM2)
    - Niveaux collège (6ème → 3ème)
    - Niveaux lycée (2nde → Tle)
    - Niveaux université (L1 → M2)

---

## 🔧 PRÉREQUIS TECHNIQUE

### ❌ **BLOQUANT : Backend ne démarre pas (95 erreurs TypeScript)**

**Avant toute implémentation, il faut corriger le backend !**

#### Erreurs principales :
1. Module Users : relations `userRoles` manquantes
2. Modules 9-18 : DTOs incompatibles avec Prisma
3. Relations manquantes (club.members, etc.)
4. Conversions enum DayOfWeek

**Temps estimation** : 2-4h de corrections

---

## 📅 PLANNING PROPOSÉ

### **PHASE 0 : CORRECTION BACKEND (Jour 1 matin - 4h)**
- ✅ Corriger 95 erreurs TypeScript
- ✅ Démarrer backend local
- ✅ Tester authentification
- ✅ Valider tous les endpoints existants

### **PHASE 1 : PRIORITÉ 1 (Jour 1 après-midi + Jour 2 - 8h)**
- Module Utilisateurs & Rôles (2h)
- Module Établissement (1h)
- Module Moteur de calcul (2h)
- Module Saisie notes (2h)
- Module Bulletins (1h)

### **PHASE 2 : PRIORITÉ 2 (Jour 3 + Jour 4 matin - 10h)**
- Module Inscriptions (2h)
- Module Présences (2h)
- Module Discipline (1.5h)
- Module Santé (1.5h)
- Module Templates (1h)

### **PHASE 3 : PRIORITÉ 3 (Jour 4 après-midi + Jour 5 - 12h)**
- Module RH & Paie (3h)
- Module Patrimoine (2h)
- Module Clubs (2h)
- Module Bibliothèque (2h)
- Module Transport (1.5h)
- Module Cantine (1.5h)

### **PHASE 4 : PRIORITÉ 4 (Jour 6 - 6h)**
- Module Emplois du temps (2h)
- Structure étendue (2h)
- Tests finaux (2h)

**TOTAL : ~40h (1 semaine complète)**

---

## 🚀 APPROCHE RECOMMANDÉE

### **Option A : Tout en une semaine (recommandé)**
- 8h/jour × 5 jours
- Développement intensif
- Livraison complète fin semaine

### **Option B : Par sprints de 2h**
- 1 module à la fois
- Validation après chaque module
- Plus flexible mais plus long (2-3 semaines)

### **Option C : Modules critiques uniquement**
- Focus PRIORITÉ 1 seulement (8h)
- MVP fonctionnel rapide
- Reste à implémenter plus tard

---

## 📊 DÉTAIL PAR MODULE

### 1. UTILISATEURS & RÔLES (2h)

**Backend** :
- ✅ Endpoints déjà créés (`/users`, `/roles`)
- ⏳ À corriger : relations userRoles

**Frontend à créer** :
```typescript
// apps/web/src/app/(dashboard)/settings/users/page.tsx
- DataTable utilisateurs
- Formulaire création/édition
- Activation/désactivation
- Reset mot de passe

// apps/web/src/app/(dashboard)/settings/roles/page.tsx
- Liste rôles
- Formulaire rôle
- Matrice permissions
- Assignment utilisateurs
```

### 2. ÉTABLISSEMENT (1h)

**Backend** :
- ✅ Endpoint `/tenants/:id` existe
- ⏳ Ajouter endpoint `/tenants/:id/config`

**Frontend à créer** :
```typescript
// apps/web/src/app/(dashboard)/settings/establishment/page.tsx
- Formulaire informations
- Upload logo
- Configuration générale
- Paramètres métier
```

### 3. MOTEUR DE CALCUL (2h)

**Backend à créer** :
```typescript
// apps/api/src/modules/settings/settings.module.ts
POST /settings/calculation-engine
GET  /settings/calculation-engine
PUT  /settings/calculation-engine/:id
```

**Frontend à créer** :
```typescript
// apps/web/src/app/(dashboard)/settings/calculation-engine/page.tsx
- Types évaluation (continue, séquentielle, LMD)
- Pondérations matières
- Seuils mentions
- Règles arrondi
- Règles absence
```

### 4. SAISIE NOTES (2h)

**Backend** :
- ✅ CRUD grades existe (`/grades`)
- ⏳ Ajouter endpoint batch `/grades/batch`

**Frontend à créer** :
```typescript
// apps/web/src/app/(dashboard)/grades/entry/page.tsx
- Sélection classe + matière + période
- Grille saisie notes (DataTable éditable)
- Validation notes (min/max)
- Sauvegarde batch
- Historique modifications
```

### 5. BULLETINS (1h)

**Backend à créer** :
```typescript
POST /bulletins/generate  // Génération PDF
POST /bulletins/send      // Envoi email
GET  /bulletins/:id/pdf   // Téléchargement
```

**Frontend à créer** :
```typescript
// apps/web/src/app/(dashboard)/bulletins/page.tsx
- Sélection période + classe
- Aperçu bulletin
- Génération PDF
- Téléchargement batch
- Envoi email parents
```

---

## 🎯 PROPOSITION IMMÉDIATE

**Voulez-vous que je commence par :**

### **A) Corriger le backend d'abord** (4h)
→ Permet de tester toutes les fonctionnalités ensuite

### **B) Implémenter un module prioritaire** (2h)
→ Par exemple : Utilisateurs & Rôles
→ Backend en mode "mock" temporaire

### **C) Créer un roadmap détaillé** (30min)
→ Planning précis avec tickets
→ Puis démarrer Phase 0

**Quelle option préférez-vous ?**

---

## 💡 RECOMMANDATION

⭐ **Je recommande l'Option A** : Corriger le backend en priorité

**Pourquoi ?**
- Aucune fonctionnalité ne peut être testée sans backend
- 95 erreurs TypeScript = backend ne démarre pas
- Une fois corrigé, tout le reste devient facile
- Gain de temps sur le long terme

**Plan :**
1. **Maintenant → 4h** : Corriger backend (95 erreurs)
2. **Demain** : Implémenter modules prioritaires
3. **Cette semaine** : Compléter tous les modules

**Êtes-vous d'accord pour commencer par la correction du backend ?** 🔧

Si oui, je commence immédiatement les corrections !
