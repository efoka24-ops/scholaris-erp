# GUIDE D'UTILISATION - SCHOLARIS ERP v2.0
## Documentation complète pour tous les profils utilisateurs

**Version** : 2.0  
**Date** : 15 Juillet 2026  
**Environnement** : Production (Railway) + Development (localhost)  

---

## 📋 TABLE DES MATIÈRES

1. [Connexion et Premiers Pas](#1-connexion-et-premiers-pas)
2. [Guide Super Admin](#2-guide-super-admin)
3. [Guide Enseignant](#3-guide-enseignant)
4. [Guide Parent](#4-guide-parent)
5. [Guide Censeur](#5-guide-censeur)
6. [Guide Intendant](#6-guide-intendant)
7. [Guide Secrétaire](#7-guide-secrétaire)
8. [FAQ et Dépannage](#8-faq-et-dépannage)

---

## 1. CONNEXION ET PREMIERS PAS

### 1.1 Accès à l'application

**URL Production** : https://scholaris-erp-production.up.railway.app  
**URL Development** : http://localhost:3000  

### 1.2 Compte Super Admin (Démo)

- **Email** : admin@scholaris.dev
- **Mot de passe** : ChangeMe123!
- **Permissions** : 79 permissions (accès complet)

### 1.3 Page de connexion

1. Ouvrir l'URL dans un navigateur
2. Saisir l'email et le mot de passe
3. Cliquer sur "Se connecter"
4. Redirection automatique vers le tableau de bord

### 1.4 Navigation

L'interface est composée de :
- **Sidebar gauche** : Menu de navigation (masqué sur mobile, visible sur desktop)
- **Topbar** : Fil d'Ariane + email utilisateur + bouton Déconnexion
- **Zone principale** : Contenu de la page courante

### 1.5 Menu de navigation (20 pages disponibles)

```
📊 Tableau de bord
📚 Structure pédagogique
🏫 Classes
🚪 Salles
📖 Matières
📑 UE & EC (Enseignement Supérieur)
👨‍🏫 Assignations (Enseignants ↔ Matières)
👨‍🎓 Élèves
📝 Notes
📋 Admissions
🔄 Réinscriptions
💵 Grilles tarifaires
🧾 Factures
💰 Finances (Dashboard)
📧 Communication
👥 Utilisateurs
📅 Années académiques
🧮 Moteur de calcul
📜 Journal d'audit
⚙️ Établissement
```

---

## 2. GUIDE SUPER ADMIN

**Profil** : Administrateur système avec accès complet  
**Permissions** : 79 permissions  
**Responsabilités** : Configuration, gestion utilisateurs, structure pédagogique, paramètres  

### 2.1 Tableau de Bord

**URL** : `/dashboard`

**Widgets disponibles** :
- Établissement (à implémenter - affichera le nom, type, statut)
- Utilisateurs actifs (à implémenter - nombre de comptes)
- Année académique (à implémenter - année en cours)

**État actuel** : Widgets vides avec message "À implémenter avec le module correspondant"

### 2.2 Configuration de l'Établissement

**URL** : `/settings/establishment`

**Fonctionnalités** :
- Informations générales (nom, type, statut public/privé)
- Coordonnées (adresse, téléphone, email)
- Logo (upload)
- Configuration JSON (paramètres avancés)

**Actions disponibles** :
1. Consulter les informations actuelles
2. Modifier les informations générales
3. Uploader un logo
4. Sauvegarder les modifications

**API Backend** :
- `GET /api/tenants/:id` - Récupérer les infos
- `PUT /api/tenants/:id` - Modifier les infos

### 2.3 Structure Pédagogique

**URL** : `/academics/structure`

**Hiérarchie** :
```
Cycles (Primaire, Secondaire, Supérieur)
  ↓
Programmes / Filières (Scientifique C, Littéraire A, etc.)
  ↓
Niveaux (6ème, 5ème, 4ème, 3ème, 2nde, 1ère, Tle)
  ↓
Classes (6ème A, 6ème B, Tle C1, etc.)
```

**Actions disponibles** :
1. **Créer un cycle** :
   - Cliquer sur "Nouveau cycle"
   - Remplir : Code, Nom, Ordre
   - Valider

2. **Créer un programme** :
   - Sélectionner un cycle
   - Cliquer sur "Nouveau programme"
   - Remplir : Code, Nom
   - Lier au cycle
   - Valider

3. **Créer un niveau** :
   - Sélectionner un cycle
   - Cliquer sur "Nouveau niveau"
   - Remplir : Code, Nom, Ordre
   - Valider

4. **Afficher l'arborescence** :
   - Vue hiérarchique Cycles → Programmes → Niveaux → Classes
   - Drag-and-drop pour réorganiser (si implémenté)

**API Backend** :
- `GET /api/cycles` - Liste des cycles
- `POST /api/cycles` - Créer un cycle
- `GET /api/programs` - Liste des programmes
- `POST /api/programs` - Créer un programme
- `GET /api/levels` - Liste des niveaux
- `POST /api/levels` - Créer un niveau
- `GET /api/structure/tree` - Arborescence complète

### 2.4 Classes

**URL** : `/academics/classrooms`

**Fonctionnalités** :
- Liste paginée des classes
- Filtres (niveau, filière, section FR/EN)
- Affichage effectif actuel / capacité maximale

**Actions disponibles** :
1. **Créer une classe** :
   - URL : `/academics/classrooms/new`
   - Formulaire :
     - Code (ex: 6A, TleC1)
     - Nom (ex: 6ème A, Terminale C1)
     - Capacité maximale (ex: 40)
     - Niveau (sélecteur en cascade)
     - Salle (sélecteur)
     - Enseignant principal (sélecteur)
     - Section (FR/EN)
   - Valider

2. **Modifier une classe** :
   - Cliquer sur une classe dans la liste
   - Modifier les champs
   - Sauvegarder

3. **Consulter les élèves d'une classe** :
   - Cliquer sur le nombre d'élèves
   - Affiche la liste des inscrits

**API Backend** :
- `GET /api/classrooms` - Liste des classes
- `POST /api/classrooms` - Créer une classe
- `GET /api/classrooms/:id` - Détail d'une classe
- `PATCH /api/classrooms/:id` - Modifier une classe

### 2.5 Salles

**URL** : `/academics/rooms`

**Fonctionnalités** :
- Liste des salles
- Types : classroom, lab, amphitheater, office
- Capacité, bâtiment, étage, équipements

**Actions disponibles** :
1. **Créer une salle** :
   - URL : `/academics/rooms/new`
   - Formulaire :
     - Code (ex: A101, LAB-BIO)
     - Nom (ex: Salle A101, Laboratoire de Biologie)
     - Type (classroom/lab/amphitheater/office)
     - Capacité (nombre de places)
     - Bâtiment (ex: Bloc A)
     - Étage (ex: 1)
     - Équipements (tableau, projecteur, ordinateurs, etc.)
   - Valider

2. **Visualisation taux d'occupation** :
   - Affiche le nombre de classes assignées
   - Indicateur de disponibilité

**API Backend** :
- `GET /api/rooms` - Liste des salles
- `POST /api/rooms` - Créer une salle
- `GET /api/rooms/:id` - Détail d'une salle
- `PATCH /api/rooms/:id` - Modifier une salle

### 2.6 Matières

**URL** : `/academics/subjects`

**Fonctionnalités** :
- Liste des matières (secondaire)
- Filtres (catégorie, niveau, enseignant)
- Coefficient, heures hebdomadaires, catégorie

**Actions disponibles** :
1. **Créer une matière** :
   - URL : `/academics/subjects/new`
   - Formulaire :
     - Code (ex: MATH, FR, ANG)
     - Nom (ex: Mathématiques, Français, Anglais)
     - Coefficient (ex: 5, 4, 3)
     - Heures hebdomadaires (ex: 6, 5, 4)
     - Catégorie (literary/scientific/technical/language/sports)
     - Matière éliminatoire ? (oui/non)
     - Seuil éliminatoire (ex: 5/20)
   - Valider

2. **Modifier une matière** :
   - URL : `/academics/subjects/:id/edit`
   - Modifier les champs
   - Sauvegarder

3. **Importer des matières (Excel)** :
   - URL : `/academics/subjects/import`
   - Télécharger le template Excel
   - Remplir avec les matières
   - Upload du fichier
   - Prévisualisation
   - Confirmation
   - Rapport : créées, doublons, erreurs

**API Backend** :
- `GET /api/subjects` - Liste des matières
- `POST /api/subjects` - Créer une matière
- `GET /api/subjects/:id` - Détail d'une matière
- `PUT /api/subjects/:id` - Modifier une matière
- `DELETE /api/subjects/:id` - Supprimer (soft delete)
- `POST /api/subjects/import` - Import Excel

### 2.7 UE & EC (Enseignement Supérieur LMD)

**URL** : `/academics/teaching-units`

**Hiérarchie** :
```
UE (Unité d'Enseignement) - 10 crédits ECTS
  ↓
EC (Élément Constitutif) - 6 + 4 crédits
```

**Actions disponibles** :
1. **Créer une UE** :
   - Code (ex: UE11, UE12)
   - Nom (ex: Mathématiques Fondamentales)
   - Crédits ECTS (ex: 10)
   - Semestre (1 ou 2)
   - Type (fondamentale/complémentaire)
   - Valider

2. **Créer un EC** :
   - Code (ex: EC111, EC112)
   - Nom (ex: Analyse, Algèbre)
   - Crédits (ex: 6, 4)
   - Heures CM/TD/TP (ex: 30/20/10)
   - Coefficient
   - Lier à une UE
   - Valider

3. **Vérifier cohérence** :
   - Somme crédits EC = crédits UE
   - Alert si incohérence

**API Backend** :
- `GET /api/teaching-units` - Liste des UE
- `POST /api/teaching-units` - Créer une UE
- `GET /api/course-elements` - Liste des EC
- `POST /api/course-elements` - Créer un EC

### 2.8 Assignations Enseignants

**URL** : `/academics/assignments`

**Fonctionnalités** :
- Matrice croisée : Classes × Matières
- Assignation enseignant par cellule
- Drag-and-drop ou sélecteur

**Actions disponibles** :
1. **Assigner un enseignant à une matière/classe** :
   - Sélectionner la classe
   - Sélectionner la matière
   - Choisir l'enseignant
   - Valider

2. **Visualiser les assignations** :
   - Par enseignant (charge horaire)
   - Par classe (grille complète)
   - Par matière (enseignants responsables)

**API Backend** :
- `GET /api/subject-assignments` - Liste des assignations
- `POST /api/subject-assignments` - Créer une assignation
- `GET /api/subject-assignments/teachers` - Liste enseignants
- `GET /api/subject-assignments/academic-years` - Années académiques

### 2.9 Gestion des Élèves

**URL** : `/students`

**Fonctionnalités** :
- Liste paginée avec recherche
- Filtres (classe, niveau, statut)
- Affichage photo miniature
- Actions rapides (voir dossier, modifier, inscrire)

**Actions disponibles** :
1. **Créer un élève** :
   - URL : `/students/new`
   - Wizard multi-étapes (6 étapes) :
     
     **Étape 1 - Identité** :
     - Nom de famille
     - Prénom(s)
     - Date de naissance
     - Lieu de naissance
     - Genre (M/F)
     - Nationalité
     - Photo (upload)
     
     **Étape 2 - Parents** :
     - Père (nom, prénom, téléphone, WhatsApp, email, profession, adresse)
     - Mère (idem)
     - Tuteur (optionnel)
     - Relation (père/mère/tuteur)
     
     **Étape 3 - Scolarité antérieure** :
     - Établissement précédent
     - Classe précédente
     - Moyenne obtenue
     - Décision conseil (admis/redoublant)
     
     **Étape 4 - Choix pédagogiques** :
     - Régime (externe/demi-pensionnaire/internat)
     - Langue vivante 2
     - Options
     
     **Étape 5 - Documents** :
     - Upload acte de naissance
     - Upload certificat de scolarité
     - Upload photos d'identité
     - Autres documents
     
     **Étape 6 - Confirmation** :
     - Récapitulatif complet
     - Vérification doublons (alerte si similarité)
     - Validation finale
     - Génération automatique du matricule

2. **Consulter le dossier complet d'un élève** :
   - URL : `/students/:id`
   - Onglets :
     - **Identité** : Infos personnelles, photo
     - **Parents** : Contacts parents/tuteurs
     - **Inscriptions** : Historique par année
     - **Notes** : Résultats par période
     - **Paiements** : Situation financière
     - **Discipline** : Sanctions et récompenses
     - **Santé** : Fiche médicale
   
3. **Modifier le dossier** :
   - Cliquer sur "Modifier"
   - Formulaire éditable
   - Sauvegarder
   - Audit log automatique

4. **Importer une liste d'élèves (Excel)** :
   - URL : `/students/import`
   - Télécharger template
   - Remplir (colonnes : nom, prénom, date naissance, lieu, genre, parents...)
   - Upload fichier
   - Mapping colonnes (si nécessaire)
   - Prévisualisation
   - Rapport : créés, doublons détectés, erreurs

**API Backend** :
- `GET /api/students` - Liste paginée
- `GET /api/students/:id` - Dossier complet
- `POST /api/students` - Créer un élève
- `PUT /api/students/:id` - Modifier
- `DELETE /api/students/:id` - Soft delete
- `POST /api/students/import` - Import Excel
- `GET /api/students/:id/export` - Export PDF du dossier

**Matricule automatique** :
- Format configurable dans tenant.configJson
- Exemple : LBD/2026/0001, DEMO/2026/0001
- Compteur atomique (séquence PostgreSQL)
- Unicité garantie

### 2.10 Admissions (Enseignement Supérieur)

**URL** : `/admissions`

**Fonctionnalités** :
- Liste des candidatures
- Types : concours, étude de dossier, admission directe
- Statuts : pending, accepted, rejected, waitlisted

**Actions disponibles** :
1. **Créer une candidature** :
   - Nom du candidat
   - Informations (JSON : diplômes, résultats, motivations)
   - Type (exam/dossier/direct)
   - Score (si concours)
   - Documents (upload)
   - Valider

2. **Traiter une candidature** :
   - Consulter le dossier
   - Décision :
     - Accepter → générer inscription automatique
     - Refuser → notification email
     - Liste d'attente → en attente
   - Saisir le rang (si concours)
   - Valider

3. **Statistiques** :
   - Nombre de candidatures
   - Taux d'acceptation
   - Répartition par type

**API Backend** :
- `GET /api/admissions` - Liste paginée
- `GET /api/admissions/:id` - Détail candidature
- `POST /api/admissions` - Créer candidature
- `PUT /api/admissions/:id/decision` - Accepter/Refuser

### 2.11 Inscriptions et Réinscriptions

**URL Inscription** : `/enrollments` (intégré dans le dossier élève)  
**URL Réinscription batch** : `/enrollments/re-enroll`

**Fonctionnalités** :
1. **Inscrire un élève dans une classe** :
   - Depuis le dossier élève
   - Sélectionner l'année académique
   - Sélectionner la classe
   - Type : new/re-enrollment/transfer
   - Régime : external/half-board/boarding
   - Redoublant ? (oui/non)
   - Documents justificatifs
   - Valider
   - Génération automatique facture (selon grille tarifaire)

2. **Réinscription batch (passer toute une classe)** :
   - Sélectionner la classe source
   - Choisir la classe destination (niveau suivant ou même classe si redoublement)
   - Prévisualisation élève par élève :
     - Proposition automatique : passage ou redoublement selon résultats
     - Possibilité de modifier individuellement
   - Confirmation
   - Traitement asynchrone
   - Rapport : réinscrits, échecs

3. **Affectation automatique** :
   - Équilibrage des effectifs entre classes
   - Critères : genre, résultats, fratrie
   - Proposition
   - Validation

4. **Transfert vers un autre établissement** :
   - Génération dossier numérique chiffré (PDF + JSON)
   - QR Code de vérification
   - Export

**API Backend** :
- `GET /api/enrollments` - Liste des inscriptions
- `POST /api/enrollments` - Inscrire un élève
- `PUT /api/enrollments/:id/status` - Changer statut (annuler, suspendre)
- `POST /api/enrollments/re-enroll` - Réinscription batch
- `POST /api/enrollments/auto-assign` - Affectation automatique
- `POST /api/enrollments/transfer` - Transfert

### 2.12 Notes et Moyennes

**URL Saisie** : `/grades/entry/:classId/:subjectId/:periodId`  
**URL Progress** : `/grades/progress`  
**URL Résultats** : `/grades/results/:classId/:periodId`

**Fonctionnalités** :
1. **Saisie collective** :
   - Grille : élèves en lignes, évaluations en colonnes
   - Navigation clavier (Tab, Entrée)
   - Couleurs : rouge <10, orange 10-12, vert >12
   - Sauvegarde automatique (debounce 2s)
   - Gestion absences (checkbox is_absent)
   - Pondération par évaluation

2. **Import notes Excel** :
   - URL : `/grades/import`
   - Télécharger template (liste élèves pré-remplie)
   - Remplir les notes
   - Upload
   - Prévisualisation
   - Validation
   - Rapport : enregistrées, erreurs

3. **Verrouillage** :
   - Enseignant verrouille quand terminé
   - Après verrouillage : modification impossible
   - Seul le Censeur peut déverrouiller

4. **Dashboard d'avancement** :
   - URL : `/grades/progress`
   - Tableau par classe :
     - Matières saisies (vert ✓)
     - En cours de saisie (orange ⏳)
     - Non saisies (rouge ✗)
     - Pourcentage d'avancement global

5. **Calcul des moyennes** :
   - URL : `/grades/results/:classId/:periodId`
   - Bouton "Calculer"
   - Algorithmes :
     - **Séquentiel** : Moy_matière = Σ(note × poids) / Σ(poids)
     - **Général** : Moy_générale = Σ(moy_matière × coeff) / Σ(coeff)
     - **Trimestriel** : Moy_trim = Séq1 × P1 + Séq2 × P2
     - **Annuel** : Moy_ann = T1×P1 + T2×P2 + T3×P3
     - **LMD** : Note_EC = CC×Pcc + Exam×Pex, Moy_UE, GPA
   - Classement : gestion ex æquo (1,2,2,4)
   - Mentions : Passable ≥10, AB ≥12, B ≥14, TB ≥16, Excellent ≥18
   - Arrondis selon config (dixième, centième, entier)

6. **Délibérations** :
   - URL : `/grades/deliberation/:classId/:periodId`
   - Liste élèves avec moyennes et rangs
   - Saisie décisions :
     - Passage
     - Redoublement
     - Exclusion
   - Observations par élève
   - Commentaire professeur principal
   - Validation → enregistrement résultats période

7. **Publication** :
   - Bouton "Publier" (réservé Directeur)
   - Rend les résultats visibles aux parents/élèves
   - Notification automatique

**API Backend** :
- `POST /api/grades/batch` - Saisie collective
- `POST /api/grades/import` - Import Excel
- `PUT /api/grades/:id` - Modifier une note
- `PUT /api/grades/lock/:classId/:subjectId/:periodId` - Verrouiller
- `PUT /api/grades/unlock/:classId/:subjectId/:periodId` - Déverrouiller
- `POST /api/grades/calculate/:classId/:periodId` - Calculer moyennes
- `POST /api/grades/calculate-annual/:classId` - Calculer moyennes annuelles
- `GET /api/grades/progress/:periodId` - Dashboard avancement
- `GET /api/grades/student/:studentId` - Toutes les notes d'un élève
- `GET /api/grades/results/:classId/:periodId` - Résultats classe
- `POST /api/grades/deliberation/:classId/:periodId` - Saisir délibérations
- `POST /api/grades/publish/:periodId` - Publier résultats

### 2.13 Gestion Financière

**URLs** :
- Grilles tarifaires : `/finance/fee-structures`
- Factures : `/finance/invoices`
- Paiements : `/finance/payments/new`
- Dashboard : `/finance/dashboard`

**Fonctionnalités** :
1. **Configurer les grilles tarifaires** :
   - Types de frais :
     - Inscription (annuel, obligatoire)
     - Scolarité (annuel/trimestriel, obligatoire)
     - Cantine (trimestriel/mensuel, optionnel)
     - Transport (trimestriel/mensuel, optionnel)
     - Internat (trimestriel, optionnel)
   - Pour chaque type :
     - Nom
     - Montant (XAF)
     - Fréquence (annual/trimester/monthly)
     - Filtres (niveau, régime)
     - Obligatoire/Optionnel
   - Valider

2. **Générer les factures** :
   - Automatique à l'inscription
   - Manuel : `/finance/invoices` → "Générer factures classe"
   - Batch : toute une classe
   - Calcul selon :
     - Régime élève (externe/demi-pensionnaire/internat)
     - Niveau
     - Remises applicables
   - Lignes de facture détaillées

3. **Enregistrer un paiement** :
   - URL : `/finance/payments/new`
   - Formulaire :
     - Rechercher élève (nom, matricule)
     - Afficher factures impayées
     - Sélectionner facture
     - Montant à payer
     - Canal :
       - Espèces
       - Orange Money
       - MTN MoMo
       - Carte bancaire
       - Virement bancaire
     - Référence (si mobile money/carte)
     - Validé par (nom du caissier)
   - Valider
   - Génération automatique :
     - Reçu PDF avec QR Code
     - Mise à jour solde facture
     - Écriture comptable SYSCOHADA

4. **Paiements Mobile Money** :
   - Initiation : appel API Orange Money / MTN MoMo
   - Affichage instructions (numéro à composer)
   - Attente callback (webhook)
   - Confirmation automatique
   - Mise à jour facture

5. **Remises et bourses** :
   - Types :
     - Bourse sociale (-50% scolarité)
     - Remise fratrie (-10% dès 2e enfant)
     - Bourse excellence (-100% scolarité)
     - Remise personnalisée
   - Approbation par Admin/Directeur
   - Application automatique sur factures

6. **Relances automatiques** :
   - Cron job BullMQ :
     - J-7 : Rappel (SMS/Email/WhatsApp)
     - J+1 : 1ère relance
     - J+15 : 2e relance
     - J+30 : Mise en demeure
   - Templates personnalisables
   - Envoi multicanal

7. **Situation financière élève** :
   - Depuis le dossier élève, onglet "Paiements"
   - Affichage :
     - Facture totale annuelle
     - Paiements effectués (historique)
     - Solde restant
     - Prochaine échéance
     - Statut (à jour / en retard)

8. **Dashboard financier** :
   - URL : `/finance/dashboard`
   - KPIs :
     - Recettes du jour
     - Recettes du mois
     - Taux de recouvrement (%)
     - Impayés (montant + nombre)
     - Trésorerie
   - Graphiques :
     - Évolution recettes mensuelle
     - Répartition par type de frais
     - Top 10 impayés
   - Export PDF/Excel

9. **Rapports comptables** :
   - Journal comptable (toutes les écritures)
   - Balance (débit/crédit par compte)
   - Grand livre (détail par compte)
   - Export SYSCOHADA

**API Backend** :
- `GET/POST /api/fee-structures` - CRUD grilles tarifaires
- `GET /api/invoices` - Liste factures
- `GET /api/invoices/:id` - Détail facture
- `POST /api/invoices/generate/:enrollmentId` - Générer facture élève
- `POST /api/invoices/generate-batch/:classId` - Générer factures classe
- `POST /api/payments` - Enregistrer paiement
- `POST /api/payments/mobile-money/initiate` - Initier Mobile Money
- `POST /api/payments/mobile-money/callback` - Webhook callback
- `GET /api/payments/:id/receipt` - Télécharger reçu PDF
- `POST /api/discounts` - Appliquer remise
- `POST /api/finance/reminders` - Déclencher relances
- `GET /api/finance/dashboard` - Dashboard financier
- `GET /api/finance/reports/:type` - Rapports (journal, balance, grand-livre)
- `GET /api/students/:studentId/financial-summary` - Situation élève

### 2.14 Communication Multicanal

**URLs** :
- Envoyer message : `/communications/new`
- Templates : `/communications` (onglet Templates)
- Historique : `/communications/logs`

**Canaux disponibles** :
- Email (Brevo/SendGrid)
- SMS (Africa's Talking)
- WhatsApp Business API (Meta Cloud API)
- Notifications push (FCM)

**Fonctionnalités** :
1. **Envoyer un message** :
   - URL : `/communications/new`
   - Formulaire :
     - Destinataires :
       - Individuel (sélectionner parent/enseignant)
       - Groupe (classe, niveau, établissement)
     - Canal :
       - Email
       - SMS
       - WhatsApp
       - Push notification
     - Template (optionnel)
     - Message :
       - Sujet (email uniquement)
       - Corps avec variables dynamiques : {nom_eleve}, {classe}, {montant}, {date}
     - Pièce jointe (email uniquement)
   - Prévisualisation
   - Envoyer
   - Job asynchrone BullMQ
   - Rapport : envoyés, en file, échecs

2. **Templates de communication** :
   - Créer template :
     - Nom
     - Canal (email/sms/whatsapp)
     - Sujet (email)
     - Corps avec variables
     - Actif/Inactif
   - Modifier template
   - Prévisualiser avec données test
   - Utiliser dans envoi

3. **Historique des messages** :
   - URL : `/communications/logs`
   - DataTable avec :
     - Date/heure
     - Destinataire
     - Canal
     - Sujet/Aperçu
     - Statut :
       - Queued (en file)
       - Sent (envoyé)
       - Delivered (délivré)
       - Read (lu)
       - Failed (échec)
   - Filtres : canal, statut, destinataire, date
   - Détail : clic sur ligne → affiche message complet + erreur si échec

4. **Préférences de communication** :
   - Par parent (depuis le dossier élève)
   - Canal préféré
   - Langue (fr/en)
   - Fréquence (realtime/daily digest/weekly digest)
   - Opt-out promotionnel (ne reçoit que les messages importants)

5. **Fallback automatique** :
   - Si le canal préféré échoue :
     - WhatsApp → SMS → Email
   - Tentatives automatiques
   - Log des tentatives

6. **Chatbot WhatsApp** (si implémenté) :
   - Parent envoie mot-clé :
     - "SOLDE" → situation financière
     - "NOTES" → dernières notes
     - "EMPLOI" → emploi du temps
     - "RENDEZ-VOUS" → prendre RDV
   - Réponse automatique
   - Authentification par numéro WhatsApp

**API Backend** :
- `POST /api/communications/send` - Envoyer message
- `POST /api/communications/send-bulk` - Envoi en masse
- `GET /api/communications` - Historique
- `GET/POST/PUT /api/communication-templates` - CRUD templates
- `GET/PUT /api/users/:id/channel-preference` - Préférences
- `POST /api/whatsapp/webhook` - Webhook WhatsApp

### 2.15 Gestion des Utilisateurs

**URL** : `/settings/users`

**Profils disponibles** :
- SUPER_ADMIN
- Directeur
- Censeur
- Enseignant
- Intendant
- Secrétaire
- Parent
- Élève

**Fonctionnalités** :
1. **Liste des utilisateurs** :
   - DataTable paginée
   - Filtres : rôle, statut (actif/inactif/suspendu)
   - Recherche : nom, email
   - Affichage : nom, email, rôle, statut, dernière connexion

2. **Créer un utilisateur** :
   - URL : `/settings/users/new`
   - Formulaire :
     - Nom, prénom
     - Email (unique)
     - Mot de passe initial
     - Rôle (sélecteur)
     - Statut (actif/inactif/suspendu)
   - Valider
   - Email de bienvenue automatique

3. **Modifier un utilisateur** :
   - URL : `/settings/users/:id/edit`
   - Modifier infos
   - Changer rôle
     - Assigner/retirer permissions
   - Réinitialiser mot de passe
   - Suspendre/Réactiver

4. **Audit des actions** :
   - Historique complet des actions utilisateur
   - Filtrable par date, ressource, action

**API Backend** :
⚠️ **MODULE À IMPLÉMENTER** - Endpoints manquants :
- `GET /api/users` - Liste des utilisateurs
- `GET /api/users/:id` - Détail utilisateur
- `POST /api/users` - Créer utilisateur
- `PUT /api/users/:id` - Modifier utilisateur
- `DELETE /api/users/:id` - Soft delete
- `PUT /api/users/:id/roles` - Assigner rôles

### 2.16 Années Académiques et Périodes

**URL** : `/settings/academic-years`

**Fonctionnalités** :
1. **Créer une année académique** :
   - Libellé (ex: 2026-2027)
   - Date début (ex: 01/09/2026)
   - Date fin (ex: 31/07/2027)
   - Statut (active/closed/archived)
   - Une seule année active à la fois

2. **Créer des périodes** :
   - Type :
     - Séquence (6 par an pour le système camerounais)
     - Trimestre (3 par an)
     - Semestre (2 par an)
   - Numéro (1, 2, 3...)
   - Dates début/fin
   - Statut saisie notes (open/closed/locked)

3. **Gestion du statut** :
   - Ouvrir une période → enseignants peuvent saisir
   - Fermer une période → saisie bloquée, calcul possible
   - Verrouiller → aucune modification possible
   - Déverrouiller (Censeur uniquement)

**API Backend** :
- `GET /api/academic-years` - Liste années
- `GET /api/academic-years/:id` - Détail année
- `POST /api/academic-years` - Créer année
- `PATCH /api/academic-years/:id/activate` - Activer
- `PATCH /api/academic-years/:id/close` - Clôturer
- `GET /api/periods` - Liste périodes
- `POST /api/periods` - Créer période
- `PUT /api/periods/:id/status` - Changer statut

### 2.17 Moteur de Calcul

**URL** : `/settings/calculation-engine`

**Configuration avancée** :
- Type d'évaluation (séquentiel, trimestriel, semestriel, annuel, LMD)
- Pondérations :
  - Séquences : Seq1 × 40% + Seq2 × 60%
  - Trimestres : Trim1 × 30% + Trim2 × 30% + Trim3 × 40%
  - CC/Exam : CC × 40% + Exam × 60%
- Seuils :
  - Passage : 10/20
  - Mentions : AB 12, B 14, TB 16, Excellent 18
  - Compensation LMD : UE validée si ≥ 10
- Arrondis :
  - Dixième (14.3)
  - Centième (14.25)
  - Entier supérieur (15)
  - Entier inférieur (14)
- Gestion absences :
  - Note 0
  - Note neutralisée (exclue du calcul)
  - Justifiable
- Compensation :
  - Activée/Désactivée
  - Seuil minimal par EC
- Classement :
  - Départage ex æquo : plus jeune / tirage au sort / aucun

**Prévisualisation** :
- Saisir notes test
- Voir le calcul en direct
- Vérifier résultats

**API Backend** :
- `GET /api/tenants/:id/config` - Configuration actuelle
- `PUT /api/tenants/:id/config` - Modifier configuration

### 2.18 Journal d'Audit

**URL** : `/settings/audit-logs`

**Fonctionnalités** :
- Liste paginée de toutes les actions
- Colonnes :
  - Date/heure
  - Utilisateur
  - Action (create/read/update/delete)
  - Ressource (Student, Grade, Invoice...)
  - ID de la ressource
  - Ancienne valeur (JSON)
  - Nouvelle valeur (JSON)
  - Adresse IP
- Filtres :
  - Utilisateur
  - Action
  - Ressource
  - Date
- Détail : affichage diff (ancienne vs nouvelle)

**API Backend** :
- `GET /api/audit-logs` - Liste paginée

---

## 3. GUIDE ENSEIGNANT

**Profil** : Enseignant d'une ou plusieurs matières  
**Permissions** : Saisie notes, consultation élèves de ses classes

### 3.1 Tableau de Bord Enseignant

**Widgets** :
- Mes classes (nombre d'élèves total)
- Notes à saisir (nombre de grilles en attente)
- Prochains cours (emploi du temps)

### 3.2 Saisie des Notes

**Accès** :
1. Menu → Notes
2. Sélectionner ma matière
3. Sélectionner la classe
4. Sélectionner la période (séquence/trimestre)
5. Grille de saisie

**Fonctionnalités** :
- Saisie rapide au clavier
- Validation instantanée (note ∈ [0, 20])
- Couleurs automatiques
- Gestion absences
- Sauvegarde automatique
- Verrouillage quand terminé

### 3.3 Consultation Résultats

**Accès** : Menu → Notes → Résultats

**Affichage** :
- Moyennes de mes matières par classe
- Classement des élèves
- Identification élèves en difficulté

### 3.4 Communication Parents

**Accès** : Menu → Communication

**Actions** :
- Envoyer message individuel à un parent
- Envoyer message à tous les parents d'une classe
- Consulter historique

**Permissions** :
❌ Ne peut PAS modifier structure, créer élèves, voir finances

---

## 4. GUIDE PARENT

**Profil** : Parent d'un ou plusieurs élèves  
**Permissions** : Consultation données de ses enfants uniquement

### 4.1 Tableau de Bord Parent

**Widgets** :
- Mes enfants (liste avec photo)
- Dernières notes publiées
- Situation financière
- Messages récents

### 4.2 Consultation Notes

**Accès** : Menu → Élèves → [Nom enfant] → Onglet Notes

**Affichage** :
- Notes par période et par matière
- Moyennes
- Rang dans la classe
- Graphiques d'évolution

**Restrictions** :
- ❌ Notes non publiées invisibles
- ❌ Notes d'autres enfants invisibles (IDOR protection)

### 4.3 Téléchargement Bulletins

**Accès** : Menu → Élèves → [Nom enfant] → Bulletins

**Actions** :
- Consulter les bulletins disponibles
- Télécharger PDF
- Vérifier QR Code d'authenticité

### 4.4 Situation Financière

**Accès** : Menu → Élèves → [Nom enfant] → Paiements

**Affichage** :
- Facture annuelle
- Paiements effectués
- Solde restant
- Prochaine échéance

**Actions** :
- Effectuer un paiement Mobile Money
- Télécharger reçus PDF

### 4.5 Communication

**Accès** : Menu → Messages

**Actions** :
- Lire les messages de l'établissement
- Répondre
- Demander rendez-vous

**Permissions** :
❌ Ne peut PAS voir structure, autres élèves, notes non publiées, finances globales

---

## 5. GUIDE CENSEUR

**Profil** : Responsable pédagogique  
**Permissions** : Gestion notes, calculs, délibérations

### 5.1 Tableau de Bord Censeur

**Widgets** :
- Avancement saisie notes
- Périodes à calculer
- Délibérations à tenir

### 5.2 Suivi Avancement Saisie

**Accès** : Menu → Notes → Avancement

**Affichage** :
- Tableau par classe et matière
- Statut : ✓ Saisi et verrouillé, ⏳ En cours, ✗ Non saisi
- Pourcentage global
- Identification retards

**Actions** :
- Relancer enseignants en retard
- Déverrouiller si nécessaire (exception)

### 5.3 Calcul des Moyennes

**Accès** : Menu → Notes → Calcul

**Workflow** :
1. Vérifier que toutes les notes sont saisies et verrouillées
2. Cliquer "Calculer" pour une classe/période
3. Vérification résultats
4. Validation

### 5.4 Délibérations

**Accès** : Menu → Notes → Délibérations

**Workflow** :
1. Sélectionner classe et période
2. Affichage liste élèves avec moyennes et rangs
3. Pour chaque élève :
   - Saisir décision (passage/redoublement/exclusion)
   - Observations
4. Commentaire professeur principal
5. Valider → enregistrement définitif

### 5.5 Permissions

✅ **Peut** : Déverrouiller notes, calculer les moyennes, délibérer, superviser les présences (lecture + saisie) et traiter les signalements de discipline (lecture + création)  
❌ **Ne peut PAS** : Publier résultats (réservé Directeur), saisir/modifier une note (réservé Enseignant)

---

## 6. GUIDE INTENDANT

**Profil** : Responsable financier  
**Permissions** : Gestion complète finances

### 6.1 Tableau de Bord Financier

**Widgets** :
- Recettes du jour/mois
- Taux de recouvrement
- Impayés
- Trésorerie

### 6.2 Gestion Factures

**Accès** : Menu → Finances → Factures

**Actions** :
1. Générer factures pour une classe
2. Consulter factures impayées
3. Appliquer remises
4. Envoyer relances

### 6.3 Enregistrement Paiements

**Accès** : Menu → Finances → Nouveau paiement

**Workflow** :
1. Rechercher élève
2. Sélectionner facture
3. Saisir montant et canal
4. Valider
5. Génération automatique reçu + mise à jour solde

### 6.4 Rapports Comptables

**Accès** : Menu → Finances → Rapports

**Rapports disponibles** :
- Journal comptable
- Balance
- Grand livre
- Recouvrement par classe/niveau
- Export Excel/PDF

### 6.5 Permissions

✅ **Peut** : Toutes opérations financières, gestion du patrimoine (biens/équipements), gestion transport et cantine  
❌ **Ne peut PAS** : Modifier structure, notes, admissions

---

## 7. GUIDE SECRÉTAIRE

**Profil** : Secrétariat scolarité  
**Permissions** : Gestion dossiers élèves, inscriptions

### 7.1 Inscription Élèves

**Accès** : Menu → Élèves → Nouvel élève

**Workflow** :
- Wizard 6 étapes (voir Guide Super Admin 2.9)

### 7.2 Modification Dossiers

**Actions** :
- Mettre à jour informations
- Upload documents
- Générer attestations

### 7.3 Admissions

**Actions** :
- Enregistrer candidatures
- (Décision réservée Commission/Direction)

### 7.4 Permissions

✅ **Peut** : CRUD élèves, inscriptions, documents, envoi de communications, gestion de la bibliothèque (catalogue et emprunts)  
❌ **Ne peut PAS** : Modifier structure, notes, finances, décisions admission

---

## 8. FAQ ET DÉPANNAGE

### 8.1 Problèmes de Connexion

**Q : Je ne peux pas me connecter**  
R : Vérifier email et mot de passe. Si oublié, contacter l'admin.

**Q : Message "JWT expiré"**  
R : Session expirée après 15 minutes. Se reconnecter.

**Q : Erreur 401 Unauthorized**  
R : Token invalide. Déconnexion + reconnexion.

### 8.2 Problèmes de Saisie

**Q : Je ne peux pas modifier une note**  
R : Période fermée ou notes verrouillées. Contacter le Censeur.

**Q : La sauvegarde automatique ne fonctionne pas**  
R : Vérifier la connexion internet. Réessayer manuellement.

### 8.3 Problèmes d'Affichage

**Q : Je ne vois pas la sidebar**  
R : Sur mobile, cliquer sur le menu hamburger. Sur desktop, agrandir la fenêtre (>768px).

**Q : Les widgets du dashboard sont vides**  
R : Fonctionnalité en cours d'implémentation. Les données arrivent prochainement.

### 8.4 Problèmes de Permissions

**Q : Message "403 Forbidden"**  
R : Vous n'avez pas la permission pour cette action. Contacter l'admin.

**Q : Je ne vois pas certaines pages**  
R : Normal, l'affichage est filtré selon votre rôle.

### 8.5 Support Technique

**Email** : support@scholaris.cm  
**WhatsApp** : +237 6XX XX XX XX  
**Heures** : Lundi-Vendredi 8h-18h  

---

## ANNEXES

### A. Raccourcis Clavier

- `Tab` : Cellule suivante (saisie notes)
- `Shift+Tab` : Cellule précédente
- `Enter` : Ligne suivante
- `Ctrl+S` : Sauvegarder (formulaires)
- `Esc` : Fermer modal

### B. Format Matricule

**Format** : `CODE_ETABLISSEMENT/ANNEE/NUMERO`  
**Exemple** : DEMO/2026/0001, LBD/2026/0542  
**Configurable** : dans tenant.configJson

### C. Canaux de Communication

| Canal | Provider | Limite |
|-------|----------|--------|
| Email | Brevo/SendGrid | 1000/heure |
| SMS | Africa's Talking | 100/minute |
| WhatsApp | Meta Cloud API | Selon quota |
| Push | Firebase FCM | Illimité |

### D. Codes Erreur API

| Code | Signification |
|------|---------------|
| 400 | Bad Request (données invalides) |
| 401 | Unauthorized (JWT manquant/invalide) |
| 403 | Forbidden (permissions insuffisantes) |
| 404 | Not Found (ressource inexistante) |
| 409 | Conflict (doublon, contrainte) |
| 429 | Too Many Requests (rate limit) |
| 500 | Internal Server Error |

---

**FIN DU GUIDE D'UTILISATION**

Pour toute question complémentaire, consulter la documentation technique ou contacter le support.
