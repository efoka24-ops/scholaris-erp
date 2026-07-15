# 🧪 TESTS D'INTÉGRATION ET DE SÉCURITÉ - SCHOLARIS ERP

Ce document décrit la suite complète de tests d'intégration, de charge et de sécurité pour SCHOLARIS ERP v2.0.

---

## 📋 Vue d'ensemble

### Tests créés

| Type | Fichier | Description | Outil |
|------|---------|-------------|-------|
| **Intégration E2E** | `apps/web/e2e/integration/full-academic-cycle.spec.ts` | Parcours complet inscription → notes → bulletin (30 élèves) | Playwright |
| **Intégration Financière** | `apps/web/e2e/integration/financial-cycle.spec.ts` | Parcours financier : factures, paiements, relances | Playwright |
| **Système LMD** | `apps/web/e2e/integration/lmd-system.spec.ts` | UE/EC, crédits ECTS, compensation, GPA | Playwright |
| **Tests de charge** | `load-tests.js` | 500 utilisateurs simultanés, génération bulletins | k6 |
| **Tests de sécurité** | `apps/web/e2e/security/security-tests.spec.ts` | SQL injection, XSS, IDOR, rate limiting, JWT | Playwright |

---

## 🚀 Prérequis

### 1. Installation des dépendances

```powershell
# Dépendances principales
npm install

# Playwright (déjà installé)
npx playwright --version

# k6 (tests de charge)
# Télécharger depuis : https://k6.io/docs/get-started/installation/
# Ou avec Chocolatey :
choco install k6
```

### 2. Configuration de l'environnement

Créer `.env.test` à la racine :

```env
# API Backend
NEST_API_URL=https://scholaris-erp-production.up.railway.app/api

# Frontend
NEXT_PUBLIC_URL=http://localhost:3000

# Test Database (optionnel, pour tests isolés)
TEST_DATABASE_URL=postgresql://user:pass@localhost:5432/scholaris_test

# Identifiants de test
TEST_ADMIN_EMAIL=admin@scholaris.dev
TEST_ADMIN_PASSWORD=ChangeMe123!
```

---

## 🎯 Exécution des tests

### Tests d'intégration Playwright

#### Tous les tests E2E
```powershell
npm run test:e2e --workspace=@scholaris/web
```

#### Test spécifique
```powershell
# Parcours académique complet
npx playwright test apps/web/e2e/integration/full-academic-cycle.spec.ts

# Parcours financier
npx playwright test apps/web/e2e/integration/financial-cycle.spec.ts

# Système LMD
npx playwright test apps/web/e2e/integration/lmd-system.spec.ts
```

#### Mode debug (avec UI)
```powershell
npx playwright test --ui
```

#### Générer rapport HTML
```powershell
npx playwright test --reporter=html
npx playwright show-report
```

---

### Tests de sécurité

```powershell
# Tous les tests de sécurité
npx playwright test apps/web/e2e/security/security-tests.spec.ts

# Test spécifique
npx playwright test apps/web/e2e/security/security-tests.spec.ts -g "injection SQL"
npx playwright test apps/web/e2e/security/security-tests.spec.ts -g "IDOR"
npx playwright test apps/web/e2e/security/security-tests.spec.ts -g "Rate limiting"
```

---

### Tests de charge (k6)

#### Test de charge standard (500 utilisateurs)
```powershell
k6 run load-tests.js
```

#### Test de charge personnalisé
```powershell
# Avec variables d'environnement
k6 run --env API_URL=https://scholaris-erp-production.up.railway.app/api `
       --env CLASSROOM_ID=your-classroom-id `
       --env SUBJECT_1_ID=math-id `
       load-tests.js
```

#### Test de charge progressif (warmup)
```powershell
# Montée progressive de 0 à 200 utilisateurs
k6 run --vus 200 --duration 5m load-tests.js
```

#### Avec métriques Cloud (k6 Cloud)
```powershell
k6 login cloud --token YOUR_TOKEN
k6 cloud load-tests.js
```

---

## 📊 Critères de validation

### Tests d'intégration E2E

#### Parcours académique (full-academic-cycle.spec.ts)
- ✅ Création de 30 élèves avec parents
- ✅ Inscription dans la classe
- ✅ Saisie de 90 notes (30 élèves × 3 matières) pour 2 séquences
- ✅ Calcul des moyennes trimestrielles
- ✅ Génération de 30 bulletins PDF
- ✅ Envoi des bulletins aux 30 parents par email
- ✅ Classement correct (tri par moyenne décroissante)

#### Parcours financier (financial-cycle.spec.ts)
- ✅ Facture auto-générée à l'inscription (180 000 FCFA)
- ✅ Paiement partiel en espèces (50 000 FCFA) → solde = 130 000 FCFA
- ✅ Paiement Mobile Money (100 000 FCFA) → callback → solde = 30 000 FCFA
- ✅ Relance SMS automatique pour solde impayé
- ✅ Reçu PDF généré avec numéro unique
- ✅ Paiement final → facture soldée (status = PAID)

#### Système LMD (lmd-system.spec.ts)
- ✅ Création UE avec crédits ECTS (UE1: 10 crédits, UE2: 8 crédits)
- ✅ Création EC avec coefficients
- ✅ Saisie notes CC (40%) + Examen (60%)
- ✅ Calcul moyennes EC : (CC × 0.4) + (Exam × 0.6)
- ✅ Compensation : EC < 10 compensée par UE moyenne ≥ 10
- ✅ Capitalisation : crédits validés même avec EC échouée si UE validée
- ✅ Rattrapage : meilleure note conservée
- ✅ GPA calculé sur échelle 4.0
- ✅ Relevé de notes / Transcript généré en PDF

---

### Tests de charge

#### Objectifs
- 🎯 **500 utilisateurs simultanés** (pic de charge)
- 🎯 **95% des requêtes < 200ms**
- 🎯 **Taux d'erreur < 1%**
- 🎯 **Génération de 60 bulletins simultanés < 5s**

#### Scénarios de charge
| Scénario | Proportion | Opération |
|----------|-----------|-----------|
| Saisie de notes | 40% | POST /grades (concurrence) |
| Consultation | 30% | GET /grades/student/:id |
| Génération bulletins | 20% | POST /reports/bulletins/batch |
| Dashboard | 10% | GET /finance/dashboard, /grades/progress |

#### Résultats attendus
```
checks.........................: 99.00% ✓ 47500  ✗ 500
http_req_duration..............: avg=120ms min=10ms med=95ms max=500ms p(95)=180ms
http_req_failed................: 0.50%  ✓ 250    ✗ 49750
```

---

### Tests de sécurité

#### Protection SQL Injection
- ✅ Requêtes avec `' OR '1'='1` retournent 200 (vide)
- ✅ Aucune erreur SQL exposée dans la réponse
- ✅ Login avec injection SQL retourne 401 (pas de bypass)

#### Protection XSS
- ✅ Scripts `<script>alert()</script>` sanitisés ou rejetés
- ✅ Balises `<img onerror=>` échappées
- ✅ `javascript:` URLs bloquées

#### Protection IDOR
- ✅ Parent ne peut pas accéder aux notes d'un autre enfant (403)
- ✅ Enseignant ne peut pas modifier notes d'une classe non assignée (403)
- ✅ Élève ne peut pas consulter données financières d'un autre (403)

#### Rate Limiting
- ✅ 429 Too Many Requests après 100 requêtes/minute
- ✅ Header `Retry-After` présent
- ✅ Rate limit par utilisateur (pas global)

#### Gestion JWT
- ✅ JWT expiré → 401 Unauthorized
- ✅ Refresh token → nouveau access token
- ✅ Refresh token invalide → 401
- ✅ Refresh token utilisé 2× → 401 (rotation)

#### RBAC (Role-Based Access Control)
- ✅ Enseignant ne peut pas accéder aux routes admin (403)
- ✅ Parent accède uniquement aux données de ses enfants

#### Protection données sensibles
- ✅ Mot de passe jamais renvoyé dans les réponses
- ✅ Numéros bancaires masqués (ex: `****1234`)

---

## 🐛 Debugging

### Playwright en mode debug

```powershell
# Pause sur échec
npx playwright test --debug

# Ouvrir Inspector
npx playwright test --headed --debug

# Trace viewer (après échec)
npx playwright show-trace trace.zip
```

### k6 en mode verbose

```powershell
k6 run --verbose load-tests.js

# Exporter métriques en JSON
k6 run --out json=results.json load-tests.js
```

---

## 📈 Intégration CI/CD

### GitHub Actions

Créer `.github/workflows/e2e-tests.yml` :

```yaml
name: E2E and Security Tests

on:
  push:
    branches: [main, preprod]
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-node@v3
        with:
          node-version: 20
      
      - name: Install dependencies
        run: npm ci
      
      - name: Install Playwright browsers
        run: npx playwright install --with-deps chromium
      
      - name: Run E2E tests
        run: npm run test:e2e --workspace=@scholaris/web
        env:
          NEST_API_URL: ${{ secrets.API_URL }}
      
      - name: Run security tests
        run: npx playwright test apps/web/e2e/security/
      
      - name: Upload test results
        if: always()
        uses: actions/upload-artifact@v3
        with:
          name: playwright-report
          path: playwright-report/
```

---

## 📝 Maintenance

### Mise à jour des données de test

Modifier les fixtures dans :
- `apps/api/test/fixtures/` (données seeding)
- Variables d'environnement dans `load-tests.js`

### Ajout de nouveaux tests

1. Créer le fichier dans `apps/web/e2e/`
2. Suivre la structure existante
3. Utiliser `@faker-js/faker` pour données aléatoires
4. Ajouter assertions avec `expect()`

### Revue régulière

- ⏰ Exécuter tests de charge **hebdomadairement**
- ⏰ Exécuter tests de sécurité **avant chaque release**
- ⏰ Mettre à jour Playwright et k6 **mensuellement**

---

## 🆘 Support

En cas de problème :

1. **Vérifier les logs** : `playwright-report/index.html`
2. **Consulter la doc** : https://playwright.dev/
3. **k6 documentation** : https://k6.io/docs/
4. **Issues GitHub** : Créer un ticket avec les logs complets

---

## ✅ Checklist avant production

- [ ] Tous les tests E2E passent (200+ assertions)
- [ ] Tests de sécurité passent à 100%
- [ ] Tests de charge : p95 < 200ms, erreurs < 1%
- [ ] Rate limiting activé en production
- [ ] JWT secrets configurés (pas de valeurs par défaut)
- [ ] DATABASE_URL pointe vers production
- [ ] Logs sensibles désactivés (pas de passwords)
- [ ] CORS configuré correctement
- [ ] HTTPS activé (certificat valide)
- [ ] Monitoring configuré (Sentry, Datadog, etc.)

---

**Suite de tests créée le** : 15 juillet 2026
**Version SCHOLARIS** : 2.0.0
**Auteur** : GitHub Copilot
