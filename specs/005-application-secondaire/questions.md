# Questions pour l'utilisateur : application Secondaire

Date : 2026-10-15. Chaque question a une **valeur par défaut paramétrable « à confirmer »** : le développement avance avec elle, sans jamais la présenter comme une règle officielle. Les valeurs viennent du CDC ou sont des choix techniques neutres. Ordre = priorité.

| # | Question | Bloque | Valeur par défaut « à confirmer » (paramètre) |
|---|----------|--------|-----------------------------------------------|
| Q1 | **cartescolaire.cm** : existe-t-il une interface (API) ? Sinon, formats exacts (colonnes, encodage, séparateur) : (a) export des matricules, (b) export des paiements (contributions, frais d'examen), (c) fichier des conseils de fin d'année à saisir ou importer, et date limite MINESEC | T828, T829, T971, DOC-33 | Fichiers CSV UTF-8 `;` ; colonnes `secondary.carte_scolaire.columns.*` = matricule, nom, prénoms, date de naissance, classe, (paiement : référence, nature, montant, date) ; (fin d'année : moyenne annuelle, rang, décision) ; échéance `secondary.carte_scolaire.year_end_deadline` vide = pas d'alerte |
| Q2 | **Textes MINESEC en vigueur** : coefficients et volumes par niveau, série, spécialité ; conditions de passage ; distinctions et sanctions du travail ; âge limite par niveau | stratégie (valeurs), RG-INS-2 | Valeurs d'exemple du CDC (distinctions 16/14/12/10/8, passage 10/9, pratique 8) ; coefficients saisis par l'établissement, non verrouillés tant que la source n'est pas renseignée ; âge limite vide = aucune alerte |
| Q3 | **Formats des listes de candidats** de l'Office du Baccalauréat, de la Direction des examens et du GCE Board ; format des résultats publiés | T906, T907 | Export CSV générique (`integrations.export_types.secondary`) : matricule, nom, prénoms, naissance, sexe, série, matières optionnelles ; import CSV : matricule, résultat, mention |
| Q4 | **Catalogue des frais autorisés du public** : liste et montants par cycle (contributions exigibles, APEE, services facultatifs), et qui le tient (client ou plateforme) | T831 | Catalogue vide tenu par le client ; APEE seulement avec référence du PV d'assemblée ; tout autre frais → demande d'ajout |
| Q5 | **Spécialités techniques du pilote** et grilles d'évaluation pratique existantes ; définition d'un « manquement grave à la sécurité » | T908, T909 | Grille du CDC (5 critères, 4/4/6/4/2 points) ; plafond 8 si la case « manquement grave » est cochée par l'enseignant |
| Q6 | Réseaux confessionnels ou groupes à servir dès la version 1 ; indicateurs partagés avec le réseau | RG-ORG-5, T970 | Aucun réseau en V1 ; si un réseau existe : effectifs, taux de réussite, recouvrement agrégés, sans données nominatives |
| Q7 | [EN] Correspondance note → lettre des bulletins anglophones | T870 | Désactivée ; table paramétrable `secondary.grades.letter_scale` vide |
| Q8 | [TEC] Le refus d'admission pour pratique < 8 porte-t-il sur la moyenne annuelle de la matière « pratique professionnelle » ou sur chaque trimestre ? Quelles matières sont « pratiques » ? | stratégie | Moyenne annuelle des matières marquées `practical` dans `se_subject_coefficients` ; trimestre : alerte seulement |
| Q9 | [PRI] Paie simplifiée : éléments (brut, primes, CNPS, IRPP, retenues) et bulletin attendu ; la plateforme doit-elle calculer les cotisations ? | T912 | Saisie du brut et de retenues libres ; aucun taux légal calculé ; net = brut − retenues |
| Q10 | Établissements pilotes : quel lycée privé, quel public, quel lycée technique ou CETIC ? | T979 | Établissements de démonstration créés par `establishment:create` |
| Q11 | RG-FIN-4 : ordre entre enfants d'une même famille quand le paiement ne couvre pas toutes les tranches | T945 | `billing.allocation_sibling_order = enrolled_first` (ordre d'inscription), tranche par tranche ; le test ne vérifie que les totaux du CDC |
| Q12 | Rapport de rentrée et statistiques pour le MINESEC (DOC-32) : modèle officiel ? | T971 | Effectifs par niveau, sexe, âge, redoublants ; personnel par statut ; format libre PDF + XLSX |
| Q13 | Comptes externes R21-R23 : qui les crée, pour combien de temps, sur quel périmètre (portée territoriale) ? | SS13 | Créés par l'administrateur fonctionnel, 30 jours, établissement visité seulement, lecture seule journalisée |
| Q14 | Faut-il « exclu de cours » et « infirmerie » dans l'appel lui-même (SS10) ou dans des écrans séparés ? | SS10, T867 | Écrans séparés (`se_class_exclusions`, `se_infirmary_visits`), sans changer l'appel du socle |
| Q15 | Composition de fin d'année : organisée ? comptée dans la séquence 6 avec quel poids ? | stratégie | Si elle existe : une épreuve de la séquence 6, poids 1 |
| Q16 | Délai de recours des parents contre une décision de fin d'année (EX-PAS-04) | T905 | `secondary.appeal_days = 8` jours calendaires |
| Q17 | Règle d'arrondi exacte : demi vers le haut au centième (11,295 → 11,30) ? Les moyennes de séquence sont-elles arrondies avant la moyenne trimestrielle ? | stratégie | Oui aux deux (« au centième, à chaque étape », CDC) ; `rounding = 2`, `round_each_step = true` |

## Questions du CDC reprises
Les six questions ouvertes du CDC sont Q10 (pilotes), Q2 (textes), Q1 (cartescolaire), Q3 (formats d'examen), Q5 (spécialités et grilles), Q6 (réseaux).

## Prioritaires (à poser en premier)
Q1, Q2, Q3, Q4, Q5 : elles conditionnent l'exactitude des données officielles ; aucune ne bloque le démarrage du code.
