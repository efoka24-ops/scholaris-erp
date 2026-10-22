# Recherche : cartescolaire.cm, séries et spécialités, prix, paramétrage par l'établissement

Date : 22 octobre 2026. Réponses du propriétaire aux questions Q1, Q2, Q22 et Q25 de [questions.md](questions.md), et recherche sur sources publiques. **Rien ici n'est une règle officielle** : tout ce qui vient du web est à confirmer auprès du MINESEC avant d'être utilisé comme valeur par défaut.

## 1. Coefficients, volumes horaires, règles de passage (Q2) : décision
**Décision du propriétaire** : ces valeurs sont **paramétrées par les responsables de chaque établissement**, car elles peuvent varier d'un établissement à l'autre. Conséquences pour la spécification :
- Aucune valeur n'est écrite dans le code ni imposée par type d'établissement. Chaque type de profil (LEG, lycée technique, CES, CETIC, collège privé, lycée privé, autre) fournit seulement des **valeurs de départ éditables**, marquées « à confirmer ».
- L'administrateur de l'établissement (ou le directeur, ou l'adjoint pédagogique, selon les droits) modifie les coefficients par matière et par série, les volumes horaires, les seuils de passage et les règles de décision du conseil, depuis son back office. Chaque modification est versionnée, avec auteur, date et motif, comme les circuits de validation (voir le module `Workflows`).
- Une année scolaire figée garde les valeurs qui étaient en vigueur (pas de recalcul rétroactif).
- La plateforme peut poser un **plancher** (par exemple une note de passage minimale réglementaire) qu'aucun établissement ne peut lever, si le ministère l'impose. À confirmer.

## 2. cartescolaire.cm (Q1) : ce que la recherche établit
- **Exploitant :** le MINESEC (ministère des Enseignements secondaires). Le pied de page du site le dit.
- **À quoi il sert :** ce n'est pas une carte des établissements. C'est la plateforme du **matricule unique de l'élève** du secondaire et du **paiement électronique** des frais de scolarité, d'inscription et d'examen. On y trouve : payer, retrouver un matricule (recherche par nom et établissement), télécharger un reçu, FAQ.
- **Paiement :** par opérateurs agréés (Campost, Express Union, MTN, Orange, Afriland First Bank). Les reçus se confirment sur la plateforme et les parents peuvent vérifier un paiement. Les économes peuvent extraire l'historique des paiements.
- **Évolutions récentes (presse) :** pour 2025/2026, les conseils de classe de fin d'année doivent être tenus sur la plateforme ; les matricules des élèves de première année sont générés par les établissements ; les frais d'examen d'entrée se paient avec le code de l'établissement et non le matricule.
- **Interface technique :** **aucune API publique, aucun import ni export de fichiers n'est visible** sur le site. Le seul export visible est le reçu à télécharger, au format non précisé.

**Conséquence pour SCHOLARIS (à valider)** : ne pas promettre une intégration automatique. Prévoir, par ordre de priorité : (1) champ **matricule unique** sur chaque élève, avec contrôle de format paramétrable et saisie manuelle ; (2) **import d'un fichier** de matricules (CSV) fourni par l'établissement après extraction sur la plateforme, avec rapprochement par nom et date de naissance et rapport d'écarts ; (3) **rapprochement des paiements** par import du relevé de l'économe ; (4) un connecteur direct seulement si le MINESEC fournit une API et une convention d'accès : question à poser formellement au ministère. Voir aussi les tâches T828, T829 et T971 de [tasks.md](tasks.md).

## 3. Séries et spécialités (Q22) : ce que la recherche établit
**Enseignement secondaire général (second cycle, sous-système francophone)**, d'après la presse camerounaise (arrêté du 17 mars 2022) :
- Séries scientifiques et technologiques : **C, D, E (mathématiques et techniques, créée en 2022), TI**.
- Séries littéraires : **A1** (lettres, latin et grec), **A2** (lettres, latin, langue vivante 2), **A3** (lettres et latin), **A4** (lettres et langue vivante 2, avec philosophie), **A5** (lettres, langue vivante 2 et 3), **ABI** (A4 bilingue).
- Séries apparues dans certains établissements depuis 2019 : **AC** (arts cinématographiques) et **SH** (sciences humaines).
- Examens du baccalauréat général : séries A, ABI, C, D, E, TI.

**Enseignement secondaire technique** : les CETIC (collèges d'enseignement technique industriel et commercial) et les lycées techniques existent, et le second cycle technique dure trois ans, avec des certificats intermédiaires après la deuxième année puis baccalauréat technique, brevet de technicien ou brevet professionnel. **Je n'ai trouvé aucune source fiable listant les séries et spécialités techniques** (sections industrielle et commerciale, codes F et G) ni les spécialités des CETIC.

**Conséquence pour SCHOLARIS (décision de conception)** : les séries et les spécialités ne sont **pas codées en dur** : ce sont des **listes paramétrables** (catalogue plateforme, que l'établissement peut compléter), amorcées avec les séries générales ci-dessus marquées « à confirmer », et avec un catalogue technique **vide** tant que le MINESEC ou un établissement pilote n'a pas fourni la liste. À demander au MINESEC (ou à un lycée technique pilote) : liste officielle des séries, spécialités, sections et codes, par cycle, avec la source juridique.

## 4. Prix et modèle commercial du public (Q25)
Réponse du propriétaire : **90 000 FCFA**. L'unité n'est pas précisée (par mois ? par an ? par établissement ?). **À confirmer avant de modifier le catalogue.** Aujourd'hui le catalogue affiche 60 000 FCFA par mois pour l'enseignement secondaire (un seul produit pour les 7 types). Tant que la réponse n'est pas confirmée, le catalogue n'est pas modifié.

## 5. Sources consultées
- Site cartescolaire.cm (consulté le 22 octobre 2026).
- [Le MINESEC ouvre le paiement électronique des inscriptions, frais d'examens et concours (Journal du Cameroun)](https://fr.journalducameroun.com/cameroun-le-minesec-ouvre-le-paiement-electronique-des-inscriptions-frais-dexamens-et-concours/)
- [Cameroun : le matricule unique imposé aux élèves du secondaire (Digital Business Africa)](https://www.digitalbusiness.africa/cameroun-le-matricule-unique-impose-aux-eleves-du-secondaire-visitez-le-site-cartescolaire-cm/)
- [Le Cameroun digitalise les inscriptions scolaires pour la rentrée 2024 (Digital Business Africa)](https://www.digitalbusiness.africa/le-cameroun-digitalise-les-inscriptions-scolaires-pour-la-rentree-2024-via-la-plateforme-web-cartescolaire-cm/)
- [Cameroun : le matricule unique (osiris.sn)](https://www.osiris.sn/Cameroun-Le-matricule-unique.html)
- [La série E (mathématiques et techniques) créée au secondaire (Journal du Cameroun)](https://fr.journalducameroun.com/cameroun-la-serie-e-mathematiques-et-techniques-creee-au-secondaire/)
- [Enseignements secondaires : le nouveau visage des séries (Cameroon Tribune)](https://www.cameroon-tribune.cm/article.html/20201/fr.html/details_2)
- [Guide sur l'éducation internationale, Cameroun (Alberta)](https://www.alberta.ca/fr/iqas-education-guide-cameroon)
