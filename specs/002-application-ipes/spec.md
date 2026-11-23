# Feature Specification: Application Supérieur (IPES)

**Feature Branch**: `002-application-ipes` (branche non créée : travail sur `preprod`)

**Created**: 2026-10-08

**Status**: Draft

**Input**: User description: « Application Supérieur (IPES) de la plateforme SCHOLARIS, d'après CDC_Application_IPES.pdf v1.0 du 8 octobre 2026 : modules ACA, ADM, INS, MAQ, EDT, ENS, PRE, NOT, DEL, FIN, STG, EXN, TUT, DOC (32 documents), POR, PIL ; 23 rôles R01-R23 ; règles LMD/BTS/HND ; lots I1 à I4 »

**Dépend de** : [001-socle-plateforme](../001-socle-plateforme/spec.md) (comptes, organisation, personnes, frais et paiements, communication, documents, emplois du temps, présences, évaluation, personnel, portails, audit, intégrations). Maquettes de référence : [design.md](../001-socle-plateforme/design.md).

## Périmètre

- **Inclus** : paramétrage académique (écoles, départements, filières, autorisations, tutelles), admissions et concours, inscriptions, maquettes de formation, emplois du temps et salles, enseignants et vacations, présences et discipline, évaluations et notes, calculs, rattrapage et délibérations, frais de scolarité, stages et soutenances, candidatures aux examens nationaux BTS et HND, transmissions à l'université de tutelle, 32 documents imprimables, portails (étudiant, garant, enseignant, jury, tutelle), tableaux de bord et statistiques MINESUP.
- **Option** : bibliothèque (lot I4, hors version 1 par défaut).
- **Exclu** : délivrance du diplôme d'État (université de tutelle), organisation de l'examen national (MINESUP), plateforme de cours en ligne complète (intégration prévue), comptabilité générale (export seulement), résidences et restauration, paie complète du personnel permanent.
- **Valeurs par défaut** : tous les seuils et formules de calcul de ce document sont des paramètres par défaut, remplacés en cadrage par le règlement de l'université de tutelle et la politique de l'institut.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Ouvrir une filière autorisée et ses maquettes (Priority: P1) 🎯 MVP

L'administrateur et la direction académique créent les écoles internes, départements, filières (avec régime LMD, BTS, HND ou certificat), enregistrent les autorisations MINESUP et les tutelles, publient les maquettes de formation (unités d'enseignement, éléments, crédits, coefficients, volumes) et fixent le calendrier académique et le règlement des études.

**Why this priority**: Aucune candidature, inscription ni note n'est possible sans filière autorisée et sans maquette. C'est aussi la protection contre les sanctions du MINESUP (filières non autorisées).

**Independent Test**: Créer une école, un département, une filière LMD avec autorisation, tutelle pour l'année et une maquette équilibrée ; vérifier qu'une filière sans autorisation valide ne peut ni ouvrir de candidatures ni recevoir d'inscription.

**Acceptance Scenarios**:
1. **Given** une filière sans autorisation valide pour le niveau et l'année, **When** la scolarité tente d'ouvrir une campagne ou d'inscrire un étudiant, **Then** l'action est refusée avec un motif explicite, et reste bloquée tant qu'une autorisation n'est pas enregistrée.
2. **Given** une filière LMD sans université de tutelle pour l'année, **When** une inscription est tentée, **Then** elle est refusée.
3. **Given** une maquette LMD de 28 crédits pour un semestre, **When** on tente de la publier, **Then** la publication est refusée car le semestre doit totaliser 30 crédits (valeur réglable).
4. **Given** une maquette publiée dont des notes ont été saisies, **When** on la modifie, **Then** une nouvelle version est créée ; les étudiants restent sur la version de leur année d'inscription.
5. **Given** une autorisation ou une convention de tutelle qui expire dans 90, 30 ou 7 jours, **Then** la direction est alertée par e-mail.
6. **Given** un règlement des études figé à l'ouverture des inscriptions, **When** il est modifié, **Then** la validation du directeur académique est exigée et la modification est tracée.

### User Story 2 — Admettre et inscrire un étudiant (Priority: P1)

Un candidat dépose son dossier en ligne ou au guichet, paie les frais de dossier par mobile money, est évalué (dossier, concours ou mixte), reçoit une décision motivée, puis la scolarité l'inscrit : matricule, garant, contrat pédagogique, première tranche de frais.

**Why this priority**: C'est l'entrée de tous les étudiants et la première source de revenus de l'institut.

**Independent Test**: Parcourir candidature, pièces vérifiées, décision « admis », inscription administrative et pédagogique avec paiement de l'inscription, jusqu'à l'attribution d'un matricule unique et d'une carte d'étudiant.

**Acceptance Scenarios**:
1. **Given** un candidat dont le diplôme exigé n'est pas justifié par une pièce vérifiée, **When** le directeur d'école tente de l'admettre, **Then** la décision « admis » est impossible.
2. **Given** une décision « admis sous réserve de pièce » et une date limite dépassée (30 jours par défaut), **Then** la décision devient « refusé » et le candidat est notifié.
3. **Given** des places ouvertes épuisées, **When** une admission supplémentaire est demandée, **Then** elle exige une décision tracée du directeur d'école.
4. **Given** une inscription, **Then** le matricule est unique, définitif et jamais réattribué (format par défaut : année, code école, code filière, numéro d'ordre sur 4 chiffres).
5. **Given** un étudiant inscrit en niveau N, **When** il se réinscrit en N+1, **Then** la décision de passage de la délibération de l'année N est exigée ; les UE capitalisées sont reportées et les UE en dette inscrites d'office.
6. **Given** un doublon suspecté (même nom, date de naissance, téléphone), **Then** la scolarité compare les fiches avant de créer l'étudiant.
7. **Given** un étudiant majeur, **Then** il décide du partage de ses notes et absences avec son garant ; le garant voit toujours la situation financière qu'il paie.

### User Story 3 — Encaisser les frais de scolarité (Priority: P1)

La comptabilité paramètre les grilles par école, filière, niveau et mode ; l'étudiant ou le garant paie par mobile money, espèces, virement ou carte ; le paiement est affecté automatiquement, un reçu numéroté est émis, les relances partent aux échéances et les blocages (notes, documents, réinscription) restent paramétrables.

**Why this priority**: Objectifs O3 et O4 : réduire les impayés et digitaliser 70 % des paiements. La caisse est le premier écran métier de la maquette.

**Independent Test**: Reprendre l'exemple du cahier des charges (inscription 50 000 + pension 450 000 avec bourse de 20 % en 3 tranches) : un paiement de 170 000 FCFA solde l'inscription et la tranche 1 et émet un reçu.

**Acceptance Scenarios**:
1. **Given** pension 450 000 et bourse de mérite de 20 %, **Then** la pension nette est de 360 000 en trois tranches de 120 000.
2. **Given** un paiement de 170 000 le 10 octobre, **Then** 50 000 soldent l'inscription, 120 000 soldent la tranche 1 et le reçu est émis par SMS et PDF.
3. **Given** une tranche dont la date limite est passée, **Then** elle est « en retard » dès le lendemain et les relances partent à J-7, J+1 et J+15 (valeurs réglables).
4. **Given** un impayé et un blocage configuré, **Then** les notes, certains documents ou la réinscription sont bloqués, mais jamais l'accès aux cours ni la composition, et jamais le certificat de scolarité, le reçu, le relevé de compte ni l'attestation de paiement.
5. **Given** un étudiant non à jour des frais d'examen national, **Then** il n'est pas inscrit sur la liste des candidats BTS/HND.
6. **Given** un remboursement, **Then** il est enregistré par écriture inverse motivée et validé par un second agent.
7. **Given** un quitus financier demandé avec un solde non nul, **Then** il n'est pas émis.

### User Story 4 — Faire cours : emplois du temps, présences, cahier de textes, notes (Priority: P2)

Le chef de département affecte les enseignements ; la scolarité construit l'emploi du temps sans conflit ; l'enseignant fait l'appel (liste ou QR), renseigne le cahier de textes et fait émarger la séance ; il saisit les notes (téléphone, ordinateur, hors-ligne, import Excel) ; le chef de département valide et verrouille ; les corrections passent par une demande motivée.

**Why this priority**: C'est la matière première des résultats, des vacations et de l'assiduité (objectifs O1 et O5).

**Independent Test**: Un enseignant affecté fait l'appel hors-ligne, saisit les notes d'une évaluation, les soumet ; le chef de département les valide et les verrouille avec double authentification ; une correction sans procédure est refusée.

**Acceptance Scenarios**:
1. **Given** un enseignant non affecté à un EC, **When** il tente de saisir ses notes, **Then** l'accès est refusé (403) et la tentative est journalisée.
2. **Given** une note hors barème (0 à 20, au quart de point), **Then** elle est refusée.
3. **Given** une note verrouillée, **When** on tente de la modifier sans procédure, **Then** l'opération est refusée et la valeur d'origine reste inchangée ; une correction exige une demande motivée avec pièce, validée par le chef de département puis le directeur d'école.
4. **Given** celui qui a saisi une note, **Then** il ne peut pas la verrouiller ; celui qui demande une correction ne peut pas la valider.
5. **Given** un étudiant dont les absences non justifiées dépassent 30 % des séances d'un EC, **Then** il n'est pas autorisé à composer cet EC et reçoit 0, avec rattrapage possible selon le règlement.
6. **Given** deux séances en conflit (enseignant, salle, groupe ou capacité), **Then** l'emploi du temps est refusé.
7. **Given** un appel fait hors-ligne, **Then** il se synchronise au retour du réseau et les parents ou garants consentants sont notifiés à la synchronisation.
8. **Given** les codes d'absence ABJ, ABI, NA, FR, **Then** ABI et NA comptent pour 0, ABJ ouvre droit à une épreuve de remplacement ou au rattrapage, FR entraîne 0 et la saisine du conseil de discipline.

### User Story 5 — Calculer, délibérer et publier les résultats (Priority: P2)

Le moteur calcule notes d'EC, moyennes d'UE, de semestre et d'année, crédits, lettres, points et décisions selon le règlement de la filière ; la scolarité simule la délibération ; le jury (quorum, décisions motivées, points de jury plafonnés) signe le procès-verbal ; seulement alors les résultats sont publiés.

**Why this priority**: Objectif O2 (publier en moins de 10 jours) et O1 (zéro note modifiée sans trace). C'est le cœur de la valeur métier.

**Independent Test**: Reprendre l'exemple « Marie N. » (Licence 1 Comptabilité et finance, semestre 1) avec deux règlements différents et vérifier les résultats exacts attendus.

**Acceptance Scenarios**:
1. **Given** les notes de l'exemple et une note éliminatoire de 7, **Then** moyenne de semestre 11,55 ; MGP 2,40 ; 30 crédits ; l'UE2 (7,10) est « acquise par compensation » ; mention Passable.
2. **Given** les mêmes notes, une note éliminatoire de 8 et un rattrapage à 11 sur l'EC de mathématiques financières, **Then** note d'EC 9,80 ; moyenne de semestre 12,09 ; 30 crédits ; mention Assez bien.
3. **Given** un total de 60 crédits, **Then** « admis » ; de 45 à 59, « admis avec dettes » ; moins de 45, « redouble » avec capitalisation des UE acquises ; deuxième redoublement du même niveau, « exclu pour insuffisance » sauf dérogation.
4. **Given** des notes non toutes verrouillées, **Then** la délibération ne peut pas s'ouvrir.
5. **Given** un PV non signé par le président du jury, **Then** la publication des résultats est refusée et les relevés ne peuvent pas être émis.
6. **Given** des points de jury au-delà du plafond (0,5 point par défaut), **Then** ils sont refusés ; accordés, ils sont motivés et listés nominativement dans le PV.
7. **Given** une délibération signée, **Then** notes et décisions sont figées ; seule une réouverture motivée par le directeur académique, avec nouveau PV, peut les modifier.
8. **Given** les cas à moins de 0,5 point d'un seuil, **Then** la simulation les met en évidence.
9. **Given** une filière BTS ou HND, **Then** les matières sont évaluées par coefficients (note de matière = 0,4 × devoirs + 0,6 × examen), passage en 2ᵉ année si la moyenne annuelle est au moins égale à 10, et le rang est calculé dans le groupe.

### User Story 6 — Émettre des documents officiels vérifiables (Priority: P2)

La scolarité et la comptabilité émettent les 32 documents du catalogue (accusé de candidature, convocation, lettre d'admission, carte d'étudiant, certificat de scolarité, relevés, attestations, PV, convention de stage, contrat et état de vacation, quitus, etc.) avec numéro unique et QR de vérification pour les documents officiels.

**Why this priority**: Les faux relevés et diplômes sont la première cause de sanction ; les documents vérifiables sont la réponse.

**Independent Test**: Émettre un relevé de notes semestriel après signature du PV ; scanner son QR (page publique « valide », sans notes) ; l'annuler et vérifier « annulé » avec le motif.

**Acceptance Scenarios**:
1. **Given** un relevé, une attestation ou une liste de résultats, **When** le PV correspondant n'est pas signé, **Then** l'émission est refusée.
2. **Given** un document émis, **Then** son numéro est unique et non réutilisable (exemple : `REL-EC-2026-000315`).
3. **Given** le QR d'un document, **Then** la page publique affiche type, nom de l'étudiant, date et statut (valide, annulé, remplacé) sans afficher les notes.
4. **Given** une réimpression, **Then** elle porte la mention « Duplicata », la date de réimpression et le numéro d'origine.
5. **Given** un document émis par erreur, **Then** il est annulé, jamais supprimé.
6. **Given** un document réimprimé, **Then** il utilise la version de modèle de son émission, en français ou en anglais selon la langue d'enseignement de la filière.
7. **Given** le diplôme BTS ou HND, **Then** l'application n'émet jamais un document intitulé « BTS » ou « HND » ; elle émet une attestation de fin de formation.

### User Story 7 — Payer et contrôler les vacations (Priority: P3)

Les affectations d'enseignement génèrent le contrat de vacation ; les heures émargées, contre-signées et reportées au cahier de textes sont validées chaque mois par le chef de département, converties en heures équivalent TD, payées par virement ou mobile money avec bulletin de vacation.

**Why this priority**: Objectif O5 : zéro heure payée sans émargement validé.

**Independent Test**: Reprendre l'exemple (enseignant vacataire, 18 h CM + 12 h TD + 6 h TP, taux 6 000 FCFA par HETD) : 43,5 HETD et 261 000 FCFA bruts.

**Acceptance Scenarios**:
1. **Given** une séance non émargée, non contre-signée ou sans cahier de textes, **Then** l'heure n'est pas payable.
2. **Given** les heures de l'exemple, **Then** 43,5 HETD et 261 000 FCFA bruts (1 CM = 1,5 HETD, 1 TD = 1, 1 TP = 0,75 ; réglables).
3. **Given** des heures au-delà du volume de la maquette, **Then** elles ne sont payées qu'après accord écrit du directeur d'école.
4. **Given** un EC dont les notes ne sont pas complètement saisies, **Then** le solde des vacations de cet EC est retenu (paramètre activé par défaut).

### User Story 8 — Stages, mémoires et soutenances (Priority: P3)

Le responsable des stages gère conventions tripartites, suivi par tuteur et encadreur, dépôt du mémoire, planning des soutenances et jury, saisie des notes et PV de soutenance.

**Independent Test**: Programmer une soutenance uniquement lorsque la convention est signée, l'attestation de stage déposée, le mémoire déposé et le quitus financier émis.

**Acceptance Scenarios**:
1. **Given** une des quatre conditions manquantes, **Then** la soutenance ne peut pas être programmée.
2. **Given** un jury de moins de trois membres (réglable) ou présidé par l'encadreur, **Then** il est refusé.

### User Story 9 — Candidats aux examens nationaux BTS et HND et transmissions à la tutelle (Priority: P3)

La scolarité constitue la liste des candidats à partir des seules inscriptions valides de l'année de sortie, contrôle assiduité, frais et pièces, exporte les dossiers aux formats MINESUP, importe les résultats ; pour les filières LMD, elle transmet listes, notes, relevés et PV à l'université de tutelle et suit les dossiers de diplôme.

**Independent Test**: Tenter d'ajouter un étudiant non inscrit en année de sortie à la liste HND (refusé), puis exporter la liste ; toute modification après export est tracée et signalée au directeur général.

**Acceptance Scenarios**:
1. **Given** un étudiant sans inscription valide en année de sortie dans la spécialité autorisée, **Then** il ne peut pas figurer sur la liste ; un candidat externe est impossible.
2. **Given** une assiduité inférieure à 70 % (BTS/HND, réglable), des frais d'examen impayés ou des pièces incomplètes, **Then** l'étudiant n'est pas éligible.
3. **Given** un dossier de diplôme LMD, **Then** il n'est transmis qu'après signature du PV de délibération finale et émission du quitus financier.
4. **Given** une attestation de réussite LMD, **Then** elle mentionne l'université de tutelle et précise que le diplôme est délivré par elle.

### User Story 10 — Piloter l'institut et consulter par portail (Priority: P2)

La direction voit un tableau de bord consolidé (étudiants, recouvrement par école et par canal, assiduité, vacations, avancement de la saisie, taux de réussite, résultats nationaux, statistiques MINESUP) ; l'étudiant, le garant, l'enseignant, le membre de jury et l'université de tutelle ont chacun un portail adapté ; l'auditeur externe consulte en lecture seule pendant une durée limitée.

**Independent Test**: Se connecter avec six rôles différents et vérifier que chacun ne voit que son périmètre (école, département, filière, groupes, ses propres données).

**Acceptance Scenarios**:
1. **Given** un utilisateur limité à une école, **Then** le sélecteur « École » n'apparaît pas ; un utilisateur multi-écoles voit « Toutes les écoles ».
2. **Given** un étudiant, **Then** il ne voit ses notes qu'après publication par la scolarité et sans blocage financier actif.
3. **Given** l'université de tutelle ou un auditeur externe, **Then** son compte est nominatif, en lecture seule, limité à ses filières ou à une durée, avec journal des consultations.
4. **Given** un étudiant en double cursus, **Then** il garde un seul compte et un seul matricule et voit ses deux parcours.

### Edge Cases

- Changement de régime d'une filière : refusé, on crée une nouvelle filière.
- Changement de tutelle en cours d'année : les années passées ne sont pas modifiées.
- Étudiant ayant abandonné après la date limite de remboursement : aucun remboursement ; avant la date, selon la grille de l'école.
- Exclusion définitive : exige le procès-verbal signé du conseil de discipline et clôt l'inscription au statut « exclu ».
- Deux écoles internes avec règlements différents : chaque école surcharge les paramètres de l'établissement, la surcharge est visible et tracée.
- Pression pour modifier une note après délibération : seule la réouverture motivée par le directeur académique, avec double validation et alerte au directeur général, le permet.
- Reprise de données antérieures incomplètes : limitée aux années en cours et aux UE capitalisées.
- Coupure réseau pendant la saisie des notes : conflit de version signalé, jamais résolu automatiquement.

## Requirements *(mandatory)*

### Functional Requirements

Les exigences reprennent les identifiants du cahier des charges IPES (Must par défaut ; priorité indiquée sinon).

**Paramétrage académique (ACA)**
- **FR-001** : Créer écoles internes, départements, filières, spécialités, niveaux et groupes ; associer régime, langue, mode (jour, soir, alternance) et durée (EX-ACA-01/02).
- **FR-002** : Registre des autorisations (arrêté, date, niveaux, fin, document) et des tutelles (université, filières, années, convention) ; blocage des candidatures et inscriptions sans autorisation ou tutelle valide (EX-ACA-03/04, RG-ACA-1/2).
- **FR-003** : Règlement des études par régime et par école, figé à l'ouverture des inscriptions ; calendrier académique ; alertes d'expiration à 90/30/7 jours (EX-ACA-05/06/07, RG-ACA-3).

**Admissions (ADM)**
- **FR-004** : Campagnes par filière et niveau, candidature en ligne ou au guichet, frais de dossier par mobile money, contrôle bloquant des prérequis, admission sur dossier, concours ou mixte, décision motivée notifiée (EX-ADM-01 à 04, 06 ; Should : concours complet, transferts, statistiques EX-ADM-05/07/08).
- **FR-005** : Règles d'admission : pièce vérifiée obligatoire, expiration de « admis sous réserve », plafond des places, frais de dossier non remboursables (RG-ADM-1 à 4).

**Inscriptions (INS)**
- **FR-006** : Inscription administrative et pédagogique, matricule unique et définitif, réinscription en ligne, statuts, carte d'étudiant, consentement de partage avec le garant ; changement de filière ou groupe (Should) ; gel d'inscription (Could) (EX-INS-01 à 08, RG-INS-1 à 7).

**Maquettes (MAQ)**
- **FR-007** : Maquettes versionnées par filière, niveau et année (UE, EC, crédits, coefficients, CM, TD, TP, travail personnel), types d'UE, rattachement aux épreuves nationales (BTS, HND), contrôle de cohérence, import/export Excel (Should) (EX-MAQ-01 à 06, RG-MAQ-1 à 3).

**Emplois du temps (EDT)**
- **FR-008** : Emplois du temps par groupe, enseignant et salle avec détection des conflits, cours du soir et mutualisés, suivi de consommation des volumes, reports notifiés, planning des examens (Should) (EX-EDT-01 à 06).

**Enseignants et vacations (ENS)**
- **FR-009** : Dossier enseignant, affectations par le chef de département, cahier de textes, émargement et contre-signature, validation mensuelle, état et paiement des vacations, contrat (Should), évaluation des enseignements (Could) (EX-ENS-01 à 08, RG-ENS-1 à 5).

**Présences et discipline (PRE)**
- **FR-010** : Appel par liste ou QR de séance, justificatifs, taux d'assiduité avec alertes, liste des non-autorisés à composer, sanctions avec conseil de discipline (Should) (EX-PRE-01 à 05, RG-PRE-1/2).

**Notes (NOT)**
- **FR-011** : Évaluations par EC, saisie en grille ou import Excel hors-ligne possible, codes ABJ/ABI/NA/FR, anonymat (Should), cycle brouillon, soumise, validée, verrouillée, publiée, corrections motivées à double validation, requête de l'étudiant (Should), suivi des retards de saisie avec relances, statistiques par EC (Should) (EX-NOT-01 à 09, RG-NOT-1 à 5).

**Délibérations (DEL)**
- **FR-012** : Calculs F1 à F7, compensation, capitalisation, rattrapage, simulation, sessions de jury avec quorum, PV signé électroniquement, publication après signature, décisions de passage, réouverture exceptionnelle (EX-DEL-01 à 08, RG-DEL-1 à 5), avec paramètres par défaut du règlement LMD et règles BTS/HND.

**Frais (FIN)**
- **FR-013** : Grilles par école, filière, niveau et mode ; échéancier par défaut en 3 tranches ; bourses et réductions plafonnées ; prise en charge par un tiers (Should) ; blocages ; attestation de paiement et quitus (EX-FIN-01 à 06, RG-FIN-1 à 6).

**Stages (STG)**
- **FR-014** : Convention tripartite, suivi, mémoire, planning et PV de soutenance ; offres de stage et contrôle de similarité (Should) (EX-STG-01 à 06, RG-STG-1/2).

**Examens nationaux et tutelle (EXN, TUT)**
- **FR-015** : Sessions BTS et HND, liste des candidats tirée des seules inscriptions valides, contrôle des conditions internes, exports aux formats MINESUP, import des résultats, attestation de fin de formation ; jamais de document intitulé « BTS » ou « HND » (EX-EXN-01 à 07, RG-EXN-1 à 3).
- **FR-016** : Transmissions à la tutelle (listes, notes, PV), suivi des dossiers de diplôme, accès lecture pour la tutelle (Could), frais reversés (Should) (EX-TUT-01 à 05, RG-TUT-1/2).

**Documents (DOC)**
- **FR-017** : Catalogue DOC-01 à DOC-32 avec signataire et QR selon le tableau du cahier des charges ; règles de numérotation, vérification publique, duplicata, annulation, versions de modèles, blocage financier jamais appliqué au certificat de scolarité, au reçu, au relevé de compte ni à l'attestation de paiement ; français ou anglais selon la langue de la filière (RG-DOC-1 à 8).

**Notifications**
- **FR-018** : Les 15 notifications automatiques NOT-01 à NOT-15 sont envoyées aux destinataires et canaux prévus.

**Rôles, portails, pilotage**
- **FR-019** : 23 rôles R01 à R23 avec périmètres (établissement, écoles, département, filière, groupes) et la matrice des droits (lecture, écriture, validation) du cahier des charges ; séparation des tâches (saisie ≠ verrouillage, demande ≠ validation).
- **FR-020** : Portails étudiant, garant, enseignant, membre de jury, université de tutelle (POR) ; sélecteur « École » et vue « Toutes les écoles » (RG-ORG-3) ; identité visuelle de l'école sélectionnée (RG-ORG-4).
- **FR-021** : Tableaux de bord et statistiques (PIL) selon destinataires et fréquences du cahier des charges, y compris statistiques d'effectifs MINESUP.
- **FR-022** : Sécurité propre : nouvelle double authentification pour verrouiller, corriger, signer un PV, publier, exporter la liste nationale ou modifier un règlement figé ; contrôle de version des notes ; empreinte de chaque PV ; exports nominatifs journalisés et filigranés ; accès externes nominatifs à durée limitée.

### Key Entities

Écoles et départements, filières (régime, autorisation, tutelle, règlement), maquettes (UE, EC, versions), campagnes et candidatures, inscriptions (matricule, contrat pédagogique, UE capitalisées et en dette), affectations d'enseignement et séances (cahier de textes, émargement), évaluations et notes (états, corrections), jurys et délibérations (PV, décisions), résultats figés, stages et soutenances, sessions nationales et candidats, transmissions à la tutelle, documents émis et leurs modèles. Détail dans le plan.

## Success Criteria *(mandatory)*

- **SC-001** : Aucune inscription n'est enregistrée dans une filière sans autorisation valide ou sans prérequis vérifiés (objectif O6 : 0).
- **SC-002** : Aucune note n'est modifiée après verrouillage sans procédure tracée à double validation (objectif O1 : 0).
- **SC-003** : Les résultats d'une session sont publiés moins de 10 jours après la dernière épreuve (objectif O2).
- **SC-004** : Au moins 70 % des paiements passent par mobile money ou banque douze mois après le démarrage (objectif O4) ; le taux d'impayés de fin d'année baisse de 30 % la première année (objectif O3).
- **SC-005** : Aucune heure de vacation n'est payée sans émargement validé (objectif O5 : 0).
- **SC-006** : Le tableau de bord consolidé de toutes les écoles est à jour chaque jour (objectif O7).
- **SC-007** : Les exemples chiffrés du cahier des charges produisent exactement les résultats attendus : semestre « Marie N. » (11,55, 2,40, 30 crédits, mention Passable ; variante 12,09, mention Assez bien), vacations (43,5 HETD, 261 000 FCFA), échéancier (50 000 + 3 × 120 000).
- **SC-008** : 100 % des documents officiels émis portent un numéro unique et un QR de vérification ; la vérification publique d'un document annulé affiche « annulé » et son motif.
- **SC-009** : Un utilisateur d'un autre client ou d'une autre école, ou un enseignant non affecté, n'obtient jamais de donnée (réponse 403 ou 404 sur 100 % des routes testées).
- **SC-010** : Un enseignant saisit les notes d'une évaluation de 42 étudiants en moins de 5 minutes, y compris hors-ligne puis synchronisation.

## Assumptions

- L'application s'appuie sur les services du socle (001) ; elle ne les redéveloppe pas.
- Les règlements, grilles de lettres et taux de vacation des cahiers des charges sont des valeurs par défaut fictives, remplacées en cadrage par les textes de l'université de tutelle et la politique de l'institut.
- Les formats d'export MINESUP et tutelle sont configurables par modèle et non codés en dur.
- La signature électronique est la signature simple (code de confirmation) par défaut ; un certificat qualifié reste à décider.
- La bibliothèque (BIB, lot I4) est hors version 1.
- Le lot d'implémentation suit I1 (entrée et inscription), I2 (enseignement et notes), I3 (résultats et fin d'études), I4 (recette et pilote).
- Le design de référence est celui de `specs/001-socle-plateforme/design.md` ; les écrans manquants (admissions, notes, délibération, documents) sont à concevoir avant les lots concernés.

## Questions ouvertes (reprises du cahier des charges)

- IPES pilote : lequel, avec quelles écoles internes et quelles universités de tutelle ?
- Règlements des études et grilles de lettres officielles des tutelles concernées ?
- Formats exacts attendus par le MINESUP pour les listes BTS et HND ?
- Taux de vacation par grade et retenues applicables, à fournir par le comptable ?
- Signature électronique : simple ou certificat qualifié ?
- Bibliothèque : version 1 ou plus tard ?
