# Plan d'exécution par vagues d'agents : application Secondaire

Date : 2026-10-15. Modèles : `specs/002-application-ipes/wave-plan.md`, `specs/003-application-primaire/wave-plan.md`. Entrées : [tasks.md](tasks.md), [socle-reuse.md](socle-reuse.md), [plan.md](plan.md), [questions.md](questions.md).

## Règles communes
1. **Au plus 3 agents simultanés**, en comptant les agents IPES, Primaire et front déjà actifs ; une vague a plusieurs tours si besoin.
2. **Dossiers exclusifs** : un dossier, un agent. `database/migrations/**`, `config/**`, `src/Core/**`, `composer.*` : un seul agent par vague. Migrations Secondary : `S/<Module>/Database/Migrations/AAAAMMJJHHMM_secondary_<module>_*.sql`.
3. **`S/definitions.php` et `S/routes.php` racine** : écrits par l'agent du squelette seulement (glob sur les sous-modules).
4. **Contrats du socle seulement** ; dépendances entre sous-modules Secondary par `S/<Module>/Contracts`.
5. **Tests** : `tests/{Feature,Unit,Acceptance}/Secondary/<Module>/`, fixtures `tests/Fixtures/Secondary/<module>_*`.
6. **Qualité avant rendu** : php-cs-fixer sur ses fichiers, phpstan 0 erreur sans baseline, phpunit vert (départ 5130 tests + ceux des autres agents), front : vue-tsc, eslint, vitest, build.
7. **Aucune valeur officielle en dur** : seuils, coefficients, barèmes en paramètres (`Defaults.php` marqué « à confirmer »).
8. **Rapport d'agent** : fichiers, routes, permissions `secondary.*`, résultats réels des contrôles, demandes au socle.
9. Les tests T940 à T949 sont écrits **avant** le code qu'ils vérifient.

## Ce qui démarre sans réponse / ce qui attend
| Démarre tout de suite | Partie bloquée (question) |
|-----------------------|---------------------------|
| Vague 0 entière (SS10 seulement si Q14 = oui) | Import et export cartescolaire réels T828, T829, T971 (Q1) |
| E1 : Setup, Enrolment, Fees privé, Documents E1, portails | Coefficients officiels chargés (Q2) : la stratégie tourne avec les exemples |
| E2 entier | Catalogue public des frais T831 (Q4) : mécanisme livré, contenu vide |
| Stratégie et tests T940-T949 (valeurs du CDC) | Formats d'examen T906, T907 (Q3) |
| E3 : Councils, PAS, STG, heures RHP | Grilles et spécialités ateliers T908, T909 (Q5) ; paie T912 (Q9) |

---

## Vague 0 (avant E1) : socle et squelette
| Agent | Tour | Dossiers exclusifs | Tâches | Critères |
|-------|------|--------------------|--------|----------|
| W0S-1 Organisation | a | `src/Modules/Socle/Organisation/**`, tests Organisation | T800, T801 | tests listés ; 0 test existant cassé |
| W0S-2 Billing + Documents | a | `src/Modules/Socle/{Billing,Documents}/**` | T802, T803, T804 | `KindPriorityRule` sur l'exemple T945 ; garde 422 ; lien 24 h |
| W0S-3 Squelette Secondary | a | `src/Modules/Apps/Secondary/{SecondaryApplication.php,definitions.php,routes.php,Support,Policies}`, `tests/Architecture/SecondaryConventionsTest.php`, `tests/Support/Secondary/` | T810, T811 | manifeste visible au catalogue ; WriteGate 423/403 |
| W0S-4 Assessment | b | `src/Modules/Socle/Assessment/**` | T805, T806, T807 | agrégat récursif, rangs par matière, fournisseurs |
| W0S-5 Identity + Messaging (+ Attendance) | b | `database/migrations/**` (seul), `src/Modules/Socle/{Identity,Messaging}/**`, `web/src/lib/permissions.ts` (ciblé) | T808, T809 | matrice des droits du CDC |
| W0S-3 (suite) | b | `S/Councils/Domain/**`, `tests/Acceptance/Secondary/**` | T812, T940-T944, T947 | TST-01 à TST-04, exemples, NF-S5 verts |

## Vague 1 = lot E1 (S24-S28)
| Agent | Dossiers | Tâches | Critères |
|-------|----------|--------|----------|
| E1-A Setup + Enrolment | `S/Setup/**`, `S/Enrolment/**` | T820-T827 | RG-ORG-1..4, RG-INS-1..4 |
| E1-B Fees + CarteScolaire | `S/Fees/**`, `S/CarteScolaire/**` | T830-T832, T828, T829 (défauts Q1), T945, T946 | TST-06, TST-07 |
| E1-C Documents + Portails + OpenAPI | `S/Documents/**`, `S/Portals/**`, `S/Notifications/**`, `openapi.d/secondary-*.yaml` | T833, T834, T835 | DOC-01 à 06, 27 à 29 ; NOT-01, 09 |

## Vague 2 = lot E2 (S29-S33)
| Agent | Dossiers | Tâches | Critères |
|-------|----------|--------|----------|
| E2-A Timetable + Syllabus | `S/Timetable/**`, `S/Syllabus/**` | T860-T863 | génération sans conflit ; couverture |
| E2-B Discipline + appel | `S/Discipline/**` | T864-T867, T884 | TST-10 ; RG-PRE-1..3 |
| E2-C Grades + OpenAPI E2 | `S/Grades/**` | T868-T871, T948 | TST-05, TST-09 |

## Vague 3a = lot E3, tour 1 (S34-S36)
| Agent | Dossiers | Tâches | Critères |
|-------|----------|--------|----------|
| E3-A Councils BUL | `S/Councils/**` | T900-T904 | TST-01 à TST-05 de bout en bout (API) |
| E3-B OfficialExams | `S/OfficialExams/**` | T906, T907, T949 | TST-08 |
| E3-C Workshops (ATL, STG) | `S/Workshops/**` | T908-T910 | grille 12/20, plafond 8, RG-ATL-1 |

## Vague 3b = lot E3, tour 2 (S37-S38)
| Agent | Dossiers | Tâches | Critères |
|-------|----------|--------|----------|
| E3-D Councils PAS | `S/Councils/**` (après E3-A) | T905 | recours, orientation |
| E3-E Staff | `S/Staff/**` | T911, T912 | heures payables seulement avec appel |
| E3-F Documents E3 + OpenAPI | `S/Documents/**` | T913, T914 | 34 types émis |

## Vague 4 = lot E4 (S39-S40 + un trimestre de pilote)
| Agent | Dossiers | Tâches | Critères |
|-------|----------|--------|----------|
| E4-A Dashboards + rapports | `S/Dashboards/**` | T970, T971 | O1-O7 calculés |
| E4-B Services + portails | `S/Services/**`, `S/Portals/**` | T972-T974 | santé : infirmier seul |
| E4-C Recette | `tests/Load/Secondary/**`, `web/e2e/secondary-*`, scripts de reprise | T975-T977, T979 | NF-S1 à NF-S5 mesurés sur MySQL |

Front (T978) : un agent front par lot, en remplacement d'un agent back quand le créneau se libère ; éditions ciblées des fichiers partagés.
