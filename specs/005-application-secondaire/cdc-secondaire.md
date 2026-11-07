Cahier des charges de l’application Secondaire
Lycées d’enseignement général et technique, collèges CES et CETIC, établissements privés et publics, sur la plateforme SCHOLARIS
Emmanuel FOKA
9 octobre 2026 — version 1.0 (projet)

## Présentation du document

### Objet
Ce cahier des charges décrit l’application Secondaire de la plateforme SCHOLARIS. Elle gère tous les établissements d’enseignement secondaire relevant du Ministère des Enseignements secondaires (MINESEC), privés et publics :

| Type d’établissement | Enseignement | Cycles | Examens préparés |
|---|---|---|---|
| Lycée d’enseignement général | Général | 1er cycle (6e à 3e ; Form 1 à 5) et 2nd cycle (2nde à Tle ; Lower et Upper Sixth) | BEPC, Probatoire, BAC ; GCE O Level et A Level |
| Collège d’enseignement secondaire (CES) ou collège privé | Général | 1er cycle seulement | BEPC ; GCE O Level |
| Lycée technique | Technique et professionnel | 1er cycle (1re à 4e année) et 2nd cycle (2nde à Tle) | CAP, Probatoire technique, BAC technique, BT ; GCE technique |
| Collège d’enseignement technique industriel et commercial (CETIC) | Technique et professionnel | 1er cycle seulement | CAP |
| Lycée polyvalent ou complexe scolaire | Général et technique | Selon autorisation | Tous ceux de ses sections |

Un même établissement peut avoir une section francophone et une section anglophone. Le type, les sections, les séries et les spécialités sont des paramètres de l’établissement : il n’existe qu’une application, qui s’adapte.

### Place dans la plateforme

| L’application utilise (socle) | Elle apporte (spécifique) |
|---|---|
| S01 Comptes et rôles | Rôles du secondaire : proviseur, principal, censeur, surveillant général, chef des travaux, professeur principal |
| S02 Organisation | Sections, cycles, séries, spécialités techniques, autorisations d’ouverture |
| S03 Personnes | Matricule national cartescolaire.cm, élève et parents, délégués |
| S04 Frais et paiements | Pension (privé) ; contributions exigibles et APEE (public) ; rapprochement avec cartescolaire.cm |
| S05 Communication | Absences par heure de cours, convocations, résultats |
| S06 Documents | 34 documents du secondaire |
| S07 Emplois du temps | Coefficients, volumes horaires, ateliers, salles spécialisées |
| S08 Présences | Absences par heure, retards, conduite |
| S09 Moteur d’évaluation | Séquences, coefficients, moyennes, rangs, conseil de classe, pratique professionnelle |
| S10 Personnel | Permanents, vacataires, fonctionnaires affectés au public |
| S12 Tableaux de bord | Indicateurs de couverture des programmes, de réussite, de recouvrement |
| S14 Intégrations | cartescolaire.cm, listes pour les examens, applications Primaire et Supérieur |


### Privé et public

| Sujet | Établissement privé | Établissement public |
|---|---|---|
| Responsable | Promoteur ; réseau confessionnel le cas échéant | État ; chef d’établissement nommé |
| Autorisation | Autorisation de création et d’ouverture, contrôlée par le MINESEC | Acte de création |
| Frais | Inscription, pension par tranches, APEE, services annexes | Contributions exigibles payées par matricule sur cartescolaire.cm, APEE |
| Personnel | Salariés et vacataires de l’établissement ; paie gérée dans la plateforme | Fonctionnaires et contractuels de l’État ; vacataires payés localement |
| Contrôle | Brigade nationale de contrôle des établissements privés, délégations | Inspections pédagogiques, délégations, Inspection générale |
| Pilotage | Rentabilité, recouvrement, réputation (résultats aux examens) | Couverture des programmes, réussite, effectifs |


### Hypothèses retenues

| Hypothèse | Conséquence |
|---|---|
| Le client est un promoteur (privé), un réseau confessionnel, ou une autorité publique pour un groupe d’établissements | Le modèle client et établissement de la plateforme s’applique ; les rôles de supervision sont externes |
| Tous les établissements utilisent cartescolaire.cm pour le matricule national | L’application l’enregistre, l’utilise comme clé et prépare les données que l’établissement doit y saisir |
| Les coefficients, les seuils de passage et les distinctions de ce document sont des valeurs par défaut | Ils sont remplacés par les textes du MINESEC en vigueur et le règlement intérieur de l’établissement |
| Les examens officiels sont organisés par l’Office du Baccalauréat du Cameroun, la Direction des examens du MINESEC et le GCE Board | L’application prépare les candidatures et importe les résultats ; elle ne délivre aucun diplôme |
| Implémentation selon les choix de la plateforme : PHP 8.4, Laravel 13, API REST | Module app/Modules/Apps/Secondary |


### Conventions

| Élément | Signification |
|---|---|
| Must / Should / Could | Indispensable au MVP / attendu en version 1 / souhaitable |
| EX-xxx-nn, RG-xxx-n | Exigence fonctionnelle, règle de gestion |
| DOC-nn, NOT-nn | Document imprimable, notification |
| [PRI] [PUB] [TEC] [EN] | Propre au privé, au public, à l’enseignement technique, au sous-système anglophone |
| Paramètre | Valeur réglable sans développement |


## Contexte

### Cadre institutionnel
Organisation. Le décret n° 2012/267 du 11 juin 2012 organise le MINESEC : Direction des examens, des concours et de la certification ; Direction de l’enseignement secondaire technique et professionnel ; sous-directions de suivi des établissements privés ; Brigade nationale de contrôle des établissements privés ; cellule chargée des statistiques et de la carte scolaire ; délégations régionales (avec inspections pédagogiques régionales et service de la carte scolaire) et départementales (organigramme MINESEC).
Matricule unique et cartescolaire.cm. Depuis 2024-2025, chaque élève du public et du privé reçoit dès la 6e un matricule unique conservé pendant tout le secondaire, géré sur cartescolaire.cm et utilisé pour payer les contributions exigibles et les frais d’examen (Journal du Cameroun, août 2024). Le paiement passe par Campost, Express Union, MTN, Orange ou Afriland First Bank ; les établissements privés devaient enregistrer leurs élèves avant le 15 décembre 2024 (Journal du Cameroun).
Rentrée 2026-2027. Le paiement en ligne des contributions et des frais d’examen a ouvert le 24 août 2026 ; les établissements doivent générer les matricules des élèves de 6e, Form One et 1re année, et les chefs d’établissement publics et privés doivent renseigner sur la plateforme les conseils de classe de fin d’année 2025-2026 (Journal du Cameroun, août 2026).
Frais indus. En octobre 2024, le MINESEC a rappelé à l’ordre des chefs d’établissement qui percevaient des frais non prévus (stage, reprographie, matière d’œuvre d’atelier). Une source régionale citait un droit d’inscription autorisé de 3 500 FCFA dans le privé, alors que certains établissements demandaient jusqu’à 25 000 FCFA ; un lycée public affichait 7 500 FCFA (1er cycle) et 10 000 FCFA (2nd cycle) de frais exigibles et 25 000 FCFA d’APEE (Cameroon Tribune).
Calendrier et évaluation. Le calendrier scolaire 2021-2022 du MINESEC prévoit un conseil de classe à la fin de chaque trimestre, l’arrêt des compositions trimestrielles au profit d’une composition de fin d’année, et une moyenne annuelle égale à la moyenne des trois moyennes trimestrielles ; les évaluations sommatives ne doivent pas conduire à renvoyer des élèves (calendrier MINESEC 2021-2022). Le calendrier de chaque année est un paramètre.
Séries. Depuis 2018-2019, le second cycle général compte les séries littéraires (A1 à A5, ABI), scientifiques et technologiques (C, D, TI), sciences humaines (SH) et arts (AC) (arrêté n° 227/18/MINESEC/IGE, Médiaterre).
Examens 2026. BAC général écrit du 25 au 30 mai ; BEPC du 1er au 4 juin ; Probatoire général, STT et BT du 8 au 12 juin ; GCE O et A Level écrit du 2 au 18 juin (GCE Board), résultats au plus tard le 31 juillet (Infos pratiques).

### Ce que l’application doit résoudre

| Problème courant | Conséquence | Réponse |
|---|---|---|
| Notes sur cahier et calculs de moyennes sur tableur | Erreurs de moyenne et de rang, contestations | Saisie contrôlée, calculs automatiques, verrouillage |
| Bulletins produits tard pour des classes de 60 à 100 élèves | Conseils de classe bâclés | Bulletins générés, dossier de conseil de classe prêt |
| Double saisie sur cartescolaire.cm | Matricules en erreur, conseil de classe non renseigné | Matricule importé, données de fin d’année exportées |
| Pensions suivies sur registre (privé) | Impayés, conflits | Échéanciers, mobile money, relances |
| Frais non autorisés (public) | Sanctions, perte de confiance | Catalogue de frais limité aux frais autorisés |
| Ateliers et matières d’œuvre non suivis (technique) | Pratique insuffisante, coûts opaques | Module ateliers : séances, consommables, évaluations pratiques |
| Absences par heure mal suivies | Décrochage, conduite non sanctionnée | Appel par heure de cours, cumul et barème disciplinaire |


## Objectifs et indicateurs

| N° | Objectif | Indicateur | Cible |
|---|---|---|---|
| O1 | Fiabiliser les notes | Réclamations fondées sur une erreur de calcul | 0 |
| O2 | Tenir les conseils de classe à temps | Bulletins disponibles avant le conseil | 100 %, au plus tard 5 jours après la dernière séquence |
| O3 | Éviter la double saisie | Données de fin d’année transmises à cartescolaire.cm sans ressaisie | 100 % |
| O4 | Améliorer le recouvrement [PRI] | Pension recouvrée en fin d’année | Plus de 90 % |
| O5 | Respecter les frais autorisés [PUB] | Frais perçus hors catalogue autorisé | 0 |
| O6 | Suivre la couverture des programmes | Classes avec un taux de couverture connu chaque trimestre | 100 % |
| O7 | Réduire l’absentéisme | Heures d’absence injustifiées par élève et par trimestre | Baisse de 30 % |


## Périmètre

| Inclus | Exclu ou traité ailleurs |
|---|---|
| Paramétrage : type, sections, cycles, séries, spécialités, coefficients | Organisation des examens officiels |
| Inscriptions, réinscriptions, transferts, matricule | Paie des fonctionnaires de l’État |
| Classes, emplois du temps, salles et ateliers | Comptabilité générale complète (export) |
| Cahier de textes et couverture des programmes | Plateforme d’enseignement en ligne complète |
| Présences par heure, discipline, conseil de discipline |  |
| Séquences, notes, moyennes, rangs, bulletins |  |
| Conseils de classe, passage, orientation |  |
| Ateliers, travaux pratiques, stages en entreprise [TEC] |  |
| Candidatures aux examens officiels, résultats |  |
| Pension, contributions, APEE, services annexes |  |
| Personnel, vacations, paie du privé (socle S10) |  |
| Internat, cantine, transport, bibliothèque, infirmerie |  |
| Portails élève, parent, enseignant |  |
| Tableaux de bord et rapports pour le MINESEC |  |


## Organisation de l’établissement

### Structure
Structure d’un lycée polyvalent dans l’application

### Niveaux par sous-système et par enseignement

| Enseignement | Sous-système | 1er cycle | Examen | 2nd cycle | Examens |
|---|---|---|---|---|---|
| Général | Francophone | 6e, 5e, 4e, 3e | BEPC | 2nde, 1re, Tle, par série | Probatoire (1re), BAC (Tle) |
| Général | Anglophone | Form 1 à Form 5 | GCE O Level | Lower Sixth, Upper Sixth | GCE A Level |
| Technique | Francophone | 1re, 2e, 3e, 4e année, par spécialité | CAP | 2nde, 1re, Tle, par spécialité | Probatoire technique, BAC technique, BT |
| Technique | Anglophone | Form 1 à Form 5, par spécialité | GCE O Level technique | Lower et Upper Sixth | GCE A Level technique |

Les noms de niveaux, séries, spécialités et examens sont des listes de référence tenues au niveau de la plateforme et mises à jour quand les textes changent.

### Règles d’organisation

| ID | Règle |
|---|---|
| RG-ORG-1 | Le type d’établissement limite les cycles possibles : un CES ou un CETIC n’a pas de 2nd cycle. |
| RG-ORG-2 | Une classe appartient à une section (francophone ou anglophone), un enseignement (général ou technique), un niveau et, au 2nd cycle ou en technique, une série ou une spécialité. |
| RG-ORG-3 | [PRI] Une série, une spécialité ou un cycle ne peut recevoir d’inscription que si l’autorisation correspondante est enregistrée et valide. |
| RG-ORG-4 | Les coefficients et volumes horaires sont définis par niveau, série ou spécialité ; l’établissement ne peut pas les modifier s’ils sont fixés par les textes, seulement compléter les matières propres à son projet (club, option) sans coefficient dans la moyenne officielle. |
| RG-ORG-5 | Un établissement privé qui fait partie d’un réseau (confessionnel ou groupe) partage avec le réseau les indicateurs agrégés choisis par le client ; les données nominatives restent dans l’établissement. |


## Acteurs et rôles

| N° | Rôle | Périmètre | Missions dans l’application |
|---|---|---|---|
| R01 | Promoteur ou fondateur [PRI] | Établissements du client | Tableaux de bord, finances, validation des tarifs |
| R02 | Responsable de réseau (secrétariat à l’éducation, direction de groupe) [PRI] | Établissements du réseau | Indicateurs consolidés, modèles communs |
| R03 | Proviseur (lycée) ou principal (collège) | Établissement | Pilotage, validation des bulletins, présidence des conseils de classe et de discipline, signature |
| R04 | Censeur ou préfet des études | Niveaux attribués | Emplois du temps, suivi des notes, cahier de textes, préparation des conseils de classe |
| R05 | Surveillant général | Niveaux attribués | Absences, retards, discipline, consignes |
| R06 | Chef des travaux [TEC] | Ateliers | Planning des ateliers, matières d’œuvre, sécurité, stages |
| R07 | Animateur pédagogique ou chef de département | Sa discipline | Progressions, épreuves communes, contrôle de la couverture |
| R08 | Professeur principal | Sa classe | Synthèse de la classe, appréciations générales, lien avec les parents |
| R09 | Enseignant | Ses classes et matières | Appel par heure, cahier de textes, notes |
| R10 | Conseiller d’orientation | Établissement | Fiches d’orientation, entretiens, choix de série |
| R11 | Intendant ou économe | Établissement | Frais, encaissements, dépenses, rapprochements |
| R12 | Caissier | Établissement | Encaissements en espèces, reçus, arrêté de caisse |
| R13 | Secrétaire ou chargé de la scolarité | Établissement | Inscriptions, dossiers, documents, listes d’examen |
| R14 | Infirmier | Établissement | Passages à l’infirmerie, aptitude à l’EPS (sans diagnostic visible des autres rôles) |
| R15 | Responsable de l’internat, de la cantine ou du transport | Service concerné | Effectifs, présences, frais du service |
| R16 | Bibliothécaire | Établissement | Prêts et retours |
| R17 | Élève | Son parcours | Emploi du temps, notes publiées, absences, devoirs, documents |
| R18 | Délégué de classe | Sa classe | Contre-signature de l’appel, remontée d’informations |
| R19 | Parent ou tuteur | Ses enfants | Notes, absences, bulletins, paiements, messages |
| R20 | Bureau de l’APEE | Établissement | Contributions APEE, comptes rendus |
| R21 | Inspecteur pédagogique régional (externe) | Établissements visités | Consultation des cahiers de textes, progressions, résultats |
| R22 | Délégation départementale ou régionale (externe) | Établissements du territoire | Statistiques, contrôle des frais, listes d’examen |
| R23 | Brigade de contrôle des établissements privés (externe) [PRI] | Établissement contrôlé | Lecture des autorisations, effectifs, frais, personnel lors d’un contrôle |
| R24 | Administrateur fonctionnel | Client | Comptes, rôles, paramètres, modèles |


### Matrice des droits
L = lecture ; É = écriture ; V = validation ; — = aucun accès.

| Module | R03 Proviseur | R04 Censeur | R05 Surv. général | R08 Prof. principal | R09 Enseignant | R11 Intendant | R17 Élève | R19 Parent |
|---|---|---|---|---|---|---|---|---|
| Paramétrage | V | É | — | — | — | L | — | — |
| Inscriptions | V | L | L | L | — | L | L (la sienne) | L |
| Emplois du temps | V | É | L | L | L | — | L | L |
| Cahier de textes | L | V | — | L | É | — | L | — |
| Présences et discipline | V | L | É | L | É (appel) | — | L | L |
| Notes | L | V | — | L | É (ses matières) | — | L (publiées) | L (publiées) |
| Bulletins et conseil de classe | V | É | É (conduite) | É (appréciation) | É (appréciation) | — | L | L |
| Examens officiels | V | É | — | L | — | L | L | L |
| Frais et paiements | L | — | — | — | — | É | L | L, paiement |
| Ateliers [TEC] | L | L | — | — | É (ses séances) | L (coûts) | L | — |
| Tableaux de bord | L (tout) | L | L | L (sa classe) | L (ses classes) | L (finances) | — | — |


## Année scolaire type

| Période | Activités dans l’application | Modules |
|---|---|---|
| Juillet, août | Paramétrage de l’année, tarifs, classes, affectations, emplois du temps | ETB, FIN, EDT |
| Fin août, septembre | Inscriptions, réinscriptions, matricules cartescolaire.cm, paiement de la 1re tranche | INS, FIN, CSC |
| Septembre à novembre | Séquences 1 et 2, appel par heure, cahier de textes, ateliers | NOT, PRE, CTX, ATL |
| Fin novembre | Conseil de classe du 1er trimestre, bulletins | BUL |
| Décembre à mars | Séquences 3 et 4, candidatures aux examens officiels, conseil du 2e trimestre | NOT, EXA, BUL |
| Mars à mai | Séquences 5 et 6, épreuves zéro, stages en entreprise [TEC] | NOT, STG |
| Mai à juillet | Examens officiels (calendrier de l’année) | EXA |
| Juin | Conseils de classe de fin d’année, passage, orientation, livret annuel | BUL, PAS |
| Juillet | Import des résultats, transmission des conseils de fin d’année à cartescolaire.cm, clôture | EXA, CSC |


## Modules fonctionnels

| Code | Module | Services du socle | Lot |
|---|---|---|---|
| ETB | Paramétrage de l’établissement | S02 | E1 |
| INS | Inscriptions et dossiers | S03 | E1 |
| CSC | Intégration cartescolaire.cm | S14 | E1 |
| FIN | Frais, pension, contributions, APEE | S04 | E1 |
| EDT | Emplois du temps, salles et ateliers | S07 | E2 |
| CTX | Cahier de textes et couverture des programmes | S07 | E2 |
| PRE | Présences et discipline | S08 | E2 |
| NOT | Évaluations et notes | S09 | E2 |
| BUL | Moyennes, bulletins et conseils de classe | S09, S06 | E3 |
| PAS | Passage et orientation | S09 | E3 |
| EXA | Examens officiels | S06, S14 | E3 |
| ATL | Ateliers et travaux pratiques [TEC] | S07 | E3 |
| STG | Stages en entreprise [TEC] | S06 | E3 |
| RHP | Personnel et vacations | S10 | E3 |
| SVC | Internat, cantine, transport, bibliothèque, infirmerie | S03, S04 | E4 |
| POR | Portails élève, parent, enseignant | S11 | E1 à E3 |
| PIL | Tableaux de bord et rapports | S12 | E4 |


### ETB · Paramétrage de l’établissement

| ID | Exigence | Priorité |
|---|---|---|
| EX-ETB-01 | Type, statut (public, privé laïc, privé confessionnel), sections, cycles, séries, spécialités, code d’établissement officiel | Must |
| EX-ETB-02 | [PRI] Registre des autorisations : création, ouverture, extensions de cycles et de séries, avec dates et pièces | Must |
| EX-ETB-03 | Matières, coefficients et volumes horaires par niveau, série ou spécialité, depuis les référentiels de la plateforme | Must |
| EX-ETB-04 | Calendrier de l’année : trimestres, 6 séquences, congés, conseils de classe, épreuves zéro | Must |
| EX-ETB-05 | Règlement intérieur : barème des absences, sanctions, distinctions, conditions de passage propres à l’établissement dans les limites des textes | Must |
| EX-ETB-06 | Salles, laboratoires, ateliers avec capacité et équipements | Must |


### INS · Inscriptions et dossiers

| ID | Exigence | Priorité |
|---|---|---|
| EX-INS-01 | Préinscription en ligne ou au guichet ; test d’entrée [PRI] ; inscription avec pièces (acte de naissance, dernier bulletin, certificat de fin d’études, exeat) | Must |
| EX-INS-02 | Réinscription des élèves autorisés par le conseil de classe de fin d’année | Must |
| EX-INS-03 | Dossier de l’élève : identité, matricule national, parents, contacts d’urgence, photo, parcours, situation particulière | Must |
| EX-INS-04 | Entrée en 6e, Form 1 ou 1re année depuis l’application Primaire quand l’école l’utilise (dossier transféré) | Should |
| EX-INS-05 | Transferts : exeat, dossier transmis, contrôle du matricule dans le nouvel établissement | Must |
| EX-INS-06 | Choix de série ou de spécialité à l’entrée au 2nd cycle, selon l’orientation | Must |
| EX-INS-07 | Carte scolaire de l’élève avec photo, matricule et QR (DOC-04) | Should |


| ID | Règle |
|---|---|
| RG-INS-1 | Le matricule national est obligatoire pour tout élève inscrit ; un élève sans matricule est « en attente » et apparaît dans la liste à régulariser sur cartescolaire.cm. |
| RG-INS-2 | L’âge et les conditions d’admission par niveau sont des paramètres ; un écart produit une alerte et exige la décision du chef d’établissement. |
| RG-INS-3 | Un élève exclu définitivement par un conseil de discipline ne peut être réinscrit dans le même établissement qu’avec une décision motivée du chef d’établissement. |
| RG-INS-4 | [PUB] L’inscription n’est jamais conditionnée par le paiement de frais autres que ceux fixés par les textes. |


### CSC · Intégration cartescolaire.cm

| ID | Exigence | Priorité |
|---|---|---|
| EX-CSC-01 | Enregistrement du matricule national dans le dossier de l’élève, par saisie, import de fichier ou interface si l’administration en ouvre une | Must |
| EX-CSC-02 | Liste des élèves de 6e, Form 1 et 1re année dont le matricule doit être généré par l’établissement | Must |
| EX-CSC-03 | Rapprochement des paiements faits par les parents sur cartescolaire.cm (contributions, frais d’examen) avec les élèves de l’établissement | Must |
| EX-CSC-04 | Préparation des données des conseils de classe de fin d’année au format demandé, pour saisie ou import sur la plateforme publique | Must |
| EX-CSC-05 | Contrôle de cohérence : élève présent dans l’application et absent de cartescolaire.cm, ou l’inverse | Must |


| ID | Règle |
|---|---|
| RG-CSC-1 | En l’absence d’interface officielle, l’intégration se fait par fichiers ; aucune automatisation de navigateur sur la plateforme publique n’est utilisée. |
| RG-CSC-2 | Les données de cartescolaire.cm font foi pour le matricule ; en cas d’écart d’identité, l’application signale et la scolarité corrige après vérification des pièces. |


### FIN · Frais, pension, contributions et APEE

| ID | Exigence | Priorité |
|---|---|---|
| EX-FIN-01 | [PRI] Grille par niveau, série ou spécialité : inscription, pension en tranches, APEE, frais d’atelier ou de laboratoire, tenue, services annexes | Must |
| EX-FIN-02 | [PRI] Remises : fratrie, enfant du personnel, mérite, cas social ; plafonds et validation | Must |
| EX-FIN-03 | [PUB] Catalogue limité aux frais autorisés : contributions exigibles (suivies via cartescolaire.cm), APEE votée en assemblée, services annexes facultatifs | Must |
| EX-FIN-04 | Paiement mobile money, carte, virement, espèces ; reçu immédiat ; rapprochement (socle S04) | Must |
| EX-FIN-05 | Relances graduées par SMS ; plan de paiement individuel | Must |
| EX-FIN-06 | Blocages paramétrables (bulletin en ligne, documents, réinscription) [PRI] ; aucun blocage d’accès aux cours ni aux évaluations | Must |
| EX-FIN-07 | Situation financière par classe, niveau, établissement ; prévisions d’encaissement | Must |


| ID | Règle |
|---|---|
| RG-FIN-1 | Aucun élève n’est renvoyé ni privé d’évaluation pour impayé ; l’application ne propose pas cette action. |
| RG-FIN-2 | [PUB] Un frais absent du catalogue autorisé ne peut pas être créé par l’établissement ; une demande d’ajout est adressée au client et tracée. |
| RG-FIN-3 | Les frais d’examens officiels payés sur cartescolaire.cm ne sont pas encaissés une seconde fois par l’établissement ; l’application affiche seulement leur statut. |
| RG-FIN-4 | [PRI] Un paiement est affecté dans l’ordre suivant (paramètre) : inscription, APEE, tranches de pension de la plus ancienne à la plus récente, services annexes ; un paiement familial est réparti entre les enfants dans cet ordre, tranche par tranche. |


### EDT · Emplois du temps, salles et ateliers

| ID | Exigence | Priorité |
|---|---|---|
| EX-EDT-01 | Génération assistée à partir des volumes horaires et des disponibilités des enseignants, vacataires compris | Must |
| EX-EDT-02 | Conflits détectés : enseignant, salle, classe, atelier, laboratoire | Must |
| EX-EDT-03 | Classes dédoublées pour les travaux pratiques et les ateliers [TEC] | Must |
| EX-EDT-04 | Remplacements et permutations notifiés aux élèves et aux parents | Should |


### CTX · Cahier de textes et couverture des programmes

| ID | Exigence | Priorité |
|---|---|---|
| EX-CTX-01 | Progression annuelle par matière et par niveau, découpée en leçons et en heures | Must |
| EX-CTX-02 | Cahier de textes par séance : leçon traitée, travaux donnés, signature électronique | Must |
| EX-CTX-03 | Taux de couverture du programme par classe et par matière, comparé à la progression | Must |
| EX-CTX-04 | Visa du censeur et de l’animateur pédagogique ; consultation par l’inspecteur pédagogique | Should |


### PRE · Présences et discipline

| ID | Exigence | Priorité |
|---|---|---|
| EX-PRE-01 | Appel à chaque heure de cours par l’enseignant, sur téléphone ou ordinateur, hors-ligne possible | Must |
| EX-PRE-02 | Retards, absences justifiées et injustifiées, exclusions de cours, passages à l’infirmerie | Must |
| EX-PRE-03 | SMS au parent dès la première heure d’absence (paramètre) | Must |
| EX-PRE-04 | Barème disciplinaire selon le règlement intérieur ; propositions automatiques de sanction | Must |
| EX-PRE-05 | Conseil de discipline : convocation, dossier, décision, procès-verbal (DOC-17) | Must |
| EX-PRE-06 | Note ou appréciation de conduite reportée au bulletin | Must |


| ID | Règle |
|---|---|
| RG-PRE-1 | Barème par défaut des heures d’absence injustifiées dans le trimestre (exemple à remplacer par le règlement intérieur) : 10 h, avertissement ; 20 h, blâme ; 30 h, exclusion temporaire de 3 jours proposée ; au-delà, conseil de discipline. |
| RG-PRE-2 | Une sanction proposée par l’application n’est appliquée qu’après décision du surveillant général ou du chef d’établissement selon son niveau. |
| RG-PRE-3 | Une exclusion définitive n’est prononcée que par le conseil de discipline, avec procès-verbal ; l’élève et ses parents sont entendus. |


### NOT · Évaluations et notes

| ID | Exigence | Priorité |
|---|---|---|
| EX-NOT-01 | Six séquences par an (deux par trimestre) ; plusieurs évaluations possibles par séquence (devoir, interrogation, travaux pratiques) avec poids | Must |
| EX-NOT-02 | Saisie par l’enseignant, sur téléphone ou ordinateur, hors-ligne, import Excel | Must |
| EX-NOT-03 | Codes : absent justifié, absent injustifié, dispensé (EPS), fraude | Must |
| EX-NOT-04 | Épreuves communes par niveau organisées par l’animateur pédagogique | Should |
| EX-NOT-05 | Cycle : brouillon, soumise, validée par le censeur, verrouillée, publiée | Must |
| EX-NOT-06 | Correction d’une note verrouillée par demande motivée validée par le censeur puis le chef d’établissement | Must |
| EX-NOT-07 | Notes publiées aux élèves et parents après validation, avec moyenne de la classe | Must |
| EX-NOT-08 | [EN] Correspondance optionnelle note et lettre pour les bulletins anglophones | Should |


| ID | Règle |
|---|---|
| RG-NOT-1 | Notes de 0 à 20 au quart de point (paramètre). |
| RG-NOT-2 | Absence justifiée à une évaluation : la séquence est calculée sur les autres évaluations, ou l’élève compose une épreuve de remplacement ; absence injustifiée : 0. |
| RG-NOT-3 | Fraude : 0 à l’évaluation et saisine du surveillant général. |
| RG-NOT-4 | Une note verrouillée n’est jamais écrasée ; la correction crée une nouvelle valeur liée à l’ancienne, avec motif et validations. |


### BUL · Moyennes, bulletins et conseils de classe

| ID | Exigence | Priorité |
|---|---|---|
| EX-BUL-01 | Calculs automatiques : moyennes de séquence, de trimestre et annuelles par matière et générales, rangs, statistiques de classe (partie « Règles de calcul ») | Must |
| EX-BUL-02 | Bulletin séquentiel (DOC-09), trimestriel (DOC-10) et annuel (DOC-11), en français ou en anglais | Must |
| EX-BUL-03 | Appréciations par matière (enseignant) et générale (professeur principal), avec banque de formulations | Must |
| EX-BUL-04 | Dossier de conseil de classe : moyennes, distribution, couverture des programmes, assiduité, cas à examiner (DOC-12) | Must |
| EX-BUL-05 | Décisions du conseil : distinctions, avertissements, passage, redoublement, exclusion, orientation ; procès-verbal (DOC-13) | Must |
| EX-BUL-06 | Publication des bulletins en ligne et par SMS (résumé : moyenne, rang, décision) | Must |
| EX-BUL-07 | Tableau d’honneur affichable par classe (DOC-14) | Should |


| ID | Règle |
|---|---|
| RG-BUL-1 | Un bulletin n’est édité que lorsque toutes les notes de la période sont verrouillées ou marquées « non évalué » par le censeur. |
| RG-BUL-2 | Les distinctions et sanctions du travail sont proposées par l’application et décidées par le conseil de classe. |
| RG-BUL-3 | Après le conseil, le bulletin est figé ; un rectificatif exige la décision du chef d’établissement et porte la mention « rectificatif ». |
| RG-BUL-4 | Les données du conseil de fin d’année sont transmises à cartescolaire.cm (EX-CSC-04) avant la date fixée par le MINESEC. |


### PAS · Passage et orientation

| ID | Exigence | Priorité |
|---|---|---|
| EX-PAS-01 | Propositions automatiques selon la politique de passage (partie « Règles de calcul ») | Must |
| EX-PAS-02 | Orientation en fin de 3e, Form 5 ou fin de 1er cycle technique : vœux de l’élève, avis du conseil, série ou spécialité proposée | Must |
| EX-PAS-03 | Fiche d’orientation (DOC-16) et entretien avec le conseiller | Should |
| EX-PAS-04 | Recours des parents dans un délai paramétré ; décision motivée | Should |


### EXA · Examens officiels

| ID | Exigence | Priorité |
|---|---|---|
| EX-EXA-01 | Liste des candidats par examen (BEPC, CAP, Probatoire, BAC, BT, GCE O et A Level), série et spécialité, avec matières optionnelles | Must |
| EX-EXA-02 | Contrôle des dossiers : matricule, identité, photo, frais d’examen payés sur cartescolaire.cm, pièces | Must |
| EX-EXA-03 | Export au format demandé par l’organisateur ; fiche individuelle du candidat (DOC-19) | Must |
| EX-EXA-04 | Suivi des épreuves pratiques et des rapports de stage [TEC] | Should |
| EX-EXA-05 | Import des résultats officiels ; taux de réussite par classe, série, spécialité, enseignant de la matière | Must |
| EX-EXA-06 | Comparaison entre moyenne annuelle et résultat à l’examen (aide au pilotage pédagogique) | Could |


| ID | Règle |
|---|---|
| RG-EXA-1 | Seul un élève régulièrement inscrit dans la classe d’examen peut figurer sur la liste de l’établissement. |
| RG-EXA-2 | L’application ne délivre aucun diplôme ni relevé officiel ; elle produit des attestations internes et des statistiques. |


### ATL · Ateliers et travaux pratiques [TEC]

| ID | Exigence | Priorité |
|---|---|---|
| EX-ATL-01 | Ateliers par spécialité (électricité, mécanique, maçonnerie, menuiserie, habillement, comptabilité, secrétariat, etc.) avec postes, équipements et capacité | Must |
| EX-ATL-02 | Planning des séances pratiques par groupe, avec chef des travaux et professeur d’atelier | Must |
| EX-ATL-03 | Fiches de travaux pratiques : objectif, matière d’œuvre, outillage, consignes de sécurité | Should |
| EX-ATL-04 | Stock de matière d’œuvre : entrées, sorties par séance, coût par élève | Should |
| EX-ATL-05 | Évaluation pratique par grille de critères (préparation, exécution, sécurité, qualité, temps), convertie en note sur 20 | Must |
| EX-ATL-06 | Registre de sécurité : équipements de protection, incidents, contrôle des machines | Must |


| ID | Règle |
|---|---|
| RG-ATL-1 | Un élève sans équipement de protection requis ne peut pas être affecté à un poste machine ; l’enseignant le note et l’élève suit la séance en observation. |
| RG-ATL-2 | [PUB] Aucun frais de matière d’œuvre n’est demandé aux familles s’il n’est pas prévu par les textes (RG-FIN-2). |


### STG · Stages en entreprise [TEC]

| ID | Exigence | Priorité |
|---|---|---|
| EX-STG-01 | Entreprises d’accueil, conventions (DOC-21), tuteurs | Must |
| EX-STG-02 | Suivi : visites de l’enseignant, fiche d’appréciation du tuteur, rapport de stage | Must |
| EX-STG-03 | Note de stage intégrée aux moyennes selon le coefficient de la spécialité | Should |


### RHP · Personnel et vacations

| ID | Exigence | Priorité |
|---|---|---|
| EX-RHP-01 | Dossier du personnel enseignant et non enseignant ; statut (fonctionnaire, contractuel de l’État, salarié du privé, vacataire) | Must |
| EX-RHP-02 | Heures réalisées tirées du cahier de textes et de l’appel ; validation par le censeur | Must |
| EX-RHP-03 | Paiement des vacations et paie simplifiée du personnel du privé (socle S10) | Must |
| EX-RHP-04 | Absences et remplacements des enseignants | Must |


### SVC · Services annexes

| ID | Exigence | Priorité |
|---|---|---|
| EX-SVC-01 | Internat : chambres, présences du soir, sorties autorisées, frais | Should |
| EX-SVC-02 | Cantine et transport : abonnements, présences, lignes et arrêts | Could |
| EX-SVC-03 | Bibliothèque : catalogue, prêts, retards | Could |
| EX-SVC-04 | Infirmerie : passages, dispenses d’EPS, appel des parents ; données de santé visibles du seul infirmier | Should |


## Règles de calcul et notes
Les valeurs ci-dessous sont des paramètres par défaut. Les coefficients des exemples sont fictifs : en production, ils viennent des textes du MINESEC pour chaque niveau, série et spécialité. Les seuils de passage et les distinctions sont à aligner sur les textes en vigueur et le règlement intérieur.

### Paramètres

| Paramètre | Valeur par défaut |
|---|---|
| Échelle | 0 à 20, au quart de point |
| Séquences | 6 par an, 2 par trimestre |
| Note de séquence d’une matière | Moyenne pondérée des évaluations de la séquence (poids égaux par défaut) |
| Moyenne trimestrielle d’une matière | Moyenne des deux notes de séquence du trimestre |
| Moyenne trimestrielle générale | Σ (moyenne de la matière × coefficient) ÷ Σ coefficients des matières évaluées |
| Moyenne annuelle générale | Moyenne des trois moyennes trimestrielles générales (calendrier MINESEC 2021-2022, art. 23) |
| Composition de fin d’année | Si elle est organisée, comptée comme une évaluation de la séquence 6 (paramètre) |
| Arrondi | Au centième, à chaque étape |
| Rang | Calculé dans la classe sur la moyenne générale ; ex æquo au même rang, le suivant est décalé |
| Matière non évaluée | Exclue du calcul avec son coefficient, signalée sur le bulletin |


### Distinctions et sanctions du travail (exemple)

| Moyenne trimestrielle générale | Proposition au conseil de classe |
|---|---|
| 16 et plus | Félicitations et tableau d’honneur |
| 14 à moins de 16 | Encouragements et tableau d’honneur |
| 12 à moins de 14 | Tableau d’honneur |
| 8 à moins de 10 | Avertissement pour le travail |
| Moins de 8 | Blâme pour le travail |

Une distinction est refusée par défaut à un élève sanctionné pour la conduite dans le trimestre (paramètre).

### Politique de passage (exemple)

| Situation en fin d’année | Proposition automatique |
|---|---|
| Moyenne annuelle ≥ 10 | Admis en classe supérieure |
| 9 ≤ moyenne < 10 | Cas examiné par le conseil de classe : admission ou redoublement |
| Moyenne < 9 | Redoublement proposé |
| Moyenne < 9 et déjà redoublant dans le niveau, ou âge au-delà de la limite du niveau | Exclusion pour insuffisance proposée, décision du conseil |
| [TEC] Moyenne de pratique professionnelle < 8, quelle que soit la moyenne générale | Admission refusée par défaut ; le conseil peut accorder un passage conditionnel avec remise à niveau en atelier |


### Exemple 1 : élève de 4e, 1er trimestre (enseignement général)
Élève fictive : Aïcha M., 4e B, 58 élèves.

| Matière | Coefficient | Séquence 1 | Séquence 2 | Moyenne | Moyenne × coefficient |
|---|---|---|---|---|---|
| Français | 4 | 12 | 10,5 | 11,25 | 45,00 |
| Anglais | 3 | 9 | 11 | 10,00 | 30,00 |
| Mathématiques | 4 | 8,5 | 7 | 7,75 | 31,00 |
| Physique-chimie-technologie | 2 | 11 | 13 | 12,00 | 24,00 |
| Sciences de la vie et de la Terre | 2 | 14 | 12,5 | 13,25 | 26,50 |
| Histoire | 2 | 10 | 12 | 11,00 | 22,00 |
| Géographie | 1 | 13 | 11 | 12,00 | 12,00 |
| Éducation à la citoyenneté | 1 | 15 | 14 | 14,50 | 14,50 |
| Langues et cultures nationales | 1 | 16 | 15 | 15,50 | 15,50 |
| Informatique | 1 | 12 | 14 | 13,00 | 13,00 |
| Éducation physique et sportive | 1 | 14 | 16 | 15,00 | 15,00 |
| Total | 22 |  |  |  | 248,50 |

Moyenne du 1er trimestre : 248,50 ÷ 22 = 11,30. Proposition : aucune distinction ni sanction du travail. Avec des moyennes de 11,20 au 2e trimestre et 12,05 au 3e, la moyenne annuelle est (11,30 + 11,20 + 12,05) ÷ 3 = 11,52 : admise en 3e.
Effet dans l’application : la moyenne de mathématiques (7,75) est signalée au professeur principal et aux parents, et l’élève est proposée pour le soutien dans cette matière.

### Exemple 2 : élève de 3e année d’un CETIC, spécialité électricité
Élève fictif : Brice T., 3e année électricité.

| Matière | Coefficient | Séquence 1 | Séquence 2 | Moyenne | Moyenne × coefficient |
|---|---|---|---|---|---|
| Français | 2 | 11 | 10 | 10,50 | 21,00 |
| Anglais | 2 | 10 | 9 | 9,50 | 19,00 |
| Mathématiques | 3 | 9 | 11 | 10,00 | 30,00 |
| Sciences physiques | 2 | 10 | 10,5 | 10,25 | 20,50 |
| Technologie électrique | 4 | 12 | 11,5 | 11,75 | 47,00 |
| Dessin technique | 3 | 13 | 12 | 12,50 | 37,50 |
| Pratique professionnelle (atelier) | 6 | 7 | 8,5 | 7,75 | 46,50 |
| Total | 22 |  |  |  | 221,50 |

Moyenne du trimestre : 221,50 ÷ 22 = 10,07. La moyenne générale dépasse 10, mais la pratique professionnelle (7,75) est sous le seuil de 8. Si la situation se répète en fin d’année, l’application propose un refus d’admission, et le conseil de classe peut accorder un passage conditionnel avec remise à niveau en atelier.

### Évaluation pratique en atelier [TEC]

| Critère (exemple) | Points | Observé |
|---|---|---|
| Préparation du poste et lecture du schéma | 4 | 3 |
| Respect des consignes de sécurité | 4 | 2 |
| Exécution du montage | 6 | 4 |
| Qualité et contrôle du résultat | 4 | 2 |
| Temps alloué respecté | 2 | 1 |
| Note sur 20 | 20 | 12 |

Règle par défaut : un manquement grave à la sécurité plafonne la note pratique à 8, quelle que soit la somme des points (paramètre).

### Absences et conduite (exemple)

| Heures d’absence injustifiées dans le trimestre | Proposition |
|---|---|
| 10 | Avertissement de conduite |
| 20 | Blâme de conduite |
| 30 | Exclusion temporaire de 3 jours |
| Au-delà de 30 ou récidive | Conseil de discipline |


### Exemple de frais dans le privé (valeurs fictives)
Lycée privé laïc, classe de 2nde, deux enfants de la même famille. Les frais d’examen ne figurent pas ici car ils sont payés sur cartescolaire.cm.

| Ligne | Enfant 1 | Enfant 2 (fratrie, 10 % sur la pension) |
|---|---|---|
| Inscription | 3 500 | 3 500 |
| Pension | 150 000 | 135 000 |
| Tranche 1 (au 30 septembre) | 60 000 | 54 000 |
| Tranche 2 (au 15 janvier) | 50 000 | 45 000 |
| Tranche 3 (au 15 mars) | 40 000 | 36 000 |
| APEE | 10 000 | 10 000 |
| Total de l’année | 163 500 | 148 500 |

Un paiement de 120 000 FCFA reçu le 25 septembre pour la famille est réparti selon RG-FIN-4 : inscriptions (7 000), APEE (20 000), puis tranches 1 des deux enfants (93 000 sur 114 000 dus) ; le reste dû au 30 septembre est de 21 000.

## Impressions et documents
Les modèles sont paramétrables par établissement (en-tête, logo, signataires, langue). Les documents officiels portent un numéro unique et un QR de vérification.

### Catalogue des documents

| N° | Document | Émis par, à quel moment | Signataire | QR |
|---|---|---|---|---|
| DOC-01 | Fiche de préinscription et résultat du test d’entrée [PRI] | Scolarité | Aucun | Non |
| DOC-02 | Fiche d’inscription | Scolarité, à l’inscription | Parent, chef d’établissement | Non |
| DOC-03 | Certificat de scolarité | Scolarité, à la demande | Chef d’établissement | Oui |
| DOC-04 | Carte scolaire de l’élève | Scolarité, après inscription | Chef d’établissement | Oui |
| DOC-05 | Exeat (certificat de sortie) | Scolarité, au transfert | Chef d’établissement | Oui |
| DOC-06 | Liste de classe | Scolarité, à la demande | Aucun | Non |
| DOC-07 | Emploi du temps (classe, enseignant, salle, atelier) | Censeur | Aucun | Non |
| DOC-08 | Relevé de notes de séquence par classe | Enseignant, après verrouillage | Enseignant | Non |
| DOC-09 | Bulletin séquentiel | Censeur, fin de séquence | Chef d’établissement | Non |
| DOC-10 | Bulletin trimestriel | Censeur, après le conseil de classe | Chef d’établissement | Oui |
| DOC-11 | Bulletin annuel avec décision | Censeur, après le conseil de fin d’année | Chef d’établissement | Oui |
| DOC-12 | Dossier de conseil de classe | Censeur, avant le conseil | Aucun | Non |
| DOC-13 | Procès-verbal du conseil de classe | Professeur principal | Chef d’établissement, professeur principal | Oui |
| DOC-14 | Tableau d’honneur de la classe | Censeur, après le conseil | Chef d’établissement | Non |
| DOC-15 | Relevé des absences et de la conduite | Surveillant général | Surveillant général | Non |
| DOC-16 | Fiche d’orientation | Conseiller d’orientation | Conseiller, chef d’établissement | Non |
| DOC-17 | Procès-verbal du conseil de discipline | Surveillant général | Chef d’établissement | Oui |
| DOC-18 | Notification de sanction | Surveillant général | Chef d’établissement | Oui |
| DOC-19 | Fiche individuelle de candidat à un examen officiel | Scolarité | Chef d’établissement | Non |
| DOC-20 | Liste des candidats par examen, série ou spécialité | Scolarité | Chef d’établissement | Non |
| DOC-21 | Convention de stage [TEC] | Chef des travaux | Élève, parent, entreprise, chef d’établissement | Oui |
| DOC-22 | Fiche d’appréciation du stagiaire [TEC] | Tuteur en entreprise | Tuteur | Non |
| DOC-23 | Fiche de travaux pratiques et grille d’évaluation [TEC] | Professeur d’atelier | Professeur d’atelier | Non |
| DOC-24 | État du stock de matière d’œuvre [TEC] | Chef des travaux | Chef des travaux | Non |
| DOC-25 | Cahier de textes de la classe | Enseignants, en continu | Censeur (visa) | Non |
| DOC-26 | Rapport de couverture des programmes | Censeur, chaque trimestre | Chef d’établissement | Non |
| DOC-27 | Reçu de paiement | Automatique (socle) | Caissier ou cachet électronique | Oui |
| DOC-28 | Échéancier et relevé de compte de l’élève | Intendant | Aucun | Non |
| DOC-29 | Attestation de paiement ou quitus | Intendant | Intendant | Oui |
| DOC-30 | État des vacations et bulletin de paie [PRI] | Intendant | Chef d’établissement | Non |
| DOC-31 | Attestation de réussite à un examen officiel (interne, en attendant le diplôme) | Scolarité, après import des résultats | Chef d’établissement | Oui |
| DOC-32 | Rapport de rentrée et statistiques pour le MINESEC | Chef d’établissement | Chef d’établissement | Non |
| DOC-33 | Fichier des conseils de fin d’année pour cartescolaire.cm | Scolarité | Chef d’établissement | Non |
| DOC-34 | Rapport annuel des résultats (classes, examens, comparaison avec l’année précédente) | Chef d’établissement | Chef d’établissement | Non |


### Modèle de bulletin trimestriel
Exemple de bulletin trimestriel (DOC-10) avec les données de l’exemple 1

| Zone | Contenu |
|---|---|
| En-tête | République du Cameroun, MINESEC, délégation régionale et départementale, établissement (nom, statut, code, autorisation pour le privé) |
| Identité | Nom, prénoms, date et lieu de naissance, matricule national, classe, effectif, redoublant ou non |
| Corps | Par matière : notes des deux séquences, moyenne, coefficient, moyenne × coefficient, rang dans la matière, enseignant, appréciation |
| Synthèse | Moyenne générale, rang, moyennes de la classe (plus haute, plus basse, générale), absences, conduite, distinction ou sanction, décision |
| Pied | Visa du parent, signature du chef d’établissement, numéro, QR |


### Règles des documents

| ID | Règle |
|---|---|
| RG-DOC-1 | Numérotation unique par établissement, type et année ; jamais réutilisée. |
| RG-DOC-2 | La page de vérification du QR affiche le type de document, l’établissement, l’élève, la date et le statut, sans les notes. |
| RG-DOC-3 | Un bulletin trimestriel ou annuel n’est émis qu’après le conseil de classe (RG-BUL-3). |
| RG-DOC-4 | Aucun document scolaire ne mentionne une dette de l’élève ; le blocage pour impayé [PRI] retarde la remise mais n’altère pas le contenu (RG-FIN-1). |
| RG-DOC-5 | Les bulletins sont produits dans la langue de la section ; une version dans l’autre langue officielle peut être demandée. |
| RG-DOC-6 | Rééditions marquées « Duplicata » ou « Rectificatif », avec le numéro d’origine. |


### Notifications automatiques

| N° | Événement | Destinataires | Canal |
|---|---|---|---|
| NOT-01 | Inscription validée, matricule enregistré | Parent | SMS |
| NOT-02 | Absence à la première heure de cours | Parent | SMS |
| NOT-03 | Seuil d’heures d’absence atteint | Parent, surveillant général | SMS, notification |
| NOT-04 | Note publiée | Élève, parent | Notification |
| NOT-05 | Bulletin disponible (moyenne, rang, décision) | Parent, élève | SMS, portail |
| NOT-06 | Convocation au conseil de discipline | Parent | SMS, courrier |
| NOT-07 | Échéance de pension à J-7 [PRI] | Parent | SMS |
| NOT-08 | Tranche en retard [PRI] | Parent | SMS |
| NOT-09 | Paiement reçu | Payeur | SMS |
| NOT-10 | Frais d’examen non payés sur cartescolaire.cm à J-15 de la date limite | Parent, élève | SMS |
| NOT-11 | Saisie des notes en retard | Enseignant, censeur | Notification |
| NOT-12 | Cahier de textes non rempli depuis 7 jours | Enseignant, censeur | Notification |
| NOT-13 | Résultat d’examen officiel importé | Parent, élève | SMS |
| NOT-14 | Changement d’emploi du temps | Élèves, parents | Notification |


## Conception technique

### Cadre
L’application suit les choix de la plateforme : PHP 8.4, Laravel 13, API REST /api/v1, PostgreSQL 16, Redis et Horizon, Gotenberg pour les PDF, OpenAPI 3.1. Elle vit dans app/Modules/Apps/Secondary et n’appelle que les services publics du socle.

### Structure du module
app/Modules/Apps/Secondary/├── Setup/           # ETB : type, sections, séries, coefficients├── Enrolment/       # INS : inscriptions, transferts├── CarteScolaire/   # CSC : matricules, rapprochements, exports├── Fees/            # FIN : grilles privé et public, APEE├── Timetable/       # EDT : emplois du temps, ateliers├── Syllabus/        # CTX : progressions, cahier de textes├── Discipline/      # PRE : absences par heure, sanctions├── Grades/          # NOT : séquences, évaluations├── Councils/        # BUL, PAS : moyennes, bulletins, conseils├── OfficialExams/   # EXA : BEPC, CAP, Probatoire, BAC, GCE├── Workshops/       # ATL, STG : ateliers, stages├── Services/        # SVC : internat, cantine, transport└── Http/            # Contrôleurs, FormRequest, JsonResource

### Modèle de données propre
Toutes les tables portent tenant_id et establishment_id et sont préfixées se_.

| Entité | Champs clés | Remarques |
|---|---|---|
| se_sections, se_streams | langue, enseignement ; série ou spécialité, cycle | Sections, séries, spécialités |
| se_authorizations | objet, référence, date, fin, document | [PRI] Autorisations |
| se_subjects, se_coefficients | matière ; niveau, série, coefficient, volume, version | Référentiels |
| se_classes | niveau, série, section, effectif, professeur principal | Classes |
| se_students | matricule national, identité, parents, statut | Élèves |
| se_enrolments | élève, classe, année, redoublant, statut | Inscriptions |
| se_lessons, se_lesson_logs | progression ; séance, contenu, visa | Cahier de textes |
| se_absences | élève, date, heure, matière, motif, justifiée | Absences par heure |
| se_sanctions | élève, type, motif, décideur, date | Discipline |
| se_assessments, se_marks | séquence, matière, classe, poids ; note, code, état, version | Notes |
| se_period_results | élève, période, moyennes, rang, distinction, figé | Résultats |
| se_council_decisions | élève, année, proposition, décision, motif | Conseils |
| se_exam_candidates | élève, examen, série, frais payés, statut, résultat | Examens officiels |
| se_workshops, se_material_moves | spécialité, postes ; matière, quantité, séance | [TEC] Ateliers |
| se_internships | élève, entreprise, tuteur, dates, note | [TEC] Stages |


### API spécifiques

| ID | Méthode et chemin | Description | Rôles |
|---|---|---|---|
| API-01 | POST /api/v1/secondary/enrolments | Inscrire un élève | R13 |
| API-02 | PUT /api/v1/secondary/students/{id}/national-id | Enregistrer le matricule national | R13 |
| API-03 | POST /api/v1/secondary/carte-scolaire/payments/import | Importer les paiements constatés sur cartescolaire.cm | R11 |
| API-04 | GET /api/v1/secondary/carte-scolaire/year-end-export | Fichier des conseils de fin d’année | R03, R13 |
| API-05 | PUT /api/v1/secondary/classes/{id}/attendance/{date}/{hour} | Appel d’une heure de cours | R09 |
| API-06 | POST /api/v1/secondary/lesson-logs | Séance du cahier de textes | R09 |
| API-07 | PUT /api/v1/secondary/assessments/{id}/marks | Saisir les notes | R09 (ses classes) |
| API-08 | POST /api/v1/secondary/assessments/{id}/lock | Valider et verrouiller (double authentification) | R04 |
| API-09 | POST /api/v1/secondary/periods/{id}/compute | Calculer moyennes et rangs | R04 |
| API-10 | POST /api/v1/secondary/councils/{id}/decisions | Enregistrer les décisions du conseil | R03, R04 |
| API-11 | POST /api/v1/secondary/report-cards/{period}/publish | Publier les bulletins | R03 |
| API-12 | GET /api/v1/secondary/students/me/report-cards | Bulletins de l’élève | R17, R19 |
| API-13 | POST /api/v1/secondary/official-exams/{id}/candidates/export | Exporter une liste de candidats | R13, R03 |
| API-14 | POST /api/v1/secondary/workshops/{id}/sessions | Séance d’atelier et consommation | R06, R09 |
| API-15 | POST /api/v1/secondary/disciplinary-councils | Ouvrir un conseil de discipline | R05 |


### Sécurité propre

| Domaine | Mesure |
|---|---|
| Notes et rangs | Politique par affectation (enseignant, classe, matière) ; verrouillage avec double authentification ; versionnement des notes ; journal inaltérable |
| Mineurs | Données minimales ; photos sous consentement ; dossier de santé limité à l’infirmier ; journal des consultations |
| Bulletins publiés | Accessibles au seul élève et à ses parents rattachés ; liens de téléchargement signés et expirant en 24 h |
| Matricule national | Clé d’identification non devinable dans les URL (identifiant interne opaque), jamais exposée dans une page publique |
| Paiements | Clé d’idempotence, rapprochement à double contrôle, aucune suppression d’encaissement |
| Accès externes (R21 à R23) | Comptes nominatifs, lecture seule, durée limitée, périmètre restreint, journalisés |
| Séparation des tâches | Qui saisit ne verrouille pas ; qui encaisse ne valide pas les annulations |


### Exigences non fonctionnelles propres

| ID | Domaine | Exigence |
|---|---|---|
| NF-S1 | Calcul | Moyennes et rangs d’un établissement de 3 000 élèves calculés en moins de 60 secondes |
| NF-S2 | Bulletins | 3 000 bulletins PDF générés en moins de 15 minutes |
| NF-S3 | Pics | Saisie simultanée de 300 enseignants la veille des conseils de classe |
| NF-S4 | Hors-ligne | Appel et saisie des notes sans réseau, synchronisés sans perte |
| NF-S5 | Exactitude | Résultats identiques au centième à un calcul de référence indépendant sur 100 classes de test |


### Tests d’acceptation

| ID | Scénario | Résultat attendu |
|---|---|---|
| TST-01 | Calculer le trimestre de l’exemple « Aïcha M. » | Total 248,50 ; coefficients 22 ; moyenne 11,30 |
| TST-02 | Calculer l’année avec 11,20 et 12,05 aux trimestres suivants | Moyenne annuelle 11,52 ; proposition « admise » |
| TST-03 | Calculer l’exemple CETIC « Brice T. » | Moyenne 10,07 ; alerte pratique professionnelle sous 8 |
| TST-04 | Deux élèves ex æquo au 3e rang | Tous deux 3e ex æquo, le suivant 5e |
| TST-05 | Publier un bulletin avec une note non verrouillée | Refus |
| TST-06 | [PUB] Créer un frais « reprographie » hors catalogue | Refus, demande d’ajout tracée |
| TST-07 | Répartir 120 000 FCFA sur la famille de l’exemple de frais | Inscriptions et APEE soldées, 93 000 sur les tranches 1, reste 21 000 |
| TST-08 | Inscrire un élève sur la liste du BAC sans frais payés sur cartescolaire.cm | Candidat bloqué « frais non constatés » |
| TST-09 | Un enseignant saisit des notes dans une classe qui ne lui est pas affectée | Réponse 403, tentative journalisée |
| TST-10 | Une sanction est proposée à 20 heures d’absence | Proposition de blâme, appliquée seulement après décision |


## Feuille de route et charge

### Lots
L’application est réalisée pendant les semaines 24 à 40 de la feuille de route de la plateforme, avec un pilote dans un lycée privé et un établissement public, dont au moins un lycée technique ou un CETIC.

| Lot | Semaines | Contenu |
|---|---|---|
| E1 Entrée et finances | S24 à S28 | ETB, INS, CSC, FIN, portails (inscription, paiement) ; DOC-01 à DOC-06, DOC-27 à DOC-29 |
| E2 Vie scolaire | S29 à S33 | EDT, CTX, PRE, NOT ; DOC-07, DOC-08, DOC-15, DOC-17, DOC-18, DOC-25 |
| E3 Résultats et technique | S34 à S38 | BUL, PAS, EXA, ATL, STG, RHP ; DOC-09 à DOC-14, DOC-16, DOC-19 à DOC-24, DOC-26, DOC-30, DOC-31 |
| E4 Pilotage et pilote | S39 à S40, puis un trimestre de pilote | PIL, SVC, DOC-32 à DOC-34 ; reprise des données, formation, accompagnement |


### Charge estimée
En jours-personne, en plus du socle (682,5).

| Profil | E1 | E2 | E3 | E4 | Total |
|---|---|---|---|---|---|
| Chef de projet | 5 | 5 | 5 | 6 | 21 |
| Analyste métier et formateur | 8 | 8 | 10 | 20 | 46 |
| Designer UX/UI | 8 | 6 | 5 | 2 | 21 |
| Développeurs back-end Laravel | 40 | 45 | 50 | 12 | 147 |
| Développeurs front-end et mobile | 30 | 35 | 30 | 8 | 103 |
| Testeurs | 12 | 15 | 18 | 15 | 60 |
| DevOps et sécurité | 3 | 3 | 3 | 5 | 14 |
| Total | 106 | 117 | 121 | 68 | 412 |


### Risques

| Risque | Probabilité | Impact | Parade |
|---|---|---|---|
| Absence d’interface officielle avec cartescolaire.cm | Élevée | Élevé | Échanges par fichiers ; demande formelle d’interface au MINESEC |
| Coefficients et règles différents d’un texte à l’autre | Moyenne | Élevé | Référentiels versionnés tenus par la plateforme ; validation par un conseiller pédagogique |
| Contestations sur les rangs et les moyennes | Moyenne | Moyen | Calcul de référence testé (NF-S5), détail des calculs consultable |
| Enseignants vacataires peu équipés | Élevée | Moyen | Saisie sur téléphone, hors-ligne, import Excel |
| [TEC] Spécialités nombreuses et ateliers hétérogènes | Moyenne | Moyen | Grilles d’évaluation pratique paramétrables par spécialité |
| [PUB] Pressions pour ajouter des frais non autorisés | Moyenne | Élevé | Catalogue verrouillé (RG-FIN-2), journal des demandes |


### Questions ouvertes
☐ Établissements pilotes : quel lycée privé, quel établissement public, quel lycée technique ou CETIC ?
☐ Textes de référence à jour : coefficients par niveau et série, conditions de passage, distinctions ?
☐ Format attendu sur cartescolaire.cm pour les conseils de fin d’année et existence d’une interface ?
☐ Formats des listes de candidats de l’Office du Baccalauréat, de la Direction des examens et du GCE Board ?
☐ Spécialités techniques à couvrir dans le pilote et grilles d’évaluation pratique existantes ?
☐ Réseaux confessionnels à servir dès la version 1 ?

## Sources
MINESEC, organigramme (décret n° 2012/267 du 11 juin 2012)
MINESEC, calendrier scolaire 2021-2022
Journal du Cameroun, matricule unique et cartescolaire.cm (août 2024)
Journal du Cameroun, paiement des contributions exigibles 2024-2025
Journal du Cameroun, paiement électronique 2026-2027 (août 2026)
Cameroon Tribune, frais indus (octobre 2024)
Médiaterre, réforme du second cycle général (2018)
Infos pratiques, calendrier des examens officiels 2026
Cahier des charges de la plateforme SCHOLARIS, 8 octobre 2026
