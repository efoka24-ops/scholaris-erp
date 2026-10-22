# Décision : un seul produit « Enseignement secondaire », personnalisé par le type d'établissement

Date : 2026-10-22. Statut : **décision du propriétaire** (reprise telle quelle), conséquences rédigées pour validation. Documents liés : [spec.md](spec.md), [plan.md](plan.md), [data-model.md](data-model.md), [questions.md](questions.md), [audit-reutilisation.md](audit-reutilisation.md), [tasks.md](tasks.md), [wave-plan.md](wave-plan.md).

## Résumé pour les non-techniciens
- Il n'existe **qu'une seule application** pour tous les établissements secondaires : clé technique `secondary`, une seule ligne au catalogue, un seul code, une seule équipe. Il n'y aura **ni** « secondaire public » **ni** application distincte par type d'école.
- À la demande d'accès, la personne choisit le **type d'établissement** dans une liste fermée de 7 : `leg`, `lycee_technique`, `ces`, `cetic`, `college_prive`, `lycee_prive`, `autre`. Ce choix est déjà obligatoire et enregistré (`establishment_subtype`).
- Le type ne change pas le code : il **règle des paramètres** à l'ouverture (cycles proposés, enseignement général ou technique, examens, modules visibles, mode de frais) et **active ou masque des modules** (ateliers, stages, second cycle, paie, catalogue de frais…). L'administrateur de l'établissement peut ajuster ces réglages, dans des limites.
- Public ou privé n'est pas un produit différent : c'est un réglage (`ownership`) qui change surtout les frais, la paie et les contrôles.
- **Aucune règle officielle n'est écrite comme un fait** : tout barème, coefficient, seuil, durée, calendrier ou règle de passage est un paramètre et une question au client ([questions.md](questions.md)). Les chiffres du cahier des charges ne servent que de jeu d'exemple pour les tests.

## 1. La décision
1. Un seul produit `secondary` (catalogue : « Enseignement secondaire », statut `coming_soon` jusqu'à la recette).
2. Le type d'établissement choisi à la demande d'accès **personnalise** le produit. Liste fermée, codes stables (ne jamais renommer un code : ils sont stockés) :
   | Code | Libellé | Lecture « grand public » |
   |---|---|---|
   | `leg` | Lycée d'enseignement général | 1er et 2nd cycles généraux |
   | `lycee_technique` | Lycée technique | 1er et 2nd cycles techniques et professionnels |
   | `ces` | Collège d'enseignement secondaire | 1er cycle général seulement |
   | `cetic` | Collège d'enseignement technique, industriel et commercial | 1er cycle technique seulement |
   | `college_prive` | Collège privé | 1er cycle, établissement privé |
   | `lycee_prive` | Lycée privé | 1er et 2nd cycles, établissement privé |
   | `autre` | Autre (lycée polyvalent ou complexe scolaire, cas particulier) | tout est à régler à la main |
   Ces lectures viennent du cahier des charges (CDC v1.0, rubrique « Présentation du document ») ; leur exactitude est à valider (Q18 à Q21).
3. Il n'y a pas de clé `public_secondary`. Les établissements publics du secondaire ouvrent `secondary` comme les autres (voir §6 pour le modèle commercial).

## 2. Mécanisme retenu : « profil de type » + « modules activables »
- **Profil de type** : pour chaque code, un ensemble de valeurs par défaut stocké comme **paramètre de plateforme** (`secondary.type_profiles.<code>`), pas dans le code. Contenu : cycles offerts, enseignements offerts (`general`, `technical`), propriété par défaut (`public`, `private_secular`, `private_faith`, ou à choisir), modules activés par défaut, examens proposés, vocabulaire des rôles (proviseur ou principal).
- **À l'ouverture de l'application** pour un établissement, le profil est **copié** dans `se_establishment_profiles` (version du profil conservée). Les évolutions ultérieures du profil de plateforme ne modifient pas un établissement déjà ouvert sans action de son administrateur.
- **Modules activables** (liste fermée, §4) : une route d'un module inactif répond `409 module_not_enabled`, son menu est masqué, ses données restent intactes (lecture seule) si le module est coupé plus tard.
- **Garde d'architecture** : un test vérifie que chaque route d'un module activable passe par la garde de module, comme chaque route d'écriture passe par la garde d'abonnement (423 suspendu, 403 fermé).
- **Le type est modifiable** après l'ouverture par l'administrateur fonctionnel du client, avec motif et journal (jamais de perte de données ; un module coupé qui a des données passe en lecture seule). Q33.

## 3. Ce que la décision change
### Rôles
- Les 24 rôles du CDC (R01 à R24) existent **une fois**. Un rôle n'est proposé que si son module est actif : le chef des travaux (R06) seulement avec les ateliers ; le promoteur et le réseau (R01, R02) et la brigade de contrôle (R23) seulement pour un établissement privé ; le bureau de l'APEE (R20), l'intendant et le caissier selon le mode de frais.
- Le libellé du chef d'établissement suit le type (proviseur ou principal) : c'est du vocabulaire, pas un rôle de plus.
- Les rôles externes (R21 à R23) restent en lecture seule, datés et journalisés.
### Séries et filières
- Les listes de référence (niveaux, séries, spécialités, matières, coefficients officiels, examens) sont tenues **au niveau de la plateforme** et **filtrées par le profil** : un CES ne propose aucune série du second cycle ; un lycée technique propose des spécialités ; un lycée d'enseignement général propose des séries.
- Les listes livrées au départ sont marquées « exemple (CDC) » tant que le client n'a pas fourni les textes en vigueur (Q22, Q23). Aucune liste n'est présentée comme officielle.
### Cycles
- Le type limite les cycles (RG-ORG-1 du CDC) : `ces`, `cetic`, `college_prive` n'ont que le 1er cycle ; une classe de second cycle y est refusée en 422. Un `college_prive` qui ouvre un second cycle devient `lycee_prive` (Q21).
### Évaluation
- **Un seul moteur** : mêmes séquences, mêmes périodes, même stratégie de calcul `secondary`, même conseil de classe, mêmes bulletins pour tous les types. Seuls changent les **paramètres** (coefficients par niveau, série ou spécialité ; règles de passage ; matières « pratiques » ; barème d'absences) et la présence d'un module (la pratique professionnelle n'a de sens qu'avec les ateliers).
### Documents
- 34 documents du CDC, **une seule liste** ; certains sont conditionnels au module (stage, ateliers : DOC-21 à 24 ; paie du privé : DOC-30). Le modèle d'un document peut varier par type (langue, mentions), pas son numéro ni sa mécanique (QR, rectificatif, duplicata).
### Tarif et catalogue
- Une seule ligne au catalogue (`secondary`, 60 000 XAF par mois, 90 jours d'essai, 15 jours de grâce aujourd'hui, valeurs de la table de plateforme, modifiables). Le type n'ouvre pas de tarif distinct par défaut (Q25).
- Le catalogue public affiche **un** produit « Enseignement secondaire » ; le type se choisit dans le formulaire de demande d'accès, pas dans le catalogue. La visibilité du produit au catalogue public reste la question Q-A1 de l'audit (non tranchée ici).

## 4. Ce qui devient paramètre ou module (pas application séparée)
Légende : **P** = paramètre ; **M** = module activable (code du module entre parenthèses) ; défaut = valeur par défaut du profil, **à confirmer** (Q18, Q28).

| Sujet | `leg` | `lycee_technique` | `ces` | `cetic` | `college_prive` | `lycee_prive` | `autre` |
|---|---|---|---|---|---|---|---|
| Cycles offerts (P) | 1er et 2nd | 1er et 2nd | 1er | 1er | 1er | 1er et 2nd | à choisir |
| Enseignement (P) | général | technique | général | technique | général | général (technique possible, Q19) | à choisir |
| Propriété par défaut (P) | public | public | public | public | privé | privé | à choisir |
| Second cycle : séries, orientation, probatoire, baccalauréat (M `second_cycle`, `orientation`) | oui | oui | non | non | non | oui | au choix |
| Ateliers, EPI, matière d'œuvre, évaluation pratique (M `workshops`) | non | oui | non | oui | non | optionnel | optionnel |
| Stages en entreprise (M `internships`) | non | oui | non | oui | non | optionnel | optionnel |
| Examens du 1er cycle (P, liste plateforme) | oui | oui (versions techniques) | oui | oui | oui | oui | au choix |
| Examens du 2nd cycle (P) | oui | oui | non | non | non | oui | au choix |
| Section anglophone (P `sections`) | optionnelle | optionnelle | optionnelle | optionnelle | optionnelle | optionnelle | optionnelle |
| Frais : grille, remises, tranches (M `fees_private`) | non | non | non | non | oui | oui | à choisir |
| Frais : catalogue limité aux frais autorisés (M `fees_public_catalog`) | oui | oui | oui | oui | non | non | à choisir |
| Registre des autorisations d'ouverture (M `authorizations`) | non | non | non | non | oui | oui | à choisir |
| Paie simplifiée du personnel (M `payroll_simple`) | non | non | non | non | oui | oui | à choisir |
| Indicateurs de réseau (M `network_indicators`) | non | non | non | non | optionnel | optionnel | optionnel |
| Services annexes : internat, cantine, transport, bibliothèque, infirmerie (M, un par service) | optionnels | optionnels | optionnels | optionnels | optionnels | optionnels | optionnels |
| Contrôle externe : inspection pédagogique (R21) | oui | oui | oui | oui | oui | oui | oui |
| Contrôle externe : brigade des établissements privés (R23) | non | non | non | non | oui | oui | à choisir |
| Vocabulaire du chef d'établissement | proviseur | proviseur | principal | principal | principal | proviseur | à choisir |

Toujours actifs pour tous les types (noyau) : paramétrage, inscriptions, matricule national et rapprochement avec le site de la carte scolaire (fichiers), emplois du temps, cahier de textes, appel par heure, discipline, séquences et notes, conseils de classe, bulletins, passage, personnel et vacations, documents, messages, portails, tableaux de bord.

## 5. Ce que la décision interdit
- Une branche de code par type (`if ($type === 'cetic')` en dehors du résolveur de profil) : un test d'architecture l'interdit ; tout passe par le profil et ses modules.
- Une valeur réglementaire écrite dans le code, la base de démonstration ou ce dossier **comme un fait** (RG, barème, coefficient, seuil, durée, calendrier, règle de passage). Les exemples du CDC vivent dans des jeux de test marqués `example_*`.
- Une application `public_secondary`, ou un second tarif créé implicitement par le type.

## 6. Public, privé et modèle commercial
- Le même produit sert un lycée public et un collège privé. Le **contrat** diffère : un établissement public relève en général d'un programme ou d'une autorité (pas de facture au chef d'établissement). Ce n'est pas un code différent : c'est un **réglage commercial du client** par l'administrateur de la plateforme (tarif, essai, prise en charge). Q25, Q26.
- Une demande d'accès d'un établissement public suit le **même parcours** que celle d'un privé (le type est obligatoire) ; l'administrateur de la plateforme accepte ou refuse. Pas de création par lot d'écoles ici (cela relève de l'application publique des écoles primaires, hors périmètre).

## 7. Conséquences sur les documents du dossier
| Document | Ce qui change |
|---|---|
| [spec.md](spec.md) | Une application paramétrée ; exigences EX-SEC-xx avec colonne « types concernés » ; rôles et parcours par type |
| [plan.md](plan.md) | Résolveur de profil, garde de module, profils de type en paramètres de plateforme |
| [data-model.md](data-model.md) | Profil d'établissement (7 types, propriété, modules, version), journal des changements de profil |
| [tasks.md](tasks.md) | Tâches T813 à T815, T836 à T838, T950 à T958 pour le profil, les modules et les tests par type |
| [wave-plan.md](wave-plan.md) | Squelette et profil dès la vague 0 ; 3 agents au plus avec dossiers exclusifs |
| [questions.md](questions.md) | Questions par type (Q18 à Q33) avec réponse par défaut et interlocuteur |
| [audit-reutilisation.md](audit-reutilisation.md) | Ce qui existe déjà et les écarts réels au 22 octobre 2026 |
