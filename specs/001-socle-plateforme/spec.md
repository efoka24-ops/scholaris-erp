# Feature Specification: Socle commun de la plateforme SCHOLARIS

**Feature Branch**: `001-socle-plateforme` (branche non créée : travail sur `preprod`)
**Created**: 2026-10-08
**Status**: Draft
**Input**: `CDC_Plateforme_SCHOLARIS.pdf` v1.0 — 14 services du socle (S01 à S14), interface unique, lots L0 à L3. L'application Supérieur (IPES) a son propre cahier des charges et fera l'objet d'une spécification séparée ; ce document couvre le socle qu'elle consomme.

## Périmètre

- **Inclus** : S01 à S14, API `/api/v1`, interface unique (lanceur, sélecteur établissement/rôle, menus dynamiques), portails parent/apprenant/enseignant (PWA).
- **Exclus** : règles métier propres aux applications Maternelle, Primaire, Secondaire, Supérieur ; application mobile native (décision ouverte, PWA retenue par défaut).
- **Hypothèse de recette** : MySQL 8 sur Camoo ; cible PostgreSQL 16 (voir plan).

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Se connecter et naviguer selon ses rôles (Priority: P1) 🎯 MVP

Une personne se connecte avec téléphone ou e-mail, active son compte par code à usage unique, et voit uniquement les établissements, applications, menus et données de ses rôles. Elle change d'établissement ou de rôle sans se reconnecter.

**Why this priority**: Aucune autre fonction n'est utilisable ni testable sans identité, rôles et cloisonnement. C'est la barrière de sécurité de tout le reste.

**Independent Test**: Créer deux clients, chacun avec un établissement et un utilisateur ; vérifier que chacun se connecte, voit ses seuls établissements, change de rôle, et reçoit 403/404 sur toute ressource de l'autre client.

**Acceptance Scenarios**:
1. **Given** un compte créé, **When** la personne saisit le code reçu par SMS ou e-mail, **Then** le compte est activé et un mot de passe conforme (10 car. min., non compromis) est défini.
2. **Given** 5 échecs de connexion, **When** une 6e tentative est faite, **Then** le compte est verrouillé temporairement et le titulaire est alerté.
3. **Given** un rôle sensible (direction, finances, notes, administration), **When** la personne se connecte, **Then** une 2FA TOTP ou SMS est exigée.
4. **Given** une personne avec rôles dans deux établissements, **When** elle utilise le sélecteur, **Then** menus et données changent sans reconnexion.
5. **Given** une action interdite forcée par appel direct, **Then** le serveur la refuse (403) même si l'interface ne l'affiche pas.
6. **Given** un utilisateur du client A, **When** il appelle une ressource du client B, **Then** la réponse est 403 ou 404.

### User Story 2 — Organiser un client, ses établissements et son année (Priority: P1)

L'administrateur crée un client (groupe), ses établissements typés, unités internes, structures pédagogiques, années et périodes, et règle l'identité visuelle et les paramètres à trois niveaux.

**Why this priority**: Toute donnée métier se rattache à cette hiérarchie.

**Independent Test**: Créer un groupe avec 3 établissements de types différents ; surcharger un paramètre au niveau établissement et vérifier héritage et traçabilité.

**Acceptance Scenarios**:
1. **Given** un client, **When** on crée un établissement, **Then** il a un et un seul type qui détermine l'application qui le gère.
2. **Given** un paramètre défini au niveau client, **When** l'établissement le surcharge, **Then** la valeur effective change et la surcharge est visible et tracée.
3. **Given** une structure sans autorisation administrative valide, **When** on tente une inscription, **Then** elle est refusée.
4. **Given** deux établissements d'un groupe, **When** l'un clôture son année, **Then** l'autre n'est pas affecté.

### User Story 3 — Gérer les personnes, relations et inscriptions (Priority: P1)

Création d'une fiche personne unique par client (détection de doublons), relations parent/tuteur/garant/personne autorisée, dossier documentaire, consentements, inscription dans un établissement.

**Independent Test**: Créer un parent et deux enfants dans deux établissements du groupe ; le parent apparaît une seule fois ; une création en doublon est signalée.

**Acceptance Scenarios**:
1. **Given** une fiche existante (même nom, date de naissance, téléphone), **When** on en crée une quasi identique, **Then** un doublon est signalé.
2. **Given** un consentement révoqué, **When** une communication est préparée, **Then** elle est bloquée pour cette personne.
3. **Given** un étudiant majeur sans consentement, **Then** ses parents n'accèdent pas à ses données.

### User Story 4 — Facturer et encaisser les frais (Priority: P1)

Catalogue de frais, grilles par niveau, échéanciers, remises, paiement mobile money, espèces ou virement, affectation automatique, reçu numéroté sans trou, caisse, annulation par écriture inverse validée par un second agent.

**Independent Test**: Un parent a deux enfants dans deux établissements ; un paiement unique est réparti automatiquement (retards d'abord, puis échéances les plus anciennes) ; le reçu porte un numéro continu par établissement et par année.

**Acceptance Scenarios**:
1. **Given** un paiement rejoué avec la même `Idempotency-Key`, **Then** un seul encaissement est enregistré.
2. **Given** un paiement mobile money confirmé par webhook signé, **Then** il est affecté et un reçu PDF et SMS est émis.
3. **Given** une annulation demandée, **When** aucun second validateur n'a approuvé, **Then** elle reste en attente.
4. **Given** un impayé, **When** un blocage est configuré sur les résultats, **Then** les résultats sont masqués mais jamais l'accès aux cours.
5. **Given** les relevés de l'opérateur, **When** on lance le rapprochement, **Then** les écarts sont listés.

### User Story 5 — Communiquer et notifier (Priority: P2)

Envoi SMS, WhatsApp, e-mail et notifications app à partir de modèles bilingues, ciblage (personne, classe, niveau, impayés, absents du jour), respect du consentement et des plages 7 h–21 h, comptage des SMS par établissement.

**Independent Test**: Cibler les impayés d'un établissement ; vérifier que seuls les consentants reçoivent le message, hors urgence jamais avant 7 h ni après 21 h, et que le compteur SMS est incrémenté.

### User Story 6 — Émettre des documents officiels vérifiables (Priority: P2)

Modèles par établissement et par langue éditables sans développement, PDF à l'unité et en lot (file), numéro unique et QR renvoyant vers une page publique de vérification, registre des émissions, révocation.

**Independent Test**: Générer 50 documents d'une classe en lot ; scanner le QR d'un document ; la page publique confirme sa validité, puis après révocation indique « révoqué ».

### User Story 7 — Calendrier, emplois du temps, présences (Priority: P2)

Calendrier de l'établissement, emploi du temps par classe, enseignant et salle avec détection de conflits ; appel par séance hors-ligne ; absences, retards, justificatifs ; notification des parents le jour même.

**Independent Test**: Créer deux cours en conflit pour un même enseignant (refusé) ; faire l'appel hors-ligne puis synchroniser ; le parent est notifié.

### User Story 8 — Évaluer : notes, calculs, délibérations (Priority: P2)

Référentiel d'évaluation, saisie de notes en grille, import Excel, hors-ligne ; cycle saisie → validation (verrouillage) → publication ; correction après verrouillage par demande motivée approuvée ; calculs déclaratifs versionnés par année ; conseils de classe ou délibérations avec PV ; historique complet de chaque note.

**Independent Test**: Saisir une note hors bornes (rejetée), valider et verrouiller, demander une correction (second validateur), vérifier l'historique et le recalcul de la moyenne.

### User Story 9 — Personnel, vacations et paie simplifiée (Priority: P3)

Dossier du personnel multi-établissements, vacations issues du cahier de textes et de l'émargement, validation des heures avant paiement, congés, paie simplifiée (Could).

### User Story 10 — Portails, tableaux de bord, audit et intégrations (Priority: P2/P3)

Espaces parent (tous les enfants, situation financière, paiement), apprenant, enseignant ; tableaux de bord par rôle et vue consolidée du groupe ; journal d'audit inaltérable, export des données d'une personne, export complet d'un client, clôture d'année ; intégrations (mobile money, SMS, e-mail, WhatsApp, cartescolaire.cm, tutelle, comptabilité).

### Edge Cases

- Un parent ou enseignant dont les rôles couvrent plusieurs clients : un compte par client (RG-ORG-3).
- Paiement reçu pour un montant supérieur à la dette : le surplus est conservé en crédit, jamais perdu.
- Webhook de paiement rejoué ou dans le désordre : traitement idempotent, signature et IP vérifiées.
- Coupure réseau de 3 jours : les saisies hors-ligne se synchronisent sans doublon ni écrasement silencieux (conflit signalé).
- Note modifiée après verrouillage sans approbation : refusée et journalisée.
- Numérotation des reçus en concurrence : aucun trou, aucun doublon.
- Perte de l'appareil du second facteur : codes de secours et révocation de toutes les sessions.

## Requirements *(mandatory)*

### Functional Requirements

Les identifiants renvoient au CDC (priorité Must/Should/Could entre parenthèses).

- **FR-001** : Compte unique par personne et par client, identifiant téléphone ou e-mail, activation par OTP (EX-S01-01/02, Must).
- **FR-002** : Mots de passe Argon2id, 10 caractères minimum, contrôle des fuites ; verrouillage après 5 échecs ; alerte nouvel appareil (EX-S01-03/07, Must).
- **FR-003** : 2FA obligatoire pour les rôles sensibles (EX-S01-04, Must).
- **FR-004** : Rôles = rôle + périmètre (client, établissement, unité, classe), cumulables ; changement sans reconnexion ; sessions et jetons révocables ; comptes de service à clés tournantes (EX-S01-05/06/08/10, Must) ; délégation temporaire (EX-S01-09, Should).
- **FR-005** : Gestion clients, établissements typés, unités internes, structures, années, périodes, jours fériés, identité visuelle, paramètres à trois niveaux avec surcharge tracée, registre des autorisations, activation des modules par contrat (EX-S02-01 à 07).
- **FR-006** : Fiche personne unique, relations, matricules (interne et national), dossier documentaire, doublons, consentements, historique de scolarité ; santé restreinte (Should) (EX-S03).
- **FR-007** : Catalogue de frais, grilles, échéanciers, remises et bourses, paiements multi-canaux, référence unique, affectation paramétrable, reçu numéroté sans trou, relances, blocages paramétrables, caisse, annulation par écriture inverse validée, export comptable, rapprochement mobile money (EX-S04-01 à 13).
- **FR-008** : Envoi multi-canaux par modèles bilingues, ciblage, respect du consentement et des plages horaires, suivi de délivrance et comptage SMS (EX-S05-01/02/03/06 Must ; 04/05 Should).
- **FR-009** : Modèles de documents éditables, PDF unitaire et en lot en file, numéro et QR avec page publique de vérification, registre et révocation, exports Excel/CSV (EX-S06-01 à 04, 07 Must ; 05/06 Should).
- **FR-010** : Calendrier, emplois du temps avec détection de conflits (EX-S07-01/02 Must ; 03 Should ; 04 Could).
- **FR-011** : Appel hors-ligne, absences, retards, justificatifs, notifications le jour même (EX-S08-01/02/03 Must ; 04 Should ; 05 Could).
- **FR-012** : Moteur d'évaluation : référentiel, épreuves pondérées, saisie grille/import/hors-ligne, cycle de vie à verrouillage, règles déclaratives versionnées, délibérations, historique des notes ; anonymat (Should) (EX-S09).
- **FR-013** : Dossier du personnel, vacations, validation avant paiement (Must) ; congés (Should) ; paie simplifiée (Could) (EX-S10).
- **FR-014** : PWA et portails parent, apprenant, enseignant, hors-ligne, utilisables en 3G sur smartphone d'entrée de gamme (EX-S11).
- **FR-015** : Tableaux de bord par rôle, vue groupe, statistiques réglementaires et rapports planifiés (EX-S12).
- **FR-016** : Journal d'audit inaltérable chaîné, double validation des opérations sensibles, registre des traitements, export et suppression des données d'une personne, export complet d'un client, clôture d'année (EX-S13).
- **FR-017** : Intégrations : mobile money via agrégateur, SMS, e-mail (Must) ; WhatsApp, cartescolaire.cm, tutelle, comptabilité (Should) ; Moodle (Could) (EX-S14).
- **FR-018** : Interface unique : lanceur, sélecteur établissement/rôle, menus dynamiques, vocabulaire par application, identité visuelle de l'établissement, parent multi-enfants, français et anglais (RG-IHM-1 à 9).
- **FR-019** : API conforme à la partie « Conception des API » : `/api/v1`, `X-Establishment-Id`, curseur, Problem Details, `Idempotency-Key`, `Accept-Language`, OpenAPI 3.1.

### Key Entities

`tenants`, `establishments`, `org_units`, `academic_years`, `periods`, `people`, `person_relations`, `role_assignments`, `enrollments`, `fee_items`, `fee_schedules`, `installments`, `payments`, `allocations`, `receipts`, `documents`, `assessments`, `grades`, `grade_events`, `audit_log` (détail dans `data-model.md`).

## Success Criteria *(mandatory)*

- **SC-001** : 100 % des routes métier renvoient 403/404 dans la suite d'isolation inter-clients et inter-établissements.
- **SC-002** : 95 % des appels API répondent en moins de 300 ms hors génération de documents.
- **SC-003** : 1 000 bulletins ou relevés sont générés en moins de 10 minutes.
- **SC-004** : Aucun trou ni doublon dans la numérotation des reçus lors d'un test de 100 encaissements concurrents.
- **SC-005** : Un paiement mobile money confirmé est affecté et son reçu envoyé en moins de 60 secondes.
- **SC-006** : Appel, notes et encaissements saisis hors-ligne pendant 3 jours se synchronisent sans perte.
- **SC-007** : Les pages principales s'affichent en moins de 2 s en 3G.
- **SC-008** : Export complet d'un client en moins de 48 h ; journal d'audit vérifiable par recalcul des empreintes sans rupture de chaîne.
- **SC-009** : Larastan sans erreur au niveau visé, `composer audit` sans vulnérabilité élevée, test d'architecture vert.

## Assumptions

- Le front sera décidé en cadrage (Next.js déjà amorcé ou Vue/Nuxt) ; le plan d'API-first n'en dépend pas.
- L'agrégateur de paiement n'est pas choisi : l'intégration passe par une interface (port) avec un simulateur pour la recette.
- La passerelle SMS réelle n'est pas choisie : même approche (port + simulateur).
- Les établissements pilotes et le nom commercial sont hors périmètre de cette spécification.
- Le code NestJS/Next.js existant n'est pas migré ; seuls rôles, périmètres et cloisonnement sont repris.

## Questions ouvertes (reprises du CDC)

- Front-end : Next.js ou Vue/Nuxt ?
- Application mobile native en v1 ou PWA seule ?
- Hébergement de production au Cameroun et site de secours ?
- Agrégateur de paiement au démarrage ?
- Établissements pilotes ?
