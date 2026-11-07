# Plan d'exécution par vagues d'agents : application Enseignement secondaire (un produit, 7 types)

**Entrées** : [tasks.md](tasks.md), [plan.md](plan.md), [spec.md](spec.md), [audit-reutilisation.md](audit-reutilisation.md), [questions.md](questions.md). Modèles : `specs/002-application-ipes/wave-plan.md`, `specs/003-application-primaire/wave-plan.md`. Date : 2026-10-22 (remplace celui du 2026-10-15).

## Résumé pour les non-techniciens
- Le travail se fait en **vagues** : la vague 0 prépare le socle et le squelette (dont le **profil de type** et les **modules activables**), puis quatre lots (E1 à E4).
- **Au plus 3 agents en même temps**, chacun dans **ses propres dossiers** : deux agents ne touchent jamais le même fichier.
- Un seul produit pour les 7 types d'établissement : les agents n'ont **pas** de version « privé » ou « technique » à écrire séparément ; les différences sont des paramètres et des modules (voir [decision-un-produit-secondaire.md](decision-un-produit-secondaire.md)).
- Chaque agent rend un rapport ; **aucun agent ne fait de commit ni de push**.

## Règles communes
1. **Au plus 3 agents simultanés**, en comptant les agents IPES, Primaire, maternelle privée et front déjà actifs ; une vague peut avoir plusieurs tours.
2. **Dossiers exclusifs** : un dossier, un agent. `database/migrations/**`, `config/**`, `src/Core/**`, `composer.*` : un seul agent par tour. Migrations Secondary : `S/<Module>/Database/Migrations/AAAAMMJJHHMM_secondary_<module>_*.sql` (`S/` = `src/Modules/Apps/Secondary/`). Jamais de modification d'une migration existante.
3. **`S/definitions.php` et `S/routes.php` racine** : écrits par l'agent du squelette seulement (glob sur les sous-modules).
4. **Contrats du socle seulement** (`Socle\<Module>\Contracts`) ; dépendances entre sous-modules Secondary par `S/<Module>/Contracts`.
5. **Un seul lecteur du code de type** : `S/Setup/Application/ProfileResolver` ; tout autre code demande le profil ou un module (`ModuleGate`) ; vérifié par T814 et T958.
6. **Numérotation partagée** : cette application utilise T800 à T999 ; la maternelle privée T1000 et suivants ; un agent ne crée jamais un identifiant hors de sa plage.
7. **Tests** : `tests/{Feature,Unit,Acceptance}/Secondary/<Module>/`, fixtures `tests/Fixtures/Secondary/<module>_*`, usines dans `tests/Support/Secondary/` (ProfileFactory pour les 7 types, T950).
8. **Qualité avant rendu** : php-cs-fixer sur ses fichiers seulement, phpstan 0 erreur sans baseline, phpunit vert (départ : nombre de tests de la branche au lancement), front : vue-tsc, eslint, vitest (`--pool=threads` sous charge), build ; fichiers en LF.
9. **Aucune valeur officielle en dur** : seuils, coefficients, barèmes en paramètres (`Defaults.php` et `secondary.type_profiles.*` marqués « à confirmer ») ; les chiffres du CDC n'existent que dans les jeux de test `example_cdc`.
10. **Protocole** : rapport `.coordination/reports/<ID>.md` (fichiers, routes, permissions `secondary.*`, résultats réels des contrôles, demandes au socle), puis `.coordination/done/<ID>.done` en dernier. Aucun commit, aucun push. Aucun secret ni donnée réelle dans le dépôt.
11. Les tests T940 à T949 sont écrits **avant** le code qu'ils vérifient ; les tests par type T950 à T958 suivent la livraison de T813 à T815.

## Ce qui démarre sans réponse / ce qui attend
| Démarre tout de suite | Partie bloquée (question) |
|-----------------------|---------------------------|
| Vague 0 entière, y compris T813 à T815 avec les profils par défaut « à confirmer » (Q18) | Import et export cartescolaire réels T828, T829, T971 (Q1) |
| E1 : Setup, Enrolment, Fees privé, Documents E1, portails, assistant (T836 à T838) | Coefficients, volumes et seuils officiels (Q2) : la stratégie tourne avec les exemples |
| E2 entier | Catalogue public des frais T831 (Q4) : mécanisme livré, contenu vide |
| Stratégie et tests T940 à T949 (jeux d'exemple) et T950 à T958 | Formats d'examen T906, T907 (Q3) ; listes de séries et spécialités (Q22) |
| E3 : Councils, PAS, STG, heures RHP | Ateliers T908, T909 (Q5) ; paie T912 (Q9) ; modèle de bulletin par type (Q24) |

---

## Vague 0 (avant E1) : socle et squelette
Statut du socle : plusieurs changements sont déjà livrés (voir [audit-reutilisation.md](audit-reutilisation.md)) ; les agents vérifient puis ajoutent le reste.
| Agent | Tour | Dossiers exclusifs | Tâches | Critères |
|-------|------|--------------------|--------|----------|
| W0S-1 Organisation | a | `src/Modules/Socle/Organisation/**`, tests Organisation | T800, T801 | tests listés ; 0 test existant cassé |
| W0S-2 Billing + Documents | a | `src/Modules/Socle/{Billing,Documents}/**` | T802, T803, T804 | `KindPriorityRule` sur l'exemple T945 ; garde 422 ; lien à durée limitée |
| W0S-3 Squelette Secondary | a | `S/{SecondaryApplication.php,definitions.php,routes.php,Support,Policies,Setup/Application/ProfileResolver.php}`, `tests/Architecture/SecondaryConventionsTest.php`, `tests/Support/Secondary/` | T810, T811, T813, T814 | manifeste visible au catalogue ; WriteGate 423/403 ; profil lu pour 7 types ; module inactif 409 |
| W0S-4 Assessment | b | `src/Modules/Socle/Assessment/**` | T805, T806, T807 | agrégat récursif, rangs par matière, fournisseurs, préfixe de variables |
| W0S-5 Identity + Messaging | b | `database/migrations/**` (seul), `src/Modules/Socle/{Identity,Messaging}/**`, `web/src/lib/permissions.ts` (ciblé) | T808, T809 | matrice des droits du CDC |
| W0S-3 (suite) | b | `S/Councils/Domain/**`, `tests/Acceptance/Secondary/**`, `tests/Support/Secondary/ProfileFactory` | T812, T940 à T944, T947, T950 | TST-01 à TST-04, NF-S5 verts ; ProfileFactory des 7 types |

*Tour a : 3 agents ; tour b : 3 agents (W0S-3 suite après W0S-4 et W0S-5 pour les contrats de stratégie).*

## Vague 1 = lot E1 : entrée, finances, profil
| Agent | Dossiers | Tâches | Critères |
|-------|----------|--------|----------|
| E1-A Setup + Enrolment | `S/Setup/**` (hors `ProfileResolver` déjà livré), `S/Enrolment/**` | T815, T820 à T827, T838 | RG-ORG-1..4, RG-INS-1..4 ; ouverture des 7 types ; journal de changement de profil |
| E1-B Fees + CarteScolaire | `S/Fees/**`, `S/CarteScolaire/**` | T830 à T832, T828, T829 (défauts Q1), T945, T946 | TST-06, TST-07 ; modules `fees_private` et `fees_public_catalog` gardés |
| E1-C Documents + Portails + OpenAPI + Front profil | `S/Documents/**`, `S/Portals/**`, `S/Notifications/**`, `openapi.d/secondary-*.yaml`, `web/src/views/secondary/setup/**` | T833, T834, T835, T836, T837 | DOC-01 à 06, 27 à 29 ; NOT-01, 09 ; menu suivant les modules |

## Vague 2 = lot E2 : vie scolaire
| Agent | Dossiers | Tâches | Critères |
|-------|----------|--------|----------|
| E2-A Timetable + Syllabus | `S/Timetable/**`, `S/Syllabus/**` | T860 à T863 | génération sans conflit ; couverture ; dédoublement d'atelier seulement avec `workshops` |
| E2-B Discipline + appel | `S/Discipline/**` | T864 à T867, T884 | TST-10 ; RG-PRE-1..3 |
| E2-C Grades + OpenAPI E2 | `S/Grades/**` | T868 à T871, T948 | TST-05, TST-09 |

## Vague 3a = lot E3, tour 1
| Agent | Dossiers | Tâches | Critères |
|-------|----------|--------|----------|
| E3-A Councils (bulletins) | `S/Councils/**` | T900 à T904 | TST-01 à TST-05 de bout en bout (API) |
| E3-B OfficialExams | `S/OfficialExams/**` | T906, T907, T949 | TST-08 ; module `official_exams` gardé |
| E3-C Workshops (ateliers, stages) | `S/Workshops/**` | T908 à T910, T951 à T954 | grille d'exemple 12/20, plafond d'exemple, RG-ATL-1 ; tests par type LEG, technique, CES, CETIC |

## Vague 3b = lot E3, tour 2
| Agent | Dossiers | Tâches | Critères |
|-------|----------|--------|----------|
| E3-D Councils (orientation) | `S/Councils/**` (après E3-A) | T905 | recours, orientation ; module `orientation` gardé |
| E3-E Staff | `S/Staff/**` | T911, T912, T955 | heures payables seulement avec appel ; paie seulement avec `payroll_simple` ; tests collège et lycée privés |
| E3-F Documents E3 + OpenAPI | `S/Documents/**` | T913, T914 | types de documents émis selon les modules (DOC-21 à 24 : technique seulement) |

## Vague 4 = lot E4 : pilotage, services, recette
| Agent | Dossiers | Tâches | Critères |
|-------|----------|--------|----------|
| E4-A Dashboards + rapports | `S/Dashboards/**` | T970, T971 | O1 à O7 calculés ; réseau sans données nominatives |
| E4-B Services + portails | `S/Services/**`, `S/Portals/**` | T972 à T974, T956, T957 | santé : infirmier seul ; type `autre` ; module coupé en lecture seule |
| E4-C Recette | `tests/Load/Secondary/**`, `web/e2e/secondary-*`, scripts de reprise | T975 à T977, T958, T979 | NF-S1 à NF-S5 mesurés sur MySQL ; TST-T1 à TST-T7 verts ; HTTPS actif avant donnée réelle |

Front (T978) : un agent front par lot, en remplacement d'un agent back quand le créneau se libère ; éditions ciblées des fichiers partagés (`router/routes.ts`, `i18n/*`).

## Dépendances entre vagues
```
Vague 0 (socle + squelette + profil) -> E1 -> E2 -> E3a -> E3b -> E4
                                           \-> E3 (OfficialExams peut démarrer après T829)
```
Une vague ne démarre que si la précédente a rendu son rapport et son fichier `.done` à `ok`.
