# Audit : applications Primaire et Maternelle (privées et publiques)

Date : 2026-10-19. Audit en lecture seule du dépôt (branche `feat/rewrite-php-laravel`, commits `00e0e2f` puis `05835ef`, arbre de travail non commité). Référence d'une application « terminée » : l'IPES (`src/Modules/Apps/Higher`, 421 fichiers PHP, 58 fichiers de tests, recette Septentrion).

## Résumé (20 lignes)
1. **Aucune des quatre applications n'est prête** pour un client ; seule la Maternelle publique a du code applicatif réel.
2. **Tout le travail public est non commité**. Le commit `05835ef` (IPES et plateforme, 19/10 à 19:48), arrivé pendant l'audit, ne l'a pas inclus. Restent `??` : `src/Modules/Apps/PublicNursery/` (44 fichiers), `PublicPrimary/` (9), `Socle/Sync/`, les territoires, les migrations de rôles publics, les tests et les écrans `public-nursery` et `apps/`. Une autre session modifie en ce moment `web/src/views/nursery/` (2 fichiers `M`). Une perte de poste efface plusieurs jours de travail.
3. **Maternelle publique** : 32 tâches sur 48 cochées ; 11 exigences sur 21 faites et testées, 2 partielles, 8 absentes (codes du jour, paquet hors ligne, veille de non-récupération, registre DOC-19, carte DOC-21, transfert, NOT-14, journal nominatif).
4. **Défaut de sécurité** : `NurseryWriteGate::assertWritable` n'est appelé par **aucune route** (seulement par `NurseryPromotionPlanner`). Une application suspendue ou fermée accepte encore les écritures, contrairement à la règle 423/403 de l'IPES.
5. **Défaut de cloisonnement** : `EstablishmentGuard::assertSection` (portée de section R11) n'est appelé par aucun contrôleur. Les contrôleurs du module Développement ne vérifient ni le type d'établissement ni l'ouverture de l'application.
6. **Primaire public** : 11 tâches sur 82 cochées, toutes au socle. `PublicSchools` (modules communs) n'existe pas. `PublicPrimary` ne contient que le registre provisoire du 14/10, à déplacer (T625).
7. **Primaire privé** (`src/Modules/Apps/Primary`) : vide (`.gitkeep`). Spécifications 003 complètes, 0 tâche T5xx réalisée. Les extensions du socle PS1, PS2, PS4 et PS5 sont absentes. PS3 (appel par demi-journée) est livrée par T605.
8. **Maternelle privée** (`src/Modules/Apps/Nursery`) : vide, **aucune spécification**. Les écrans `web/src/views/nursery/` lisent `/authorized-pickups` du socle et renvoient vers les écrans publics.
9. Tests exécutés : `phpunit tests/Feature/PublicNursery tests/Feature/PublicSchools tests/Isolation` **vert, 143 tests, 2 708 assertions** (dont 46 tests propres au public).
10. **Suite complète rouge** : `tests/Feature/Platform/AccessRequestTest::testLeCataloguePublicListeLesApplicationsNonRetirees` échoue, car le manifeste `public_nursery` s'ajoute au catalogue public (risque annoncé par T610).
11. **Le catalogue public annonce `public_nursery` comme `available`** (30 000 XAF, essai de 90 jours) alors que le site est en HTTP et que l'application n'est pas finie.
12. PHPStan sur `PublicNursery` et `PublicPrimary` : **51 erreurs**, toutes dans `PublicNursery` (règle du projet : 0 erreur, sans baseline). `PublicPrimary` : 0 erreur.
13. `vue-tsc --noEmit` : **10 erreurs**, toutes dans `web/src/views/public-nursery/TransitionDecisionsView.vue`. Le build front est donc cassé.
14. ESLint sur `src/views/public-nursery` et `src/apps` : **1 erreur** (`vue/no-v-html`, `TransitionLiaisonView.vue:102`). Risque XSS faible : le gabarit DOC-20 échappe ses données.
15. DOC-18 et DOC-20 sont rendus par `PdfRenderer` sans passer par le catalogue `Documents` : pas de numéro, pas de QR, pas de vérification publique. Écart à RG-DOC-5.
16. Le carnet DOC-18 (`ProgressBookService`) n'a **aucune route HTTP** ; son écran est inactif (`ComingSoon`).
17. Les extensions du socle pour le public sont pour la plupart livrées et testées : Sync, territoires, LearnerWriter, numérotation, `day_part`, catégorie `essential`, plafond SMS, StaffMover, ApprovalWorkflow, réédition. Restent absents : T606/T607 (portée territoriale, journal nominatif), T608, T613 (agrégats et `DisclosurePolicy` sans implémentation), T617, T618.
18. `tests/ApiContract` (générique, toutes routes) : 7 échecs sur 5 602, dont `POST /public-nursery/development/frames` → **500** sur corps vide et 3 listes sans pagination. Il n'y a aucun parcours E2E ni Playwright pour ces applications.
19. Deux migrations portent une date future (`202610270900`, `202611030900`) : une migration datée d'aujourd'hui passerait avant elles.
20. Recommandation (voir [phases.md](phases.md)) : stabiliser et commiter le public, puis lancer le **primaire privé en pilote rapide**. Le primaire public suit, une fois les réponses du MINEDUB obtenues.

## Tableau de synthèse chiffré
| Application | Code app | Tâches cochées | Exigences : testées / sans test / partielles / absentes | Tests propres | PHPStan | TS / lint | Commité |
|---|---|---|---|---|---|---|---|
| Maternelle publique (`public_nursery`) | 44 fichiers, 31 routes | 32/48 | 11 / 0 / 2 / 8 (sur 21 FR-M) | 37 Feature + 4 Archi + 1 Isolation | 51 erreurs | 10 / 1 | non |
| Primaire public (`public_primary` + `public_schools`) | 9 fichiers (registre), 9 routes | 11/82 (socle seul) | 0 / 0 / 3 / 66 (sur 69 EX communs + primaire), dont 7 avec le socle prêt | 5 Feature + 3 Isolation + 12 rôles | 0 | 0 / 0 | non |
| Primaire privé (`primary`) | 0 | 0/49 | 0 / 0 / 1 / 45 (sur 46 EX-PRI ; 21 « C » couverts par le socle, non câblés) | 0 | n/a | n/a | n/a |
| Maternelle privée (`nursery`) | 0 | aucune tâche | non spécifiée | 0 | n/a | 3 écrans socle | oui (écrans) |

Détail par application : [audit-maternelle-publique.md](audit-maternelle-publique.md), [audit-primaire-public.md](audit-primaire-public.md), [audit-prive.md](audit-prive.md), [audit-qualite.md](audit-qualite.md).
