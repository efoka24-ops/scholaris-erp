# Questions pour l'utilisateur : application Enseignement secondaire (un produit, 7 types)

**Date** : 2026-10-22 (remplace la version du 2026-10-15). **Entrées** : [spec.md](spec.md), [decision-un-produit-secondaire.md](decision-un-produit-secondaire.md), [tasks.md](tasks.md).

## Résumé pour les non-techniciens
- Il y a **33 questions**. Chacune a une **réponse par défaut** : le développement avance avec elle, sans attendre. Cette réponse est un **paramètre modifiable**, jamais une règle officielle.
- **Aucune valeur réglementaire n'est donnée comme un fait** : tout seuil, barème, coefficient ou liste est écrit comme « valeur d'exemple, à confirmer ».
- Les questions **Q1 à Q17** viennent du cahier des charges (CDC) ; **Q18 à Q33** sont nouvelles : elles portent sur les 7 types d'établissement (LEG, lycée technique, CES, CETIC, collège privé, lycée privé, autre).
- **À poser en premier** : Q1, Q2, Q18, Q22, Q25.
- **Interlocuteur** : Ministère (MINESEC et offices d'examen), Promoteur (propriétaire du client), Chef d'établissement, ou Plateforme (équipe SCHOLARIS).

## A. Questions du CDC (Q1 à Q17)
| # | Question | Interlocuteur | Bloque | Réponse par défaut (paramètre, à confirmer) |
|---|----------|---------------|--------|---------------------------------------------|
| Q1 | **cartescolaire.cm** : existe-t-il une interface (API) ? Sinon, formats exacts (colonnes, encodage, séparateur) : export des matricules, export des paiements (contributions, frais d'examen), fichier des conseils de fin d'année, date limite | Ministère | T828, T829, T971, DOC-33 | Fichiers CSV UTF-8 séparateur `;` ; colonnes dans `secondary.carte_scolaire.columns.*` (matricule, nom, prénoms, date de naissance, classe ; paiement : référence, nature, montant, date ; fin d'année : moyenne annuelle, rang, décision) ; échéance `secondary.carte_scolaire.year_end_deadline` vide = pas d'alerte |
| Q2 | **Textes en vigueur** : coefficients et volumes par niveau, série, spécialité ; conditions de passage ; distinctions et sanctions du travail ; âge limite par niveau | Ministère | stratégie (valeurs), RG-INS-2 | Aucune valeur officielle. Valeurs d'exemple du CDC dans `Defaults.php` et les jeux `example_cdc`, étiquetées « exemple » ; coefficients saisis par l'établissement, non verrouillés tant que la source n'est pas renseignée ; âge limite vide = aucune alerte |
| Q3 | **Formats des listes de candidats** de l'Office du Baccalauréat, de la Direction des examens, du GCE Board ; format des résultats publiés | Ministère | T906, T907 | Export CSV générique (`integrations.export_types.secondary`) : matricule, nom, prénoms, naissance, sexe, série, matières optionnelles ; import CSV : matricule, résultat, mention |
| Q4 | **Catalogue des frais autorisés** d'un établissement public : liste et montants par cycle ; qui le tient | Ministère, Plateforme | T831 | Catalogue vide, tenu par le client ; tout frais absent → demande d'ajout (`se_fee_requests`), refus 422 en attendant ; référence de décision demandée pour les contributions de parents d'élèves |
| Q5 | **Spécialités techniques** du pilote, grilles d'évaluation pratique existantes, définition d'un « manquement grave à la sécurité » | Chef d'établissement | T908, T909 | Grille d'exemple du CDC (5 critères, 4/4/6/4/2 points, étiquetée exemple) ; plafond d'exemple si la case « manquement grave » est cochée par l'enseignant ; les deux sont des paramètres |
| Q6 | Réseaux confessionnels ou groupes à servir dès la version 1 ; indicateurs partagés avec le réseau | Promoteur | RG-ORG-5, T970 | Aucun réseau en V1 ; sinon effectifs, taux de réussite, recouvrement agrégés, sans donnée nominative |
| Q7 | [EN] Correspondance note → lettre des bulletins anglophones | Chef d'établissement | T870 | Désactivée ; table `secondary.grades.letter_scale` vide |
| Q8 | [TEC] Le refus d'admission pour une pratique insuffisante porte-t-il sur la moyenne annuelle de la matière pratique ou sur chaque trimestre ? Quelles matières sont « pratiques » ? | Ministère | stratégie | Moyenne annuelle des matières marquées `practical` dans `se_subject_coefficients` ; par trimestre : alerte seulement ; seuil = paramètre d'exemple |
| Q9 | [PRI] Paie simplifiée : éléments (brut, primes, cotisations, impôts, retenues), bulletin attendu ; la plateforme doit-elle calculer les cotisations ? | Promoteur | T912 | Saisie du brut et de retenues libres ; aucun taux légal calculé ; net = brut − retenues |
| Q10 | Établissements pilotes : quel lycée privé, quel public, quel lycée technique ou CETIC ? | Promoteur | T979 | Établissements de démonstration créés par `establishment:create`, un par type |
| Q11 | RG-FIN-4 : ordre entre enfants d'une même famille quand le paiement ne couvre pas toutes les tranches | Promoteur | T945 | `billing.allocation_sibling_order = enrolled_first`, tranche par tranche ; le test ne vérifie que les totaux du jeu d'exemple |
| Q12 | Rapport de rentrée et statistiques pour le MINESEC (DOC-32) : modèle officiel ? | Ministère | T971 | Effectifs par niveau, sexe, âge, redoublants ; personnel par statut ; PDF et XLSX en format libre |
| Q13 | Comptes externes R21 à R23 : qui les crée, pour combien de temps, sur quel périmètre ? | Plateforme, Ministère | T809 (SS13) | Créés par l'administrateur fonctionnel, 30 jours, établissement visité seulement, lecture seule journalisée |
| Q14 | Faut-il « exclu de cours » et « infirmerie » dans l'appel lui-même ou dans des écrans séparés ? | Plateforme | T867 | Écrans séparés (`se_class_exclusions`, `se_infirmary_visits`), sans changer l'appel du socle ; statuts d'appel du socle conservés |
| Q15 | Composition de fin d'année : organisée ? comptée avec quel poids ? | Chef d'établissement | stratégie | Si elle existe : une épreuve de la dernière séquence, poids 1 (paramètre) |
| Q16 | Délai de recours des parents contre une décision de fin d'année | Ministère, Chef d'établissement | T905 | `secondary.appeal_days` = 8 jours calendaires (valeur d'exemple, à confirmer) |
| Q17 | Règle d'arrondi exacte (demi vers le haut au centième ? arrondi avant la moyenne trimestrielle ?) | Ministère | stratégie | Oui aux deux (`rounding = 2`, `round_each_step = true`) ; paramètre par version de règles |

## B. Questions propres aux types d'établissement (Q18 à Q33)
| # | Question | Interlocuteur | Bloque | Réponse par défaut (paramètre, à confirmer) |
|---|----------|---------------|--------|---------------------------------------------|
| Q18 | **Valeurs par défaut de chaque type** : cycles, type d'enseignement (général ou technique), modules activés, examens de fin de cycle présentés. La table de la décision ([decision-un-produit-secondaire.md](decision-un-produit-secondaire.md), §4) est-elle juste ? | Ministère, Promoteur | T813 | Table du document de décision, lue du CDC ; chaque ligne est une donnée de plateforme `secondary.type_profiles.<code>`, modifiable sans développement ; `autre` sans défaut au-delà du noyau |
| Q19 | Un **établissement technique privé** a-t-il son propre code de type ou est-il un `lycee_prive` avec enseignement technique ? | Plateforme | T813 | `lycee_prive` avec enseignement technique et modules `workshops` et `internships` activés ; pas de huitième code |
| Q20 | Un **lycée d'enseignement général et technique (polyvalent)** : quel type retenir ? | Plateforme | T813 | `autre`, avec enseignements général et technique choisis dans l'assistant |
| Q21 | Un **collège privé qui ouvre un second cycle** : devient-il `lycee_prive` ? Qui change le type ? | Promoteur | T838 | Oui : changement de type par l'administrateur fonctionnel, avec motif et journal ; les classes existantes sont conservées |
| Q22 | **Listes de séries et de spécialités** (général et technique) : sources, mises à jour, qui les maintient ? | Ministère | T821 | Listes vides, tenues par la plateforme ; les séries du CDC fournies comme « exemple » ; chaque établissement active celles qu'il utilise |
| Q23 | **Noms des niveaux** par sous-système (francophone, anglophone) et pour le technique (années de formation) | Ministère | T821 | Libellés d'exemple du CDC dans `se_streams` et les niveaux du socle ; renommables par l'établissement |
| Q24 | **Modèles réels de bulletin et de carnet** par type (échantillons) : ont-ils des rubriques propres ? | Chef d'établissement | T904, T913 | Un modèle de bulletin par nature de période, avec rubriques de base ; rubriques supplémentaires activées selon les modules ; échantillons demandés au pilote |
| Q25 | **Prix et essai par type**, et **modèle commercial du public** : même catalogue ? contrat public à part ? | Plateforme, Promoteur | décision §6 | Un seul tarif au catalogue pour tous les types (valeurs de plateforme modifiables) ; le contrat d'un établissement public est un réglage du client posé par l'administrateur de la plateforme ; aucun code `public_secondary` |
| Q26 | **Accès d'un établissement public** : même demande d'accès et mêmes validations qu'un privé ? Qui valide ? | Plateforme | T815 | Même demande d'accès ; validation par l'administrateur de la plateforme ; type pris dans la fiche de l'établissement (`subtype`) |
| Q27 | **Calendrier scolaire** : dates de l'année, trimestres, séquences, vacances : fournies par qui, et sont-elles les mêmes pour tous les types ? | Ministère, Chef d'établissement | T822 | Aucun calendrier codé ; saisi par établissement avec modèle réutilisable ; contrôle seulement que les séquences sont dans leur trimestre |
| Q28 | **Modules activés par défaut** par type (cantine, transport, internat, bibliothèque, infirmerie, paie simple) | Promoteur | T813 | Aucun module de service actif par défaut ; activables dans l'assistant ; internat, cantine, transport, bibliothèque, infirmerie : décision de l'établissement |
| Q29 | **Structure anglophone et GCE** : s'applique-t-elle à tous les types ? Quelles différences de bulletin et d'examen ? | Ministère | T870, T906 | Sous-système choisi par établissement (paramètre) ; bulletin en anglais disponible ; lettres désactivées (Q7) ; format GCE : export générique (Q3) |
| Q30 | **Durée d'une heure d'appel** et plages horaires : communes à tous les types ? | Chef d'établissement | T860, T867 | Plages définies par établissement (paramètre) ; appel par créneau (`slot`) ; aucune durée codée |
| Q31 | **Statuts du personnel** (fonctionnaire, contractuel, vacataire, permanent d'un privé) : quels statuts selon la propriété ? | Promoteur | T911, T912 | Statuts du socle `staff` ; privé : permanent ou vacataire ; public : fonctionnaire affecté, vacataire ; paie simple jamais pour un fonctionnaire |
| Q32 | **Documents par type** : DOC-21 à 24 (stages, ateliers) réservés au technique ? DOC-30 (paie) au privé ? Autres restrictions ? | Chef d'établissement | T833, T913 | DOC-21 à 24 émis seulement avec les modules `workshops` ou `internships` ; DOC-30 seulement avec `payroll_simple` ; les autres documents pour tous les types |
| Q33 | **Changement de type** après l'ouverture : permis ? avec quelles limites ? | Plateforme | T838 | Permis par l'administrateur fonctionnel, motif obligatoire, journal (`se_profile_changes`) ; module coupé avec données : lecture seule ; rien n'est supprimé |

## C. Documents à obtenir (pour lever les réserves)
| Document | À obtenir de | Lève |
|----------|--------------|------|
| Textes en vigueur du MINESEC (coefficients, volumes, passage, distinctions, âges) | Ministère | Q2, Q8, Q15 à Q17 |
| Spécifications cartescolaire.cm (ou échantillons de fichiers) | Ministère | Q1 |
| Formats de listes de candidats et de résultats (Office du Baccalauréat, Direction des examens, GCE Board) | Ministère | Q3, Q29 |
| Catalogue des frais autorisés du public, modèle de contrat public | Ministère, Plateforme | Q4, Q25 |
| Modèles de bulletin, de carnet, de fiches de stage et d'atelier par type | Chef d'établissement | Q5, Q24, Q32 |
| Listes officielles de séries et de spécialités, noms des niveaux | Ministère | Q22, Q23 |
| Calendrier scolaire de l'année en cours | Ministère, Chef d'établissement | Q27 |
| Coordonnées des établissements pilotes (un par grande famille de type) | Promoteur | Q10 |

## D. Ordre de priorité
1. **Q1, Q2, Q18, Q22, Q25** : elles conditionnent l'exactitude des données officielles et la définition même des profils ; aucune ne bloque le démarrage du code.
2. Q3, Q4, Q5, Q19 à Q21, Q24, Q26 : avant les lots E3 et le pilote.
3. Les autres : avant la recette (T979).

## E. Rappel sur les réponses par défaut
Chaque réponse par défaut est un **paramètre** (plateforme, client ou établissement) ; elle est lue au lancement d'un jeu de test ou à l'ouverture d'un établissement ; la modifier ne demande ni développement ni nouvelle version. Les nombres des jeux d'essai (T940 à T949) sont des données de test, jamais des règles.
