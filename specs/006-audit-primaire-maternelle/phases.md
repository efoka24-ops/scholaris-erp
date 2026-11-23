# Phases de lancement : application des écoles primaires (privé et public)

Date : 2026-10-19. Tâches réutilisées : T5xx (`specs/003-application-primaire/tasks.md`), T6xx et T7xx (`specs/004-.../primaire|maternelle/tasks.md`). Au plus 3 agents par phase, dans des dossiers exclusifs. Avant chaque rendu : php-cs-fixer sur les fichiers modifiés, phpstan 0 erreur, phpunit complet vert, `vue-tsc`, `eslint`, `vitest`, `npm run build`.

## Ordre recommandé : le privé d'abord, le public ensuite
**Justification.**
- Le privé (`primary`) n'attend aucun commanditaire et aucune chaîne territoriale (T606). Il réutilise tels quels le catalogue, l'essai, la facturation et le paiement mobile, déjà éprouvés par l'IPES.
- 21 de ses 46 exigences sont déjà couvertes par le socle. Les règles d'évaluation sont paramétrables et ne bloquent que le bulletin officiel.
- Le public exige T606, T607 et T613 au socle, la création de `PublicSchools` et des réponses du MINEDUB (Q3, Q8, Q9, Q10), et vise une échelle hors de portée du mutualisé.
- Le public tire parti des briques du privé : squelette, référentiel, documents, portail. Seul le socle est mutualisé, il n'y a pas de dépendance d'application à application.

## Phase 0 · Stabiliser l'existant (préalable, 1 à 2 jours)
- **Objectif** : retrouver une base verte et commitée avant d'ouvrir un nouveau chantier.
- **Contenu** :
  - la session propriétaire commite `PublicNursery`, `PublicPrimary`, les extensions du socle et les migrations ;
  - corriger les 51 erreurs phpstan, les 10 erreurs `vue-tsc` et le `v-html` ;
  - **brancher `NurseryWriteGate::assertWritable`** sur toutes les routes non GET, avec un test d'architecture ;
  - appeler `assertSection` dans les contrôleurs, et `EstablishmentGuard` dans le module Développement ;
  - corriger la 500 de `POST /public-nursery/development/frames` et paginer les 3 listes ;
  - régler la présence de `public_nursery` au catalogue public (Q-A1 de [questions.md](questions.md)) et adapter `AccessRequestTest` ;
  - renommer les migrations datées dans le futur.
- **Dépendances** : accord de l'autre session (ne pas éditer en parallèle).
- **Sortie** : `phpunit` complet vert (≥ 5 130 tests de référence + nouveaux, 0 échec) ; phpstan 0 erreur ; `vue-tsc` 0 ; build front OK ; `git status` sans fichier `??` dans `src/`.
- **Agents** : 1 (la session propriétaire) + 1 relecteur.
- **Attend l'utilisateur** : Q-A1 (catalogue public).

## Phase 1 · Primaire privé : socle et squelette (vague 0 de 003)
- **Objectif** : une application `primary` ouvrable par un client, vide mais gardée.
- **Contenu** : T500 (`periods.parent_id`), T502, T503, T504, T505 (extensions Assessment), T506 à T509 (squelette `Primary`, `WriteGate`, `RoleMatrix`, rôles `primary_*`, modèles de messages) ; manifeste `platform.applications.primary` resté `coming_soon`.
- **Dépendances** : phase 0.
- **Sortie** : tests d'architecture `PrimaryConventionsTest` (dont « toute route non GET gardée » et « aucun seuil codé en dur », SC-PRI-4) ; `WriteGateTest` (423 et 403) ; `PrimaryScopeTest` (autre client → 404, autre classe → 404) ; au moins 40 tests nouveaux ; tests du socle Assessment inchangés et verts.
- **Agents (3)** : A Organisation et Assessment (T500, T502 à T505) · B squelette et tests (T506, T507) · C rôles et messages (T508, T509).
- **Attend l'utilisateur** : Q18 (rôles), Q22 (prix et essai). Les défauts suffisent pour démarrer.

## Phase 2 · Primaire privé : année, inscriptions, frais, documents, appel
- **Objectif** : une école paramétrée et ses élèves inscrits et facturés en moins d'une demi-journée (SC-PRI-1).
- **Contenu** : T520 à T526 (référentiel, profils de niveau, frais `billing.fee_kinds.primary`, documents administratifs, front) ; T535 (appel `day_part`, déjà au socle) ; T536 (appel seulement) ; reprise de données : adaptation de `Higher/Migration` pour les élèves, les parents et les soldes d'ouverture.
- **Dépendances** : phase 1.
- **Sortie** : parcours E2E 1 à 4 de 003 §3 verts ; facture inscription + 1re tranche avec remise fratrie testée ; certificat de scolarité vérifiable par QR ; appel hors ligne rejoué sans doublon ; Playwright `web/e2e/primary/setup.spec.ts`.
- **Agents (3)** : A Academic et Enrolment · B Billing, Documents et reprise · C front.
- **Attend l'utilisateur** : Q1 (sous-systèmes), Q5 (niveaux), Q17 et DOC-9 (grille de frais).

## Phase 3 · Primaire privé : évaluation, conseil, bulletins, portail
- **Objectif** : un trimestre complet, de la saisie au bulletin publié aux parents.
- **Contenu** : T530 à T534 (règles `example_*` tant que DOC-2 manque), T536 (feuille de compétences), T540 à T545 (bulletin, conseil, cahier de liaison, portail `portals.sections.primary`).
- **Dépendances** : phase 2 ; **réponses Q2, Q3, Q4, Q6, Q7 et DOC-1 à DOC-3** pour le bulletin officiel.
- **Sortie** : bulletins de 60 élèves en moins de 2 minutes (SC-PRI-2) ; test chiffré de la stratégie sur les règles fournies ; bulletin émis figé, rectificatif par `DocumentReissuer` ; SMS « bulletin disponible » par la passerelle HTTP (TeeeOTP) au simulateur ; au moins 80 tests nouveaux.
- **Agents (3)** : A Grades et stratégie · B bulletins et conseil · C portail et front.
- **Attend l'utilisateur** : un bulletin réel, le texte d'évaluation MINEDUB et le curriculum.

## Phase 4 · Primaire privé : pilotage, recette et pilote
- **Contenu** : T551, T552, T555, T556 (tableau de bord, exports tutelle, clôture d'année) ; T550 (examen, si Q11) ; T560 à T566 et T570 à T572 (contrats, E2E 1 à 8, Playwright, export client, MySQL réel, charge, passage `available`, passation).
- **Sortie** : 0 échec `tests/ApiContract` sur `/primary/*` ; 8 parcours E2E verts ; `phpunit.mysql.xml` vert ; rapport `specs/003-application-primaire/test-report.md` ; **HTTPS actif** ; une école pilote avec données réelles.
- **Agents (3)** : A tableaux de bord et exports · B recette backend · C Playwright et guide.
- **Attend l'utilisateur** : Q16 (exports), choix de l'école pilote, date HTTPS.

## Phase 5 · Public : socle territorial et commun `PublicSchools` (lot P1)
- **Contenu** : T606, T607, T608, T610 (clés de catalogue), T619 ; T620 à T624 (squelettes, garde commune remplaçant `NurseryWriteGate`) ; T625 à T646 (ECO avec reprise du registre, ELV, CLA, PRE, PER, COM, Territory, PWA) ; maternelle T724 à T730, T735 à T737, T763 (sécurité de la sortie, P1 du CDC).
- **Dépendances** : phase 0 ; peut démarrer en parallèle de la phase 3 si 3 agents restent disponibles.
- **Sortie** : TST-01, 02, 04, 05 et 06 verts (`tests/E2E/PublicSchools/P1Test.php`, `tests/E2E/PublicNursery/DayAtGateTest.php`) ; `PublicTerritoryTest` vert ; 10 jours de lots hors ligne sans doublon ; contrôle quotidien O7 = 0 remise non autorisée.
- **Agents (3)** : A socle Identity et Reporting (T606, T607, T613) · B `PublicSchools` · C maternelle SOR.
- **Attend l'utilisateur** : Q1 (commanditaire, arrondissement), Q6, Q7, Q-02, Q-05, Q-09.

## Phase 6 · Public : évaluation, passage, APEE, statistiques (lot P2), puis P3 et recette
- **Contenu** : T650 à T668 ; puis T680 à T690 ; puis T691 à T699 et T790 à T793.
- **Sortie** : TST-03 (cas « Paul E. ») et TST-07 à TST-10 verts ; SC-07 (< 3 s pour 500 écoles simulées) ; revue de sécurité des données de mineurs.
- **Attend l'utilisateur** : **Q8 et Q9 (référentiels et règles validés par l'Inspection générale), Q3 (questionnaire), Q10 (formats CEP/FSLC)**, Q19 (hébergement au-delà du pilote).
