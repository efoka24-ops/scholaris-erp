Cahier des charges des applications Primaire et Maternelle
Écoles primaires et maternelles publiques du Cameroun, sur la plateforme SCHOLARIS : de l’école à l’inspection, à la délégation et au ministère
Emmanuel FOKA
9 octobre 2026 — version 1.0 (projet)

## Présentation du document

### Objet
Ce cahier des charges décrit les applications Primaire et Maternelle de la plateforme SCHOLARIS, dans leur version destinée aux écoles publiques relevant du Ministère de l’Éducation de base (MINEDUB). Il couvre :
la gestion quotidienne de l’école : élèves, classes, présences, enseignants, évaluation par compétences, bulletins, communication avec les familles, association des parents ;
la sécurité et le suivi du jeune enfant en maternelle ;
la remontée des données vers la chaîne de supervision : inspection d’arrondissement, délégation départementale, délégation régionale, services centraux ;
la préparation des examens de fin de primaire (CEP, FSLC) et des listes pour l’entrée au secondaire ;
la carte scolaire et les statistiques annuelles.
Un seul document traite les deux applications, car elles partagent le même environnement public, les mêmes acteurs de supervision et souvent la même école (groupes scolaires avec section maternelle). Les parties propres à chaque application sont signalées.

### Place dans la plateforme

| Les applications utilisent (socle) | Elles apportent (spécifique) |
|---|---|
| S01 Comptes et rôles | Rôles du public : directeur d’école, inspecteur, délégué, conseiller pédagogique, bureau de l’APEE |
| S02 Organisation | Extension demandée au socle : périmètres territoriaux région, département, arrondissement au-dessus des écoles |
| S03 Personnes et relations | Élève sans acte de naissance, personnes autorisées à récupérer l’enfant |
| S04 Frais et paiements | Contributions APEE et dons uniquement ; aucun frais de scolarité |
| S05 Communication | SMS et messages vocaux en langue locale pour les parents sans smartphone |
| S06 Documents | 28 documents du primaire et de la maternelle publics |
| S08 Présences | Présence des enseignants, contrôlée par l’inspection |
| S09 Moteur d’évaluation | Évaluation par compétences : unités d’apprentissage, critères, mentions A, ECA, NA |
| S10 Personnel | Statuts du public (fonctionnaire, contractuel, maître des parents) ; pas de paie de l’État |
| S12 Tableaux de bord | Indicateurs consolidés par arrondissement, département, région, pays |
| S14 Intégrations | Échanges avec les outils statistiques du MINEDUB et l’application Secondaire |


### Ce qui change dans le public

| Sujet | École privée | École publique (ce document) |
|---|---|---|
| Client de la plateforme | Le promoteur de l’école ou du groupe | Le MINEDUB, ou un programme pilote sur un territoire |
| Frais | Pension, inscription, cantine | Aucun frais de scolarité ; contributions APEE gérées à part, jamais condition d’accès |
| Supervision | Interne au groupe | Inspection d’arrondissement, délégations départementale et régionale, services centraux |
| Enseignants | Salariés de l’école | Fonctionnaires et contractuels de l’État, maîtres des parents payés par l’APEE |
| Équipement | Variable | Souvent un téléphone par enseignant, parfois pas d’électricité ni de réseau |
| Effectifs | Classes moyennes | 56 élèves par enseignant en moyenne dans le public en 2021/2022, classes jumelées |
| Données attendues | Pilotage interne | Carte scolaire et annuaire statistique nationaux |


### Hypothèses retenues

| Hypothèse | Conséquence |
|---|---|
| Le client est le MINEDUB ou un programme qui couvre un territoire (arrondissement, département, région) | Un client unique contient des milliers d’écoles et une hiérarchie territoriale |
| Le déploiement commence par un pilote d’un arrondissement | Les volumes et le dispositif d’accompagnement sont dimensionnés pour passer ensuite à l’échelle |
| Les écoles ont une connectivité faible ou intermittente | Fonctionnement hors-ligne obligatoire pour toutes les saisies de l’école |
| La paie des enseignants de l’État est gérée hors de la plateforme | Les applications gèrent présences, affectations et carrière utile au pilotage, pas la solde |
| Les règles d’évaluation et de passage données ici sont des valeurs par défaut | Elles sont validées par l’Inspection générale des enseignements du MINEDUB avant mise en service |
| Les deux sous-systèmes (francophone et anglophone) sont couverts | Vocabulaire, classes, examens et documents existent en français et en anglais |


### Conventions

| Élément | Signification |
|---|---|
| Must / Should / Could | Indispensable au pilote / attendu en version 1 / souhaitable |
| EX-xxx-nn | Exigence fonctionnelle |
| RG-xxx-n | Règle de gestion |
| DOC-nn, NOT-nn | Document imprimable, notification |
| [P] [M] | Exigence propre au Primaire, à la Maternelle ; sans mention, commune aux deux |
| Paramètre | Valeur réglable sans développement, au niveau national, régional ou de l’école |


## Contexte

### Chiffres clés
Année scolaire 2021/2022, dépliant de l’annuaire statistique du MINEDUB publié par l’INS (DPPC/MINEDUB) :

| Indicateur | Préscolaire public | Primaire public |
|---|---|---|
| Écoles | 4 318 | 13 258 |
| Élèves | 172 306 | 3 667 463 |
| Enseignants | 10 229 | 65 839 |
| Élèves par enseignant | — | 56 (43 tous secteurs) |

Le taux brut de scolarisation était de 39,7 % au préscolaire et de 119,2 % au primaire ; sur 100 élèves entrés au primaire, 75 atteignent le CM2 (même source).

### Cadre réglementaire et pédagogique
Gratuité. La gratuité de l’école primaire publique a été annoncée le 10 février 2000 ; une circulaire du 24 juillet 2000 a instauré un « paquet minimum » de fonctionnement pour les écoles (Memoire Online). Des contributions des associations de parents (APEE) restent demandées, entre 5 000 et 15 000 FCFA au primaire selon un article de septembre 2024 (Le360 Afrique).
Organisation. Le décret n° 2012/268 du 11 juin 2012 organise le MINEDUB avec des délégations régionales, des délégations départementales et des inspections d’arrondissement de l’éducation de base. L’inspection identifie les besoins, tient la carte scolaire de l’arrondissement, inspecte les écoles et contrôle l’accès gratuit à l’école publique ; la délégation départementale tient un service des statistiques et de la carte scolaire et délivre les attestations du CEP et du FSLC ; la délégation régionale signe ces certificats ; la Direction des examens, des concours et de la certification organise les examens (Camerlex).
Curriculum. Le curriculum de 2018 de l’enseignement primaire francophone suit l’approche par compétences. Au niveau 1 (SIL, CP), l’année est organisée en 8 centres d’intérêt d’environ un mois ; la dernière semaine de chaque mois sert à l’intégration, à l’évaluation et à la remédiation ; l’horaire est de 30 h par semaine à plein temps et 25 h à mi-temps (curriculum niveau 1, MINEDUB 2018).
Bulletin. Depuis 2018-2019, le bulletin du primaire remplace les notes sur 20 par trois mentions par discipline : A (compétence acquise), ECA (en cours d’acquisition), NA (non acquise) ; les six séquences ont été remplacées par des évaluations mensuelles (Cameroon Tribune, janvier 2019).
Structure. Le primaire compte six classes (SIL à CM2 ; Class 1 à Class 6) ; la maternelle compte trois classes (petite, moyenne et grande section ; Pre-Nursery, Nursery 1, Nursery 2), avec un âge légal d’entrée de 4 ans (Wikipédia).
Examens. Le primaire se termine par le CEP (francophone) et le FSLC (anglophone) ; en 2026, épreuves écrites les 16 et 17 juin, résultats au plus tard le 10 juillet. Le Common Entrance anglophone a eu lieu le 13 mai 2026 (Infos pratiques). Le concours d’entrée en 6e, organisé par le MINESEC, a réuni plus de 250 000 élèves de CM2 en 2025 (Journal du Cameroun).
Données personnelles. La loi n° 2024/017 sur la protection des données personnelles s’applique ; les élèves sont des mineurs, ce qui impose minimisation des données, consentement des parents et accès restreint.

### Ce que les applications doivent résoudre

| Problème courant | Conséquence | Réponse |
|---|---|---|
| Registres d’appel et d’inscription sur papier | Effectifs incertains, données de la carte scolaire saisies deux fois | Registre numérique de l’école, source unique de la carte scolaire |
| Questionnaire statistique annuel rempli à la main | Retards, erreurs, consolidation lente | Questionnaire pré-rempli depuis les données de l’école, validation en cascade |
| Bulletins par compétences rédigés à la main pour 50 à 100 élèves | Charge des enseignants, documents hétérogènes | Saisie des critères sur téléphone, bulletins générés |
| Absentéisme des enseignants peu visible | Heures d’enseignement perdues | Présence des enseignants remontée à l’inspection |
| Contributions APEE peu transparentes | Conflits, pression sur les familles | Reçus, comptes publiés à l’assemblée générale, aucune exclusion pour non-paiement |
| Enfants sans acte de naissance | Problème au moment du CEP | Signalement précoce et liste suivie par l’école et l’inspection |
| Sorties de maternelle mal contrôlées | Risque pour l’enfant | Liste des personnes autorisées, contrôle à la sortie |


## Objectifs et indicateurs

| N° | Objectif | Indicateur | Cible après un an de pilote |
|---|---|---|---|
| O1 | Connaître les effectifs réels | Écart entre effectifs déclarés et présents lors d’un contrôle | Moins de 2 % |
| O2 | Accélérer la carte scolaire | Délai entre la rentrée et la consolidation des données de l’arrondissement | Moins de 30 jours |
| O3 | Alléger les bulletins | Temps de l’enseignant pour produire les bulletins d’une classe | Divisé par 3 |
| O4 | Suivre la présence des enseignants | Écoles qui remontent la présence chaque semaine | 90 % |
| O5 | Préparer le CEP et le FSLC sans erreur | Candidats rejetés pour dossier incomplet ou état civil manquant | 0 |
| O6 | Rendre l’APEE transparente | Écoles qui publient leur état financier APEE | 100 % |
| O7 | Protéger les jeunes enfants | Sorties de maternelle sans personne autorisée | 0 |


## Périmètre

| Inclus | Exclu ou traité ailleurs |
|---|---|
| Identité et carte scolaire de l’école (infrastructures, équipements) | Paie des fonctionnaires et contractuels de l’État |
| Inscriptions, transferts, radiations | Organisation matérielle du CEP, du FSLC et des concours (MINEDUB, MINESEC) |
| Classes, classes jumelées, double flux | Construction et maintenance des bâtiments |
| Présences des élèves et des enseignants | Gestion comptable de l’État |
| Évaluation par compétences et bulletins [P] | Plateforme d’enseignement à distance |
| Suivi du développement et cahier de vie [M] | Dossier médical de l’enfant |
| Sécurité des sorties [M] |  |
| Passage de classe et conseil des maîtres |  |
| Listes de candidats CEP, FSLC, entrée en 6e et Common Entrance [P] |  |
| APEE : contributions, dépenses, comptes rendus |  |
| Paquet minimum et dons reçus |  |
| Cantine et santé scolaire (version 1) |  |
| Communication avec les familles |  |
| Inspection et suivi pédagogique |  |
| Questionnaire statistique annuel et tableaux de bord territoriaux |  |


## Organisation territoriale

### Modèle
De l’école au ministère : la chaîne de supervision dans la plateforme
Dans la plateforme, le client est le MINEDUB (ou le programme pilote). Chaque école publique est un établissement ; une école qui a une section maternelle et une section primaire devient deux établissements liés (Maternelle et Primaire) qui partagent directeur, familles et infrastructures. Au-dessus des écoles, le socle doit gérer des périmètres territoriaux : région, département, arrondissement.

### Règles d’organisation

| ID | Règle |
|---|---|
| RG-TER-1 | Toute école appartient à un et un seul arrondissement, lui-même rattaché à un département et à une région ; le rattachement est tenu par l’administration centrale. |
| RG-TER-2 | Un rôle de supervision (inspecteur, délégué) donne accès en lecture aux écoles de son territoire et à leurs données agrégées ; il n’accède aux données nominatives d’un élève que pour une action prévue (transfert, examen, contrôle) et la consultation est journalisée. |
| RG-TER-3 | Les données nominatives des élèves ne remontent jamais au-delà du département, sauf pour les listes d’examen ; la région et le niveau national ne voient que des agrégats. |
| RG-TER-4 | Un découpage territorial modifié (création d’un arrondissement) ne modifie pas les statistiques des années clôturées. |
| RG-TER-5 | Une école créée par décret est enregistrée avec la référence de l’acte de création ; une école sans acte n’apparaît pas dans les statistiques officielles. |
| RG-TER-6 | Les paramètres (calendrier, règles d’évaluation, modèles de documents) se règlent au niveau national ; la région peut ajuster le calendrier dans les limites fixées ; l’école ne modifie que son identité et ses horaires. |


## Acteurs et rôles

| N° | Rôle | Périmètre | Missions dans les applications |
|---|---|---|---|
| R01 | Cabinet du ministre, secrétariat général | National | Tableaux de bord agrégés |
| R02 | Direction des examens, concours et certification | National | Listes de candidats consolidées, import des résultats, vérification des attestations |
| R03 | Division de la planification (statistiques, carte scolaire) | National | Questionnaire annuel, consolidation, annuaire statistique |
| R04 | Inspection générale des enseignements | National | Référentiels d’évaluation, modèles de bulletins, rapports d’inspection |
| R05 | Délégué régional | Région | Pilotage, validation des statistiques régionales, signature des certificats |
| R06 | Délégué départemental et service des statistiques | Département | Validation des données des arrondissements, attestations CEP et FSLC, transferts |
| R07 | Inspecteur d’arrondissement | Arrondissement | Contrôle des écoles, présence des enseignants, validation du questionnaire, inspections |
| R08 | Conseiller ou animateur pédagogique | Écoles attribuées | Visites de classe, accompagnement, rapports |
| R09 | Directeur d’école | Son école | Inscriptions, classes, présences, bulletins, conseil des maîtres, APEE, questionnaire annuel |
| R10 | Enseignant titulaire de classe | Ses classes | Appel, évaluation, bulletins, communication avec les parents |
| R11 | Éducatrice ou enseignant de maternelle [M] | Ses sections | Suivi du développement, cahier de vie, contrôle des sorties |
| R12 | Aide maternelle [M] | Sa section | Appel, contrôle des sorties |
| R13 | Parent ou tuteur | Ses enfants | Bulletins, absences, messages, contribution APEE, personnes autorisées |
| R14 | Personne autorisée à récupérer l’enfant [M] | Enfants désignés | Aucun compte ; identifiée par nom, téléphone, photo et code |
| R15 | Bureau de l’APEE (président, trésorier) | Son école | Contributions, dépenses, comptes rendus d’assemblée |
| R16 | Membre du conseil d’école | Son école | Consultation des comptes rendus et du projet d’école |
| R17 | Responsable de la cantine | Son école | Effectifs servis, stocks reçus |
| R18 | Agent de santé scolaire | Écoles attribuées | Campagnes (déparasitage, vaccination) en effectifs, sans dossier médical |
| R19 | Partenaire technique et financier | Territoire du programme | Indicateurs agrégés et anonymes |
| R20 | Agent de saisie de l’inspection | Écoles sans équipement | Saisie pour le compte de l’école, à partir des registres |
| R21 | Administrateur fonctionnel | National ou région | Comptes, rôles, paramètres, modèles |

Les élèves n’ont pas de compte : ils sont mineurs et jeunes ; leurs familles accèdent à leurs informations.

### Matrice des droits
L = lecture ; É = écriture ; V = validation ; A = agrégats seulement ; — = aucun accès.

| Module | R09 Directeur | R10 Enseignant | R13 Parent | R15 APEE | R07 Inspecteur | R06 Délégation dépt | R05 Délégation rég. | R03 Planification |
|---|---|---|---|---|---|---|---|---|
| Identité et infrastructures | É | L | — | L | V | L | A | A |
| Élèves et inscriptions | É | L (ses classes) | L (ses enfants) | — | L | L | A | A |
| Présences des élèves | L | É | L (ses enfants) | — | A | A | A | A |
| Présences des enseignants | É | L (la sienne) | — | — | V | A | A | A |
| Évaluation et bulletins | V | É | L (ses enfants) | — | L | A | A | A |
| Passage et conseil des maîtres | V | É | L (décision) | — | L | A | A | A |
| Listes d’examen | É | L | L (son enfant) | — | V | V | L | A |
| APEE | L | — | L (ses reçus, comptes) | É | L | — | — | — |
| Questionnaire statistique | É | — | — | — | V | V | V | V |
| Inspection | L (ses rapports) | L (ses rapports) | — | — | É | L | A | A |


## Année scolaire type

| Période | Activités dans les applications | Acteurs |
|---|---|---|
| Août | Paramétrage de l’année (calendrier national et régional), ouverture des classes, affectation des enseignants | R21, R09 |
| Septembre | Rentrée : inscriptions, réinscriptions, répartition en classes, élection et assemblée générale de l’APEE | R09, R10, R15 |
| Octobre | Questionnaire statistique de rentrée pré-rempli, validé par l’inspection puis la délégation | R09, R07, R06 |
| Octobre à mai | Appel quotidien, présence des enseignants, unités d’apprentissage, évaluations mensuelles, bulletins | R10, R09, R07 |
| Décembre, mars | Bulletins trimestriels, rencontres parents-enseignants | R10, R09, R13 |
| Janvier à mars | Listes de candidats au CEP, au FSLC, à l’entrée en 6e et au Common Entrance | R09, R07, R06 |
| Mai, juin | Common Entrance, concours d’entrée en 6e, CEP, FSLC (calendrier officiel) | R02, R06 |
| Juin | Conseil des maîtres, décisions de passage, livret annuel, comptes rendus APEE | R09, R10, R15 |
| Juillet | Import des résultats d’examen, clôture de l’année, transfert des dossiers de CM2 vers le secondaire | R06, R09 |


## Modules communs aux deux applications

| Code | Module | Services du socle | Lot |
|---|---|---|---|
| ECO | École et carte scolaire | S02 | P1 |
| ELV | Élèves, inscriptions, transferts | S03 | P1 |
| CLA | Classes et effectifs | S02 | P1 |
| PRE | Présences des élèves et des enseignants | S08 | P1 |
| PER | Personnel de l’école | S10 | P1 |
| COM | Communication avec les familles | S05 | P1 |
| APE | APEE, paquet minimum et dons | S04 | P2 |
| STA | Statistiques et questionnaire annuel | S12 | P2 |
| INS | Inspection et suivi pédagogique | S06 | P3 |
| CAN | Cantine et santé scolaire | S03 | P3 |


### ECO · École et carte scolaire

| ID | Exigence | Priorité |
|---|---|---|
| EX-ECO-01 | Fiche de l’école : nom, code officiel, acte de création, sous-système, cycle (maternelle, primaire, groupe scolaire), statut, arrondissement, coordonnées GPS, contacts | Must |
| EX-ECO-02 | Infrastructures : salles (matériau, état), tables-bancs, points d’eau, latrines séparées, électricité, clôture, cantine, bibliothèque | Must |
| EX-ECO-03 | Équipements numériques et connectivité observée (réseau mobile, Internet) | Should |
| EX-ECO-04 | Photos géolocalisées de l’école prises lors des visites de l’inspection | Should |
| EX-ECO-05 | Historique annuel des infrastructures, figé à la clôture du questionnaire | Must |


### ELV · Élèves, inscriptions et transferts

| ID | Exigence | Priorité |
|---|---|---|
| EX-ELV-01 | Inscription : nom, prénoms, sexe, date et lieu de naissance, référence de l’acte de naissance s’il existe, parents ou tuteurs, téléphone, langue parlée à la maison | Must |
| EX-ELV-02 | Identifiant national de l’élève attribué une fois et conservé du préscolaire à la fin du primaire, et transmis au secondaire | Must |
| EX-ELV-03 | Détection des doublons dans le territoire (même nom, même date de naissance, mêmes parents) avant création | Must |
| EX-ELV-04 | Situations particulières : élève déplacé ou réfugié, élève en situation de handicap (type d’aménagement seulement), orphelin, sans acte de naissance | Must |
| EX-ELV-05 | Transfert entre écoles de la plateforme : demande, accord, dossier transmis électroniquement, certificat de radiation | Must |
| EX-ELV-06 | Abandon déclaré ou présumé (absence continue au-delà d’un seuil), avec relance de la famille | Should |
| EX-ELV-07 | Saisie hors-ligne et inscription groupée à partir d’un registre papier (import d’une photo de registre et saisie assistée par l’agent R20) | Should |


| ID | Règle |
|---|---|
| RG-ELV-1 | L’inscription dans une école publique ne peut jamais être refusée ni suspendue pour défaut de contribution APEE ; l’application ne propose aucun champ qui lierait inscription et paiement. |
| RG-ELV-2 | L’absence d’acte de naissance n’empêche pas l’inscription ; l’élève est signalé, la liste est suivie par le directeur et transmise à l’inspection chaque trimestre, pour régularisation avant l’inscription au CEP ou au FSLC. |
| RG-ELV-3 | Âge d’entrée par défaut : 4 ans en maternelle, 6 ans en SIL ou Class 1, calculés au 31 décembre de l’année (paramètres). Un écart d’âge déclenche un avertissement, jamais un refus. |
| RG-ELV-4 | Un élève n’est inscrit que dans une école à la fois ; un transfert clôt l’inscription d’origine à la date d’accueil. |
| RG-ELV-5 | Les données d’un élève radié sont conservées dans l’école d’origine en lecture seule ; la nouvelle école ne reçoit que le dossier utile (identité, parcours, dernier bulletin). |


### CLA · Classes et effectifs

| ID | Exigence | Priorité |
|---|---|---|
| EX-CLA-01 | Classes par niveau et sous-système ; classes jumelées (plusieurs niveaux, un enseignant) ; double flux (matin et après-midi) | Must |
| EX-CLA-02 | Affectation d’un enseignant titulaire par classe, avec date d’effet | Must |
| EX-CLA-03 | Effectifs par classe, sexe, âge ; alerte au-delà d’un seuil (par défaut 60 élèves) | Must |
| EX-CLA-04 | Répartition automatique proposée des élèves d’un niveau entre plusieurs classes (équilibre filles et garçons, âges) | Could |


### PRE · Présences

| ID | Exigence | Priorité |
|---|---|---|
| EX-PRE-01 | Appel quotidien (matin, et après-midi si plein temps) en moins de 2 minutes : tous présents par défaut, l’enseignant touche les absents | Must |
| EX-PRE-02 | Motif d’absence signalé par le parent par SMS ou par l’enseignant | Should |
| EX-PRE-03 | Présence des enseignants : arrivée et départ déclarés par le directeur, contrôle aléatoire par l’inspection | Must |
| EX-PRE-04 | Absences et permissions des enseignants avec pièce ; synthèse mensuelle pour l’inspection (DOC-22) | Must |
| EX-PRE-05 | Alerte d’absences répétées d’un élève (par défaut 5 jours en un mois) au directeur et à la famille | Must |


| ID | Règle |
|---|---|
| RG-PRE-1 | L’appel du jour peut être corrigé par l’enseignant jusqu’à la fin de la journée, puis seulement par le directeur, avec motif. |
| RG-PRE-2 | Un enseignant absent sans remplaçant entraîne l’indication « classe sans enseignant » pour la journée ; l’indicateur remonte à l’inspection. |
| RG-PRE-3 | Les absences d’élèves ne donnent lieu à aucune sanction automatique ; elles alimentent le suivi et la prévention de l’abandon. |


### PER · Personnel de l’école

| ID | Exigence | Priorité |
|---|---|---|
| EX-PER-01 | Fiche : matricule de la fonction publique ou numéro de contrat, statut (fonctionnaire, contractuel, maître des parents, bénévole), grade, diplôme professionnel, date de prise de service | Must |
| EX-PER-02 | Affectations et mutations à l’intérieur du territoire, avec historique | Must |
| EX-PER-03 | Formations suivies (journées pédagogiques, formation continue) | Should |
| EX-PER-04 | Besoins en enseignants calculés par école (élèves par enseignant, classes sans titulaire) | Must |


| ID | Règle |
|---|---|
| RG-PER-1 | Les maîtres des parents sont rémunérés par l’APEE ; leurs paiements sont des dépenses APEE (module APE), pas des salaires de l’État. |
| RG-PER-2 | Les données de carrière visibles par l’inspection sont limitées au statut, au grade, aux affectations et aux présences. |


### COM · Communication avec les familles

| ID | Exigence | Priorité |
|---|---|---|
| EX-COM-01 | Messages SMS à une classe, un niveau, toute l’école ; modèles en français et en anglais | Must |
| EX-COM-02 | Messages vocaux enregistrés par le directeur dans une langue locale, envoyés par appel automatique | Could |
| EX-COM-03 | Consultation par code USSD ou SMS : absences du jour, mention du dernier bulletin, convocations | Should |
| EX-COM-04 | Portail parent sur téléphone : bulletins, absences, messages, reçus APEE, personnes autorisées [M] | Must |
| EX-COM-05 | Annonces de l’inspection ou de la délégation à toutes les écoles d’un territoire | Must |


| ID | Règle |
|---|---|
| RG-COM-1 | Le coût des SMS est pris en charge par le budget de communication du programme ou de l’école, jamais facturé au parent. |
| RG-COM-2 | Un parent peut refuser les SMS non essentiels ; les messages de sécurité (fermeture de l’école, sortie de l’enfant) restent envoyés. |


### APE · APEE, paquet minimum et dons

| ID | Exigence | Priorité |
|---|---|---|
| EX-APE-01 | Montant de la contribution voté en assemblée générale, avec le compte rendu numérisé | Must |
| EX-APE-02 | Encaissement par le trésorier (espèces, mobile money), reçu numéroté par SMS (DOC-24) | Must |
| EX-APE-03 | Dépenses avec pièce : maîtres des parents, entretien, petit matériel, examens blancs | Must |
| EX-APE-04 | État financier de l’APEE à présenter à l’assemblée générale (DOC-25) | Must |
| EX-APE-05 | Réception du paquet minimum et des dons en nature : bordereau, quantités, répartition par classe | Should |
| EX-APE-06 | Exonérations décidées par le bureau (familles nombreuses, situation difficile), sans publicité nominative | Should |


| ID | Règle |
|---|---|
| RG-APE-1 | La contribution APEE n’a aucun effet sur l’inscription, la présence en classe, les bulletins, les examens ni les documents de l’élève (RG-ELV-1). |
| RG-APE-2 | La liste nominative des familles qui n’ont pas contribué n’est visible que du trésorier et du président de l’APEE ; elle n’est jamais affichée ni envoyée aux enseignants. |
| RG-APE-3 | Une dépense APEE sans pièce justificative reste « en attente » et figure comme telle dans l’état financier. |
| RG-APE-4 | Le directeur d’école consulte les comptes de l’APEE mais ne les modifie pas ; le trésorier ne modifie pas les données scolaires. |


### STA · Statistiques et questionnaire annuel

| ID | Exigence | Priorité |
|---|---|---|
| EX-STA-01 | Questionnaire de rentrée généré par l’application et pré-rempli depuis ECO, ELV, CLA, PER (effectifs par niveau, sexe, âge, redoublants, enseignants, infrastructures) | Must |
| EX-STA-02 | Contrôles de cohérence avant envoi : effectifs par âge égaux aux effectifs par niveau, élèves par salle, valeurs extrêmes | Must |
| EX-STA-03 | Circuit de validation : directeur, inspecteur, délégation départementale, délégation régionale, planification ; renvoi motivé possible à chaque niveau | Must |
| EX-STA-04 | Export au format attendu par les outils statistiques du MINEDUB (format à obtenir en cadrage) | Must |
| EX-STA-05 | Tableaux de bord par territoire (partie « Indicateurs ») | Must |
| EX-STA-06 | Données de fin d’année : promus, redoublants, abandons, résultats aux examens | Must |


| ID | Règle |
|---|---|
| RG-STA-1 | Les statistiques officielles d’une année sont figées à la validation régionale ; toute correction ultérieure crée une version motivée. |
| RG-STA-2 | Une école qui n’utilise pas l’application est saisie par l’agent de l’inspection (R20) à partir du questionnaire papier, pour que la couverture territoriale soit complète. |


### INS · Inspection et suivi pédagogique

| ID | Exigence | Priorité |
|---|---|---|
| EX-INS-01 | Planning des visites de l’inspecteur et des conseillers pédagogiques | Should |
| EX-INS-02 | Grille de visite de classe paramétrable (préparation, déroulement, évaluation, tenue des registres), remplie hors-ligne sur téléphone | Must |
| EX-INS-03 | Rapport de visite (DOC-26) transmis à l’enseignant et au directeur ; recommandations suivies jusqu’à la visite suivante | Must |
| EX-INS-04 | Contrôle des effectifs sur place : comptage comparé au registre de l’application | Must |
| EX-INS-05 | Contrôle de la gratuité : signalement d’un frais exigé illégalement, traité par l’inspection | Should |


### CAN · Cantine et santé scolaire

| ID | Exigence | Priorité |
|---|---|---|
| EX-CAN-01 | Repas servis par jour et par classe, stocks reçus et consommés | Could |
| EX-CAN-02 | Campagnes de santé (déparasitage, vaccination, distribution de moustiquaires) saisies en effectifs, avec consentement des parents | Could |
| EX-CAN-03 | Allergies alimentaires signalées par le parent, visibles du seul personnel concerné [M] | Should |


## Modules propres au Primaire

| Code | Module | Lot |
|---|---|---|
| EVA | Évaluation par compétences et bulletins | P2 |
| PAS | Passage et conseil des maîtres | P2 |
| EXA | Examens et entrée au secondaire | P3 |


### EVA · Évaluation par compétences et bulletins [P]

| ID | Exigence | Priorité |
|---|---|---|
| EX-EVA-01 | Référentiel national par sous-système et niveau : disciplines, compétences, critères minimaux et de perfectionnement, issus des curricula ; versionné | Must |
| EX-EVA-02 | Calendrier des unités d’apprentissage (par défaut 8 par an, un mois chacune), avec semaine d’intégration et d’évaluation | Must |
| EX-EVA-03 | Saisie par l’enseignant, sur téléphone et hors-ligne, de la maîtrise de chaque critère par élève (maîtrisé ou non) pour chaque occasion de vérification | Must |
| EX-EVA-04 | Calcul automatique de la mention par discipline (A, ECA, NA) selon la règle d’évaluation (partie « Règles d’évaluation ») | Must |
| EX-EVA-05 | Liste des élèves à remédier par discipline et critère, à l’issue de chaque unité | Must |
| EX-EVA-06 | Bulletin de l’unité d’apprentissage (DOC-08), bulletin trimestriel (DOC-09), livret scolaire annuel (DOC-10) | Must |
| EX-EVA-07 | Appréciation de l’enseignant par élève, avec banque de formulations à compléter | Should |
| EX-EVA-08 | Option « notes chiffrées » pour les écoles ou épreuves qui en utilisent encore (épreuves blanches de CEP ou de FSLC) | Should |


| ID | Règle |
|---|---|
| RG-EVA-1 | Aucun rang n’est calculé ni imprimé au primaire (paramètre national, désactivé par défaut). |
| RG-EVA-2 | Un bulletin n’est publié aux parents qu’après validation du directeur ; il est ensuite figé. |
| RG-EVA-3 | Une mention ne peut être modifiée après publication que par le directeur, avec motif ; le bulletin est réédité avec la mention « rectificatif ». |
| RG-EVA-4 | Un élève absent à toutes les occasions de vérification d’une discipline reçoit « non évalué », jamais NA. |


### PAS · Passage et conseil des maîtres [P]

| ID | Exigence | Priorité |
|---|---|---|
| EX-PAS-01 | Proposition automatique de décision par élève selon la politique de passage (partie « Règles d’évaluation ») | Must |
| EX-PAS-02 | Séance du conseil des maîtres : décisions, motifs, procès-verbal (DOC-12) | Must |
| EX-PAS-03 | Notification de la décision aux parents ; recours auprès du directeur dans un délai paramétré | Should |
| EX-PAS-04 | Statistiques de passage, redoublement et abandon par classe, école, territoire | Must |


| ID | Règle |
|---|---|
| RG-PAS-1 | La politique de passage est fixée au niveau national ; l’école ne peut pas la modifier. |
| RG-PAS-2 | Toute décision de redoublement est motivée dans le procès-verbal ; un deuxième redoublement dans le même niveau exige l’avis de l’inspecteur. |


### EXA · Examens et entrée au secondaire [P]

| ID | Exigence | Priorité |
|---|---|---|
| EX-EXA-01 | Liste des candidats au CEP (CM2) et au FSLC (Class 6) générée à partir des inscrits, avec contrôle de l’état civil | Must |
| EX-EXA-02 | Liste des candidats au concours d’entrée en 6e et au Common Entrance, au format demandé par l’organisateur | Must |
| EX-EXA-03 | Circuit : directeur, inspection (contrôle), délégation départementale (consolidation, centres) | Must |
| EX-EXA-04 | Fiche individuelle du candidat (DOC-14) avec photo et identifiant national | Should |
| EX-EXA-05 | Import des résultats officiels ; taux de réussite par école et par territoire | Must |
| EX-EXA-06 | Préparation des attestations de réussite pour signature par la délégation départementale (DOC-16) | Should |
| EX-EXA-07 | Transfert du dossier de l’élève (identité, parcours, dernier livret) vers l’application Secondaire de la plateforme lorsque le collège l’utilise | Should |


| ID | Règle |
|---|---|
| RG-EXA-1 | Un candidat sans référence d’acte de naissance vérifiée ne peut pas être validé par l’inspection ; le directeur est alerté dès janvier (RG-ELV-2). |
| RG-EXA-2 | La liste validée par la délégation est figée ; tout ajout ultérieur est tracé et motivé. |
| RG-EXA-3 | L’application ne délivre jamais le CEP ou le FSLC ; elle prépare des listes et des attestations signées par l’autorité compétente. |


## Modules propres à la Maternelle

| Code | Module | Lot |
|---|---|---|
| DEV | Suivi du développement et cahier de vie | P2 |
| SOR | Sécurité des arrivées et des sorties | P1 |
| TRA | Passage vers le primaire | P3 |


### DEV · Suivi du développement et cahier de vie [M]

| ID | Exigence | Priorité |
|---|---|---|
| EX-DEV-01 | Référentiel d’observation par domaine (exemple à valider avec l’Inspection générale : langage, motricité, vie sociale et émotions, éveil mathématique, découverte du monde, expression artistique) | Must |
| EX-DEV-02 | Observations rapides par l’éducatrice : élève, domaine, item, niveau (acquis, en cours, pas encore), commentaire facultatif | Must |
| EX-DEV-03 | Carnet de suivi trimestriel (DOC-18) rédigé en termes positifs | Must |
| EX-DEV-04 | Cahier de vie : photos et activités de la section, partagées aux parents qui y ont consenti | Should |
| EX-DEV-05 | Signalement d’un besoin particulier (langage, vue, audition) au directeur et aux parents, pour orientation | Should |


| ID | Règle |
|---|---|
| RG-DEV-1 | Aucune note, aucun rang, aucune mention « non acquis » en maternelle : le niveau le plus bas s’intitule « pas encore ». |
| RG-DEV-2 | Une photo où apparaît un enfant n’est partagée que si ses parents ont donné leur consentement écrit ; sinon l’enfant est masqué ou la photo n’est pas publiée. |
| RG-DEV-3 | Les photos du cahier de vie sont conservées jusqu’à la fin de l’année suivante puis supprimées. |


### SOR · Sécurité des arrivées et des sorties [M]

| ID | Exigence | Priorité |
|---|---|---|
| EX-SOR-01 | Liste des personnes autorisées à récupérer l’enfant, tenue par les parents : nom, lien, téléphone, photo | Must |
| EX-SOR-02 | Contrôle à la sortie : l’éducatrice ou l’aide sélectionne l’enfant et la personne ; personne non autorisée signalée immédiatement | Must |
| EX-SOR-03 | Autorisation ponctuelle envoyée par le parent (SMS avec code à usage unique) | Should |
| EX-SOR-04 | Enfant non récupéré 30 minutes après la fin des cours (paramètre) : appel automatique des parents puis alerte du directeur | Must |
| EX-SOR-05 | Registre des arrivées et sorties consultable par le directeur (DOC-19) | Must |


| ID | Règle |
|---|---|
| RG-SOR-1 | Un enfant n’est remis qu’à une personne autorisée ; en cas de doute, il reste à l’école et les parents sont appelés. |
| RG-SOR-2 | Une décision de justice limitant un droit de garde, transmise au directeur, est enregistrée comme restriction et visible du seul personnel de la section. |
| RG-SOR-3 | Le contrôle de sortie fonctionne hors-ligne avec la liste chargée le matin. |


### TRA · Passage vers le primaire [M]

| ID | Exigence | Priorité |
|---|---|---|
| EX-TRA-01 | Décision de passage en section supérieure ou en SIL (Class 1), avec avis de l’éducatrice | Must |
| EX-TRA-02 | Fiche de liaison vers l’école primaire (DOC-20) : identité, parcours, points d’appui, besoins | Should |
| EX-TRA-03 | Transfert électronique du dossier vers l’école primaire du groupe scolaire ou d’une autre école de la plateforme | Should |


## Règles d’évaluation
Les règles de cette partie sont des valeurs par défaut à faire valider par l’Inspection générale des enseignements du MINEDUB. Elles traduisent dans le logiciel l’approche par compétences du curriculum de 2018 et le bulletin à trois mentions (A, ECA, NA). Elles sont paramétrables au niveau national, jamais par l’école.

### Paramètres du primaire

| Paramètre | Valeur par défaut |
|---|---|
| Unités d’apprentissage par an | 8, d’environ un mois (curriculum niveau 1) ; paramétrable par niveau |
| Semaine d’évaluation | Dernière semaine de chaque unité : intégration, évaluation, remédiation |
| Évaluation d’une compétence | Situation d’intégration corrigée par critères minimaux (3 par défaut) et un critère de perfectionnement |
| Occasions de vérification | 3 par critère dans la même situation (questions ou consignes indépendantes) |
| Critère maîtrisé | Au moins 2 occasions réussies sur 3 (règle des deux tiers) |
| Mention de la discipline | A si tous les critères minimaux sont maîtrisés ; ECA si au moins un l’est ; NA si aucun |
| Critère de perfectionnement | N’intervient que si la mention est A ; signalé sur le bulletin (« A, avec maîtrise du critère de perfectionnement ») |
| Mention trimestrielle | Mention de la dernière unité du trimestre, sauf décision motivée de l’enseignant |
| Mention annuelle | Mention de l’évaluation d’intégration de fin d’année ; à défaut, de la dernière unité |
| Rang | Désactivé |


### Exemple : unité d’apprentissage de novembre en CE1
Élève fictif : Paul E., CE1 B, école publique, unité 3. L’enseignant saisit pour chaque critère les occasions réussies (1) ou non (0).

| Discipline | Critère minimal | Occasion 1 | Occasion 2 | Occasion 3 | Réussies | Critère maîtrisé |
|---|---|---|---|---|---|---|
| Français | C1 Pertinence de la production | 1 | 1 | 0 | 2 sur 3 | Oui |
| Français | C2 Utilisation correcte des outils de la langue | 1 | 0 | 1 | 2 sur 3 | Oui |
| Français | C3 Cohérence | 0 | 1 | 0 | 1 sur 3 | Non |
| Mathématiques | C1 Interprétation correcte de la situation | 1 | 1 | 1 | 3 sur 3 | Oui |
| Mathématiques | C2 Utilisation correcte des outils | 1 | 1 | 0 | 2 sur 3 | Oui |
| Mathématiques | C3 Cohérence de la réponse | 1 | 0 | 1 | 2 sur 3 | Oui |
| English | C1 Relevance | 0 | 0 | 1 | 1 sur 3 | Non |
| English | C2 Correct use of language tools | 1 | 0 | 0 | 1 sur 3 | Non |
| English | C3 Coherence | 0 | 1 | 0 | 1 sur 3 | Non |


| Discipline | Critères minimaux maîtrisés | Mention | Effet dans l’application |
|---|---|---|---|
| Français | 2 sur 3 | ECA | Paul est inscrit sur la liste de remédiation « Français, C3 Cohérence » |
| Mathématiques | 3 sur 3 | A | Le critère de perfectionnement est examiné et signalé s’il est maîtrisé |
| English | 0 sur 3 | NA | Liste de remédiation sur les trois critères ; message au parent proposant une rencontre |


### Politique de passage (exemple à valider)

| Situation en fin d’année | Proposition automatique |
|---|---|
| Mention A ou ECA en français (ou English pour le sous-système anglophone), en mathématiques et dans la seconde langue officielle | Passe en classe supérieure |
| NA dans une seule de ces trois disciplines | Passe avec plan de remédiation au début de l’année suivante |
| NA dans au moins deux de ces trois disciplines | Examen par le conseil des maîtres : passage avec remédiation ou redoublement motivé |
| Classe de fin de niveau (CP, CE2, CM2) | Mêmes propositions ; le redoublement exige l’avis de l’inspecteur s’il s’agit d’un deuxième redoublement |

Le conseil des maîtres décide ; l’application propose et trace.

### Maternelle

| Paramètre | Valeur par défaut |
|---|---|
| Domaines observés | Langage ; motricité ; vie sociale et émotions ; éveil mathématique ; découverte du monde ; expression artistique (à aligner sur le référentiel officiel du préscolaire) |
| Niveaux | Acquis, en cours, pas encore |
| Fréquence | Au moins une observation par domaine et par enfant chaque mois |
| Synthèse | Carnet de suivi trimestriel, sans note ni rang |
| Passage | Passage automatique en section supérieure ; maintien exceptionnel sur demande des parents et avis de l’inspecteur |


### Indicateurs et formules

| Indicateur | Formule | Niveau de calcul |
|---|---|---|
| Élèves par enseignant | Élèves inscrits ÷ enseignants en poste tenant une classe | École à pays |
| Élèves par salle | Élèves inscrits ÷ salles utilisées | École à pays |
| Indice de parité | Effectif des filles ÷ effectif des garçons | Classe à pays |
| Taux de présence des élèves | Demi-journées de présence ÷ demi-journées attendues | Classe à pays |
| Taux de présence des enseignants | Jours présents ÷ jours ouvrés | École à pays |
| Part de A par discipline | Élèves avec mention A ÷ élèves évalués | Classe à pays |
| Taux de redoublement | Redoublants de l’année N+1 dans le niveau ÷ inscrits de l’année N dans ce niveau | École à pays |
| Taux d’abandon | Élèves inscrits ni promus, ni redoublants, ni transférés ÷ inscrits | École à pays |
| Taux de réussite au CEP ou au FSLC | Admis ÷ candidats présents | École à pays |
| Élèves sans acte de naissance | Élèves signalés ÷ inscrits | École à pays |


### Exemple : comptes de l’APEE (valeurs fictives)

| Ligne | Calcul | Montant (FCFA) |
|---|---|---|
| Contribution votée en assemblée | 5 000 par élève |  |
| Attendu | 420 élèves × 5 000 | 2 100 000 |
| Encaissé | 344 familles | 1 720 000 |
| Taux de contribution | 1 720 000 ÷ 2 100 000 | 81,9 % |
| Maîtres des parents | 2 × 25 000 × 9 mois | 450 000 |
| Petit matériel et craie |  | 300 000 |
| Entretien et réparations |  | 250 000 |
| Total des dépenses |  | 1 000 000 |
| Solde présenté à l’assemblée | 1 720 000 − 1 000 000 | 720 000 |

Les 76 familles qui n’ont pas contribué restent inscrites et leurs enfants reçoivent bulletins et documents comme les autres (RG-APE-1).

## Impressions et documents
Les modèles sont fixés au niveau national (en-tête de la République, du ministère, de la délégation, de l’école), en français et en anglais. Les documents officiels portent un numéro unique et un QR de vérification. Comme beaucoup d’écoles n’ont pas d’imprimante, chaque document peut être imprimé à l’inspection pour plusieurs écoles, ou envoyé au parent en PDF léger ou en résumé SMS.

### Catalogue des documents

| N° | Document | Émis par, à quel moment | Signataire | QR |
|---|---|---|---|---|
| DOC-01 | Fiche d’inscription | Directeur, à l’inscription | Parent, directeur | Non |
| DOC-02 | Certificat de scolarité | Directeur, à la demande | Directeur | Oui |
| DOC-03 | Certificat de radiation (exeat) | Directeur, au transfert | Directeur | Oui |
| DOC-04 | Liste de classe | Directeur, à la rentrée et à la demande | Aucun | Non |
| DOC-05 | Registre matricule de l’école | Directeur, mis à jour en continu | Directeur, visa de l’inspecteur | Non |
| DOC-06 | Cahier d’appel mensuel | Enseignant | Enseignant, directeur | Non |
| DOC-07 | Liste des élèves sans acte de naissance | Directeur, chaque trimestre | Directeur | Non |
| DOC-08 | Bulletin de l’unité d’apprentissage [P] | Enseignant, fin d’unité | Enseignant, directeur | Non |
| DOC-09 | Bulletin trimestriel [P] | Enseignant, fin de trimestre | Directeur | Oui |
| DOC-10 | Livret scolaire annuel [P] | Directeur, fin d’année | Directeur | Oui |
| DOC-11 | Liste de remédiation par discipline et critère [P] | Enseignant, fin d’unité | Aucun | Non |
| DOC-12 | Procès-verbal du conseil des maîtres | Directeur, fin d’année | Directeur et enseignants | Oui |
| DOC-13 | Liste des candidats au CEP ou au FSLC [P] | Directeur, puis inspection et délégation | Directeur, inspecteur | Non |
| DOC-14 | Fiche individuelle de candidat [P] | Directeur | Directeur, parent | Non |
| DOC-15 | Liste des candidats à l’entrée en 6e ou au Common Entrance [P] | Directeur | Directeur | Non |
| DOC-16 | Attestation de réussite au CEP ou au FSLC [P] | Préparée par l’application après import des résultats | Délégué départemental | Oui |
| DOC-17 | Relevé des résultats d’examen de l’école [P] | Après import des résultats | Directeur | Non |
| DOC-18 | Carnet de suivi trimestriel [M] | Éducatrice, fin de trimestre | Éducatrice, directeur | Non |
| DOC-19 | Registre des arrivées et sorties [M] | Automatique, chaque jour | Aucun | Non |
| DOC-20 | Fiche de liaison maternelle vers primaire [M] | Éducatrice, fin de grande section | Directeur | Non |
| DOC-21 | Carte des personnes autorisées à récupérer l’enfant [M] | Directeur, à la demande du parent | Directeur | Oui |
| DOC-22 | Synthèse mensuelle de présence des enseignants | Directeur, fin de mois | Directeur, inspecteur | Non |
| DOC-23 | Questionnaire statistique de rentrée pré-rempli | Application, en octobre | Directeur, inspecteur, délégué | Non |
| DOC-24 | Reçu de contribution APEE | Trésorier, à chaque encaissement | Trésorier ou cachet électronique | Oui |
| DOC-25 | État financier de l’APEE | Trésorier, avant l’assemblée générale | Président et trésorier | Non |
| DOC-26 | Rapport de visite d’inspection | Inspecteur ou conseiller | Inspecteur | Non |
| DOC-27 | Bordereau de réception du paquet minimum ou d’un don | Directeur, à la réception | Directeur, livreur | Non |
| DOC-28 | Tableau de bord territorial | Inspection, délégation, chaque mois | Aucun | Non |


### Modèle de bulletin trimestriel
Exemple de bulletin trimestriel du primaire (DOC-09), à valider avec l’Inspection générale

| Zone | Contenu |
|---|---|
| En-tête | République du Cameroun, ministère, délégation régionale et départementale, inspection, école (nom, code) |
| Identité | Nom, prénoms, sexe, date de naissance, identifiant national, classe, enseignant, année |
| Corps | Par discipline : mentions des unités du trimestre, mention trimestrielle, critère de perfectionnement |
| Synthèse | Présences, appréciation de l’enseignant, actions de remédiation prévues |
| Pied | Signature du directeur, numéro du document, QR de vérification ; aucune mention de rang ni de contribution APEE |


### Règles des documents

| ID | Règle |
|---|---|
| RG-DOC-1 | Les numéros des documents officiels sont uniques par école et par année et ne sont jamais réutilisés. |
| RG-DOC-2 | La page de vérification d’un QR affiche le type de document, l’école, la date et le statut ; elle n’affiche ni mention, ni donnée de santé, ni adresse. |
| RG-DOC-3 | Aucun document d’élève ne mentionne la situation de la famille vis-à-vis de l’APEE. |
| RG-DOC-4 | Un document est disponible dans la langue du sous-système de l’école ; il peut être produit dans l’autre langue officielle à la demande. |
| RG-DOC-5 | Une réédition porte la mention « Duplicata » ou « Rectificatif » et garde le numéro d’origine. |
| RG-DOC-6 | Les versions PDF envoyées aux parents pèsent moins de 200 Ko pour être reçues en 2G ou 3G. |


### Notifications automatiques

| N° | Événement | Destinataires | Canal |
|---|---|---|---|
| NOT-01 | Inscription enregistrée, identifiant attribué | Parent | SMS |
| NOT-02 | Absence de l’enfant le matin | Parent | SMS |
| NOT-03 | Absences répétées (5 jours en un mois) | Parent, directeur | SMS, notification |
| NOT-04 | Bulletin disponible avec résumé des mentions | Parent | SMS, portail |
| NOT-05 | Convocation à une rencontre parents-enseignant | Parent | SMS, appel vocal |
| NOT-06 | Enfant non récupéré à l’heure [M] | Parents, puis directeur | Appel, SMS |
| NOT-07 | Personne non autorisée à la sortie [M] | Parents, directeur | SMS, appel |
| NOT-08 | Acte de naissance manquant (janvier de l’année d’examen) [P] | Parent, directeur | SMS |
| NOT-09 | Résultat d’examen publié [P] | Parent | SMS |
| NOT-10 | Reçu APEE | Payeur | SMS |
| NOT-11 | Questionnaire statistique à valider | Inspecteur, délégué | Notification, e-mail |
| NOT-12 | École sans appel depuis 3 jours ouvrés | Directeur, puis inspecteur | Notification |
| NOT-13 | Classe sans enseignant | Directeur, inspecteur | Notification |
| NOT-14 | Fermeture exceptionnelle de l’école | Tous les parents de l’école | SMS, appel vocal |


## Conception technique

### Cadre
Les deux applications suivent les choix de la plateforme : PHP 8.4, Laravel 13, API REST /api/v1, PostgreSQL 16, Redis, génération PDF par Gotenberg, documentation OpenAPI 3.1. Elles vivent dans app/Modules/Apps/Primary et app/Modules/Apps/Nursery. Le contexte public impose quatre exigences supplémentaires : l’échelle nationale, le hors-ligne, les téléphones d’entrée de gamme et la protection renforcée des données de mineurs.

### Évolutions demandées au socle

| Évolution | Service | Raison |
|---|---|---|
| Périmètres territoriaux (région, département, arrondissement) au-dessus des établissements, avec rôles de supervision | S01, S02 | Chaîne MINEDUB (RG-TER-1 à RG-TER-6) |
| Agrégats pré-calculés par territoire, rafraîchis chaque nuit | S12 | Tableaux de bord sur des milliers d’écoles sans requêtes lourdes |
| Synchronisation hors-ligne par lots compressés pour l’application mobile | S08, S09 | Écoles sans réseau stable |
| Passerelle USSD et messages vocaux | S05 | Parents sans smartphone |
| Identifiant national de l’élève, avec contrôle de doublons au niveau du territoire | S03 | Suivi du parcours et des examens |


### Structure des modules
app/Modules/Apps/├── Primary/│   ├── Schools/         # ECO : fiche école, infrastructures│   ├── Pupils/          # ELV, CLA : élèves, classes, transferts│   ├── Attendance/      # PRE : appel, présence enseignants│   ├── Competencies/    # EVA : référentiels, critères, mentions│   ├── Promotion/       # PAS : conseil des maîtres│   ├── Exams/           # EXA : CEP, FSLC, entrée au secondaire│   ├── Parents/         # APE : APEE, paquet minimum│   ├── Census/          # STA : questionnaire annuel│   └── Inspection/      # INS : visites, rapports├── Nursery/│   ├── Development/     # DEV : observations, cahier de vie│   ├── Pickup/          # SOR : personnes autorisées, sorties│   └── Transition/      # TRA : fiche de liaison└── Shared/Public/       # Règles communes au public

### Application mobile de l’école

| Exigence | Valeur |
|---|---|
| Appareils | Android 8 ou plus, 2 Go de mémoire vive, écran de 5 pouces |
| Taille installée | Moins de 30 Mo |
| Fonctionnement hors-ligne | Appel, évaluation, inscriptions, contrôle des sorties, visites d’inspection, pendant 30 jours sans connexion |
| Synchronisation | Par lots compressés ; moins de 50 Ko pour l’appel et les évaluations d’une classe de 60 élèves sur une semaine |
| Partage d’appareil | Plusieurs enseignants sur le téléphone de l’école, chacun avec son code personnel |
| Énergie | Mode économie : pas de synchronisation en arrière-plan sous 20 % de batterie |
| Langues | Français, anglais ; libellés courts testés avec des enseignants |


### Modèle de données propre

| Entité | Champs clés | Remarques |
|---|---|---|
| pu_territories | id, type, parent_id, code officiel, nom | Région, département, arrondissement |
| pu_schools | establishment_id, territory_id, code, acte de création, sous-système, GPS | École publique |
| pu_infrastructures | school_id, année, salles, points d’eau, latrines, électricité | Historique annuel |
| pu_pupils | id national, identité, acte de naissance (référence), situation particulière | Élève |
| pu_enrolments | pupil_id, school_id, classe, année, statut, date | Inscriptions et transferts |
| pu_teacher_presence | staff_id, date, statut, motif, contrôle | Présence des enseignants |
| pu_competency_frames | sous-système, niveau, discipline, critères, version | Référentiel national |
| pu_criterion_checks | pupil_id, unité, critère, occasions réussies | Saisie de l’enseignant |
| pu_mentions | pupil_id, discipline, période, mention, publiée | Résultats figés |
| pu_promotion_decisions | pupil_id, année, proposition, décision, motif | Conseil des maîtres |
| pu_exam_candidates | pupil_id, examen, statut, résultat | CEP, FSLC, entrée au secondaire |
| pu_apee_entries | school_id, type, montant, pièce, payeur | Contributions et dépenses |
| pu_census_forms | school_id, année, contenu, état, validations | Questionnaire annuel |
| nu_observations | pupil_id, domaine, item, niveau, date | Maternelle |
| nu_pickup_persons, nu_pickups | enfant, personne, photo ; date, heure, agent | Sécurité des sorties |


### API spécifiques

| ID | Méthode et chemin | Description | Rôles |
|---|---|---|---|
| API-01 | GET /api/v1/public/territories/{id}/schools | Écoles d’un territoire | R05, R06, R07 |
| API-02 | POST /api/v1/primary/pupils | Inscrire un élève (contrôle de doublon) | R09 |
| API-03 | POST /api/v1/primary/transfers | Demander un transfert | R09 |
| API-04 | POST /api/v1/sync/batches | Envoyer un lot hors-ligne signé | R09, R10, R11, R07 |
| API-05 | PUT /api/v1/primary/classes/{id}/attendance/{date} | Appel du jour | R10 |
| API-06 | PUT /api/v1/primary/units/{id}/criterion-checks | Saisir les critères d’une unité | R10 |
| API-07 | POST /api/v1/primary/report-cards/{id}/publish | Publier un bulletin | R09 |
| API-08 | POST /api/v1/primary/promotion/{year}/council | Enregistrer les décisions du conseil des maîtres | R09 |
| API-09 | POST /api/v1/primary/exams/{id}/candidates/validate | Valider une liste de candidats | R07, R06 |
| API-10 | POST /api/v1/primary/exams/{id}/results/import | Importer les résultats officiels | R06, R02 |
| API-11 | POST /api/v1/public/census/{id}/submit | Transmettre le questionnaire au niveau suivant | R09, R07, R06, R05 |
| API-12 | GET /api/v1/public/dashboards/{territory} | Agrégats d’un territoire | R01 à R07, R19 |
| API-13 | POST /api/v1/nursery/pickups | Enregistrer une sortie | R11, R12 |
| API-14 | POST /api/v1/apee/entries | Encaissement ou dépense APEE | R15 |
| API-15 | POST /api/v1/ussd/session | Point d’entrée de la passerelle USSD | Opérateur |


### Sécurité et protection des données des enfants

| Domaine | Mesure |
|---|---|
| Minimisation | Aucune donnée médicale ; handicap limité au type d’aménagement ; pas de religion ni d’ethnie |
| Consentement | Consentement des parents pour les photos, le cahier de vie, les SMS non essentiels ; retrait possible à tout moment |
| Accès par territoire | Politiques Laravel combinant rôle et territoire ; les niveaux régional et national ne reçoivent que des agrégats (RG-TER-3) |
| Petits effectifs | Un agrégat portant sur moins de 5 élèves est masqué pour les rôles de supervision et les partenaires |
| Hors-ligne | Base locale chiffrée sur le téléphone, effacée après 5 codes erronés ; lots signés par l’appareil ; appareil révocable à distance |
| Comptes partagés interdits | Chaque enseignant a son code ; un compte d’école collectif n’existe pas |
| USSD et SMS | Aucune donnée nominative sensible ; consultation USSD protégée par un code parent |
| Journalisation | Toute consultation nominative par un rôle de supervision est journalisée et consultable par le directeur |
| Conservation | Données nominatives conservées pendant la scolarité puis 5 ans (paramètre légal à confirmer) ; photos du cahier de vie supprimées au bout d’un an |
| Hébergement | Au Cameroun, conformément à la loi n° 2024/017, avec sauvegardes chiffrées |


### Exigences non fonctionnelles propres

| ID | Domaine | Exigence |
|---|---|---|
| NF-P1 | Volumétrie | Dimensionnement cible : 17 600 écoles publiques, 3,8 millions d’élèves, 76 000 enseignants (ordres de grandeur 2021/2022) |
| NF-P2 | Pics | Rentrée et fin d’unité : 40 000 lots de synchronisation par heure |
| NF-P3 | Tableaux de bord | Agrégats d’un département affichés en moins de 3 secondes |
| NF-P4 | Hors-ligne | Aucune perte de saisie après 30 jours sans connexion |
| NF-P5 | Accessibilité | Écrans utilisables par un enseignant formé en une demi-journée |
| NF-P6 | Coût | Coût des SMS par élève et par an plafonné par paramètre et suivi |


### Tests d’acceptation

| ID | Scénario | Résultat attendu |
|---|---|---|
| TST-01 | Inscrire un élève dont la famille n’a pas payé l’APEE | Inscription acceptée ; aucun champ ne lie les deux |
| TST-02 | Inscrire un enfant sans acte de naissance | Inscription acceptée, élève signalé sur DOC-07 |
| TST-03 | Saisir les critères de l’exemple « Paul E. » | Français ECA, mathématiques A, English NA ; listes de remédiation créées |
| TST-04 | Faire l’appel hors-ligne pendant 10 jours puis synchroniser | Toutes les présences remontées sans doublon |
| TST-05 | Un délégué régional demande la liste nominative d’une classe | Refus ; seuls les agrégats sont servis |
| TST-06 | Une personne non autorisée se présente à la sortie | Sortie refusée, parents et directeur alertés |
| TST-07 | Valider la liste CEP avec un candidat sans acte de naissance | Validation de l’inspection bloquée pour ce candidat |
| TST-08 | Transmettre un questionnaire statistique incohérent | Renvoi avec les contrôles en erreur |
| TST-09 | Produire l’état financier APEE de l’exemple | Encaissé 1 720 000 ; dépenses 1 000 000 ; solde 720 000 FCFA |
| TST-10 | Afficher un agrégat de 3 élèves à un partenaire | Valeur masquée |


## Déploiement, feuille de route et charge

### Stratégie de déploiement

| Étape | Périmètre | Condition pour passer à la suivante |
|---|---|---|
| Pilote | Un arrondissement, environ 50 écoles publiques, maternelles comprises | Objectifs O1 à O4 atteints sur un trimestre |
| Extension | Le département du pilote | Questionnaire statistique produit par l’application pour toutes les écoles |
| Région | Une région | Accord de la délégation régionale et du MINEDUB |
| National | Toutes les régions | Décision du ministère, financement, dispositif de formation en cascade |

Le pilote doit démarrer à une rentrée scolaire : les inscriptions de septembre alimentent tout le reste de l’année.

### Lots
Les applications sont réalisées pendant les semaines 36 à 52 de la feuille de route de la plateforme, puis le pilote de terrain dure 8 semaines.

| Lot | Semaines | Contenu |
|---|---|---|
| P1 École et présence | S36 à S41 | Évolutions du socle (territoires, synchronisation), ECO, ELV, CLA, PRE, PER, COM, SOR ; application mobile hors-ligne |
| P2 Évaluation et finances | S42 à S47 | EVA, PAS, DEV, APE, STA ; DOC-08 à DOC-12, DOC-18, DOC-23 à DOC-25 |
| P3 Examens et supervision | S48 à S52 | EXA, INS, CAN, TRA ; tableaux de bord territoriaux ; USSD |
| P4 Pilote | 8 semaines à partir d’une rentrée | Formation des directeurs et enseignants, saisie de rentrée, accompagnement de terrain, bilan |


### Charge estimée
En jours-personne, en plus du socle (682,5) et de l’application IPES (404).

| Profil | P1 | P2 | P3 | P4 | Total |
|---|---|---|---|---|---|
| Chef de projet | 6 | 6 | 5 | 15 | 32 |
| Analyste métier et formateur | 10 | 10 | 8 | 40 | 68 |
| Designer UX/UI | 10 | 6 | 4 | 3 | 23 |
| Développeurs back-end Laravel | 40 | 45 | 35 | 10 | 130 |
| Développeurs mobile et front-end | 45 | 40 | 25 | 10 | 120 |
| Testeurs | 15 | 18 | 15 | 15 | 63 |
| DevOps et sécurité | 4 | 3 | 3 | 6 | 16 |
| Total | 130 | 128 | 95 | 99 | 452 |

Hors charge : téléphones des écoles, forfaits de données, SMS, déplacements de terrain, formation en cascade au-delà du pilote.

### Risques

| Risque | Probabilité | Impact | Parade |
|---|---|---|---|
| Pas de mandat formel du MINEDUB | Moyenne | Très élevé | Convention de pilote avant tout développement P3 ; démonstration aux délégations |
| Écoles sans téléphone ni réseau | Élevée | Élevé | Téléphone d’école fourni par le programme, saisie par l’agent de l’inspection (R20) |
| Charge perçue par des enseignants qui ont 60 élèves | Élevée | Élevé | Appel en 2 minutes, saisie par critère en un geste, bulletins générés |
| Règles d’évaluation non validées officiellement | Moyenne | Élevé | Paramétrage national, atelier de validation avec l’Inspection générale |
| Données de mineurs exposées | Faible | Très élevé | Agrégats au-delà du département, chiffrement local, journal des consultations |
| Coût des SMS à l’échelle nationale | Moyenne | Moyen | Plafond par élève, USSD et portail privilégiés, accord avec les opérateurs |
| Double saisie avec les questionnaires papier | Moyenne | Moyen | Questionnaire produit par l’application accepté officiellement dès le pilote |


### Questions ouvertes
☐ Commanditaire : MINEDUB, une délégation régionale, une commune ou un partenaire technique et financier ?
☐ Arrondissement pilote et nombre d’écoles, maternelles comprises ?
☐ Format officiel du questionnaire statistique et de la remontée vers l’annuaire du MINEDUB ?
☐ Référentiels d’évaluation et politique de passage officiels à jour (primaire et préscolaire) ?
☐ Formats des listes de candidats demandés pour le CEP, le FSLC, l’entrée en 6e et le Common Entrance ?
☐ Qui finance les téléphones, les données et les SMS des écoles ?
☐ Durée légale de conservation des données des élèves ?

## Sources
DPPC/MINEDUB, chiffres clés 2021/2022 (INS)
Camerlex, organisation du MINEDUB (décret n° 2012/268 du 11 juin 2012)
MINEDUB, curriculum de l’enseignement primaire francophone, niveau 1 (2018)
Cameroon Tribune, « Comprendre le nouveau bulletin de notes » (janvier 2019)
Memoire Online, gratuité de l’enseignement primaire (2000)
Le360 Afrique, contributions APEE (septembre 2024)
Infos pratiques, calendrier des examens officiels 2026
Journal du Cameroun, concours d’entrée en 6e 2025
Wikipédia, système éducatif au Cameroun
Cahier des charges de la plateforme SCHOLARIS, 8 octobre 2026
