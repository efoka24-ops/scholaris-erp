# Audit : Primaire privé (`primary`) et Maternelle privée (`nursery`)

## Primaire privé
Spécification : `specs/003-application-primaire/` (spec, plan, data-model, socle-reuse, questions, tasks T500 à T572, wave-plan). Il n'y a **pas de cahier des charges client**. Les 22 questions (Q1 à Q22) sont sans réponse.
- Code : `src/Modules/Apps/Primary/` ne contient que `.gitkeep`. Il n'y a pas de manifeste : le catalogue garde la ligne `primary`, `coming_soon` (`Platform/Database/Migrations/202610083451_platform_catalog_seed.sql`), avec un tarif de 60 000 XAF et la liste des modules requis de l'IPES.
- Front : aucun écran propre.

### Exigences (46 EX-PRI)
| Couverture 003 | Nombre | État réel |
|---|---|---|
| C (socle tel quel) | 21 | couvertes et testées **au socle** (personnes, inscriptions, notes, conseil, frais, messages, documents, QR) mais **non câblées** : ni manifeste, ni rôles, ni garde, ni écrans primaire |
| P (extension) | 17 | 1 partielle : EX-PRI-29, appel par demi-journée (PS3 livrée par T605, `DayPartModeTest`) ; 16 absentes |
| N (à créer) | 8 | absentes ; EX-PRI-33 (sortie sécurisée) peut réutiliser `Attendance/Contracts/AuthorizedPickupRegistry` et la logique de `PublicNursery/Pickup` |

### Extensions du socle de la vague 0 (003)
| Tâche | Objet | État vérifié |
|---|---|---|
| T500 PS1a | `periods.parent_id`, `CurrentPeriod::allAt()` | **absent** (la table `periods` n'a pas de `parent_id`) |
| T501 PS3 | appel `day_part` | **fait et testé** (T605) |
| T502 PS1b | `PeriodAggregatingStrategy` | absent |
| T503 PS2 | `assessment.qualitative_scale` | absent |
| T504 PS4 | `assessment.council_line_providers.*` | absent |
| T505 PS5 | `assessment.report_card_variables.*` | absent |
| T506 à T509 | squelette, tests, rôles `primary_*`, modèles de messages | absents |
| T510 PS8 | journal des remises | socle : contrat des autorisés livré (T700) ; journal propre au public |
| T511 PS9 | `CompetencyCatalog` partagé | non décidé |

### Écarts et risques
- Les règles d'évaluation officielles (DOC-1 à DOC-3, Q2 à Q7) bloquent la stratégie de calcul et le bulletin (T534, T540, T541). Tout le reste se construit avec les valeurs par défaut paramétrables.
- 003 et 004 doivent partager des briques sans dépendance d'application à application : sortie sécurisée, référentiel de compétences, documents (voir [integration.md](integration.md)).

## Maternelle privée
- Code : `src/Modules/Apps/Nursery/` ne contient que `.gitkeep`. Aucune spécification n'existe (`004/maternelle/spec.md` §1 l'exclut). Le catalogue garde la ligne `nursery`, `coming_soon`.
- Front : `web/src/views/nursery/` (commité) contient `NurseryDashboardView.vue` (compte des autorisés actifs, lu sur `/authorized-pickups` du socle), `NurseryPickupView.vue` (création et révocation d'autorisés ; la remise n'a pas d'interface serveur) et `NurseryLogbookView.vue` (`ComingSoon`). Le tableau de bord renvoie vers les écrans **publics** (`views/public-nursery/*`), qui exigent des permissions `public_nursery.*` : un client privé verrait des liens morts ou refusés.
- Exigences : **non spécifiées**. Les modules DEV, SOR et TRA de `PublicNursery` couvrent la plupart des besoins probables (observation sans note, cahier de vie, sortie sécurisée). Il faut décider s'ils sont réutilisés par une application `nursery` privée ou mutualisés (voir [questions.md](questions.md)).
- Risque : sans décision, deux implémentations divergentes de la sortie sécurisée (privé, public) apparaîtront.
