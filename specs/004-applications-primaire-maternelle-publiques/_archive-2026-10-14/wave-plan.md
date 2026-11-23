# Plan d'exécution par vagues — Primaire/Maternelle publiques

**Statut** : plan documentaire actualisé après les vagues locales observées ; les tâches non terminées restent au backlog. Aucun commit, push ou déploiement n'a été effectué ou autorisé. Se référer au backlog [tasks.md](tasks.md), à l'architecture proposée [plan.md](plan.md), aux décisions ouvertes [research.md](research.md) et à [socle-reuse.md](socle-reuse.md).

**Cadre de l'exécution courante** : les agents 1–2 ont livré les fondations documentées T008–T016 (T013 sous `Identity/Application`), puis l'agent 3 a livré les artefacts US1 T019–T026 et une vue partielle T027 ; l'agent 4 a aligné le contrat et les documents sur les routeurs effectivement présents. T010 a ensuite été complétée : affectations territoriales validées dans le tenant et scopes émis avec bornes tenant/établissement ; les tests ciblés Identity/isolation, PHPStan et style des fichiers modifiés sont verts. T018 reste ouvert. T001 reste non résolue et interdit tout commit/push ; T004 bloque les règles officielles US4/US7, T005/T006 bloquent les données réelles et l'exploitation. Ces changements ne valent pas validation institutionnelle.

## Règles d'exécution

1. **Maximum 3 agents simultanés.** Un agent est responsable par dossier exclusif, pas seulement par fichier. Les tâches d'une vague peuvent être distribuées en plusieurs tours ; ne jamais démarrer une tâche `[B]` tant que sa porte amont n'est pas approuvée.
2. **Autorisation courante limitée.** L'implémentation locale est autorisée uniquement dans le périmètre provisoire R8, avec fixtures synthétiques ; cette autorisation n'inclut ni données réelles, ni accès à la production, ni commit/push, ni déploiement. Les secrets des captures ne sont jamais copiés, utilisés ou versionnés. GitHub et FTP restent des opérations ultérieures distinctes ; le remote PHP reste à confirmer.
3. **Portes du produit** : T001 remote/branche GitHub PHP bloque commit/push ; T002 séparation public/privé et T003 tenant/pilote sont des décisions provisoires R8 pour le code synthétique, sans validation institutionnelle ; T004 bloque les règles MINEDUB ; T005/T006 bloquent données réelles et exploitation. PHP 8.1/HTTP documentés impliquent synthétique/anonymisé seulement.
4. **Dossiers sensibles** : `src/Core/**`, `config/**`, `composer.*`, routeurs/catalogues partagés, `database/migrations/**`, fichiers OpenAPI communs et chaque `routes.php`/`definitions.php` racine ont un seul propriétaire désigné par vague. Les autres agents remettent uniquement une demande d'interface.
5. **Migrations** : SQL portables MySQL 8/SQLite dans le module propriétaire sous `Database/Migrations/AAAAMMJJHHMM_<module>_*.sql`, conformément au code local. Aucun schéma ne va dans le registre global par défaut.
6. **Dépendances** : apps dépendent uniquement des `Contracts/` du socle ; pas de lecture de tables d'un autre module. Chaque nouvelle table porte `tenant_id` et, si applicable, `establishment_id`; tenant/établissement restent obligatoires même si un scope territorial a été ajouté.
7. **Validation** : écrire tests d'autorisation, d'isolation, règles métier, rejeu offline et contrat API avant l'implémentation correspondante (constitution). Qualité PHP, front et recette uniquement sur environnement local/CI/test dédié. Tests MySQL ne pointent jamais vers Camoo production.
8. **Routes et OpenAPI** : un propriétaire unique par fichier de routes du module ; l'agent 3 a libéré les routes US1, puis l'agent 4 a vérifié leur surface et actualisé `contracts/openapi-public-schools.yaml`. Le fichier reste explicitement draft ; aucune route US2–US8 n'y est anticipée. Vérifier l'existence des endpoints du socle avant de prétendre les réutiliser.
9. **Définition d'achevé** : test ciblé vert, contrôle tenant/établissement/territoire, PHPStan/style applicables, aucun secret, aucune migration de module tiers, notes de décisions et limites. Chaque agent rapporte chemins modifiés, tests, permissions et blocages.

## Vague 0 — arbitrages, modèle et qualité de la conception

**Résultat** : décisions formelles et chemins de code approuvés ; pas de migration ni de code applicatif avant levée des blocages.

| Rôle | Dossiers exclusifs | Tâches | Sortie / gate |
|---|---|---|---|
| D0-1 Produit / Git | `specs/004-applications-primaire-maternelle-publiques/research.md`, décisions GitHub | T001–T002 | Remote PHP et branche restent à confirmer avant commit/push ; la frontière public/privé T002 est provisoirement documentée sous R8 pour le code synthétique, sans valoir arbitrage institutionnel. |
| D0-2 Autorités / conformité | `specs/004-applications-primaire-maternelle-publiques/plan.md`, `data-model.md`, interlocuteurs MINEDUB | T003–T006, T032, T041, T046, T056, T063, T068 | Tenant pilote, règles, périmètre juridique, APEE, appareil offline et valeurs officielles recensés ; données réelles toujours bloquées si une porte manque. |
| D0-3 Architecture / inventaire | `specs/004-applications-primaire-maternelle-publiques/socle-reuse.md`, `contracts/openapi-public-schools.yaml`, tests d'architecture | T007, T017–T018 | T017 est révisée sur les routes observées ; T018 reste ouverte (test d'architecture dédié absent) et T007 n'est pas déclaré terminé. Le contrat reste draft, sans routes futures. |

Tous les rôles peuvent travailler en parallèle si les personnes consultées et les documents n'entrent pas en conflit. Quand un fichier commun doit être révisé, D0-2 en est l'unique propriétaire et collecte les propositions.

## Vague 1 — fondations socle et tests de sécurité

**Prérequis** : choix technique provisoire R8 consigné. Le tenant et territoires utilisés sont synthétiques ; aucune donnée de production n'est requise. État observé : T008–T016 sont terminées selon les artefacts et tests ciblés ; T018 reste un contrôle architectural spécifique manquant. Ce manque n'est pas présenté comme terminé.

| Tour | Agent | Dossiers exclusifs | Tâches | Dépendances / critères |
|---|---|---|---|---|
| 1 | F1 Organisation / scopes | `src/Modules/Socle/Organisation/**`, `src/Modules/Socle/Identity/**`, `tests/Isolation/**`, `tests/Feature/Socle/Organisation/**` | T008–T011 | Livrés localement ; tests ciblés tenant/scope et historique exécutés avec succès. T018 reste hors de ce lot. |
| 1 | F2 Reporting / confidentialité | `src/Modules/Socle/Reporting/Contracts/**`, `tests/Feature/Socle/Reporting/**` | T012, T014 | Livrés en contrats génériques ; aucun seuil officiel n'est fixé. |
| 1 | F3 Attendance / offline | `src/Modules/Socle/Attendance/Contracts/**`, `tests/Feature/Socle/Attendance/**`, `quickstart.md` | T015–T016 | Rejeu idempotent/conflit testé ; limites observées consignées, 30 jours explicitement non validés. Sécurité/capacité offline restent bloquantes avant données réelles. |
| 2 | F4 intégration des interfaces | routeurs/manifest communs seulement si décision requise, `tests/Architecture/PublicSchoolsConventionsTest.php` | intégration T009–T018 | Un propriétaire unique des fichiers communs ; vérification Apps → Contracts et conventions migrations/SQL portable. |

**Gate de sortie partielle** : les tests de frontière et d'idempotence ciblés sont verts ; T010 est maintenant achevée : une affectation territoriale doit référencer un territoire actif du même tenant et chaque scope retourné conserve son établissement de contexte. Les applications doivent appliquer conjointement tenant, établissement et développement territorial du scope. T018 reste ouvert avant de déclarer les conventions architecturales transversales validées. Le remote T001 reste bloquant pour commits/push et le tenant officiel T003 pour le pilote réel.

## Vague 2 — MVP synthétique : US1 registre et US2 élèves/présence

**Prérequis réellement suivis** : choix provisoire des modules publics/snapshots consigné sous R8 ; l'implémentation US1 a avancé localement sur fixtures même si le test d'architecture T018 reste ouvert. **T010 est achevée** : le modèle accepte les affectations territoriales, leur création valide un territoire actif dans le tenant et leur résolution émet un scope tenant/établissement-borné ; les consommateurs doivent filtrer via les contrats territoriaux et maintenir les trois bornes. T004/T005/T006 continuent de bloquer règles officielles et données réelles.

| Tour | Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|---|---|---|---|---|
| 1 | Agent 3 — Territoires + registre | `src/Modules/Socle/Organisation/**`, `src/Modules/Apps/PublicPrimary/Registry/**`, `tests/Feature/PublicSchools/Registry/**` | T019–T026 | Livrés localement : hiérarchie/versionnement, profils, snapshots bilingues, corrections/clôture et tests. Tests T019/T020 exécutés avec succès ; les routes exposées sont celles relevées dans le contrat draft. |
| 1 | M2 People / Staff / inscriptions | `src/Modules/Socle/People/**`, `src/Modules/Socle/Staff/**`, `web/src/apps/public-primary/learners/**`, tests correspondants | T029–T030, T032–T034, T036 | **Non démarré.** Inscription inclusive, transferts traçables, classe/affectation historisées ; tout contrôle territorial doit utiliser tenant + établissement + développement via contrat. Ne pas réutiliser la feature privée sans validation. |
| 1 | M3 Attendance / PWA appel | `src/Modules/Socle/Attendance/**`, `web/src/offline/public-primary/attendance/**`, tests et charge Attendance | T031, T035, T037–T038 | **Non démarré.** Présence/idempotence et limites de lot à éprouver en environnement de test ; tout scope territorial reste borné au tenant et à l'établissement, sans accès direct aux tables Organisation. |
| 2 | Agent 3 — Front registre | `web/src/apps/public-primary/registry/**` | T027 | Partiel : profil, lignée textuelle et snapshot visibles ; pas de carte géographique/contours. T027 reste ouvert jusqu'à réalisation ou retrait explicite de cette exigence. |
| 2 | Agent 4 — Contrat / docs | `contracts/openapi-public-schools.yaml`, `tasks.md`, `wave-plan.md` | T017, T028 et état des tâches | Après libération des routes par Agent 3 : contrat draft limité aux méthodes US1 observées, erreurs RFC 9457, permissions/scope explicités ; aucune pagination ni Idempotency-Key inventée. |

**Dossiers exclusifs** : dans le lot observé, Agent 3 a possédé les routeurs et modules US1 ; Agent 4 n'a touché qu'aux artefacts contractuels/docs après inspection. Pour la suite, un seul propriétaire conserve chaque `routes.php`, `definitions.php` et migration. Aucun routeur ni définition applicative n'a été modifié par l'agent documentaire. Ne pas créer un module `PublicSchools` partagé non documenté.

## Vague 3 — US3 protection maternelle et familles

**Prérequis** : décision distincte Maternelle, contrats People/Attendance livrés, T041 et sécurité offline approuvés.

| Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|---|---|---|---|
| N1 People / autorisations | `src/Modules/Socle/People/Contracts/**`, tests `AuthorizedPickup/**` | T039, T042 | autorisation datée, révocation et absence d'accès cross-tenant. |
| N2 Attendance / remise | `src/Modules/Socle/Attendance/Application/**`, tests `PickupHandover/**` | T040, T043 | remise/refus append-only, alerte et rejeu sans effet double ; procédure d'urgence prévue hors décision automatique. |
| N3 Interface Nursery/famille | `web/src/apps/public-nursery/{pickup,family}/**` | T044–T045 | cache local strictement minimisé, contrôles utilisables hors réseau, comptes parent seulement selon identité/consentement approuvés. |

N1 et N2 ne peuvent partager le même module People/Attendance dans un même tour sans propriétaire unique ; si les chemins se recouvrent, exécuter N1 puis N2. Le front ne peut pas valider seul une remise physique.

## Vague 4 — US4 évaluations, compétences et bulletins

**Prérequis** : T046 levée. Aucun calcul, échelle ou bulletin « officiel » avant approbation source/version.

| Agent | Dossiers exclusifs | Tâches | Dépendances / critères |
|---|---|---|---|
| E1 Socle Assessment | `src/Modules/Socle/Assessment/**`, tests `tests/Feature/Socle/Assessment/**` | T047, T049, T053 | stratégies versionnées, source officielle enregistrée, test-first des règles nationales. |
| E2 Application Primaire | `src/Modules/Apps/PublicPrimary/Assessment/**`, `web/src/apps/public-primary/assessment/**`, tests Primary | partie Primaire de T048, T050–T052 | observation/évaluation et snapshot bulletin qui conserve l'original ; publication uniquement après règle approuvée. |
| E3 Application Maternelle | `src/Modules/Apps/PublicNursery/Development/**`, `web/src/apps/public-nursery/development/**`, tests Nursery | partie Maternelle de T048, T050–T052 | carnet positif, sans note ni rang, rapport/correction historisés. |

Le fichier OpenAPI partagé est modifié après les implémentations par un unique intégrateur, jamais en parallèle avec les agents ci-dessus.

## Vague 5 — US5 APEE et dons

**Prérequis** : arbitrage APEE/tenants/roles T056, séparation produit confirmée, T054–T055 en rouge avant service.

| Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|---|---|---|---|
| A1 APEE domaine | `src/Modules/Apps/PublicPrimary/Apee/**`, `tests/Feature/PublicSchools/Apee/**` | T054–T059 | ledger isolé des frais, reçu/justificatif/approbation idempotents ; refus explicite de toute condition d'accès scolaire. |
| A2 Front APEE | `web/src/apps/public-primary/apee/**` | T060 | seules vues rôles validés ; enseignants ne voient pas liste nominative de contributeurs/non-contributeurs. |

A1 et A2 ne se chevauchent pas. Aucun contrat générique Billing n'est modifié pour simuler une caisse associative.

## Vague 6 — US6 supervision et US8 inspection/pilotage

**Prérequis** : questionnaires/roles/seuils officiels T063 ; socle scopes territoriaux et Reporting en place. Un seul owner Reporting par tour.

| Tour | Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|---|---|---|---|---|
| 1 | S1 Reporting census | `src/Modules/Socle/Reporting/Application/**`, tests `CensusWorkflow/**` | T061, T064–T065 | workflow ordonné, renvoi motivé, gel et version de correction ; formule / dictionnaire versionnés. |
| 1 | S2 Confidentialité des statistiques | `src/Modules/Socle/Reporting/Contracts/**`, tests `SmallCellDisclosure/**` | T062, intégration T067 | pas de cellules sous seuil ni de divulgation par différence ; scopes imposés côté serveur. |
| 1 | S3 Inspection PublicPrimary | `src/Modules/Apps/PublicPrimary/Inspection/**`, UI inspection et tests correspondants | T075, T077–T078 | visite/checklist versionnée, saisie offline, recommandations suivies. |
| 2 | S4 dashboards et contrat API | `web/src/apps/public-primary/{supervision,dashboards}/**`, `contracts/openapi-public-schools.yaml` | T066, T067, T080 | formulaire et indicateurs limités au scope ; contrat mis à jour une fois les vrais routeurs confirmés. |

S1 et S2 partagent Reporting : les tâches du contrat, du service et de leurs migrations sont attribuées à un seul agent dans le tour (S1), S2 fournit tests/revue ou prend le relais au tour 2. T079 (Documents) est traité en vague distincte si les templates publics sont prêts.

## Vague 7 — US7 examens et décisions de passage

**Prérequis** : T068 levée ; source d'éligibilité et modèle officiel reçus. Dépend des mécanismes d'évaluation versionnée et de dossiers d'élèves.

| Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|---|---|---|---|
| X1 Assessment / passage | `src/Modules/Apps/PublicPrimary/Assessment/Application/**`, tests `Promotion/**` | T070–T071 | règles versionnées uniquement ; aucune valeur/seuil inventé ; approbations auditées. |
| X2 Examens | `src/Modules/Apps/PublicPrimary/Exams/**`, tests `Exams/**` | T069, T072, T074 | liste soumise/gelée, ajout tardif justifié, import tracé, aucun endpoint d'émission diplôme. |
| X3 Interface primaire | `web/src/apps/public-primary/exams/**` | T073 | écran de préparation/contrôle, aucune délivrance officielle. |

T071 et les autres tâches sous le répertoire Assessment ne peuvent pas être confiés à deux agents au même tour ; séquencer X1 jusqu'à libération du dossier avant toute autre modification Assessment.

## Vague 8 — US8 documents publics et pilote qualité

**Prérequis** : gabarits/document type signés, permissions établies. Recette synthétique uniquement.

| Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|---|---|---|---|
| Q1 Documents | `src/Modules/Apps/PublicPrimary/Documents/**`, tests documents et `web/src/apps/public-primary/documents/**` | T076, T079 | émission minimale, audit, révocation et vérification publique sans données interdites. |
| Q2 Qualité / conformité | `tests/ApiContract/**`, `tests/Isolation/**`, `tests/Feature/PublicSchools/Privacy/**`, quickstart | T081–T084 | contrat, isolation, cycle de vie mineurs et tests SQLite/MySQL sur bases dédiées verts. |
| Q3 Performance / autorisation pilote | `tests/Load/PublicSchools/**`, `plan.md`, gates `quickstart.md` | T085–T087 | charge mesurée sur cible approuvée ; pilote réel bloqué tant que HTTPS/runtime/conformité/renouvellement ne sont pas démontrés ; remote confirmé. |

T085–T087 sont des décisions/gates, pas des ordres de se connecter à Camoo, FTP ou GitHub. Toute livraison demande un mandat distinct.

## Ordre global, chemins exclusifs et dépendances

```text
Vague 0 (arbitrages) → Vague 1 (contrats scopes/audit/sync)
  → Vague 2 (US1 + US2, MVP synthétique)
  → Vague 3 (US3 Maternelle)
  → Vague 4 (US4, règles officielles)
  → Vague 5 (US5 APEE) ─┐
  → Vague 6 (US6 + US8 inspection) ├→ Vague 8 qualité/portes pilote
  → Vague 7 (US7 examen/passage) ─┘
```

En cas de retard sur validation officielle, avancer uniquement les contrats génériques, tests négatifs, ergonomie de données synthétiques et documentation ; ne pas coder de règle temporaire « plausible ». Toute collision `routes.php`, `definitions.php`, manifest/catalo­gue, config, migration partagée ou fichier OpenAPI suspend le parallélisme : propriétaire unique, revue ensuite.

## Recommandation MVP

**MVP logiciel de démonstration** : US1 + US2 sur données synthétiques, sans documents officiels, évaluations officielles, frais, production, connexion FTP, accès à la base Camoo ou push vers le dépôt historique. **MVP réel** : non autorisé avant T001–T006 et T086–T087 explicitement levées par écrit.
