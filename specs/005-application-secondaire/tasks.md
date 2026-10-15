# Tâches : application Secondaire

**Entrées** : [spec.md](spec.md), [plan.md](plan.md), [data-model.md](data-model.md), [socle-reuse.md](socle-reuse.md), [questions.md](questions.md). **Numérotation** : T800 à T999. **Format** : `ID [P] [B Qn]` ; `[P]` = parallélisable dans sa vague ; `[B Qn]` = la partie chiffrée attend la réponse Qn (le code démarre avec la valeur par défaut « à confirmer »). Chemins : `S/` = `src/Modules/Apps/Secondary/`, `TF/` = `tests/Feature/Secondary/`, `TU/` = `tests/Unit/Secondary/`, `TA/` = `tests/Acceptance/Secondary/`. Chaque tâche finit par php-cs-fixer (ses fichiers), phpstan 0 erreur, phpunit vert.

## Vague 0 : socle (agents propriétaires) et squelette
| ID | Tâche et fichiers | Dépend | Tests |
|----|-------------------|--------|-------|
| T800 [P] | SS1 Organisation : migration `<date>_organisation_period_parent.sql` (`periods.parent_id`), `PeriodController`, `PeriodInfo::$parentId`, `CurrentPeriod::allAt()` (sauter si PS1a fait) | — | séquence hors dates du trimestre → 422 ; `allAt()` rend [séquence, trimestre, année] |
| T801 [P] | SS2 Organisation : `PeriodKind::Year` (hors `higher`), une par année, parent des trimestres | T800 | 2e période `year` → 422 ; `higher` refuse `year` ; tests existants verts |
| T802 [P] | SS7 Billing : `Domain/KindPriorityRule.php`, `AllocationRules::byName`, paramètres `billing.allocation_kind_order`, `billing.allocation_sibling_order` | — | `TU/../KindPriorityRuleTest` (ordre) ; défaut `registration_overdue_upcoming` inchangé |
| T803 [P] | SS8 Billing : `Contracts/FeeItemGuard.php`, `FeeItemDraft`, appel dans `FeeItemController` store/update, préfixe `billing.fee_item_guards.` | — | garde fictif → 422 `fee_not_authorized` ; sans garde : comportement actuel |
| T804 [P] | SS12 + SS14 Documents : `POST /documents/{id}/rectify`, état `replaced` visible au QR ; `POST /documents/{id}/download-link`, `GET /public/documents/{token}` (TTL `documents.download_link_ttl_hours` = 24) | — | lien expiré à 24 h + 1 s → 410 ; rectificatif : nouveau numéro, mention, numéro d'origine |
| T805 | SS3 Assessment : `Contracts/PeriodAggregatingStrategy.php`, calcul récursif d'une période parente dans `ResultService::compute` | T800, T801 | sans implémentation : résultats identiques ; avec stratégie de test : reçoit les enfants dans l'ordre `ordinal` |
| T806 | SS4 Assessment : `assessment.subject_ranks` (rang, moy./min/max par matière ; `class_avg/min/max` en `computed_extras`) | T805 | ex æquo par matière ; défaut `false` : `details` inchangé |
| T807 | SS5 + SS6 Assessment : `assessment.report_card_variables.*`, `assessment.report_card_types` ; `assessment.council_line_providers.*`, colonne `council_lines.line_extras` | T805 | saisie en séance jamais écrasée par `refresh` ; type de document par nature de période |
| T808 [P] | SS9 Identity : `database/migrations/<date>_roles_secondary.sql` (R01-R24 hors élève/parent existants), `RolePermissionsTest` étendu, `web/src/lib/permissions.ts` (édition ciblée) | T810 | chaque rôle a exactement les permissions de la matrice du CDC |
| T809 [P] | SS11 Messaging : `<date>_secondary_message_templates.sql` (NOT-01 à 14 FR/EN), variables `TemplateRenderer::ALLOWED` ; SS13 Identity `role_assignments.expires_on` ; SS10 Attendance si retenu (Q14) | — | rendu de chaque modèle ; affectation échue ignorée par `PermissionScopes` |
| T810 | Squelette `S/` : `SecondaryApplication` (manifeste `platform.applications.secondary`, statut `available` à l'ouverture du pilote), `definitions.php`/`routes.php` racine (glob), `Support/` (WriteGate, GatedAction, SecondaryRoutes, Perimeter, ScopedByClass), `Policies/` (RoleMatrix, ScopeResolver), dossiers des 17 sous-modules | — | `TF/Core/WriteGateTest` (suspendue 423, fermée 403) |
| T811 | `tests/Architecture/SecondaryConventionsTest.php` (préfixe `se_`, tenant/establishment, mots réservés, Contracts seulement, aucun seuil littéral hors `Defaults`), `tests/Isolation/SecondaryScopeTest.php`, `tests/Support/Secondary/` (fabrique d'établissement lycée/CES/CETIC, public/privé) | T810 | — |
| T812 | Stratégie `S/Councils/Domain/SecondaryStrategy.php` (`RuleStrategy` + `PeriodAggregatingStrategy`), `Defaults.php` (valeurs du CDC), enregistrée `assessment.rule_strategies.secondary` | T805 | tests T940-T944 |

## Lot E1 : entrée et finances (ETB, INS, CSC, FIN, portail inscription/paiement)
| ID | Tâche et fichiers | Dépend | Tests |
|----|-------------------|--------|-------|
| T820 [P] | Setup : migrations (`se_establishment_profiles`, `se_streams`, `se_class_profiles`, `se_subject_coefficients`, `se_room_profiles`), dépôts `TenantTable` | T810 | isolation tenant (404) |
| T821 [P] | Setup : routes `/secondary/setup/profile`, `/streams`, `/classes`, `/coefficients`, `/rooms` ; écriture des classes par `StructureWriter`, matières par `CurriculumCatalog` | T820 | RG-ORG-1 (CES + 2nde → 422) ; RG-ORG-2 ; RG-ORG-4 (coef verrouillé → 422 ; projet = 0) |
| T822 | Setup : calendrier (3 trimestres, 6 séquences, année) via contrats Organisation, règlement intérieur (paramètres `secondary.*`), version de règles `strategy: secondary` par niveau | T821, T801 | séquence 3 rattachée au trimestre 2 |
| T823 [P] | Enrolment : migrations `se_student_profiles`, `se_preregistrations`, `se_transfers`, `se_orientations` | T810 | — |
| T824 | Enrolment : préinscription, test d'entrée [PRI], inscription (API-01 `POST /secondary/enrolments`), pièces | T823, T821 | parcours complet ; [PUB] aucun refus pour impayé (RG-INS-4) |
| T825 | Enrolment : garde `organisation.enrolment.guards.secondary` (autorisation RG-ORG-3, type RG-ORG-1, exclusion RG-INS-3, âge RG-INS-2 → alerte + décision) | T824 | série non autorisée → refus ; exclu sans décision → refus |
| T826 | Enrolment : matricule (API-02 `PUT /secondary/students/{id}/national-id`), statut `pending`, liste à régulariser | T824 | RG-INS-1 ; matricule absent de toute URL et réponse publique |
| T827 | Enrolment : réinscription (autorisés par le conseil, `AdmissionDirectory`), transferts/exeat, réception d'un dossier du primaire (format d'échange JSON versionné) | T825 | EX-INS-02, 04, 05 |
| T828 [P] [B Q1] | CarteScolaire : migrations `se_cs_*`, import matricules (CSV/XLSX, colonnes paramétrées), liste 6e/Form 1/1re année sans matricule | T826 | EX-CSC-01, 02 ; fichier rejoué = idempotent (hash) |
| T829 [B Q1] | CarteScolaire : import paiements (API-03), rapprochement, statut des frais d'examen, écarts (EX-CSC-05), RG-CSC-2 | T828 | ligne inconnue → `unknown` ; doublon de référence ignoré |
| T830 [P] | Fees : natures `billing.fee_kinds.secondary` (`apee`, `workshop`, `uniform`, `boarding`, `canteen`, `transport`, `annex`) ; grilles [PRI] par niveau/série via `/fee-items` ; paramètre `billing.allocation_rule = kind_priority` | T802 | T945 |
| T831 [B Q4] | Fees : `se_fee_catalog`, `se_fee_requests`, garde `billing.fee_item_guards.secondary` [PUB] | T803 | T946 |
| T832 | Fees : plans de paiement, relances SMS graduées, blocages [PRI] (`documents.issuance_guards.secondary`, section de portail), jamais de blocage de cours/évaluations | T830 | RG-FIN-1, RG-DOC-4 |
| T833 [P] | Documents E1 : `S/Documents/SecondaryDocumentTypes` (`secondary.document_types`), modèles FR/EN DOC-01 à 06, 27 à 29 | T810 | une émission par type ; QR selon le CDC |
| T834 | Portails : sections `portals.sections.secondary` (inscription, paiements, documents) ; NOT-01, NOT-09 | T824, T830 | parent ne voit que ses enfants |
| T835 | OpenAPI `secondary-setup.yaml`, `secondary-enrolment.yaml`, `secondary-cartescolaire.yaml`, `secondary-fees.yaml` | T821-T832 | `tests/ApiContract` |

## Lot E2 : vie scolaire (EDT, CTX, PRE, NOT)
| ID | Tâche et fichiers | Dépend | Tests |
|----|-------------------|--------|-------|
| T860 [P] | Timetable : générateur assisté (volumes `se_subject_coefficients.weekly_hours`, disponibilités, vacataires), écriture `TimetableWriter`, dédoublement atelier | T821 | 10 classes sans conflit ; conflit salle/atelier → 409 |
| T861 | Timetable : remplacements et permutations, NOT-14, DOC-07 | T860, T809 | — |
| T862 [P] | Syllabus : migrations `se_progressions`, `se_progression_lessons`, `se_lesson_logs`, `se_lesson_visas` ; routes ; API-06 `POST /secondary/lesson-logs` (hors ligne `client_uuid`) | T821 | séance d'une classe non affectée → 403 journalisé |
| T863 | Syllabus : couverture (EX-CTX-03), visas, lecture R21 (portée `territory` ou affectation datée), NOT-12, DOC-25, DOC-26 | T862 | 12 h faites / 30 h prévues → 40,00 % |
| T864 [P] | Discipline : migrations `se_conduct_proposals`, `se_discipline_councils`, `se_class_exclusions` | T810 | — |
| T865 | Discipline : barème (paramètre), propositions sur `AttendanceSummaryProvider`, sanction du socle créée en `proposed` | T864 | T944 |
| T866 | Discipline : conseil de discipline (API-15), convocation NOT-06, PV DOC-17, notification DOC-18, RG-PRE-3 ; NOT-03 ; DOC-15 | T865 | exclusion définitive sans PV → refus |
| T867 | Attendance : façade API-05 `PUT /secondary/classes/{id}/attendance/{date}/{hour}` (ou appel direct `/attendance-sessions`, décision D4), exclusions de cours, infirmerie | T864 | appel hors ligne rejoué sans doublon |
| T868 [P] | Grades : cycle `assessment.lifecycle` (5 états + `not_evaluated`, 2FA au verrouillage), `assessment.segregate_lock`, codes `fraud`/`exempt`/`replacement`, `assessment.step = 0.25` posés à l'ouverture | T810 | T948 (TST-09) ; 12,3 → 422 (pas de 0,25) |
| T869 | Grades : façades API-07, API-08 ; fraude → incident pour le surveillant général (RG-NOT-3) ; chaîne de correction censeur → chef (EX-NOT-06) | T868 | correction : ancienne valeur conservée |
| T870 | Grades : épreuves communes (`se_common_tests`), lettres [EN] (`secondary.grades.letter_scale`), NOT-04, NOT-11, DOC-08 | T868 | — |
| T871 | OpenAPI `secondary-timetable.yaml`, `secondary-syllabus.yaml`, `secondary-discipline.yaml`, `secondary-grades.yaml` | T860-T870 | `tests/ApiContract` |
| T884 | Vérifier `AbsenceNotifier` : un seul SMS par élève et par jour à la 1re heure (EX-PRE-03) ; sinon demande au propriétaire Attendance | T867 | 3 heures d'absence le même jour → 1 SMS |

## Tests d'acceptation chiffrés (écrits avant le code concerné)
| ID | Test (`TA/`) | Valeurs exactes du CDC |
|----|--------------|------------------------|
| T940 | `AichaTrimesterTest` (TST-01) : 11 matières, coef 4,3,4,2,2,2,1,1,1,1,1 ; séquences (12;10,5) (9;11) (8,5;7) (11;13) (14;12,5) (10;12) (13;11) (15;14) (16;15) (12;14) (14;16) | moyennes 11,25 / 10,00 / 7,75 / 12,00 / 13,25 / 11,00 / 12,00 / 14,50 / 15,50 / 13,00 / 15,00 ; total 248,50 ; Σ coef 22 ; moyenne 11,30 ; proposition : aucune ; alerte maths 7,75 |
| T941 | `AichaYearTest` (TST-02) | trimestres 11,30 ; 11,20 ; 12,05 → 11,52 ; « admise » |
| T942 | `BriceCetic3Test` (TST-03) : coef 2,2,3,2,4,3,6 ; séquences (11;10) (10;9) (9;11) (10;10,5) (12;11,5) (13;12) (7;8,5) | 10,50 / 9,50 / 10,00 / 10,25 / 11,75 / 12,50 / 7,75 ; total 221,50 ; moyenne 10,07 ; alerte pratique < 8 ; en fin d'année avec pratique annuelle 7,75 → refus proposé, passage conditionnel possible |
| T943 | `TieRankTest` (TST-04) + `PracticalGridTest` | ex æquo 3e, 3e, suivant 5e ; grille 3+2+4+2+1 = 12/20 ; même grille avec manquement grave → 8 |
| T944 | `ConductScaleTest` (TST-10) | 10 h → avertissement ; 20 h → blâme (appliqué seulement après décision) ; 30 h → exclusion 3 jours ; 31 h → conseil de discipline |
| T945 | `FamilyAllocationTest` (TST-07) | 3 500 ×2 ; pensions 150 000 (60 000/50 000/40 000) et 135 000 (54 000/45 000/36 000) ; APEE 10 000 ×2 ; totaux 163 500 et 148 500 ; 120 000 le 25/09 → 7 000 + 20 000 + 93 000 ; reste dû au 30/09 : 21 000 |
| T946 | `PublicFeeCatalogTest` (TST-06) | « reprographie » hors catalogue → 422, `se_fee_requests` créée, audit |
| T947 | `ReferenceCalculationTest` (NF-S5) : 100 classes aléatoires (graine fixe) comparées à `tests/Support/Secondary/ReferenceCalculator.php` écrit indépendamment | écart 0,00 sur toutes les moyennes et rangs |
| T948 | `ForeignClassGradeTest` (TST-09) + `UnlockedReportCardTest` (TST-05) | 403 + audit ; validation du conseil avec épreuve non verrouillée → refus |
| T949 | `ExamFeeNotSeenTest` (TST-08) + `NoExclusionForDebtTest` (RG-FIN-1) | candidat BAC sans paiement constaté → `blocked` « frais non constatés » ; aucune route d'exclusion pour impayé |

## Lot E3 : résultats et technique (BUL, PAS, EXA, ATL, STG, RHP)
| ID | Tâche et fichiers | Dépend | Tests |
|----|-------------------|--------|-------|
| T900 | Councils : options de la stratégie (arrondi par étape, matière non évaluée, composition en séquence 6, distinctions, refus si conduite, passage, pratique) ; version de règles par niveau | T812 | T940-T943 |
| T901 | Councils : API-09 `POST /secondary/periods/{id}/compute` (façade `RuleCalculator`/route `/results`), alerte matière < 10 au professeur principal et aux parents | T900 | — |
| T902 | Councils : fournisseur `assessment.council_line_providers.secondary` (conduite depuis Discipline, heures, pratique, refus de distinction) ; appréciations par matière et banque | T807, T865 | saisie en séance conservée |
| T903 | Councils : API-10 décisions, PV DOC-13, dossier DOC-12 (distribution, couverture T863, assiduité, cas), tableau d'honneur DOC-14 | T902 | — |
| T904 | Councils : bulletins DOC-09/10/11 FR/EN (variables SS5), instantanés `se_report_snapshots`, rectificatif (SS12), publication API-11, NOT-05 SMS, API-12 liens 24 h (SS14) | T903, T804 | RG-BUL-1 ; RG-DOC-3 ; RG-DOC-5 |
| T905 [P] | Councils PAS : orientation (vœux, avis, DOC-16, entretien), recours (`secondary.appeal_days`), `organisation.closure.promotion.secondary` | T903 | recours hors délai → 422 |
| T906 [P] [B Q3] | OfficialExams : migrations, sessions, candidats (RG-EXA-1), contrôles (photo, matricule, frais via `se_cs_payments`), API-13 export, DOC-19, DOC-20, NOT-10 | T829 | T949 |
| T907 [B Q3] | OfficialExams : import des résultats, taux de réussite, comparaison moyenne/examen, DOC-31, NOT-13 | T906 | RG-EXA-2 : aucun document « diplôme » |
| T908 [P] [B Q5] | Workshops : migrations ateliers, postes, séances (API-14), EPI (RG-ATL-1), fiches TP DOC-23, registre de sécurité | T821 | élève sans EPI → observation, pas de poste machine |
| T909 [B Q5] | Workshops : stock de matière d'œuvre (coût par élève), DOC-24 ; évaluation pratique (grille → note, plafond) alimentant une épreuve du socle | T908 | T943 |
| T910 [P] | Workshops STG : entreprises, conventions DOC-21, visites, DOC-22, note de stage par coefficient (EX-STG-03) | T821 | — |
| T911 [P] | Staff : `se_staff_profiles`, heures validées par le censeur depuis `se_lesson_logs` avec appel fait (`TaughtHoursRecorder`), `staff.vacation.payability_checks.secondary` | T863 | séance sans appel → non payable |
| T912 [B Q9] | Staff : paie simplifiée [PRI] `se_payslips`, DOC-30 | T911 | net = brut − retenues paramétrées |
| T913 | Documents E3 : modèles restants ; page de vérification sans notes (RG-DOC-2) | T904 | la page publique ne contient aucune note |
| T914 | OpenAPI `secondary-councils.yaml`, `secondary-exams.yaml`, `secondary-workshops.yaml`, `secondary-staff.yaml` | T900-T912 | `tests/ApiContract` |

## Lot E4 : pilotage, services, pilote
| ID | Tâche et fichiers | Dépend | Tests |
|----|-------------------|--------|-------|
| T970 [P] | Dashboards : `reporting.indicators.secondary` (couverture, réussite, recouvrement, absentéisme, O1-O7), agrégats réseau RG-ORG-5 | E3 | aucune donnée nominative au réseau |
| T971 [B Q1, Q12] | DOC-32 rapport de rentrée, DOC-33 fichier de fin d'année (API-04), DOC-34 rapport annuel ; alerte RG-BUL-4 | T970, T904 | — |
| T972 [P] | Services : internat, abonnements cantine/transport (facturés par `InvoiceIssuer`), bibliothèque | T830 | — |
| T973 [P] | Services : infirmerie (données visibles de l'infirmier seul, dispense EPS → code `exempt`) | T867 | autre rôle → 403 |
| T974 | Portails élève/parent/enseignant complets (notes publiées, bulletins, absences, devoirs, todo `portals.grading_todo.secondary`) | T904 | — |
| T975 | Charge : `tests/Load/Secondary/` NF-S1 (3 000 élèves < 60 s), NF-S2 (3 000 PDF < 15 min), NF-S3 (300 saisies simultanées, `scripts/loadtest/k6-api.js`), sur MySQL (`phpunit.mysql.xml`) | E3 | seuils du CDC |
| T976 | Hors ligne NF-S4 : Playwright `web/e2e/secondary-*.spec.ts` (appel, notes, cahier de textes) | E2 | 0 perte, conflits 409 conservés |
| T977 | Reprise de données (import élèves, matricules, soldes) et `console.command.secondary-import` | E1 | — |
| T978 | Écrans front `web/src/views/secondary/*` par lot (éditions ciblées de `router/routes.ts`, `i18n/*`) | lots | vue-tsc, eslint, vitest, build |
| T979 | Recette pilote (Q10) : lycée privé, établissement public, lycée technique ou CETIC ; rapport `specs/005-application-secondaire/recette.md` | tout | TST-01 à TST-10 verts |

T980 à T999 : réserve (corrections de recette, questions tardives).
