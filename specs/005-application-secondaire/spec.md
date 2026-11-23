# Spécification : application Enseignement secondaire (un seul produit, personnalisé par le type d'établissement)

**Branche** : `feat/rewrite-php-laravel` · **Date** : 2026-10-22 (remplace la version du 2026-10-15) · **Source** : [cdc-secondaire.md](cdc-secondaire.md) (cahier des charges v1.0 du 9 octobre 2026 ; ses identifiants EX-, RG-, DOC-, NOT-, TST-, NF- sont conservés) · **Décision** : [decision-un-produit-secondaire.md](decision-un-produit-secondaire.md) · **Réutilisation** : [audit-reutilisation.md](audit-reutilisation.md) · **Questions** : [questions.md](questions.md)

## Résumé pour les non-techniciens
- **Un seul produit** (`secondary`) pour les 7 types d'établissement : lycée d'enseignement général (`leg`), lycée technique (`lycee_technique`), collège d'enseignement secondaire (`ces`), collège d'enseignement technique, industriel et commercial (`cetic`), collège privé (`college_prive`), lycée privé (`lycee_prive`) et autre (`autre`).
- Le **type choisi à la demande d'accès** règle des **paramètres** et active ou masque des **modules** : second cycle, ateliers, stages, frais du privé, catalogue de frais autorisés du public, paie, services annexes. Aucun code propre à un type.
- Ce que fait l'application : paramétrer l'établissement, inscrire et suivre les élèves (avec le matricule national), faire l'appel à chaque heure, tenir le cahier de textes, saisir les notes par séquence, calculer moyennes et rangs, tenir les conseils de classe, éditer les bulletins, décider du passage et de l'orientation, préparer les examens officiels, encaisser les frais, gérer les ateliers et les stages (enseignement technique), suivre le personnel, informer les familles.
- **Aucune règle officielle n'est codée.** Barèmes, coefficients, seuils, durées, calendriers et règles de passage sont des **paramètres** (valeur inconnue au départ ou valeur d'exemple marquée « à confirmer ») et des **questions** pour le client. Les chiffres du CDC servent seulement de jeu de test `example_cdc` (§8).

Légende de la colonne « Types » : **T** = tous ; **G** = enseignement général ; **TEC** = enseignement technique ; **C2** = second cycle ; **PRI** = établissement privé ; **PUB** = établissement public ; **EN** = section anglophone ; entre parenthèses, le module qui porte la fonction (§1.3).

## 1. Principe : type, profil, modules
### 1.1 Profil de type
À l'ouverture de l'application pour un établissement, le type lu sur l'établissement (`EstablishmentInfo::$subtype`) sélectionne un **profil de type** (paramètre de plateforme `secondary.type_profiles.<code>`, voir [decision-un-produit-secondaire.md](decision-un-produit-secondaire.md) §4) copié dans le **profil d'établissement**. Le profil fixe : cycles offerts, enseignements offerts, propriété (public, privé laïc, privé confessionnel), modules actifs, libellé du chef d'établissement.
### 1.2 Ce que l'administrateur peut ajuster
Cycles, enseignements, propriété (obligatoire pour `autre`), modules optionnels, sections francophone et anglophone, dans la limite des règles du profil (un établissement sans second cycle ne reçoit aucune classe de second cycle). Tout changement porte un motif et est journalisé.
### 1.3 Modules
| Code du module | Contenu | Actif par défaut pour |
|---|---|---|
| noyau | paramétrage, inscriptions, matricule, emplois du temps, cahier de textes, appel, discipline, notes, conseils, bulletins, passage, personnel, documents, messages, portails, tableaux de bord | tous |
| `second_cycle`, `orientation` | séries, orientation, probatoire, baccalauréat | `leg`, `lycee_technique`, `lycee_prive` |
| `official_exams` | candidats aux examens officiels, listes, résultats | tous (examens proposés filtrés par le profil) |
| `workshops` | ateliers, postes, EPI, matière d'œuvre, grilles pratiques, registre de sécurité | `lycee_technique`, `cetic` |
| `internships` | entreprises, conventions, visites, note de stage | `lycee_technique`, `cetic` |
| `fees_private` | grille de frais, tranches, remises, plans de paiement, blocages de documents | `college_prive`, `lycee_prive` |
| `fees_public_catalog` | catalogue de frais autorisés, demandes d'ajout tracées | `leg`, `lycee_technique`, `ces`, `cetic` |
| `authorizations` | registre des autorisations d'ouverture | `college_prive`, `lycee_prive` |
| `payroll_simple` | paie simplifiée du personnel | `college_prive`, `lycee_prive` |
| `network_indicators` | indicateurs partagés avec un réseau | aucun (option) |
| `boarding`, `canteen_transport`, `library`, `infirmary` | services annexes | aucun (options) |

`autre` : aucune valeur par défaut au-delà du noyau ; l'assistant de paramétrage pose les questions. Les valeurs par défaut sont **à confirmer** (Q18, Q28).

## 2. Acteurs et rôles
Les rôles sont créés **une fois** (migration de données, préfixe `sec_`), réutilisés par tous les types ; un rôle n'est proposé que si son module est actif. Matrice des droits détaillée : [cdc-secondaire.md](cdc-secondaire.md), « Matrice des droits ». La portée suit `ScopeType` (établissement, cycle, niveau, classe, matière).

| Code | Rôle | Types / module | Portée | Permissions principales (`secondary.*`) |
|---|---|---|---|---|
| R01 | Promoteur ou fondateur | PRI | établissements du client | `dashboard.read`, `finance.read`, `fees.validate` |
| R02 | Responsable de réseau | PRI (`network_indicators`) | établissements du réseau | `network.read` (agrégats seulement) |
| R03 | Chef d'établissement (proviseur ou principal selon le type) | T | établissement | `settings.manage`, `reportcard.validate`, `council.preside`, `discipline.council.preside`, `document.sign` |
| R04 | Censeur ou préfet des études | T | niveaux attribués | `timetable.manage`, `grades.validate`, `grades.lock`, `syllabus.visa`, `council.prepare` |
| R05 | Surveillant général | T | niveaux attribués | `attendance.manage`, `discipline.manage`, `conduct.enter` |
| R06 | Chef des travaux | TEC (`workshops`) | ateliers | `workshops.manage`, `stock.manage`, `safety.manage`, `internships.manage` |
| R07 | Animateur pédagogique ou chef de département | T | sa discipline | `syllabus.manage`, `commontests.manage` |
| R08 | Professeur principal | T | sa classe | `class.synthesis`, `appraisal.general` |
| R09 | Enseignant | T | ses classes et matières | `attendance.take`, `lesson.log`, `grades.enter`, `appraisal.subject` |
| R10 | Conseiller d'orientation | `orientation` | établissement | `orientation.manage` |
| R11 | Intendant ou économe | T (frais selon le mode) | établissement | `billing.manage`, `fees.catalog` |
| R12 | Caissier | T | établissement | `billing.cash` |
| R13 | Secrétaire ou chargé de la scolarité | T | établissement | `enrol.manage`, `documents.issue`, `exams.lists` |
| R14 | Infirmier | `infirmary` | établissement | `infirmary.manage` |
| R15 | Responsable internat, cantine ou transport | `boarding`, `canteen_transport` | service | `services.manage` |
| R16 | Bibliothécaire | `library` | établissement | `library.manage` |
| R17 | Élève | T | soi | lecture de ses notes publiées, emploi du temps, absences, documents |
| R18 | Délégué de classe | T | sa classe | contre-signature de l'appel |
| R19 | Parent ou tuteur | T | ses enfants | lecture, paiement, messagerie |
| R20 | Bureau de l'APEE | T | établissement | `apee.read` |
| R21 | Inspecteur pédagogique (externe) | T | établissements visités | lecture des cahiers de textes, progressions, résultats |
| R22 | Délégation départementale ou régionale (externe) | T | territoire | statistiques, contrôle des frais, listes d'examen |
| R23 | Brigade de contrôle des établissements privés (externe) | PRI (`authorizations`) | établissement contrôlé | lecture des autorisations, effectifs, frais, personnel |
| R24 | Administrateur fonctionnel | T | client | `identity.manage`, `settings.manage`, `profile.manage`, `documents.template.manage` |

Comptes externes R21 à R23 : lecture seule, durée limitée, journal des lectures (Q13).

## 3. Parcours principaux
1. **Ouvrir et choisir le profil** : le client ouvre `secondary` (essai) ; l'administrateur fonctionnel confirme le type, la propriété, les sections, les cycles et les modules proposés par le profil.
2. **Préparer l'année** : le censeur crée l'année, les trimestres, les séquences ; la scolarité crée les classes ; le censeur charge les matières, coefficients et volumes (depuis les référentiels de la plateforme) ; l'intendant prépare la grille de frais (PRI) ou le catalogue (PUB) ; le censeur génère les emplois du temps.
3. **Inscrire** : préinscription, inscription, matricule national (ou statut « en attente »), paiement de la première tranche (PRI), documents d'inscription.
4. **Vivre la journée** : l'enseignant fait l'appel à chaque heure (hors ligne), remplit le cahier de textes ; le surveillant général justifie les absences, propose et applique les sanctions.
5. **Évaluer** : l'enseignant saisit les notes de la séquence ; le censeur valide puis verrouille (deuxième authentification) ; les corrections passent par une demande motivée.
6. **Clore une période** : calcul des moyennes et rangs, dossier de conseil, conseil de classe, bulletins publiés aux familles.
7. **Clore l'année** : décisions de passage et d'orientation, examens officiels (candidatures, résultats), fichier des conseils de fin d'année pour le site de la carte scolaire, réinscriptions.
8. **Enseignement technique (TEC)** : planning des ateliers, séances pratiques avec équipements de protection, matière d'œuvre, évaluation pratique, stages en entreprise, note de stage.
9. **Piloter** : tableaux de bord (couverture des programmes, réussite, recouvrement ou frais, absentéisme), rapports de rentrée et annuel.

## 4. User stories priorisées
Les critères d'acceptation sont des tests ; les valeurs numériques viennent du **jeu de test `example_cdc`** (§8), jamais du code.

### P1 : MVP (lots E1 et E2)
**US1 Choisir le profil de l'établissement (profil, ETB)** : à l'ouverture, le type `ces` propose le seul 1er cycle, aucun module technique ; le type `lycee_technique` propose ateliers et stages.
- Acceptation : une classe de second cycle dans un établissement sans second cycle → 422 (RG-ORG-1) ; une route du module `workshops` pour un `leg` → 409 `module_not_enabled` ; le même `leg` active les ateliers depuis l'assistant : la route répond ; couper un module qui a des données le met en lecture seule, rien n'est supprimé ; chaque changement de profil est journalisé avec motif.

**US2 Paramétrer matières, coefficients et calendrier (ETB)** : le censeur charge matières, coefficients et volumes par niveau, série ou spécialité ; le calendrier fixe séquences et trimestres.
- Acceptation : une séquence hors des dates de son trimestre → 422 ; un coefficient fixé par la plateforme (« verrouillé ») ne se modifie pas dans l'établissement ; une matière propre au projet de l'école n'entre pas dans la moyenne officielle (coefficient hors moyenne) ; chaque version de coefficients garde sa date d'effet.

**US3 Inscrire et suivre les dossiers (INS, CSC)** : préinscription, inscription, réinscription, transfert, matricule national, liste des élèves en attente de matricule, import des paiements constatés sur le site de la carte scolaire, écarts.
- Acceptation : un élève sans matricule a le statut `pending_national_id` et figure dans la liste à régulariser (RG-INS-1) ; PRI : inscription dans une série ou un cycle sans autorisation valide → refus (RG-ORG-3) ; un exclu définitif ne se réinscrit qu'avec décision motivée du chef d'établissement (RG-INS-3) ; PUB : aucune inscription refusée pour impayé (RG-INS-4) ; le matricule n'apparaît dans aucune adresse ni page publique.

**US4 Encaisser (FIN)** : PRI : grille, remises, tranches, plans de paiement, relances ; PUB : catalogue de frais autorisés ; tous : paiements, reçus.
- Acceptation : PRI : un paiement est affecté selon l'ordre paramétré (RG-FIN-4) ; PUB : un frais absent du catalogue → 422 `fee_not_authorized` et une demande d'ajout tracée (RG-FIN-2) ; un frais d'examen déjà payé sur le site de la carte scolaire n'est jamais refacturé (RG-FIN-3) ; aucune action « renvoyer pour impayé » n'existe (RG-FIN-1).

**US5 Appel par heure et discipline (PRE)** : l'enseignant fait l'appel à chaque heure, hors ligne ; le surveillant général justifie, voit les propositions de sanction, ouvre un conseil de discipline.
- Acceptation : appel rejoué sans doublon (`client_uuid`) ; un seul SMS par élève et par jour à la première heure d'absence (paramètre) ; une sanction proposée n'est appliquée qu'après décision (RG-PRE-2) ; exclusion définitive seulement par conseil de discipline avec procès-verbal (RG-PRE-3) ; les seuils du barème viennent du règlement de l'établissement (paramètre).

**US6 Saisir et valider les notes (NOT)** : saisie (téléphone, hors ligne, import Excel), validation puis verrouillage avec deuxième authentification, corrections motivées validées en deux étapes.
- Acceptation : une note verrouillée n'est jamais écrasée (RG-NOT-4) ; le pas de saisie est un paramètre (une note hors pas → 422) ; fraude : zéro et incident pour le surveillant général (RG-NOT-3) ; un enseignant ne saisit que ses classes et matières (403 journalisé sinon).

**US7 Cahier de textes et couverture (CTX)** : progressions, séances signées, visas, taux de couverture.
- Acceptation : une séance hors des classes et matières de l'enseignant est refusée ; taux de couverture = heures faites / heures prévues à la date, calcul vérifié sur jeu d'exemple.

### P2 : lot E3
**US8 Moyennes, rangs, conseils, bulletins (BUL)** : calculs paramétrés, rangs avec ex æquo, dossier de conseil, décisions, procès-verbal, bulletins FR ou EN, publication en ligne et par SMS, rectificatif décidé par le chef.
- Acceptation : un bulletin n'est édité que si toutes les notes de la période sont verrouillées ou « non évaluées » (RG-BUL-1) ; après le conseil il est figé, un rectificatif porte la mention et le numéro d'origine (RG-BUL-3) ; la page publique de vérification n'affiche aucune note (RG-DOC-2).

**US9 Passage et orientation (PAS, `orientation`)** : propositions selon les paramètres, vœux, avis, fiche d'orientation, recours dans le délai paramétré.
- Acceptation : une proposition est calculée à partir des seuls paramètres ; la décision du conseil prévaut et est motivée si elle diffère ; un recours hors délai → 422.

**US10 Examens officiels (EXA, `official_exams`)** : listes de candidats par examen, série ou spécialité, contrôles, exports, import des résultats, attestations internes.
- Acceptation : seul un élève régulièrement inscrit dans la classe d'examen figure sur la liste (RG-EXA-1) ; candidat sans frais constatés → statut `blocked` (« frais non constatés ») ; aucun document « diplôme » n'est produit (RG-EXA-2).

**US11 Ateliers et stages (ATL, STG, TEC)** : séances pratiques, équipements de protection, matière d'œuvre, grilles d'évaluation, plafond de sécurité, conventions, visites, note de stage.
- Acceptation : un élève sans équipement de protection requis n'est pas affecté à un poste machine (RG-ATL-1) ; la grille pratique convertit la somme des points en note sur la base paramétrée ; le plafond appliqué en cas de manquement grave vient du paramètre ; PUB : aucun frais de matière d'œuvre hors catalogue (RG-ATL-2).

**US12 Personnel et vacations (RHP)** : statuts, heures tirées du cahier de textes et de l'appel, validation par le censeur, paie simplifiée du privé (`payroll_simple`).
- Acceptation : une séance sans appel fait n'est pas payable ; un fonctionnaire n'a jamais de bulletin de paie dans l'application.

### P3 : lot E4
**US13 Tableaux de bord et rapports (PIL)** : couverture, réussite, recouvrement, absentéisme, rapport de rentrée, fichier de fin d'année, rapport annuel.
**US14 Services annexes (SVC)** : internat, cantine et transport, bibliothèque, infirmerie (santé visible de l'infirmier seul).
**US15 Portails (POR)** : élève, parent, enseignant : emploi du temps, notes publiées, absences, bulletins par lien à durée limitée, paiements.
**US16 Réseau et contrôle externe (`network_indicators`, R21 à R23)** : indicateurs agrégés sans donnée nominative ; consultation externe en lecture seule et journalisée.

## 5. Exigences testables (EX-SEC)
Chaque exigence cite les identifiants du CDC qu'elle couvre. Priorité : M = Must, S = Should, C = Could. Lot : E1 à E4 ([plan.md](plan.md) §5).

| ID | Exigence et forme testable | CDC | Types (module) | Prio | Lot |
|---|---|---|---|---|---|
| EX-SEC-01 | Le profil de type est copié à l'ouverture (version conservée) ; un type inconnu ou absent est refusé | nouvelle | T | M | E1 |
| EX-SEC-02 | Cycles, enseignements, propriété, sections ajustables par l'administrateur dans les limites du profil ; journal avec motif | ETB-01 | T | M | E1 |
| EX-SEC-03 | Modules activables ; route d'un module inactif → 409 `module_not_enabled` ; menu masqué ; test d'architecture : toute route de module passe par la garde | nouvelle | T | M | E1 |
| EX-SEC-04 | Changement de type ou de module après ouverture : jamais de perte de données ; module coupé avec données → lecture seule | nouvelle | T | S | E1 |
| EX-SEC-05 | Vocabulaire et libellés des rôles suivent le type (chef d'établissement) ; aucun `if` sur le code de type hors résolveur | nouvelle | T | M | E1 |
| EX-SEC-06 | Référentiels (niveaux, séries, spécialités, examens, matières) tenus par la plateforme, filtrés par le profil, versionnés, marqués « exemple » tant que non fournis | ETB-03 | T | M | E1 |
| EX-SEC-07 | Établissement : code officiel, type, propriété, sections, cycles, enseignements ; classe refusée hors cycles du profil | ETB-01, RG-ORG-1, RG-ORG-2 | T | M | E1 |
| EX-SEC-08 | Registre des autorisations (création, ouverture, extensions de cycle, de série) avec dates et pièces ; alerte d'expiration | ETB-02, RG-ORG-3 | PRI (`authorizations`) | M | E1 |
| EX-SEC-09 | Matières, coefficients, volumes par niveau, série ou spécialité, versionnés ; coefficient verrouillé non modifiable ; matière de projet hors moyenne | ETB-03, RG-ORG-4 | T | M | E1 |
| EX-SEC-10 | Calendrier : trimestres, séquences (nombre et dates paramétrés), congés, conseils ; séquence incluse dans son trimestre | ETB-04 | T | M | E1 |
| EX-SEC-11 | Règlement intérieur : barème d'absences, sanctions, distinctions, conditions de passage, en paramètres de l'établissement dans les limites de la plateforme | ETB-05 | T | M | E1 |
| EX-SEC-12 | Salles, laboratoires, ateliers avec nature, capacité, équipements | ETB-06 | T (ateliers : TEC) | M | E1 |
| EX-SEC-13 | Préinscription (en ligne ou guichet), test d'entrée si activé, inscription avec pièces | INS-01 | T (test d'entrée : PRI) | M | E1 |
| EX-SEC-14 | Réinscription des élèves autorisés par le conseil ; exclu définitif : décision motivée requise | INS-02, RG-INS-3 | T | M | E1 |
| EX-SEC-15 | Dossier de l'élève : identité, matricule national, parents, contacts d'urgence, photo, parcours, situation particulière ; âge d'admission en alerte (paramètre) | INS-03, RG-INS-2 | T | M | E1 |
| EX-SEC-16 | Entrée depuis le primaire par fichier d'échange versionné | INS-04 | T | S | E1 |
| EX-SEC-17 | Transferts : exeat, dossier transmis, contrôle du matricule | INS-05 | T | M | E1 |
| EX-SEC-18 | Choix de série ou de spécialité à l'entrée du second cycle selon l'orientation | INS-06 | C2, TEC | M | E3 |
| EX-SEC-19 | Carte scolaire de l'élève avec photo, matricule et QR | INS-07 | T | S | E1 |
| EX-SEC-20 | Matricule national : saisie, import ; élève sans matricule « en attente » ; liste des élèves à régulariser par niveau d'entrée | CSC-01, CSC-02, RG-INS-1 | T | M | E1 |
| EX-SEC-21 | Import des paiements constatés par fichier ; rapprochement par matricule ; ligne inconnue, doublon : statuts | CSC-03, RG-CSC-2 | T | M | E1 |
| EX-SEC-22 | Fichier des conseils de fin d'année au format fourni par le client ; date limite en paramètre | CSC-04, RG-BUL-4 | T | M | E4 |
| EX-SEC-23 | Contrôle de cohérence application et site de la carte scolaire, écarts à corriger après vérification des pièces | CSC-05 | T | M | E1 |
| EX-SEC-24 | Grille de frais par niveau, série ou spécialité ; remises (fratrie, personnel, mérite, cas social) avec plafonds paramétrés | FIN-01, FIN-02 | PRI (`fees_private`) | M | E1 |
| EX-SEC-25 | Catalogue de frais autorisés ; frais hors catalogue refusé ; demande d'ajout tracée | FIN-03, RG-FIN-2, RG-ATL-2 | PUB (`fees_public_catalog`) | M | E1 |
| EX-SEC-26 | Paiements (mobile money, carte, virement, espèces), reçus, rapprochement ; frais d'examen payés ailleurs jamais refacturés | FIN-04, RG-FIN-3 | T | M | E1 |
| EX-SEC-27 | Relances graduées par SMS ; plan de paiement individuel | FIN-05 | PRI | M | E1 |
| EX-SEC-28 | Blocages paramétrables de la remise de documents (bulletin en ligne, documents, réinscription) ; jamais de blocage des cours ni des évaluations | FIN-06, RG-FIN-1, RG-DOC-4 | PRI | M | E1 |
| EX-SEC-29 | Situation financière par classe, niveau, établissement ; prévisions | FIN-07 | T | M | E4 |
| EX-SEC-30 | Génération assistée d'emplois du temps à partir des volumes et des disponibilités ; conflits détectés (enseignant, salle, classe, atelier) ; dédoublement des groupes | EDT-01 à EDT-03 | T (ateliers : TEC) | M | E2 |
| EX-SEC-31 | Remplacements et permutations notifiés | EDT-04 | T | S | E2 |
| EX-SEC-32 | Progression annuelle, cahier de textes signé, taux de couverture | CTX-01 à CTX-03 | T | M | E2 |
| EX-SEC-33 | Visas du censeur et de l'animateur ; consultation par l'inspecteur | CTX-04 | T | S | E2 |
| EX-SEC-34 | Appel par heure, hors ligne ; retards, absences justifiées ou non, exclusions de cours, passages à l'infirmerie | PRE-01, PRE-02 | T (infirmerie : `infirmary`) | M | E2 |
| EX-SEC-35 | SMS au parent dès la première heure d'absence du jour (un seul par jour) | PRE-03 | T | M | E2 |
| EX-SEC-36 | Barème disciplinaire en paramètres ; propositions automatiques de sanction, appliquées après décision | PRE-04, RG-PRE-1, RG-PRE-2 | T | M | E2 |
| EX-SEC-37 | Conseil de discipline : convocation, dossier, décision, procès-verbal, notification | PRE-05, RG-PRE-3 | T | M | E2 |
| EX-SEC-38 | Conduite reportée au bulletin | PRE-06 | T | M | E3 |
| EX-SEC-39 | Séquences (nombre paramétré), plusieurs évaluations par séquence avec poids ; saisie téléphone, ordinateur, hors ligne, import Excel ; codes d'absence, de dispense, de fraude | NOT-01 à NOT-03, RG-NOT-1 à RG-NOT-3 | T | M | E2 |
| EX-SEC-40 | Épreuves communes par niveau | NOT-04 | T | S | E2 |
| EX-SEC-41 | Cycle de vie des notes ; verrouillage par deuxième authentification ; correction motivée en deux validations | NOT-05, NOT-06, RG-NOT-4 | T | M | E2 |
| EX-SEC-42 | Notes publiées aux élèves et parents après validation, avec moyenne de classe | NOT-07 | T | M | E2 |
| EX-SEC-43 | Correspondance note et lettre (optionnelle) pour les bulletins anglophones | NOT-08 | EN | S | E2 |
| EX-SEC-44 | Calculs paramétrés : moyennes par séquence, trimestre, année ; rangs avec ex æquo ; statistiques de classe ; arrondi paramétré | BUL-01 | T | M | E3 |
| EX-SEC-45 | Bulletins séquentiel, trimestriel, annuel, FR ou EN ; appréciations par matière et générale, avec banque de formulations | BUL-02, BUL-03, RG-DOC-5 | T | M | E3 |
| EX-SEC-46 | Dossier de conseil de classe : moyennes, distribution, couverture, assiduité, cas à examiner | BUL-04 | T | M | E3 |
| EX-SEC-47 | Décisions du conseil et procès-verbal ; rectificatif sur décision du chef | BUL-05, RG-BUL-2, RG-BUL-3 | T | M | E3 |
| EX-SEC-48 | Publication des bulletins en ligne et par SMS (résumé) ; tableau d'honneur ; liens à durée limitée | BUL-06, BUL-07 | T | M | E3 |
| EX-SEC-49 | Propositions de passage calculées à partir des paramètres ; décision du conseil prévalente et motivée | PAS-01 | T | M | E3 |
| EX-SEC-50 | Orientation : vœux, avis du conseil, fiche, entretien ; recours dans le délai paramétré | PAS-02 à PAS-04 | `orientation` (C2) | M | E3 |
| EX-SEC-51 | Listes de candidats par examen, série ou spécialité ; contrôles (matricule, identité, photo, frais constatés, pièces) ; export au format fourni ; fiche individuelle | EXA-01 à EXA-03, RG-EXA-1 | T (`official_exams`) | M | E3 |
| EX-SEC-52 | Import des résultats ; taux de réussite par classe, série, spécialité, enseignant ; comparaison moyenne et examen ; aucun diplôme produit | EXA-05, EXA-06, RG-EXA-2 | T | M | E3 |
| EX-SEC-53 | Suivi des épreuves pratiques et des rapports de stage | EXA-04 | TEC | S | E3 |
| EX-SEC-54 | Ateliers par spécialité (postes, équipements, capacité), planning par groupe, fiches de travaux pratiques | ATL-01 à ATL-03 | TEC (`workshops`) | M | E3 |
| EX-SEC-55 | Stock de matière d'œuvre : entrées, sorties par séance, coût par élève | ATL-04 | TEC | S | E3 |
| EX-SEC-56 | Évaluation pratique par grille de critères (critères et points en paramètres), conversion en note, plafond de sécurité paramétré, alimentation d'une épreuve du socle | ATL-05 | TEC | M | E3 |
| EX-SEC-57 | Registre de sécurité : équipements de protection, incidents, contrôle des machines ; élève sans protection en observation | ATL-06, RG-ATL-1 | TEC | M | E3 |
| EX-SEC-58 | Stages : entreprises, conventions, tuteurs, visites, appréciation, rapport, note intégrée par coefficient | STG-01 à STG-03 | TEC (`internships`) | M | E3 |
| EX-SEC-59 | Dossier du personnel ; statuts (fonctionnaire, contractuel de l'État, salarié du privé, vacataire) | RHP-01 | T | M | E3 |
| EX-SEC-60 | Heures tirées du cahier de textes et de l'appel ; validation par le censeur | RHP-02 | T | M | E3 |
| EX-SEC-61 | Vacations ; paie simplifiée (éléments en paramètres) | RHP-03 | vacations : T ; paie : PRI (`payroll_simple`) | M | E3 |
| EX-SEC-62 | Absences et remplacements des enseignants | RHP-04 | T | M | E3 |
| EX-SEC-63 | Internat : chambres, présences du soir, sorties, frais | SVC-01 | `boarding` | S | E4 |
| EX-SEC-64 | Cantine et transport : abonnements, lignes, arrêts | SVC-02 | `canteen_transport` | C | E4 |
| EX-SEC-65 | Bibliothèque : catalogue, prêts, retards | SVC-03 | `library` | C | E4 |
| EX-SEC-66 | Infirmerie : passages, dispenses d'EPS, appel des parents ; santé visible de l'infirmier seul | SVC-04 | `infirmary` | S | E4 |
| EX-SEC-67 | Tableaux de bord et indicateurs O1 à O7 du CDC par rôle ; aucune donnée nominative pour le réseau | PIL, RG-ORG-5 | T (réseau : PRI) | M | E4 |
| EX-SEC-68 | Portails élève, parent, enseignant ; bulletins par lien à durée limitée | POR | T | M | E4 |
| EX-SEC-69 | Comptes externes R21 à R23 : lecture seule, datés, journalisés | sécurité | T (R23 : PRI) | S | E4 |
| EX-SEC-70 | Sécurité : affectation enseignant, classe, matière ; deuxième authentification au verrouillage ; données de mineurs ; matricule jamais dans une adresse ni une page publique ; séparation des tâches | sécurité | T | M | tous |
| EX-SEC-71 | Hors ligne : appel, notes, cahier de textes ; conflits 409 jamais écrasés | NF-S4 | T | M | E2 |
| EX-SEC-72 | 34 documents et 14 notifications du CDC ; chacun émis par un test ; conditionnels au module (§9) | DOC, NOT | T | M | E1 à E4 |
| EX-SEC-73 | Écriture refusée en 423 si l'abonnement est suspendu, en 403 s'il est fermé ; lecture et export maintenus en suspension | plateforme | T | M | E1 |

**Synthèse** : 73 exigences ; elles couvrent les 81 exigences et les 36 règles du CDC. Must : 59 ; Should : 12 ; Could : 2.

## 6. Règles de gestion
### 6.1 Règles nouvelles (type, profil, modules)
- RG-SEC-1 Le type d'un établissement ne peut être qu'un des 7 codes de la liste fermée ; le code stocké ne change jamais de sens.
- RG-SEC-2 Le profil copié à l'ouverture est la seule source des cycles, enseignements et modules d'un établissement ; une évolution du profil de plateforme ne le modifie pas sans action de l'administrateur.
- RG-SEC-3 Toute route d'un module activable passe par la garde de module (409 `module_not_enabled`) ; toute route d'écriture passe par la garde d'abonnement.
- RG-SEC-4 Aucune valeur réglementaire (barème, coefficient, seuil, durée, calendrier, règle de passage) n'est écrite dans le code ni dans les données livrées comme un fait ; une valeur d'exemple porte l'étiquette `example_*` et la mention « à confirmer ».
- RG-SEC-5 Les calculs de notes, résultats, conseils, présences, sanctions et frais restent dans le socle ; l'application n'ajoute que ses profils et ses règles.
### 6.2 Règles du CDC reprises
Les règles RG-ORG-1 à 5, RG-INS-1 à 4, RG-CSC-1 à 2, RG-FIN-1 à 4, RG-PRE-1 à 3, RG-NOT-1 à 4, RG-BUL-1 à 4, RG-EXA-1 à 2, RG-ATL-1 à 2 et RG-DOC-1 à 6 s'appliquent telles que rédigées dans le CDC, **mais toute valeur qu'elles citent est un paramètre** (liste §7). Exemple : RG-PRE-1 donne la forme du barème (seuils d'heures d'absence, sanctions) ; les seuils sont ceux du règlement de l'établissement (Q2).

## 7. Règles de calcul : paramètres, pas faits
Toute valeur est vide ou une valeur d'exemple pour les tests ; la valeur réelle vient du client (Q2, Q8, Q15, Q16, Q17).

| Règle | Ce que le paramètre décide | Clé de paramètre |
|---|---|---|
| Échelle et pas de saisie | barème des notes et pas de saisie | `RuleSet::scale`, `assessment.step` |
| Note de séquence d'une matière | pondération des épreuves de la séquence | stratégie `secondary` |
| Moyenne trimestrielle d'une matière | combinaison des séquences du trimestre | stratégie, `period_weights` |
| Moyenne trimestrielle générale | pondération par coefficients, matières évaluées seulement | stratégie |
| Moyenne annuelle | combinaison des trimestres | stratégie, année |
| Composition de fin d'année | existence, séquence concernée, poids | option `final_exam_in_sequence` |
| Arrondi | nombre de décimales, sens, arrondi à chaque étape ou seulement à la fin | `rounding`, `round_each_step` |
| Rang | ex æquo au même rang, rang suivant décalé | `RuleEngine::ranks` |
| Matière non évaluée | exclusion avec son coefficient, signalement | stratégie |
| Distinctions et sanctions du travail | tranches de moyenne et libellés | `councilProposals` |
| Distinction refusée après sanction de conduite | oui ou non | option `distinction_blocked_by_conduct` |
| Passage | seuil d'admission, bande d'examen par le conseil, seuil de redoublement, âge limite | options `promotion.*` |
| Pratique professionnelle (TEC) | matières concernées, seuil de refus, passage conditionnel | option `practical_min`, rôle de matière `practical` |
| Grille pratique | critères, points, base de la note, plafond de sécurité | `secondary.workshop.*` |
| Absences | seuils d'heures injustifiées et sanctions proposées | `secondary.discipline.scale` |
| Affectation d'un paiement (PRI) | ordre des natures, ordre entre enfants d'une famille | `billing.allocation_kind_order`, `billing.allocation_sibling_order` |
| Recours | délai en jours | `secondary.appeal_days` |
| Lettres (EN) | table note et lettre | `secondary.grades.letter_scale` |

## 8. Jeu de test `example_cdc` (valeurs du CDC, non officielles)
Les exemples chiffrés du CDC sont conservés comme **jeux de test** nommés `example_cdc` ; le code est testé avec ces valeurs fournies par le test, jamais écrites dans le code. Un jeu de règles réel remplacera le jeu d'exemple quand le client répondra (Q2). Détail des valeurs : [tasks.md](tasks.md), tâches T940 à T949.
- **E1 Aïcha M. (TST-01)** : 11 matières, coefficients 22, total 248,50, moyenne arrondie 11,30 ; aucune proposition ; une matière sous la barre signalée.
- **E2 année (TST-02)** : trois moyennes trimestrielles → moyenne annuelle 11,52, décision « admise » avec le jeu de règles d'exemple.
- **E3 Brice T. (TST-03)** : total 221,50 / 22 = 10,07 ; alerte pratique ; refus proposé en fin d'année si la pratique annuelle est sous le seuil d'exemple, passage conditionnel possible (TEC).
- **E4 ex æquo (TST-04)** : deux troisièmes ex æquo, le suivant cinquième.
- **E5 grille pratique** : 3 + 2 + 4 + 2 + 1 = 12 sur 20 ; plafond d'exemple appliqué en cas de manquement grave.
- **E6 absences (TST-10)** : heures injustifiées → sanction proposée selon le barème d'exemple, appliquée seulement après décision.
- **E7 frais (TST-07)** : deux enfants d'une famille, grille et remise d'exemple, paiement de 120 000 réparti selon l'ordre d'exemple ; totaux et reste dû vérifiés.
- **TST-05, 06, 08, 09** : refus (bulletin avec note non verrouillée ; frais hors catalogue avec demande tracée ; candidat sans frais constatés ; saisie dans une classe non affectée, 403 journalisé).
- **Nouveau, par type** : un établissement de chacun des 7 types est créé par la fabrique de test ; les tests TST-T1 à TST-T7 vérifient que cycles, modules, rôles et menus suivent le profil (tâches T950 à T958).

## 9. Documents et notifications
Les 34 documents (DOC-01 à DOC-34) et 14 notifications (NOT-01 à NOT-14) du CDC sont repris ; les codes **NOT-** de ce tableau sont ceux des notifications du CDC (à ne pas confondre avec les exigences d'évaluation du CDC, nommées aussi NOT-).

| Condition | Documents ou notifications concernés |
|---|---|
| noyau (tous types) | DOC-01 à 20, 25 à 29, 31 à 34 ; notifications 01 à 06, 09, 11 à 14 |
| `fees_private` | notifications 07 (échéance) et 08 (retard) ; blocage de remise de document |
| `workshops`, `internships` | DOC-21 (convention de stage), 22 (appréciation du stagiaire), 23 (fiche de travaux pratiques), 24 (stock de matière d'œuvre) |
| `payroll_simple` | DOC-30 (vacations et bulletin de paie) |
| `official_exams` | DOC-19, 20, 31 ; notifications 10 et 13 |

Règles de document : RG-DOC-1 à RG-DOC-6 (numérotation, vérification sans notes, bulletin après conseil, aucune dette mentionnée, langue de la section, mentions « Duplicata » et « Rectificatif »).

## 10. Exigences non fonctionnelles et sécurité
NF-S1 à NF-S5 du CDC, mesurées sur MySQL 8 réel (pas seulement SQLite) : les seuils de performance sont ceux du CDC, à confirmer au pilote (Q10). Sécurité : affectation enseignant, classe, matière ; deuxième authentification au verrouillage ; versionnement ; données de mineurs ; liens à durée limitée ; matricule jamais dans une adresse ni une page publique ; idempotence des envois ; comptes externes en lecture seule datés ; séparation des tâches ; **HTTPS actif avant toute donnée réelle d'élève** (données synthétiques jusque-là).

## 11. Critères de succès
- SC-1 Les 10 TST et les 7 exemples passent avec le jeu `example_cdc`.
- SC-2 Pour chacun des 7 types, la fabrique de test produit un établissement dont cycles, modules, rôles et menus suivent le profil ; aucune branche de code ne teste le code de type hors du résolveur (test d'architecture).
- SC-3 NF-S5 : 100 classes générées, écart nul avec un calcul de référence écrit séparément.
- SC-4 NF-S1 et NF-S2 mesurés sur la configuration de production.
- SC-5 Zéro route d'écriture hors garde d'abonnement ; zéro route de module hors garde de module ; zéro valeur réglementaire codée en dur.
- SC-6 PUB : zéro frais créé hors catalogue ; tout refus produit une demande tracée.
- SC-7 Les 34 documents et les 14 notifications existent, chacun avec un test d'émission.

## 12. Hors périmètre
Organisation des examens officiels, délivrance de diplômes, paie des fonctionnaires, comptabilité générale (export seulement), plateforme d'enseignement en ligne, automatisation du site de la carte scolaire (RG-CSC-1), création par lot d'établissements publics, application « secondaire public » distincte.
