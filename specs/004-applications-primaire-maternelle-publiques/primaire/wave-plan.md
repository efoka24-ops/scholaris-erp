# Plan d'exécution par vagues : modules communs publics et Primaire publique

Date : 2026-10-15. Entrées : [tasks.md](tasks.md), [socle-reuse.md](socle-reuse.md), modèle `specs/002-application-ipes/wave-plan.md`.

## Règles
1. **Au plus 3 agents simultanés** ; un agent suivant démarre dès qu'un créneau se libère, dans l'ordre indiqué.
2. **Dossiers exclusifs** : un dossier n'a qu'un agent. `database/migrations/**` et `web/src/lib/permissions.ts` : un seul agent par vague (W0-C). Éditions ciblées seulement de `web/src/router/routes.ts`, `AppShell`, `i18n/*`.
3. Les changements du socle (PP) sont faits par l'agent propriétaire du module, avec ses tests, sans toucher aux autres modules.
4. Coordination Maternelle : les contrats `PublicSchools/Contracts` (T622) sont livrés en fin de vague 1 ; l'agent Maternelle ne modifie pas `PublicSchools`, il demande. Les vagues Maternelle partagent le plafond de 3 agents.
5. Contrôles avant rendu : php-cs-fixer sur ses fichiers, phpstan 0 erreur sans baseline, phpunit vert ; front : vue-tsc, eslint, vitest, build.
6. Lots du CDC : P1 (S36-S41), P2 (S42-S47), P3 (S48-S52), P4 pilote (8 semaines dès une rentrée).

## Vague 0 : socle (avant tout code d'application) — peut démarrer sans réponse sauf T600 (Q4)
| Tour | Agent | Dossiers | Tâches |
|---|---|---|---|
| a | W0-A Organisation | `So/Organisation/**`, `tests/**/Organisation/**` | T601, T602 |
| a | W0-B People + Attendance | `So/People/**`, `So/Attendance/**` | T603, T604, T605 |
| a | W0-C Sync + données partagées | `So/Sync/**` (nouveau), `database/migrations/**`, `web/src/lib/permissions.ts` | T609, T610 (après Q4) |
| b | W0-D Identity | `So/Identity/**`, `So/Audit/**` (route de lecture) | T606, T607, T608 |
| b | W0-E Staff + Documents | `So/Staff/**`, `So/Documents/**` | T611, T615 |
| b | W0-F Reporting + Messaging | `So/Reporting/**`, `So/Messaging/**` | T612, T613, T614 |
| c | W0-G revue | lecture seule + corrections mineures | T619 |

## Vague 1 : squelettes et lot P1
| Tour | Agent | Dossiers | Tâches |
|---|---|---|---|
| a | W1-A squelettes | `PS/` (racine, `Support`, `Policies`, `Contracts`), `PP/` racine, `tests/Architecture/PublicSchoolsConventionsTest.php`, `tests/Isolation/PublicTerritoryTest.php` | T620, T621, T622, T623, T624 |
| b | W1-B École et élèves | `PS/Schools`, `PS/Pupils`, `PS/Territory` | T625, T626, T627, T628, T629, T630, T632, T633, T642 |
| b | W1-C Présences, personnel, messages | `PS/Attendance`, `PS/Staff`, `PS/Messages`, `PS/Sync` | T634, T635, T636, T637, T638, T639, T640, T641 |
| b | W1-D Front P1 | `web/src/apps/public-schools/**`, `web/e2e/public/**` | T644, T645 (après T608) |
| c | W1-E documents P1 + E2E | `PS/Documents`, `tests/E2E/PublicSchools` | T631, T643, T646 |

## Vague 2 : lot P2
| Tour | Agent | Dossiers | Tâches |
|---|---|---|---|
| a | W2-A Évaluation | `PP/Referential`, `PP/Evaluation`, `PP/Sync`, `PP/Documents` | T660, T661, T662, T663, T664, T666 |
| a | W2-B APEE + statistiques | `PS/Apee`, `PS/Census` | T650, T651, T655, T652 |
| a | W2-C Front P2 | `web/src/apps/public-primary/**` | T667 |
| b | W2-D Passage + agrégats | `PP/Promotion`, `PS/Territory` (fournisseurs) | T665, T653, T654, T668 |

## Vague 3 : lot P3
| Tour | Agent | Dossiers | Tâches |
|---|---|---|---|
| a | W3-A Examens | `PP/Exams` | T680, T681, T682, T683, T684, T685 |
| a | W3-B Inspection + cantine | `PS/Inspection`, `PS/Canteen` | T686, T687 |
| a | W3-C Socle P3 | `So/Messaging/**`, `So/Integrations/**` | T616, T617, T618, puis T688, T689 |
| b | W3-D Tableaux territoriaux | `PS/Territory`, front tableaux | T690 |

## Vague 4 : recette et pilote (P4)
W4-A T691, T692 ; W4-B T693, T694, T695 ; W4-C T696, T697, T699. T698 hors code.

## Ce qui peut démarrer sans réponse aux questions
Toute la vague 0 sauf T610 (attend Q4), toute la vague 1, T650-T651, T655, T660-T665 avec référentiel et politique d'exemple `example_*`. Attendent une réponse pour la mise en service seulement : T652 export (Q3), T681 format (Q10), chargement du référentiel officiel (Q8), politique officielle (Q9), USSD/voix réels (Q16).
