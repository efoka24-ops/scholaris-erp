# Plan d'exécution par vagues d'agents : application Maternelle privée (`nursery`)

Date : 2026-10-20. Modèle : `specs/003-application-primaire/wave-plan.md`. Entrées : [tasks.md](tasks.md), [socle-reuse.md](socle-reuse.md), [plan.md](plan.md), [questions.md](questions.md), `specs/001-socle-plateforme/agent-conventions.md`, protocole `.coordination/README.md` (rapport `.coordination/reports/<ID>.md` puis signal `.coordination/done/<ID>.done`).

## Règles communes
1. **Maximum 3 agents simultanés**, y compris les agents primaire, secondaire, IPES et front déjà actifs : le chef de projet compte les créneaux globalement. Une vague peut avoir plusieurs tours.
2. **Dossiers exclusifs** : un dossier n'a qu'un agent. `database/migrations/**`, `config/**`, `composer.*`, `src/Core/**` : un seul agent par tour (N0-1 en vague 0) ; les autres décrivent leur besoin dans leur rapport. Migrations `Nursery` : `src/Modules/Apps/Nursery/<Module>/Database/Migrations/AAAAMMJJHHMM_nursery_<module>_*.sql`, horodatage ≤ date de fusion, jamais dans le futur.
3. **Un seul `routes.php` et un seul `definitions.php` racine** (N0-3, glob sur `Nursery/*/routes.php` et `Nursery/*/definitions.php`) : aucun autre agent ne les modifie.
4. **Contrats** : `Scholaris\Modules\Socle\<M>\Contracts\*` seulement ; **aucun `use` d'une classe de `PublicNursery`, `Primary`, `Higher`…** ; contrat OpenAPI par sous-module `specs/001-socle-plateforme/contracts/openapi.d/nursery-<module>.yaml`.
5. **Tests** : `tests/Feature/Nursery/<Module>/`, `tests/Unit/Nursery/<Module>/`, `tests/Support/Nursery/` (N0-3), fixtures `tests/Fixtures/Nursery/` (préfixées par module), `tests/E2E/Nursery/`.
6. **Qualité avant rendu** : php-cs-fixer sur ses fichiers seulement (`--path-mode=intersection`), phpstan niveau 8 sans baseline avec `--memory-limit=1G`, phpunit tout vert (état de départ à relever dans le rapport), front `vue-tsc`, `eslint`, `vitest`, `vite build` si le front est touché ; LF et saut de ligne final.
7. **Aucune valeur réglementaire codée** : seuils, délais, barèmes, libellés viennent de `/settings` ou de données ; `NurseryConventionsTest` le vérifie.
8. **Front** : dossier `web/src/apps/nursery/**` exclusif ; éditions ciblées de `router/routes.ts`, `lib/permissions.ts`, `i18n/*` seulement dans la tâche qui les nomme, après relecture juste avant d'éditer ; `web/e2e/fixtures/platform.json` : proposer un diff dans le rapport.
9. **Rapport d'agent** : fichiers créés ou modifiés, routes, permissions `nursery.*` utilisées, résultats réels des commandes, demandes de changement partagé, points ouverts.
10. **Pas de commit ni de push** par les agents ; le chef de projet valide puis commite. Données de test fictives ; aucun secret.

## Ce qui démarre sans attendre / ce qui est bloqué
| Démarre tout de suite | Bloqué (question ou décision) |
|---|---|
| Vague 0 entière sauf T1000 (décision) et T1008 (MQ-11, démarre avec défauts) | Mutualisation T1010 à T1013 : attend T1000 et la stabilisation et le commit de `PublicNursery` |
| Vague 2 : T1020, T1022, T1023, T1029 (autorisés, restrictions, codes) | T1021 et suivants : attendent T1010 ou T1014 |
| Vague 3 : T1046, T1047 | Référentiel, observation, carnet, cahier de vie : T1012 ou T1014, MQ-06, MQ-08, MQ-14 |
| Vague 4 : T1050 à T1053, T1058 à T1060, T1062 | Passage T1054, T1055 (MQ-09, MQ-24), tutelle T1061 (MQ-15), voix T1066 (MQ-18) |
| | Passage à `available` T1096 : accord écrit et HTTPS |

---

# VAGUE 0 : décision, données partagées et squelette
| Agent | Tour | Dossiers exclusifs | Tâches | Critères d'acceptation |
|---|---|---|---|---|
| **N0-1 Données partagées** | a | `database/migrations/*_roles_nursery.sql`, `database/migrations/*_message_templates_nursery.sql`, `tests/Feature/Identity/RolePermissionsTest*`, `tests/Feature/Messaging/*Nursery*`, `web/tests/permissions-nursery.test.ts` | T1001, T1002 | rôles créés dans une **nouvelle** migration ; parité migration et `permissions.ts` ; modèles NOT-MAT rendus avec les seules variables autorisées ; aucune migration existante modifiée |
| **N0-2 Vérification du socle** | a | lecture seule ; écrit seulement son rapport et un éventuel `specs/007-application-maternelle-privee/decisions.md` si demandé | T1003 | pour NS5, NS6, NS7, NS8 : « aucun changement » avec les fichiers lus, ou demande précise |
| **N0-3 Squelette et tests** | b (ne dépend que de T1001) | `src/Modules/Apps/Nursery/` (fichiers racine, `Support/`, `Policies/`), `tests/Support/Nursery/`, `tests/Fixtures/Nursery/`, `tests/Architecture/NurseryConventionsTest.php`, `tests/Isolation/NurseryScopeTest.php`, `tests/Feature/Nursery/Core/`, `openapi.d/nursery-core.yaml` | T1004 à T1008 | manifeste découvert, `GET /public/applications` inchangé (le catalogue fait foi, `coming_soon`) ; `WriteGateTest` 423 et 403 ; `NurseryConventionsTest` **échoue** sur un `use` d'une autre application, une table `nu_*` sans `tenant_id`, une route non gardée (GET compris), un littéral de seuil, une migration datée dans le futur ; `RoleMatrixTest` : un cas par ligne de spec.md §2 ; au moins 40 tests nouveaux |

# VAGUE 1 : mutualisation avec la maternelle publique (prérequis : phase 0 de `phases.md` et T1000 ; propriétaires de modules)
| Agent | Tour | Dossiers exclusifs | Tâches | Critères d'acceptation |
|---|---|---|---|---|
| **N1-1 PickupGate** | a | `src/Modules/Socle/PickupGate/**` (ou `Attendance/**` selon T1000), `src/Modules/Apps/PublicNursery/Pickup/**` (refactorisation en client du contrat), `tests/**/PickupGate/**` | T1010, T1011 | **le même jeu de `tests/Feature/PublicNursery` passe sans retouche** ; migration `pn_*` → `ey_pickup_*` avec comptage ligne à ligne égal ; retour arrière documenté |
| **N1-2 EarlyYears** | b (après N1-1, même méthode) | `src/Modules/Socle/EarlyYears/**`, `src/Modules/Apps/PublicNursery/Development/**` (client du contrat), tests | T1012 | idem ; référentiel `national` intact pour le public ; aucune colonne de note |
| **N1-3 Relecteur et non-régression** | c | lecture seule + son rapport | T1013 | suite complète verte ; phpstan 0 erreur ; tests publics inchangés ; liste des fichiers publics modifiés |
Si T1000 retient le repli B (T1014) : la vague 1 est remplacée par T1014 seul et T1032 est ajouté à la vague 2.

# VAGUE 2 : sortie sécurisée (prérequis : T1004, T1005 ; T1010 ou T1014)
| Agent | Tour | Dossiers exclusifs (sous `src/Modules/Apps/Nursery/`) | Tâches | Critères d'acceptation |
|---|---|---|---|---|
| **N2-1 Pickup serveur** | a | `Pickup/`, `tests/**/Nursery/Pickup/`, `openapi.d/nursery-pickup.yaml` | T1020 à T1026, T1028, T1029, T1032 si repli ; T1027 après T1053 (modèle DOC-MAT-03 de N3-2) | remise sans identifiant d'autorisation valable impossible par toute voie (API, lot, saisie directeur) ; refus → alerte parents et directrice même si le parent a refusé les SMS non essentiels ; restriction invisible du parent (404) ; veille idempotente, jour de fermeture et absents exclus ; lot rejoué → aucun doublon ; paquet périmé → mode « doute » ; code jamais en clair |
| **N2-2 Front sortie** | a | `web/src/apps/nursery/pickup/**` | T1030, T1031 | `vue-tsc`, `eslint`, `vitest`, `build` verts ; écran utilisable à 390 px ; refus testé ; mode hors ligne testé ; aucun `v-html` |
| **N2-3 Recette de la journée** | b (après N2-1) | `tests/E2E/Nursery/DayAtGateTest.php` | T1033 | 10 jours de lots sans doublon ; zéro remise sans autorisation ; chaque enfant présent a une sortie, un maintien tracé ou une alerte |

# VAGUE 3 : suivi du développement (prérequis : T1005 ; T1012 ou T1014)
| Agent | Tour | Dossiers exclusifs | Tâches | Critères d'acceptation |
|---|---|---|---|---|
| **N3-1 Development serveur** | a | `Development/`, tests, `openapi.d/nursery-development.yaml` | T1040 à T1046, T1049 | niveau hors codes paramétrés → 422 ; aucune moyenne, total, rang ni « non acquis » exposé ; carnet non validé invisible du parent ; terme à éviter → validation bloquée ; photo sans consentement jamais servie (tous les chemins de lecture) ; purge à échéance |
| **N3-2 Documents et portail développement** | a | `Documents/` (DOC-MAT-01 et les modèles), `Portals/` (carnet, cahier de vie, allergies), tests | T1043, T1047, T1053, T1058 | DOC-MAT-01 numéroté, vérifiable par QR, léger ; parent limité à ses enfants ; allergies visibles de la section et de la cantine seulement |
| **N3-3 Front développement** | b (après N3-1) | `web/src/apps/nursery/{development,lifebook}/**` | T1048 | front vert ; Vitest du rendu texte et du filtre de consentement ; observation en moins de 3 gestes sur 390 px |

# VAGUE 4 : frais, services, passage, pilotage (prérequis : T1005 ; démarre en parallèle des vagues 2 et 3 si un créneau est libre)
| Agent | Tour | Dossiers exclusifs | Tâches | Critères d'acceptation |
|---|---|---|---|---|
| **N4-1 Billing, services et données personnelles** | a | `Billing/`, `Services/`, `Privacy/`, tests, `openapi.d/nursery-billing.yaml` | T1050, T1051, T1060, T1062 | facture d'inscription et première tranche émise avec remise fratrie au 2e enfant ; abonnement facturé une fois par cycle ; reprise : soldes d'ouverture contrôlés ligne à ligne |
| **N4-2 Transition et clôture** | a | `Transition/`, tests, `openapi.d/nursery-transition.yaml` | T1054 à T1057 | passage en brouillon pour les admis ; maintien selon la règle paramétrée avec motif ; clôture refusée si une décision manque ; un client `public_nursery` n'active pas le planificateur |
| **N4-3 Pilotage et front** | a | `Attendance/` (préréglage), `Dashboards/`, `Tutelage/`, `web/src/apps/nursery/{home,dashboard,transition,services}/**`, éditions ciblées partagées | T1052, T1059, T1061, T1063 à T1066 | indicateurs exacts sur fixtures ; menu filtré par application ouverte ; aucun lien vers un écran `public-nursery` ; front vert |

# VAGUE 5 : recette
| Agent | Tour | Tâches | Critères d'acceptation |
|---|---|---|---|
| **N5-1 Recette API et E2E** | a | T1071, T1072, T1090 | 100 % des routes `/nursery/*` dans `ApiContract` ; 8 parcours E2E verts ; export client couvrant `nu_*` et `ey_*` |
| **N5-2 Recette front, accessibilité et charge** | a | T1070, T1073, T1074, T1091 | Playwright vert ; SC-MAT-6 et SC-MAT-7 mesurés et consignés |
| **N5-3 MySQL, pilote, mise en service** | b | T1092 à T1097 | suite complète verte sur MySQL réel ; rapport de recette ; `nursery` passe à `available` seulement après accord écrit de l'utilisateur et HTTPS actif |

## Ordre récapitulatif
Tour 1 : N0-1, N0-2 (T1000 en parallèle chez l'utilisateur) → Tour 2 : N0-3, N4-1 (T1050, T1051), N4-2 (T1054 à T1057) → Tour 3 : N1-1 (si phase 0 et T1000 faits), N2-1 (T1020, T1022, T1023, T1029), N4-3 → Tour 4 : N1-2, N2-1 (suite), N2-2 → Tour 5 : N3-1, N3-2, N2-3 → Tour 6 : N3-3, N1-3 → Tour 7 : vague 5. Les tâches bloquées s'insèrent dès que la réponse arrive, sans attendre la fin d'une vague. À chaque tour, le chef de projet vérifie que le total des agents actifs (toutes applications) ne dépasse pas 3.

## Risques d'ordonnancement
| Risque | Mitigation |
|---|---|
| L'extraction N1-1 touche `PublicNursery` pendant que M-001 et M-002 sont validées | Vague 1 seulement après commit du public ; non-régression sans retouche des tests publics |
| Deux agents éditent `router/routes.ts` ou `permissions.ts` | Éditions ciblées, relecture juste avant ; une seule tâche par vague nomme chacun de ces fichiers |
| Collision de numéros de tâches avec le secondaire (T800 à T979) | Plage T1000+ |
| Valeurs de sécurité mal posées | Défauts étiquetés « exemple », questions MQ-02 à MQ-05, relecture de l'utilisateur avant le pilote |
