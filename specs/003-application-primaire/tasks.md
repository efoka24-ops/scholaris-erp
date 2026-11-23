# Tâches : application Primaire

**Entrées** : [spec.md](spec.md), [plan.md](plan.md), [data-model.md](data-model.md), [socle-reuse.md](socle-reuse.md). **Numérotation** : T500 à T572 (libre dans `specs/`). **Format** : `[ID] [P?] [Vague] Description — dépendances — exigences`. `[P]` = parallélisable avec les autres `[P]` de la même vague. **[B]** = bloquée par une réponse de l'utilisateur (questions.md). Tests d'abord quand la règle est connue.

## Vague 0 : socle (agents propriétaires de module) et squelette
| ID | Tâche | Dépend | Exig. |
|----|-------|--------|-------|
| T500 [P] | PS1a Organisation : migration `periods.parent_id`, validation dans `PeriodController` (séquence ⊂ trimestre, même année, dates incluses), `PeriodInfo::$parentId`, `CurrentPeriod::allAt()`, tests, openapi `organisation.yaml` | — | EX-PRI-04 |
| T501 [P] | PS3 Attendance : paramètre `attendance.mode`, appel `day_part` sans créneau (clé `dp:<structure>:<part>`), synthèse en demi-journées, hors ligne `sync` compris, tests | — | EX-PRI-29 |
| T502 [P] | PS1b Assessment : `PeriodAggregatingStrategy`, calcul d'une période parente dans `ResultService`, tests (sans implémentation : comportement inchangé) | T500 | EX-PRI-22 |
| T503 [P] | PS2 Assessment : `assessment.qualitative_scale` paramétrable dans `GradeService`, exposé dans la ressource d'épreuve, tests de rétrocompatibilité | — | EX-PRI-20 |
| T504 [P] | PS4 Assessment : `assessment.council_line_providers.*` à la création et au `refresh` du conseil, sans écrasement d'une saisie, tests | — | EX-PRI-31 |
| T505 [P] | PS5 Assessment : `assessment.report_card_variables.*` et paramètre `assessment.report_card_type`, tests | — | EX-PRI-26 |
| T506 | Squelette `src/Modules/Apps/Primary` : `PrimaryApplication` (manifeste), `definitions.php` et `routes.php` racine (glob), `Support/` (WriteGate, GatedAction, PrimaryRoutes, Perimeter, ScopedByClass), `Policies/` (ScopeResolver, RoleMatrix), arborescence des sous-modules, `openapi.d/primary-core.yaml` | — | EX-PRI-01 |
| T507 | Tests de squelette : `tests/Architecture/PrimaryConventionsTest.php` (dont SC-PRI-4), `tests/Isolation/PrimaryScopeTest.php`, `tests/Feature/Primary/Core/WriteGateTest.php`, `tests/Support/Primary/` | T506 | EX-PRI-01 |
| T508 | PS7 Rôles : `database/migrations/<date>_roles_primary.sql` (5 rôles, `role_permissions`), test `RolePermissionsTest` étendu, matrice `RoleMatrix` + `RoleMatrixTest` | T506 | EX-PRI-09 |
| T509 | PS6 Modèles de messages primaire (données) + variables manquantes de `TemplateRenderer::ALLOWED` | T508 | §7 spec |
| T510 [P] [B Q13] | PS8 Attendance : journal des remises `pickup_handovers`, routes `handover`/`refusals`, notification | — | EX-PRI-33 |
| T511 [P] [B décision PS9] | PS9 Assessment : `CompetencyCatalog` partagé (seulement si retenu) | — | EX-PRI-17 |

## Vague 1 : référentiel, inscriptions, frais, documents administratifs (démarre sans réponses)
| ID | Tâche | Dépend | Exig. |
|----|-------|--------|-------|
| T520 [P] | Academic : migrations `pri_level_profiles`, `pri_domains`, `pri_level_subjects`, `pri_competencies`, `pri_referential_templates` ; dépôts avec `TenantTable` | T506 | EX-PRI-05, 16, 17 |
| T521 [P] | Academic : routes CRUD + export (`ExportsList`), validation (niveau final unique par section, matière unique par niveau) ; tests d'isolation | T520 | EX-PRI-16, 17 |
| T522 | Academic : application d'un modèle de référentiel (`StructureWriter`, `CurriculumCatalog`), copie d'année, idempotence ; un modèle `example` étiqueté « exemple » dans les fixtures | T521 | EX-PRI-05, 17 |
| T523 [P] | Enrolment : profil d'élève (redoublant calculé par `EnrollmentDirectory::forPerson`), contrôle de la table de passage `GET /primary/promotion-map/check` | T506 | EX-PRI-14, 46 |
| T524 [P] | Billing : `billing.fee_kinds.primary` ; tests de facture d'inscription + 1re tranche par `InvoiceIssuer` avec remise fratrie | T506 | EX-PRI-34 |
| T525 [P] | Documents : types `primary_transfer`, `primary_exam_list` ; modèles FR/EN certificat de scolarité, attestation, carte scolaire, fiche de renseignements ; transfert sortant | T506 | EX-PRI-15, 40 |
| T526 | Front : référentiel, profils de niveau, table de passage, vocabulaire `primary` dans le lanceur | T521 | EX-PRI-05, 16 |

## Vague 2 : évaluation, compétences, conduite, appel (partiellement bloquée)
| ID | Tâche | Dépend | Exig. |
|----|-------|--------|-------|
| T530 | Grades : migrations `pri_competency_marks`, `pri_competency_mark_events`, `pri_conduct_marks` | T506 | EX-PRI-20, 31 |
| T531 | Grades : feuille de compétences par classe et période (lot, `client_uuid`, `version`, 409), verrouillage, correction approuvée, historique | T530, T503, T520 | EX-PRI-20 |
| T532 [P] | Grades : saisie de la conduite, fournisseur `PrimaryCouncilLines` (PS4) | T530, T504 | EX-PRI-31 |
| T533 | Grades : `PrimaryRuleScopes` (classe → niveau) | T506 | EX-PRI-21 |
| T534 [B Q3 Q4] | Grades : `PrimaryRuleStrategy` (`computeLearner`, `aggregatePeriods`, `decideYear`) entièrement paramétrée ; tests avec règles `example_*` ; tests chiffrés officiels **après** réponses | T502, T533 | EX-PRI-21, 22, 27 |
| T535 [P] | Attendance (côté Primary) : préréglage `attendance.mode=day_part` à l'ouverture de l'application, compteurs en demi-journées pour le tableau de bord | T501 | EX-PRI-29 |
| T536 | Front : feuille de compétences web + mobile hors ligne (modèle `CarnetSuivi.html`), conduite, appel par demi-journée (modèle `Appel.html`) | T531, T535 | EX-PRI-20, 29 |

## Vague 3 : conseil, bulletins, portail, cahier de liaison
| ID | Tâche | Dépend | Exig. |
|----|-------|--------|-------|
| T540 [B Q2] | Grades : `PrimaryReportCardVariables` (PS5) ; modèle Dompdf `primary_report_card` FR/EN calé sur DOC-1 | T505, T534 | EX-PRI-26 |
| T541 [B Q2 Q10] | Documents : `primary_annual_report` (récapitulatif annuel, décision de passage) | T540 | EX-PRI-27 |
| T542 | Parcours conseil → bulletins en lot → publication → NOT-PRI-02 (test E2E) | T540, T509 | EX-PRI-25, 28 |
| T543 [P] | ClassLife : `pri_class_journal`, `pri_journal_receipts`, routes, publication, NOT-PRI-05 | T506 | EX-PRI-38 |
| T544 [P] | Portals : sections famille (compétences, cahier, examen) et enseignant (ma classe), `portals.grading_todo.primary` | T531, T543 | EX-PRI-39 |
| T545 | Front : conseil (vue primaire), cahier de liaison, sections du portail famille (modèle `Parent.html`) | T542, T544 | EX-PRI-25, 38, 39 |

## Vague 4 : examen, pilotage, tutelle, services, clôture
| ID | Tâche | Dépend | Exig. |
|----|-------|--------|-------|
| T550 [P] [B Q11] | Exams : `pri_exam_sessions`, `pri_exam_candidates`, inscription en lot, numéros, import/saisie des résultats, export, facture d'examen | T520, T524 | EX-PRI-42 |
| T551 [P] | Dashboards : `reporting.indicators.primary`, `reporting.dashboards.primary`, `reporting.todo.primary`, commande `primary:alerts` | T531, T535 | EX-PRI-43, 44 |
| T552 [P] [B Q16] | Tutelage : `integrations.export_types.primary` (effectifs, résultats, examen) | T551 | EX-PRI-45 |
| T553 [B Q12] | Services (Could) : `pri_service_plans`, `pri_service_subscriptions`, facturation | T524 | EX-PRI-36 |
| T554 [B Q13] | Sortie sécurisée côté Primary (niveaux concernés, écran agent) | T510 | EX-PRI-33 |
| T555 [B Q10] | Clôture d'année : vérifications `organisation.closure.checks.primary` (bulletins émis, conseils validés), test E2E de passage avec `YearPromotion` | T534, T523 | EX-PRI-46 |
| T556 | Front : tableau de bord directeur (modèles `SecDashboard.html`, `MatDashboard.html`), examen, exports | T551, T550 | EX-PRI-43 |

## Vague 5 : recette
| ID | Tâche | Dépend |
|----|-------|--------|
| T560 | `tests/ApiContract` étendu à toutes les routes `/primary/*` (401, 403, isolation, RFC 9457) | toutes |
| T561 | `tests/E2E/Primary/` : parcours 1 à 8 de spec.md §3 | toutes |
| T562 | Playwright `web/e2e/primary/*` (mocks fidèles) | T526, T536, T545, T556 |
| T563 | Export client complet avec tables `pri_*` ; demande de personne concernée | toutes |
| T564 | Passage sur MySQL réel (`phpunit.mysql.xml`, `artisan db:check`) | toutes |
| T565 | Mesures SC-PRI-1 à SC-PRI-3 (`tests/Load`) | T542 |
| T566 | Catalogue : passage de `primary` de `coming_soon` à `available` (migration de données nouvelle), après accord de l'utilisateur | T560-T565 |
| T570 | Rapport de recette `specs/003-application-primaire/test-report.md` | T560-T566 |
| T571 | Mise à jour de `specs/HANDOVER.md` (§5 bis Primaire) | T570 |
| T572 | Pilote dans une école volontaire (hors code) | T571 |
