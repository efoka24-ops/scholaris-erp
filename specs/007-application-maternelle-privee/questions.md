# Questions pour le client et l'utilisateur : application Maternelle privée (`nursery`)

Date : 2026-10-20. Chaque question a une **valeur par défaut proposée**, toujours « à confirmer » : elle permet de construire sans coder de règle ni de seuil en dur (tout passe par `/settings` ou une donnée). Aucune valeur officielle camerounaise n'est inventée : les valeurs reprises de la maternelle publique (004) sont des **propositions de départ**, pas des règles du privé. Priorités : **P0** bloque le modèle de données ou l'architecture ; **P1** bloque une fonction Must ; **P2** affine ; **P3** optionnel. Les questions Q-A1 à Q-A10 de [../006-audit-primaire-maternelle/questions.md](../006-audit-primaire-maternelle/questions.md) et Q1 à Q23 de [../004-applications-primaire-maternelle-publiques/maternelle/questions.md](../004-applications-primaire-maternelle-publiques/maternelle/questions.md) restent valables.

## A. Décisions d'architecture (utilisateur)
| N° | Prio | Question | Défaut proposé | Impact si non tranché |
|---|---|---|---|---|
| MQ-01 | P0 | Mutualiser la sortie sécurisée et le suivi du développement entre `public_nursery` et `nursery` : **A** modules de socle partagés (`PickupGate`, `EarlyYears`), **B** recopie encadrée par un test de parité, **C** une application unique paramétrée ? (Reprend Q-A5.) | **A**, séquencée après la stabilisation et le commit de `PublicNursery` ; repli B pour ne pas bloquer le privé | Deux implémentations divergentes de la sortie sécurisée |
| MQ-02 | P1 | Horaires de sortie et délais d'alerte d'un enfant non récupéré : heure de fin des cours (par jour ?), délai avant l'alerte aux parents, délai avant l'alerte à la directrice | Heure saisie par l'école ; propositions 30 minutes puis 15 minutes ; aucune alerte tant que l'heure n'est pas saisie | Veille de sortie inactive |
| MQ-17 | P2 | Lots hors ligne : se brancher sur le point d'entrée commun `POST /sync/batches` (appareil signé) ou garder une route propre ? | Point d'entrée commun | Route propre à maintenir |
| MQ-21 | P1 | Reprise de Q-A1 : la clé `nursery` doit-elle apparaître au **catalogue public** et être ouvrable en essai libre-service avant la recette ? (Non modifié par cette spécification.) | Non : `coming_soon`, ouverte seulement par l'administrateur de plateforme jusqu'à la recette | Ouverture prématurée d'une application vide |

## B. Sécurité de la sortie
| N° | Prio | Question | Défaut proposé |
|---|---|---|---|
| MQ-03 | P1 | Âge maximal de la liste du matin hors ligne avant le mode « doute » (aucune remise sans appel à la directrice) ; durée de lecture hors ligne autorisée | 1 jour pour le mode « doute », 30 jours de lecture (propositions) |
| MQ-04 | P1 | Autorisation ponctuelle avec code : en V1 ? nombre d'essais avant blocage ; code transmis par le parent seulement ? | Oui ; 5 essais (proposition) ; code envoyé par SMS au parent |
| MQ-05 | P1 | Un ajout d'autorisé par le parent est-il actif tout de suite ou après validation par l'école ? | Actif tout de suite ; validation par l'école en option |
| MQ-18 | P2 | Appel vocal réel pour l'enfant non récupéré (passerelle à choisir) ? | Non : SMS urgent ; `VoiceGateway` reste désactivée |
| MQ-19 | P1 | Restriction de garde : la personne visée est-elle exclue des messages de l'école ? Qui peut saisir la décision de justice ? | Exclue des messages ; directrice seule (reprend Q-05 de 004) |

## C. Suivi du développement et cahier de vie
| N° | Prio | Question | Défaut proposé |
|---|---|---|---|
| MQ-06 | P1 | Consentement photo : écrit avec preuve déposée, ou accord donné par le parent depuis son compte ? (Reprend Q-06 de 004.) | Accord du parent authentifié, preuve déposée en option ; consentement retiré = photos retirées à la lecture suivante |
| MQ-07 | P2 | Durée de conservation des photos et du cahier de vie | Fin de l'année scolaire suivante (proposition) ; valeur légale à fournir |
| MQ-08 | P0 | **Référentiel d'observation** : qui l'écrit (plateforme, école, ou les deux) ? domaines et items ? niveaux (« acquis, en cours, pas encore ») et libellés FR/EN ? fréquence minimale par domaine ? liste de termes à éviter ? Reprend Q-A8 pour le privé | La plateforme livre un référentiel « exemple » à valider ; l'école le copie et l'adapte ; trois niveaux ; 1 observation par domaine et par mois (proposition) ; liste de termes vide |
| MQ-14 | P0 | **Carnet de suivi** : un ou plusieurs carnets réels (anonymisés) d'écoles privées cibles (francophone et anglophone) ; trimestriel ou autre rythme ; poids maximal du PDF | Carnet trimestriel, modèle éditable FR et EN, PDF léger ; modèle marqué « exemple » |
| MQ-20 | P2 | Besoins particuliers : catégories proposées (langage, vue, audition, autre) suffisantes ? orientation vers qui ? | Catégories ci-contre ; statut ouvert, orienté, clos |

## D. École, inscription, frais
| N° | Prio | Question | Défaut proposé |
|---|---|---|---|
| MQ-11 | P0 | Sections et âges : noms (petite, moyenne, grande, ou Nursery 1, 2, 3 ?), âge minimal d'admission, une ou plusieurs sections par niveau, sous-systèmes francophone, anglophone ou les deux (documents bilingues) | Libellés saisis par l'école ; âge minimal en avertissement seulement ; sous-système par section |
| MQ-12 | P1 | Rôles : qui fait quoi dans une maternelle privée cible (promoteur, directrice, éducatrice, aide, secrétaire, économe, responsable de cantine) ? Une éducatrice a-t-elle une seule section ? | Rôles de spec.md §2 ; une section par éducatrice ; aide rattachée à une section |
| MQ-13 | P2 | Autorisation d'ouverture de l'école (niveaux couverts) contrôlée à l'inscription comme pour l'IPES ? | Oui, comme `EnrolmentGate` |
| MQ-10 | P1 | Frais : natures (inscription, pension par tranches, garderie, cantine, transport, tenue, fournitures, autres), échéances, remise fratrie, blocage du carnet si impayé ? (Fournir une grille de frais d'une école cible.) | Natures de plan.md §6 ; remise fratrie du socle ; **aucun blocage** du carnet |
| MQ-22 | P2 | Garderie, cantine, transport : forfaits seulement, ou suivi au jour ? | Forfaits facturés par cycle ; suivi quotidien hors V1 |
| MQ-23 | P2 | Fermetures d'un jour (NOT-14) : qui les saisit ? parents informés par SMS ? | Directrice ; SMS aux parents des sections concernées |

## E. Passage et liaison
| N° | Prio | Question | Défaut proposé |
|---|---|---|---|
| MQ-09 | P1 | Passage de fin d'année : passage automatique en section supérieure ? maintien exceptionnel possible ? avec quelles pièces (demande des parents, avis d'un tiers) ? qui décide ? | Passage automatique ; maintien exceptionnel sur demande des parents enregistrée, décidé par la directrice ; aucun avis d'inspecteur au privé ; motif obligatoire |
| MQ-24 | P2 | Fiche de liaison : contenu officiel ou libre ? transmise à une école primaire d'un autre client de la plateforme avec accord du parent ? | Contenu libre positif ; transmission avec accord du parent |

## F. Commerce et divers
| N° | Prio | Question | Défaut proposé |
|---|---|---|---|
| MQ-16 | P2 | Tarif et essai de la maternelle privée (reprend Q-A9) | 30 000 XAF par mois, 90 jours, grâce 15 jours |
| MQ-15 | P3 | Rapports à une tutelle pour le privé (inspection, délégation) : lesquels ? | Aucun en V1 ; export d'effectifs par section et sexe, CSV/XLSX |
| MQ-25 | P0 | HTTPS actif avant toute donnée réelle d'enfant (reprend Q23 de 004) | Données synthétiques `SYN-*` seulement tant que HTTPS n'est pas actif |
| MQ-26 | P2 | Hébergement et sauvegarde pour un client privé : même mutualisé que l'IPES ? | Oui, avec sauvegardes du socle |

## G. Documents à obtenir (à joindre dans `specs/007-application-maternelle-privee/sources/`)
| # | Document | Pourquoi | Prio |
|---|---|---|---|
| DOC-M1 | Un ou plusieurs carnets de suivi réels de maternelles privées, anonymisés (FR et EN) | Modèle de carnet, vocabulaire des niveaux | P0 |
| DOC-M2 | Référentiel de développement ou programme de la maternelle utilisé par l'école cible | Domaines et items par défaut | P0 |
| DOC-M3 | Grille de frais d'une école cible | Natures et échéanciers | P1 |
| DOC-M4 | Règlement intérieur, règles de sortie et de retard de l'école cible | Paramètres de sortie, MQ-02 à MQ-05 | P1 |
| DOC-M5 | Modèles de documents émis (certificat de scolarité, attestation, carte, fiche de liaison) | Catalogue de documents | P2 |
| DOC-M6 | Formulaire de consentement photo de l'école cible | MQ-06, MQ-07 | P1 |

## H. Ordre de demande conseillé
1. MQ-01 (architecture) et MQ-21 (catalogue), sans réponse du client : l'utilisateur tranche.
2. MQ-08, MQ-11, MQ-14, DOC-M1, DOC-M2 (une seule séance avec une école cible) : débloquent référentiel, sections, carnet.
3. MQ-02, MQ-03, MQ-05, MQ-06, MQ-09, MQ-10, MQ-12, DOC-M3, DOC-M4, DOC-M6 : sortie, consentement, passage, frais, rôles.
4. Le reste au fil de l'eau ; les valeurs par défaut suffisent pour construire.
