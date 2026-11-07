# Données à fournir par chaque institut

À remettre **pour chacun des deux instituts** (ISSEG et IFPES), séparément. Principe : l'établissement fournit des documents réels ; SCHOLARIS ne devine rien. En particulier, **aucun arrêté d'autorisation n'est jamais inventé** : tant que l'arrêté réel n'est pas saisi, les filières restent non inscriptibles.

Remise des fichiers : par un canal sécurisé convenu avec l'équipe (pas par messagerie ouverte pour les listes d'étudiants), jamais dans le dépôt de code. Les fichiers de reprise sont au format **CSV UTF-8 avec séparateur « ; »** ou **XLSX** (première feuille, première ligne = en-têtes) ; les modèles sont téléchargeables sur `GET /api/v1/higher/migration/templates/{jeu}` (jeux : `students`, `credits`, `balances`, `staff`, `marks`).

## Priorités (à fournir en premier)

1. Les **arrêtés d'autorisation MINESUP** de chaque filière, avec leurs dates de validité (sans eux, aucune inscription).
2. Les **conventions avec l'université de tutelle** des filières à régime universitaire (LMD), avec leur échéance.
3. Les **maquettes** (UE/EC, crédits ou coefficients) de chaque filière et niveau ouvert, validées par l'établissement.
4. Les **grilles de frais** et échéanciers.
5. La **liste des étudiants en cours** (niveau, groupe) puis leurs soldes.

## A. Documents officiels et règles (pas de modèle de fichier : copies lisibles)

| # | Donnée | Détail attendu | Pourquoi |
|---|---|---|---|
| A1 | Arrêtés d'autorisation | pour chaque filière : numéro de l'arrêté, date, autorité (MINESUP), date de début et de fin de validité, niveaux couverts | RG-ACA-1 : inscription refusée sans autorisation valide ; alertes d'expiration |
| A2 | Conventions de tutelle | université de tutelle, référence de la convention, échéance, programme approuvé, contacts | RG-ACA-2/8 (filières LMD) ; alertes |
| A3 | Règlement des études | par école et régime : barème, poids contrôle continu / examen, note éliminatoire, rattrapage, compensation, seuils de passage (crédits ou moyenne), points de jury, mentions | calcul des résultats ; figé à la première inscription |
| A4 | Grille de lettres et points | correspondance note, lettre, points, mention (si utilisée par la tutelle) | relevés et délibérations |
| A5 | Formats MINESUP et tutelle | modèles des listes (examen national, inscrits pour la tutelle, statistiques annuelles d'effectifs) | exports |
| A6 | Calendrier de l'année | dates des inscriptions, semestres, évaluations, délibérations, vacances | calendrier et rappels |
| A7 | Identité de chaque école | nom officiel, code court (≤ 10 caractères), logo, en-tête de document, couleur, langue des documents (fr, en, bilingue) | profil documentaire |

## B. Maquettes par filière (fichier : modèle de maquette ou tableau)

Pour **chaque filière, niveau et semestre ouvert** :

| Colonne | Régime LMD / certificat | Régime BTS / HND |
|---|---|---|
| Code et intitulé de l'UE (ou de la matière) | oui | oui (matière) |
| Type d'UE | fondamentale, transversale, optionnelle, stage, projet | sans objet |
| Éléments constitutifs (EC) : code, intitulé | oui | sans objet |
| Crédits | oui (30 par semestre, RG-MAQ-2) | sans objet |
| Coefficient | oui | oui |
| Volumes horaires | CM, TD, TP, travail personnel (en heures) | CM, TD, TP |
| Épreuve de l'examen national rattachée | si applicable | **obligatoire** (EX-MAQ-03) |
| UE à choix : groupe et nombre à choisir | si applicable | sans objet |
| Référence du programme approuvé | oui | oui |

## C. Grilles de frais et échéanciers (fichier : tableau)

Par école, filière, niveau et mode (jour, soir, alternance) : montant en FCFA de chaque frais — dossier, inscription, pension, examen national, soutenance, stage, carte d'étudiant, assurance, reversement à la tutelle — avec le caractère remboursable ; **échéancier par défaut** (nombre de tranches et dates, ex. 3 tranches : 15 octobre, 15 janvier, 15 avril) ; règles de bourses et réductions (mérite, personnel, fratrie, partenariat) avec plafonds ; règle de remboursement en cas d'abandon (date limite, pourcentages) ; blocages liés aux impayés souhaités (notes, documents, réinscription).

## D. Personnel enseignant (modèle : jeu `staff`)

D'abord les **fiches du personnel** (module Personnel : identité, statut) créées dans l'application ; puis le fichier de reprise.

| Colonne | Contenu |
|---|---|
| `personne_id` | identifiant de la fiche du personnel (exporté de l'application) |
| `departement` | code du département (ou `ECOLE/CODE` si ambigu) |
| `statut` | `permanent`, `vacataire` ou `invite` |
| `grade` | assistant, chargé de cours… |
| `fonctionnaire` | `oui` / `non` ; si oui et vacataire : `autorisation_ref`, `autorisation_document`, `autorisation_jusqua` (autorisation de l'employeur) |
| `filiere`, `niveau`, `groupe`, `ec_code` | affectation à un EC et un groupe (laisser vide pour un simple dossier) |
| `nature`, `heures` | `cm`, `td` ou `tp` ; volume en heures |

Également : **taux de vacation** par grade et niveau (FCFA par heure équivalent TD, coefficients CM 1,5 / TD 1 / TP 0,75 sauf décision contraire), régime de retenues éventuel, liste des salles et capacités.

## E. Étudiants en cours (modèle : jeu `students`)

Uniquement les étudiants **inscrits dans l'année en cours** (pas les anciens).

| Colonne | Contenu | Obligatoire |
|---|---|---|
| `matricule` | matricule d'origine à conserver ; vide = attribué selon le format de l'école (ex. 26EC-CF-0042) | non |
| `nom`, `prenom` | identité | oui |
| `date_naissance` | AAAA-MM-JJ ou JJ/MM/AAAA ; étudiant majeur (18 ans) | oui |
| `lieu_naissance`, `sexe` (M/F) | | non |
| `telephone`, `email` | au moins un recommandé (messages) | non |
| `filiere`, `niveau` | codes du paramétrage (`ECOLE/CODE` si le code se répète) | oui |
| `groupe` | code du groupe (liste d'appel) | non |
| `mode` | jour, soir, alternance | non |
| `redoublant` | oui / non | non |
| `person_id`, `confirmer_different` | fiche existante à rattacher / confirmation qu'un homonyme est une autre personne | non |

## F. UE capitalisées et dettes (modèle : jeu `credits`)

Seules les UE sont reprises des années antérieures, jamais leurs notes. Colonnes : `matricule`, `filiere`, `niveau` (niveau de l'UE), `annee` (année d'acquisition, libellé libre, ex. 2024-2025, différente de l'année en cours), `ue_code`, `ue_intitule`, `credits`, `moyenne` (facultative), `statut` = `capitalisee` ou `dette`.

## G. Soldes d'ouverture (modèle : jeu `balances`)

Le **reste dû** de chaque étudiant à l'ouverture (les paiements antérieurs ne sont pas repris). Colonnes : `matricule`, `filiere`, `niveau`, `mode`, `solde_xaf` (entier), puis de 1 à 3 tranches `tranche1_date`, `tranche1_xaf`, `tranche2_date`, `tranche2_xaf`, `tranche3_date`, `tranche3_xaf` ; la somme des tranches égale le solde (une seule tranche : date seule). Un solde nul n'ouvre aucun compte. **Un solde repris ne s'annule pas** : le fichier doit être vérifié par le comptable de l'institut avant application.

## H. Notes de l'année en cours (modèle : jeu `marks`)

Colonnes : `matricule`, `filiere`, `niveau`, `groupe`, `ec_code`, `evaluation` (`cc`, `tp` ou `exam`), `note` (0 à 20 au quart de point, virgule ou point) ou code d'absence (ABJ, ABI, NA, FR). Les évaluations reprises sont créées **verrouillées**, jamais publiées ; une correction passe ensuite par la procédure normale. Les maquettes de l'année doivent être publiées avant.

## I. Organisation et comptes

Liste des personnes qui auront un compte, avec leur **fonction** et leur **périmètre** (école, département, filière) ; adresse e-mail professionnelle de chacune ; responsable de l'administration fonctionnelle de l'institut ; interlocuteur de la direction pour la décision de bascule. Les noms réels sont saisis directement dans l'application, jamais dans le dépôt.

## Exemples fictifs (modèles)

Les modèles téléchargeables contiennent des lignes **fictives** (noms « Exemple », « Modele », téléphones `+23760000000x`). Ne conserver aucune ligne d'exemple dans un fichier réel.
