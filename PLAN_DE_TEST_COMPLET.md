# PLAN DE TEST COMPLET - SCHOLARIS ERP v2.0

**Date**: 2026-07-15  
**Environnement**: Development + Production (Railway)  
**Compte Admin**: admin@scholaris.dev / ChangeMe123!

---

## 🎯 OBJECTIFS

1. **Tester TOUTES les fonctionnalités du profil SUPER_ADMIN**
2. **Identifier et tester chaque profil utilisateur** (Enseignant, Parent, Élève, Censeur, Intendant, etc.)
3. **Valider les permissions RBAC** pour chaque profil
4. **Produire un guide d'utilisation** complet par profil

---

## 📋 MODULES IMPLÉMENTÉS (d'après le schéma Prisma et les dossiers)

### ✅ Module 1 - Authentification et Configuration
- [x] Authentification (Login, JWT, MFA, Refresh)
- [x] Gestion des utilisateurs (CRUD)
- [x] Gestion des rôles et permissions (RBAC)
- [x] Configuration de l'établissement (Tenant)
- [x] Années académiques et périodes
- [x] Journal d'audit

### ✅ Module 2 - Structure Pédagogique
- [x] Cycles, Programmes (Filières), Départements
- [x] Niveaux, Classes, Groupes
- [x] Salles

### ✅ Module 3 - Matières, UE et EC
- [x] Matières (Subject)
- [x] Unités d'Enseignement (UE)
- [x] Éléments Constitutifs (EC)
- [x] Assignation enseignants

### ✅ Module 4 - Inscriptions et Admissions
- [x] Élèves (Student)
- [x] Parents
- [x] Inscriptions (Enrollment)
- [x] Candidatures (AdmissionApplication)
- [x] Matricules automatiques

### ✅ Module 5 - Saisie des Notes et Moteur de Calcul
- [x] Notes (Grade)
- [x] Calculs de moyennes (GradeCalculation)
- [x] Résultats par période (PeriodResult)
- [x] Résultats annuels (AnnualResult)

### ✅ Module 6 - Bulletins et Diplômes
- [ ] À vérifier si implémenté (pas de model Bulletin dans le schéma visible)

### ✅ Module 7 - Gestion Financière
- [x] Structures de frais (FeeStructure)
- [x] Échéanciers (FeeInstallment)
- [x] Factures (Invoice)
- [x] Paiements (Payment)
- [x] Remises et bourses (Discount)

### ✅ Module 8 - Communication Multicanal
- [x] Templates de communication
- [x] Messages (CommunicationMessage)
- [x] Messages internes (InternalMessage)
- [x] Préférences de canal

### ⏳ Modules 9-18 (à vérifier)
- [ ] Emplois du temps
- [ ] Présences
- [ ] Discipline
- [ ] Santé scolaire
- [ ] Vie scolaire (clubs, événements)
- [ ] Bibliothèque
- [ ] Transport
- [ ] Cantine et internat
- [ ] Patrimoine
- [ ] RH et paie

---

## 👥 PROFILS UTILISATEURS IDENTIFIÉS

D'après le schéma Prisma, voici les rôles à tester :

### 1. SUPER_ADMIN
**Permissions**: Accès total (77 permissions selon le seed)
**Responsabilités**:
- Configuration de l'établissement
- Gestion des utilisateurs et rôles
- Configuration du moteur de calcul
- Années académiques et périodes
- Toutes les opérations CRUD

### 2. DIRECTEUR / DIRECTION
**Permissions attendues**:
- Publication des résultats
- Validation des décisions importantes
- Accès aux rapports et statistiques
- Gestion financière (lecture)

### 3. CENSEUR / CHEF D'ÉTUDES
**Permissions attendues**:
- Gestion de la structure pédagogique
- Calcul des moyennes
- Délibérations
- Déverrouillage des notes
- Tableau de bord saisie des notes

### 4. ENSEIGNANT
**Permissions attendues**:
- Saisie des notes (ses matières uniquement)
- Verrouillage/déverrouillage notes (ses matières)
- Consultation des élèves de ses classes
- Saisie des absences

### 5. INTENDANT / RESPONSABLE FINANCIER
**Permissions attendues**:
- Gestion financière complète
- Facturation
- Encaissements
- Relances
- Rapports financiers

### 6. SECRÉTAIRE / SCOLARITÉ
**Permissions attendues**:
- Inscription des élèves
- Modification dossiers élèves
- Génération de documents
- Gestion des admissions

### 7. PARENT
**Permissions attendues**:
- Consultation notes de ses enfants (après publication)
- Consultation bulletins
- Consultation situation financière
- Paiements en ligne
- Messages avec l'établissement

### 8. ÉLÈVE / ÉTUDIANT
**Permissions attendues**:
- Consultation de ses propres notes (après publication)
- Consultation de ses bulletins
- Consultation emploi du temps
- Messagerie interne

---

## 🧪 PLAN DE TEST PAR MODULE

### MODULE 1 - AUTHENTIFICATION ET CONFIGURATION

#### Test 1.1 - Login Super Admin
- [ ] Connexion avec admin@scholaris.dev / ChangeMe123!
- [ ] Vérification du JWT dans les cookies
- [ ] Vérification des permissions chargées (77 permissions)
- [ ] Redirection vers /dashboard
- [ ] Affichage des widgets selon le rôle

#### Test 1.2 - Gestion des Utilisateurs
- [ ] GET /api/users - Liste paginée
- [ ] POST /api/users - Créer un utilisateur (enseignant)
- [ ] POST /api/users - Créer un utilisateur (parent)
- [ ] PUT /api/users/:id - Modifier un utilisateur
- [ ] DELETE /api/users/:id - Soft delete
- [ ] Vérification RBAC (non-admin bloqué)

#### Test 1.3 - Configuration Établissement
- [ ] GET /api/tenants/:id - Détail établissement
- [ ] PUT /api/tenants/:id - Modifier infos générales
- [ ] PUT /api/tenants/:id/config - Configuration moteur de calcul
- [ ] Upload logo (si implémenté)

#### Test 1.4 - Années Académiques et Périodes
- [ ] GET /api/academic-years - Liste années
- [ ] POST /api/academic-years - Créer année 2026-2027
- [ ] GET /api/periods - Liste périodes
- [ ] POST /api/periods - Créer Séquence 1, Séquence 2
- [ ] PUT /api/periods/:id/status - Ouvrir/fermer période

#### Test 1.5 - Journal d'Audit
- [ ] GET /api/audit-logs - Vérifier que les actions sont loguées
- [ ] Filtres par utilisateur, action, ressource, date

#### Test 1.6 - MFA (si implémenté)
- [ ] POST /api/auth/mfa/enable - Activer MFA
- [ ] Vérification QR Code
- [ ] Login avec code MFA

---

### MODULE 2 - STRUCTURE PÉDAGOGIQUE

#### Test 2.1 - Cycles
- [ ] GET /api/cycles
- [ ] POST /api/cycles - Créer "Primaire", "Premier Cycle", "Second Cycle"

#### Test 2.2 - Programmes (Filières)
- [ ] POST /api/programs - Créer "Scientifique C", "Littéraire A", "Technique"
- [ ] Lien avec cycles

#### Test 2.3 - Niveaux
- [ ] POST /api/levels - Créer "6ème", "5ème", "4ème", "3ème", "2nde", "1ère", "Tle"
- [ ] Vérification ordre (order field)

#### Test 2.4 - Classes
- [ ] POST /api/classrooms - Créer "6ème A", "6ème B", "Tle C1", "Tle C2"
- [ ] Assignation enseignant principal
- [ ] Assignation salle
- [ ] Capacité maximale
- [ ] Section FR/EN

#### Test 2.5 - Salles
- [ ] POST /api/rooms - Créer salles (type: classroom/lab/amphitheater)
- [ ] Capacité, équipements

#### Test 2.6 - Arborescence Complète
- [ ] GET /api/structure/tree - Vérifier arborescence Cycles → Programmes → Niveaux → Classes

---

### MODULE 3 - MATIÈRES, UE ET EC

#### Test 3.1 - Matières (Secondaire)
- [ ] POST /api/subjects - Créer matières : Mathématiques (coeff 5), Français (coeff 5), Anglais (coeff 4), SVT (coeff 3), etc.
- [ ] Catégories (literary/scientific/technical/language/sports)
- [ ] Matières éliminatoires (seuil)
- [ ] Heures hebdomadaires

#### Test 3.2 - Unités d'Enseignement (Supérieur LMD)
- [ ] POST /api/teaching-units - Créer UE (crédits ECTS)
- [ ] UE fondamentales vs complémentaires

#### Test 3.3 - Éléments Constitutifs (EC)
- [ ] POST /api/course-elements - Créer EC liés aux UE
- [ ] Crédits, heures CM/TD/TP, coefficients
- [ ] Vérification : somme crédits EC = crédits UE

#### Test 3.4 - Assignation Enseignants
- [ ] POST /api/subject-assignments - Assigner enseignants aux matières/classes
- [ ] Matrice croisée classes × matières

#### Test 3.5 - Import Matières
- [ ] POST /api/subjects/import - Import Excel grille de matières

---

### MODULE 4 - INSCRIPTIONS ET ADMISSIONS

#### Test 4.1 - Création Élèves
- [ ] POST /api/students - Créer 30 élèves avec :
  - Identité complète (nom, prénom, date/lieu naissance, genre, photo)
  - Parents (père, mère, tuteur)
  - Contact d'urgence
  - Informations médicales (groupe sanguin, allergies)
- [ ] Vérification génération matricule unique
- [ ] Détection doublons (nom + prénom + date naissance)

#### Test 4.2 - Inscription
- [ ] POST /api/enrollments - Inscrire les 30 élèves :
  - 10 élèves en 6ème A
  - 10 élèves en 6ème B
  - 10 élèves en Tle C1
- [ ] Vérification contrôle capacité (refus si classe pleine)
- [ ] Type inscription (new/re-enrollment/transfer)
- [ ] Régime (external/half-board/boarding)

#### Test 4.3 - Dossier Élève
- [ ] GET /api/students/:id - Dossier complet avec onglets :
  - Identité
  - Parents
  - Inscriptions
  - Notes
  - Paiements
  - Discipline
  - Santé

#### Test 4.4 - Réinscription Batch
- [ ] POST /api/enrollments/re-enroll - Réinscrire une classe entière vers l'année suivante
- [ ] Proposition automatique classe suivante ou redoublement

#### Test 4.5 - Admissions (Supérieur)
- [ ] POST /api/admissions - Créer candidatures
- [ ] PUT /api/admissions/:id/decision - Accepter/Refuser/Liste d'attente

#### Test 4.6 - Import Élèves
- [ ] POST /api/students/import - Import Excel liste d'élèves
- [ ] Rapport : créés, doublons, erreurs

---

### MODULE 5 - SAISIE DES NOTES ET MOTEUR DE CALCUL

#### Test 5.1 - Saisie Notes Collective
- [ ] POST /api/grades/batch - Saisir notes pour :
  - Séquence 1 : Mathématiques 6ème A (10 élèves, 2 évaluations : interro + devoir)
  - Séquence 1 : Français 6ème A
  - Séquence 1 : Anglais 6ème A
- [ ] Validation note ∈ [0, 20]
- [ ] Gestion absences (is_absent=true, value=null)
- [ ] Pondération des évaluations

#### Test 5.2 - Import Notes Excel
- [ ] GET /api/grades/template/:classId/:subjectId/:periodId - Template pré-rempli
- [ ] POST /api/grades/import - Import notes
- [ ] Rapport erreurs

#### Test 5.3 - Verrouillage Notes
- [ ] PUT /api/grades/lock/:classId/:subjectId/:periodId - Enseignant verrouille
- [ ] Vérification : modification impossible après verrouillage
- [ ] PUT /api/grades/unlock - Censeur déverrouille

#### Test 5.4 - Calcul des Moyennes
- [ ] POST /api/grades/calculate/:classId/:periodId - Calculer Séquence 1 :
  - Moyenne par matière = Σ(note × poids) / Σ(poids)
  - Moyenne générale = Σ(moy_matière × coeff) / Σ(coeff)
  - Classement avec ex æquo (1,2,2,4)
  - Mentions (Passable ≥10, AB ≥12, B ≥14, TB ≥16, Excellent ≥18)
- [ ] Vérifier résultats avec calculs manuels (JEU DE DONNÉES CONNU)

#### Test 5.5 - Calcul Trimestriel
- [ ] Saisir notes Séquence 2
- [ ] POST /api/grades/calculate - Calculer Trimestre 1 :
  - Moy_trim = Séq1 × P1 + Séq2 × P2 (pondérations depuis config_json)
- [ ] Vérifier avec données connues

#### Test 5.6 - Calcul Annuel
- [ ] POST /api/grades/calculate-annual/:classId
- [ ] Moy_ann = T1×P1 + T2×P2 + T3×P3
- [ ] Décisions : pass/repeat/exclude

#### Test 5.7 - Calcul LMD (Supérieur)
- [ ] Saisir notes CC + Exam pour EC
- [ ] Note_EC = CC×Pcc + Exam×Pex
- [ ] Moy_UE = Σ(Note_EC × crédits_EC) / Σ(crédits)
- [ ] Validation si ≥ seuil (10/20)
- [ ] Compensation UE (EC < 10 validée si UE ≥ 10)
- [ ] Capitalisation des crédits
- [ ] Rattrapage (meilleure note conservée)
- [ ] GPA cumulatif

#### Test 5.8 - Dashboard Avancement
- [ ] GET /api/grades/progress/:periodId - Tableau par classe :
  - Matières saisies (vert)
  - En cours (orange)
  - Non saisies (rouge)
  - Pourcentage d'avancement

#### Test 5.9 - Délibération
- [ ] POST /api/grades/deliberation/:classId/:periodId - Saisir décisions conseil de classe
- [ ] Observations par élève

#### Test 5.10 - Publication
- [ ] POST /api/grades/publish/:classId/:periodId - Publier résultats
- [ ] Vérification : visibles aux parents/élèves après publication uniquement

---

### MODULE 6 - BULLETINS ET DIPLÔMES (À VÉRIFIER)

#### Test 6.1 - Génération Bulletins
- [ ] POST /api/bulletins/generate/:classId/:periodId - Générer 30 bulletins (job asynchrone)
- [ ] Structure bulletin secondaire camerounais :
  - En-tête bilingue officiel
  - Groupes de matières (A-E)
  - Tableau de notes
  - Synthèse (moyenne, rang, mention)
  - Conduite
  - Décision conseil de classe
  - Signatures
- [ ] QR Code d'authenticité

#### Test 6.2 - Téléchargement PDF
- [ ] GET /api/bulletins/:id/pdf - Télécharger bulletin
- [ ] Vérification contenu : nom élève, moyennes correctes, rang

#### Test 6.3 - Envoi aux Parents
- [ ] POST /api/bulletins/send/:classId/:periodId - Envoi email/WhatsApp
- [ ] Vérification réception (mock)

#### Test 6.4 - Vérification Publique
- [ ] GET /api/bulletins/verify/:qrCode - Page publique sans login
- [ ] Affichage infos document

#### Test 6.5 - Templates Personnalisables
- [ ] GET/PUT /api/bulletin-templates - Personnalisation (logo, couleurs)

#### Test 6.6 - Diplômes et Attestations
- [ ] POST /api/diplomas/generate/:studentId
- [ ] Vérification publique /api/diplomas/verify/:serialNumber

---

### MODULE 7 - GESTION FINANCIÈRE

#### Test 7.1 - Configuration Frais
- [ ] POST /api/fee-types - Créer types de frais :
  - Inscription : 25 000 FCFA (annuel, mandatory)
  - Scolarité : 180 000 FCFA (annuel, mandatory)
  - Cantine : 50 000 FCFA (trimestriel, optional, half-board/boarding)
  - Transport : 30 000 FCFA (trimestriel, optional)
  - Internat : 100 000 FCFA (trimestriel, optional, boarding only)
- [ ] Filtres par niveau, régime

#### Test 7.2 - Génération Factures
- [ ] POST /api/invoices/generate/:classId - Factures auto-générées pour la classe 6ème A
- [ ] Vérification montants selon le régime :
  - Externe : Inscription + Scolarité = 205 000 FCFA
  - Demi-pensionnaire : + Cantine = 255 000 FCFA
  - Internat : + Cantine + Internat = 355 000 FCFA

#### Test 7.3 - Remises et Bourses
- [ ] POST /api/discounts - Appliquer remises :
  - Bourse sociale : -50% scolarité (90 000 FCFA)
  - Remise fratrie : -10% pour 2e enfant
  - Bourse excellence : -100% scolarité
- [ ] Recalcul factures

#### Test 7.4 - Paiements Espèces
- [ ] POST /api/payments - Enregistrer paiements :
  - Élève 1 : 50 000 FCFA (espèces)
  - Élève 2 : 100 000 FCFA (espèces)
- [ ] Vérification mise à jour solde
- [ ] GET /api/payments/receipt/:id - Génération reçu PDF avec QR Code

#### Test 7.5 - Paiements Mobile Money
- [ ] POST /api/payments/mobile-money/initiate - Initier paiement Orange Money 100 000 FCFA
- [ ] Attente callback (mock)
- [ ] POST /api/payments/mobile-money/callback - Confirmation
- [ ] Vérification HMAC signature
- [ ] Vérification mise à jour facture

#### Test 7.6 - Situation Financière Élève
- [ ] GET /api/invoices/student/:studentId :
  - Facture totale
  - Paiements effectués
  - Solde restant
  - Historique

#### Test 7.7 - Relances Automatiques
- [ ] POST /api/finance/reminders - Déclenchement manuel (ou attendre cron)
- [ ] Identification factures en retard :
  - J-7 : Rappel (SMS/Email/WhatsApp)
  - J+1 : Relance
  - J+15 : 2e relance
  - J+30 : Mise en demeure
- [ ] Vérification envoi messages

#### Test 7.8 - Dashboard Financier
- [ ] GET /api/finance/dashboard :
  - Recettes du jour/mois
  - Taux de recouvrement
  - Impayés
  - Trésorerie
  - Graphiques évolution

#### Test 7.9 - Rapports Comptables
- [ ] GET /api/finance/reports/journal - Journal comptable
- [ ] GET /api/finance/reports/balance - Balance
- [ ] GET /api/finance/reports/grand-livre - Grand livre
- [ ] Export PDF/Excel

#### Test 7.10 - Écritures SYSCOHADA
- [ ] GET /api/accounting/entries - Journal écritures automatiques
- [ ] Vérification débit/crédit pour chaque paiement

---

### MODULE 8 - COMMUNICATION MULTICANAL

#### Test 8.1 - Templates
- [ ] GET /api/communications/templates
- [ ] POST /api/communications/templates - Créer templates :
  - "Convocation parent" (email)
  - "Relance paiement" (SMS)
  - "Bulletin disponible" (WhatsApp)
- [ ] Variables dynamiques : {nom_eleve}, {classe}, {montant}, {date}

#### Test 8.2 - Envoi Message Individuel
- [ ] POST /api/communications/send :
  - Email à un parent
  - SMS à un parent
  - WhatsApp à un parent
- [ ] Vérification réception (mock)

#### Test 8.3 - Envoi en Masse
- [ ] POST /api/communications/send-bulk :
  - Envoyer SMS à tous les parents de 6ème A
  - Envoyer email à tous les parents d'impayés
- [ ] Job asynchrone BullMQ
- [ ] Barre de progression

#### Test 8.4 - Préférences Communication
- [ ] GET /api/communications/preferences/:parentId
- [ ] PUT - Modifier :
  - Canal préféré (email/sms/whatsapp)
  - Langue (fr/en)
  - Fréquence (realtime/daily/weekly)
  - Opt-out promotionnel

#### Test 8.5 - Fallback Automatique
- [ ] Simuler échec canal préféré
- [ ] Vérification tentative canal suivant (WhatsApp → SMS → Email)

#### Test 8.6 - Historique
- [ ] GET /api/communications/history :
  - Filtres : canal, statut, destinataire, date
  - Statuts : queued/sent/delivered/failed/read

#### Test 8.7 - Chatbot WhatsApp (si implémenté)
- [ ] Webhook entrant : mot-clé "SOLDE"
- [ ] Réponse automatique avec situation financière
- [ ] Mot-clé "NOTES" → notes de l'enfant

---

### MODULE 9-18 (À DÉTAILLER SI IMPLÉMENTÉS)

Pour chaque module, créer des tests similaires si implémentés.

---

## 🔐 TESTS RBAC - CONTRÔLE D'ACCÈS PAR PROFIL

### Test RBAC 1 - Enseignant
- [ ] Créer compte enseignant (enseignant@scholaris.dev / Test123!)
- [ ] Login
- [ ] **Autorisé** :
  - GET /api/grades/student/:id (ses élèves uniquement)
  - POST /api/grades/batch (ses matières uniquement)
  - PUT /api/grades/lock (ses matières)
- [ ] **Interdit** (403) :
  - POST /api/users
  - PUT /api/tenants/:id
  - DELETE /api/students/:id
  - GET /api/finance/dashboard

### Test RBAC 2 - Parent
- [ ] Créer compte parent (parent@scholaris.dev / Test123!)
- [ ] Lier avec un élève
- [ ] Login
- [ ] **Autorisé** :
  - GET /api/grades/student/:id (ses enfants uniquement)
  - GET /api/bulletins/:id (ses enfants uniquement, après publication)
  - GET /api/invoices/student/:id (ses enfants)
  - POST /api/payments/mobile-money/initiate (ses factures)
- [ ] **Interdit** (403) :
  - GET /api/students (liste complète)
  - POST /api/grades/batch
  - GET /api/finance/dashboard
- [ ] **IDOR Test** : Parent A ne peut PAS accéder aux notes de l'enfant du Parent B

### Test RBAC 3 - Censeur
- [ ] Créer compte censeur (censeur@scholaris.dev / Test123!)
- [ ] **Autorisé** :
  - POST /api/grades/calculate
  - POST /api/grades/deliberation
  - PUT /api/grades/unlock (toutes matières)
  - GET /api/grades/progress
- [ ] **Interdit** (403) :
  - PUT /api/tenants/:id/config
  - POST /api/users

### Test RBAC 4 - Intendant
- [ ] Créer compte intendant (intendant@scholaris.dev / Test123!)
- [ ] **Autorisé** :
  - POST /api/invoices/generate
  - POST /api/payments
  - GET /api/finance/dashboard
  - GET /api/finance/reports
- [ ] **Interdit** (403) :
  - POST /api/grades/batch
  - POST /api/students

---

## ⚡ TESTS DE PERFORMANCE

### Test Perf 1 - Saisie Concurrente
- [ ] 10 enseignants saisissent des notes simultanément (même classe, matières différentes)
- [ ] Vérification : aucun conflit, toutes les notes enregistrées

### Test Perf 2 - Génération Bulletins en Masse
- [ ] Générer 60 bulletins simultanément
- [ ] Temps < 2 minutes
- [ ] Aucune erreur

### Test Perf 3 - Calcul de Moyennes
- [ ] Classe de 60 élèves, 15 matières, 5 évaluations par matière
- [ ] Calcul complet < 5 secondes

### Test Perf 4 - Dashboard Chargement
- [ ] Accès /dashboard avec 1000+ élèves dans la base
- [ ] Temps de chargement < 2 secondes

---

## 🔒 TESTS DE SÉCURITÉ

### Test Sécu 1 - Injection SQL
- [ ] Champs de recherche : ' OR 1=1 --
- [ ] Vérification : requête bloquée ou échappée

### Test Sécu 2 - XSS
- [ ] Saisie nom élève : `<script>alert('XSS')</script>`
- [ ] Vérification : texte échappé, script non exécuté

### Test Sécu 3 - IDOR
- [ ] Parent A tente GET /api/grades/student/:idEnfantB
- [ ] Vérification : 403 Forbidden

### Test Sécu 4 - Rate Limiting
- [ ] 100+ requêtes /api/auth/login en 1 minute
- [ ] Vérification : 429 Too Many Requests

### Test Sécu 5 - JWT
- [ ] Token expiré → 401
- [ ] Refresh token → nouveau access token
- [ ] Token manipulé → 401

---

## 📊 TESTS D'INTÉGRATION GLOBAUX

### Parcours 1 - Inscription → Notes → Bulletin (30 élèves)
1. Créer établissement + année + périodes
2. Créer structure complète (cycle → filière → niveau → classes)
3. Créer matières avec coefficients
4. Assigner enseignants
5. Inscrire 30 élèves (10 par classe)
6. Générer factures automatiques
7. Saisir notes Séquence 1 (3 matières, 2 évaluations chacune)
8. Verrouiller notes
9. Calculer moyennes Séquence 1
10. Vérifier moyennes, rangs, mentions (avec données connues)
11. Saisir notes Séquence 2
12. Calculer Trimestre 1
13. Générer 30 bulletins PDF
14. Envoyer bulletins aux parents par email
15. **Vérifier CHAQUE ÉTAPE**

### Parcours 2 - Financier Complet
1. Inscription → facture auto-générée (180 000 FCFA)
2. Paiement espèces 50 000 FCFA → solde 130 000
3. Paiement Mobile Money 100 000 FCFA → callback → solde 30 000
4. Échéance dépassée → relance automatique → SMS envoyé
5. Paiement final 30 000 → solde 0
6. Génération reçu PDF
7. Vérification écritures comptables

### Parcours 3 - LMD Complet
1. Créer UE1 (10 crédits) avec EC1 (6 crédits) + EC2 (4 crédits)
2. Saisir notes CC et Exam pour EC1 et EC2
3. Calculer : Note_EC = CC×0.4 + Exam×0.6
4. Calculer Moy_UE
5. EC1 = 8/20, EC2 = 12/20 → Moy_UE = 9.6 → NON VALIDÉE
6. Compensation activée : EC1 validée car UE ≥ 10
7. Rattrapage EC1 → Note 12 → meilleure note conservée
8. Recalcul → UE validée → 10 crédits capitalisés
9. Calcul GPA cumulatif
10. Relevé de notes / Transcript

---

## 📝 GUIDE D'UTILISATION À PRODUIRE

À l'issue des tests, produire un **Guide d'Utilisation** structuré :

### Contenu du Guide

1. **Introduction**
   - Présentation SCHOLARIS ERP
   - Connexion et première utilisation
   - Navigation dans l'interface

2. **Guide Super Admin**
   - Configuration de l'établissement
   - Gestion des utilisateurs et rôles
   - Structure pédagogique
   - Configuration du moteur de calcul
   - Tableau de bord

3. **Guide Directeur**
   - Publication des résultats
   - Rapports et statistiques
   - Validation des décisions

4. **Guide Censeur**
   - Gestion des notes
   - Calcul des moyennes
   - Délibérations
   - Bulletins

5. **Guide Enseignant**
   - Saisie des notes
   - Consultation des élèves
   - Absences

6. **Guide Intendant**
   - Facturation
   - Encaissements
   - Relances
   - Rapports financiers

7. **Guide Secrétaire**
   - Inscription des élèves
   - Gestion des dossiers
   - Documents

8. **Guide Parent**
   - Consultation des notes
   - Consultation des bulletins
   - Paiements en ligne
   - Communication

9. **Guide Élève**
   - Consultation des notes
   - Emploi du temps
   - Messagerie

10. **FAQ et Dépannage**

---

## ✅ CRITÈRES DE VALIDATION GLOBALE

Le système est validé si et seulement si :

- [ ] Tous les modules implémentés fonctionnent sans erreur
- [ ] Les calculs de moyennes sont exacts (vérifiés avec données connues)
- [ ] Le RBAC bloque correctement les accès non autorisés
- [ ] Aucune faille de sécurité (SQL injection, XSS, IDOR)
- [ ] Les performances sont acceptables (< 2s chargement, < 2min génération 60 bulletins)
- [ ] Tous les tests unitaires passent (couverture > 80%)
- [ ] Tous les tests d'intégration passent
- [ ] Tous les tests E2E passent
- [ ] Le guide d'utilisation est complet et clair

---

**Début des tests** : 2026-07-15  
**Testeur** : GitHub Copilot + User  
**Environnement** : Development (localhost:3000) + Production (Railway)
