# Tâches : application Enseignement secondaire (un produit, 7 types)

**Entrées** : [spec.md](spec.md), [plan.md](plan.md), [data-model.md](data-model.md), [socle-reuse.md](socle-reuse.md), [audit-reutilisation.md](audit-reutilisation.md), [questions.md](questions.md). Date : 2026-10-22 (remplace la version du 2026-10-15). **Numérotation** : T800 à T999 (la maternelle privée utilise T1000 et suivants). **Format** : `ID [P] [B Qn]` ; `[P]` = parallélisable dans sa vague ; `[B Qn]` = la partie chiffrée attend la réponse Qn (le code démarre avec la valeur par défaut « à confirmer »). Chemins : `S/` = `src/Modules/Apps/Secondary/`, `TF/` = `tests/Feature/Secondary/`, `TU/` = `tests/Unit/Secondary/`, `TA/` = `tests/Acceptance/Secondary/`. Chaque tâche finit par php-cs-fixer (ses fichiers seulement), phpstan 0 erreur sans baseline, phpunit vert, fichiers en LF.

## Résumé pour les non-techniciens
- Les tâches sont rangées en **vagues** : la vague 0 prépare le socle et le squelette (dont le **profil de type** et les **modules activables**) ; E1 à E4 livrent les fonctions par lot.
- **Nouveau** par rapport à la version du 15 octobre : T813 à T815 (profil de type, garde de module, ouverture), T836 à T838 (assistant et menu), T950 à T958 (tests par type). Les autres tâches sont conservées et précisées.
- **Aucune valeur officielle** dans le code : les chiffres du CDC des tests T940 à T949 sont des **jeux d'exemple `example_cdc`** fournis par les tests.
- Plusieurs changements du socle sont **déjà livrés** (voir [audit-reutilisation.md](audit-reutilisation.md)) : les tâches correspondantes se réduisent à une vérification et aux ajouts propres au secondaire.

## Vague 0 : socle (agents propriétaires) et squelette
| ID | Tâche et fichiers | Dépend | Tests |
|----|-------------------|--------|-------|
| T800 [P] | SS1 Organisation : hiérarchie des périodes. **Déjà livrée** (`periods.parent_id`, migration `202610201101`) : vérifier validation séquence ⊂ trimestre et `CurrentPeriod::allAt()` ; compléter seulement ce qui manque | — | séquence hors dates du trimestre → 422 ; `allAt()` rend [séquence, trimestre, année] |
| T801 [P] | SS2 Organisation : `PeriodKind::Year` (hors `higher`), une par année, parent des trimestres | T800 | 2e période `year` → 422 ; `higher` refuse `year` ; tests existants verts |
| T802 [P] | SS7 Billing : `Domain/KindPriorityRule.php`, `AllocationRules::byName`, paramètres `billing.allocation_kind_order`, `billing.allocation_sibling_order` | — | `TU/../KindPriorityRuleTest` (ordre) ; défaut `registration_overdue_upcoming` inchangé |
| T803 [P] | SS8 Billing : `Contracts/FeeItemGuard.php`, `FeeItemDraft`, appel dans `FeeItemController` store/update, préfixe `billing.fee_item_guards.` | — | garde fictif → 422 `fee_not_authorized` ; sans garde : comportement actuel |
| T804 [P] | SS12 + SS14 Documents : confirmer `POST /documents/{id}/rectify` (via `DocumentReissuer`) et l'état `replaced` visible au QR ; ajouter `POST /documents/{id}/download-link` et `GET /public/documents/{token}` (durée `documents.download_link_ttl_hours`) | — | lien expiré → 410 ; rectificatif : nouveau numéro, mention, numéro d'origine |
| T805 | SS3 Assessment : **contrat livré** (`PeriodAggregatingStrategy::aggregatePeriods(rules, periods)`, appel dans `ResultService`) ; vérifier le calcul récursif année → trimestres → séquences et la présence des moyennes par matière dans les résumés ; ajouter ce qui manque | T800, T801 | stratégie de test reçoit les enfants dans l'ordre ; sans implémentation : résultats identiques |
| T806 | SS4 Assessment : `assessment.subject_ranks` (rang, moyenne, min, max par matière ; statistiques de classe en `computed_extras`) | T805 | ex æquo par matière ; défaut `false` : `details` inchangé |
| T807 | SS5 + SS6 Assessment : **contrats livrés** (`ReportCardVariables`, `CouncilLineProvider`) ; généraliser le préfixe des variables (`primary_` → `<application>_`) ; `assessment.report_card_types` par nature de période ; colonne `council_lines.line_extras` | T805 | saisie en séance jamais écrasée par `refresh` ; type de document par nature de période |
| T808 [P] | SS9 Identity : `database/migrations/<date>_roles_secondary.sql` (R01 à R24 hors rôles existants), `RolePermissionsTest` étendu, `web/src/lib/permissions.ts` (édition ciblée) | T810 | chaque rôle a exactement les permissions de la matrice du CDC |
| T809 [P] | SS11 Messaging : `<date>_secondary_message_templates.sql` (14 notifications FR/EN), variables `TemplateRenderer::ALLOWED` ; SS13 Identity `role_assignments.expires_on` ; SS10 Attendance si retenu (Q14) | — | rendu de chaque modèle ; affectation échue ignorée par `PermissionScopes` |
| T810 | Squelette `S/` : `SecondaryApplication` (manifeste `platform.applications.secondary`, statut `available` à l'ouverture du pilote), `definitions.php`/`routes.php` racine (glob), `Support/` (WriteGate, GatedAction, SecondaryRoutes, Perimeter, ScopedByClass), `Policies/` (RoleMatrix, ScopeResolver), dossiers des sous-modules | — | `TF/Core/WriteGateTest` (suspendue 423, fermée 403) |
| T811 | `tests/Architecture/SecondaryConventionsTest.php` (préfixe `se_`, tenant et établissement, mots réservés, contrats seulement, aucun seuil littéral hors `Defaults`), `tests/Isolation/SecondaryScopeTest.php`, `tests/Support/Secondary/` | T810 | — |
| T812 | Stratégie `S/Councils/Domain/SecondaryStrategy.php` (`RuleStrategy` + `PeriodAggregatingStrategy`), `Defaults.php` (valeurs d'exemple étiquetées), enregistrée `assessment.rule_strategies.secondary` | T805 | tests T940 à T944 |
| **T813** | **Profil de type** : données `secondary.type_profiles.<code>` (7 profils, valeurs par défaut « à confirmer » Q18), `S/Setup/Application/ProfileResolver` (seul lecteur du code de type), `S/Support/ModuleGate` (409 `module_not_enabled`), liste fermée des modules ([spec.md](spec.md) §1.3) | T810 | profil lu pour chacun des 7 codes ; type inconnu → refus ; module inactif → 409 |
| **T814** | Test d'architecture : toute route d'un module activable passe par `ModuleGate` ; aucun test du code de type hors `ProfileResolver` (RG-SEC-3, D9) | T813, T811 | échoue sur une route non gardée et sur un `if` sur un code de type |
| **T815** | Ouverture de l'application : lecture du type (`EstablishmentDirectory::find()->subtype`), copie du profil dans `se_establishment_profiles`, ligne `opened` dans `se_profile_changes` ; propriété obligatoire pour `autre` | T813, T820 | ouverture d'un établissement de chaque type ; `autre` sans propriété → 422 |

## Lot E1 : entrée et finances (ETB, INS, CSC, FIN, portail inscription et paiement)
| ID | Tâche et fichiers | Dépend | Tests |
|----|-------------------|--------|-------|
| T820 [P] | Setup : migrations (`se_establishment_profiles`, `se_profile_changes`, `se_streams`, `se_class_profiles`, `se_subject_coefficients`, `se_room_profiles`), dépôts `TenantTable` | T810 | isolation tenant (404) |
| T821 [P] | Setup : routes `/secondary/setup/profile`, `/streams`, `/classes`, `/coefficients`, `/rooms` ; classes écrites par `StructureWriter`, matières par `CurriculumCatalog` ; **cycle de la classe contrôlé contre le profil** | T820 | classe d'un cycle absent du profil → 422 (RG-ORG-1) ; RG-ORG-2 ; coefficient verrouillé → 422, matière de projet hors moyenne (RG-ORG-4) |
| T822 | Setup : calendrier (trimestres, séquences, année) via contrats Organisation, règlement intérieur (paramètres `secondary.*`), version de règles `strategy: secondary` par niveau | T821, T801 | séquence rattachée au bon trimestre |
| T823 [P] | Enrolment : migrations `se_student_profiles`, `se_preregistrations`, `se_transfers`, `se_orientations` | T810 | — |
| T824 | Enrolment : préinscription, test d'entrée [PRI], inscription (API-01 `POST /secondary/enrolments`), pièces | T823, T821 | parcours complet ; PUB : aucun refus pour impayé (RG-INS-4) |
| T825 | Enrolment : garde `organisation.enrolment.guards.secondary` (autorisation RG-ORG-3 si module `authorizations`, cycle du profil RG-ORG-1, exclusion RG-INS-3, âge RG-INS-2 → alerte + décision) | T824 | série non autorisée → refus ; exclu sans décision → refus |
| T826 | Enrolment : matricule (API-02 `PUT /secondary/students/{id}/national-id`), statut `pending`, liste à régulariser | T824 | RG-INS-1 ; matricule absent de toute adresse et réponse publique |
| T827 | Enrolment : réinscription (autorisés par le conseil, `AdmissionDirectory`), transferts et exeat, réception d'un dossier du primaire (fichier d'échange versionné) | T825 | EX-SEC-14, 16, 17 |
| T828 [P] [B Q1] | CarteScolaire : migrations `se_cs_*`, import des matricules (CSV/XLSX, colonnes paramétrées), liste des niveaux d'entrée sans matricule | T826 | EX-SEC-20 ; fichier rejoué : idempotent (empreinte) |
| T829 [B Q1] | CarteScolaire : import des paiements (API-03), rapprochement, statut des frais d'examen, écarts, RG-CSC-2 | T828 | ligne inconnue → `unknown` ; doublon de référence ignoré |
| T830 [P] | Fees [`fees_private`] : natures `billing.fee_kinds.secondary` ; grilles par niveau, série ou spécialité via `/fee-items` ; `billing.allocation_rule = kind_priority` | T802, T813 | T945 ; route d'un établissement sans le module → 409 |
| T831 [B Q4] | Fees [`fees_public_catalog`] : `se_fee_catalog`, `se_fee_requests`, garde `billing.fee_item_guards.secondary` | T803, T813 | T946 |
| T832 | Fees : plans de paiement, relances SMS graduées, blocages de remise de documents [PRI] (`documents.issuance_guards.secondary`), jamais de blocage de cours ni d'évaluations | T830 | RG-FIN-1, RG-DOC-4 |
| T833 [P] | Documents E1 : `S/Documents/SecondaryDocumentTypes` (`secondary.document_types`), modèles FR/EN DOC-01 à 06, 27 à 29 | T810 | une émission par type ; QR selon le CDC |
| T834 | Portails : sections `portals.sections.secondary` (inscription, paiements, documents) ; notifications 01 et 09 | T824, T830 | parent ne voit que ses enfants |
| T835 | OpenAPI `secondary-setup.yaml`, `secondary-enrolment.yaml`, `secondary-cartescolaire.yaml`, `secondary-fees.yaml` | T821 à T832 | `tests/ApiContract` |
| **T836** | Front : **assistant de profil** (`web/src/views/secondary/setup/`) : type, propriété, cycles, modules, explications en langage clair ; textes `secondary-{fr,en}.ts` | T815 | vue-tsc, eslint, vitest ; parcours des 7 types ; 390 px |
| **T837** | Front : **menu qui suit les modules actifs** et vocabulaire par type (proviseur ou principal) ; entrées de menu absentes pour un module inactif | T836 | test de routes : aucune entrée d'un module inactif ; rôles non concernés masqués |
| **T838** | Changement de profil après ouverture : route `/secondary/setup/profile/changes`, motif obligatoire, module coupé avec données en lecture seule, écran d'historique | T815, T836 | EX-SEC-04 : rien n'est supprimé ; journal complet |

## Lot E2 : vie scolaire (EDT, CTX, PRE, NOT)
| ID | Tâche et fichiers | Dépend | Tests |
|----|-------------------|--------|-------|
| T860 [P] | Timetable : générateur assisté (volumes `se_subject_coefficients.weekly_hours`, disponibilités, vacataires), écriture `TimetableWriter`, dédoublement d'atelier [`workshops`] | T821 | 10 classes sans conflit ; conflit salle ou atelier → 409 |
| T861 | Timetable : remplacements et permutations, notification 14, DOC-07 | T860, T809 | — |
| T862 [P] | Syllabus : migrations `se_progressions`, `se_progression_lessons`, `se_lesson_logs`, `se_lesson_visas` ; routes ; API-06 `POST /secondary/lesson-logs` (hors ligne `client_uuid`) | T821 | séance d'une classe non affectée → 403 journalisé |
| T863 | Syllabus : couverture, visas, lecture R21 (portée `territory` ou affectation datée), notification 12, DOC-25, DOC-26 | T862 | heures faites / heures prévues : calcul exact sur jeu d'exemple |
| T864 [P] | Discipline : migrations `se_conduct_proposals`, `se_discipline_councils`, `se_class_exclusions` | T810 | — |
| T865 | Discipline : barème (paramètre), propositions sur `AttendanceSummaryProvider`, sanction du socle créée en `proposed` | T864 | T944 |
| T866 | Discipline : conseil de discipline (API-15), convocation (notification 06), procès-verbal DOC-17, notification de sanction DOC-18, RG-PRE-3 ; notification 03 ; DOC-15 | T865 | exclusion définitive sans procès-verbal → refus |
| T867 | Attendance : façade API-05 `PUT /secondary/classes/{id}/attendance/{date}/{hour}` (ou appel direct `/attendance-sessions`, décision D4), exclusions de cours, infirmerie [`infirmary`] ; mode `slot` conservé | T864 | appel hors ligne rejoué sans doublon |
| T868 [P] | Grades : cycle `assessment.lifecycle`, `assessment.segregate_lock`, codes `fraud`/`exempt`/`replacement`, `assessment.step` posés à l'ouverture (valeurs en paramètres) | T810 | T948 (TST-09) ; note hors pas → 422 |
| T869 | Grades : façades API-07 et API-08 ; fraude → incident pour le surveillant général (RG-NOT-3) ; chaîne de correction censeur puis chef | T868 | correction : ancienne valeur conservée |
| T870 [B Q7] | Grades : épreuves communes (`se_common_tests`), lettres [EN] (`secondary.grades.letter_scale`), notifications 04 et 11, DOC-08 | T868 | — |
| T871 | OpenAPI `secondary-timetable.yaml`, `secondary-syllabus.yaml`, `secondary-discipline.yaml`, `secondary-grades.yaml` | T860 à T870 | `tests/ApiContract` |
| T884 | Vérifier `AbsenceNotifier` : un seul SMS par élève et par jour à la 1re heure ; sinon demande au propriétaire Attendance | T867 | 3 heures d'absence le même jour : 1 SMS |

## Tests d'acceptation chiffrés (écrits avant le code concerné) : jeux d'exemple `example_cdc`
Valeurs **du cahier des charges, non officielles**, fournies par les tests ; le code n'en contient aucune.
| ID | Test (`TA/`) | Valeurs d'exemple du CDC |
|----|--------------|--------------------------|
| T940 | `AichaTrimesterTest` (TST-01) : 11 matières, coef 4,3,4,2,2,2,1,1,1,1,1 ; séquences (12;10,5) (9;11) (8,5;7) (11;13) (14;12,5) (10;12) (13;11) (15;14) (16;15) (12;14) (14;16) | moyennes 11,25 / 10,00 / 7,75 / 12,00 / 13,25 / 11,00 / 12,00 / 14,50 / 15,50 / 13,00 / 15,00 ; total 248,50 ; Σ coef 22 ; moyenne 11,30 ; proposition : aucune ; alerte sur la matière à 7,75 |
| T941 | `AichaYearTest` (TST-02) | trimestres 11,30 ; 11,20 ; 12,05 → 11,52 ; « admise » avec le jeu d'exemple |
| T942 | `BriceCetic3Test` (TST-03) : coef 2,2,3,2,4,3,6 ; séquences (11;10) (10;9) (9;11) (10;10,5) (12;11,5) (13;12) (7;8,5) | 10,50 / 9,50 / 10,00 / 10,25 / 11,75 / 12,50 / 7,75 ; total 221,50 ; moyenne 10,07 ; alerte pratique ; refus proposé en fin d'année si pratique annuelle sous le seuil d'exemple, passage conditionnel possible |
| T943 | `TieRankTest` (TST-04) + `PracticalGridTest` | ex æquo 3e, 3e, suivant 5e ; grille 3+2+4+2+1 = 12/20 ; même grille avec manquement grave → plafond d'exemple |
| T944 | `ConductScaleTest` (TST-10) | quatre paliers d'heures du barème d'exemple ; sanction proposée, appliquée seulement après décision |
| T945 | `FamilyAllocationTest` (TST-07) | 3 500 ×2 ; pensions 150 000 (60 000/50 000/40 000) et 135 000 (54 000/45 000/36 000) ; 10 000 ×2 ; totaux 163 500 et 148 500 ; 120 000 le 25/09 → 7 000 + 20 000 + 93 000 ; reste dû au 30/09 : 21 000 |
| T946 | `PublicFeeCatalogTest` (TST-06) | frais « reprographie » hors catalogue → 422, `se_fee_requests` créée, audit |
| T947 | `ReferenceCalculationTest` (NF-S5) : 100 classes aléatoires (graine fixe) comparées à `tests/Support/Secondary/ReferenceCalculator.php` écrit indépendamment | écart 0,00 sur toutes les moyennes et rangs |
| T948 | `ForeignClassGradeTest` (TST-09) + `UnlockedReportCardTest` (TST-05) | 403 + audit ; validation du conseil avec épreuve non verrouillée → refus |
| T949 | `ExamFeeNotSeenTest` (TST-08) + `NoExclusionForDebtTest` (RG-FIN-1) | candidat sans paiement constaté → `blocked` « frais non constatés » ; aucune route d'exclusion pour impayé |

## Tests par type d'établissement (nouveaux)
| ID | Test | Critère |
|----|------|---------|
| **T950** | `tests/Support/Secondary/ProfileFactory` : un établissement de chacun des 7 types, public et privé, avec ses modules par défaut | utilisée par T951 à T958 |
| **T951** | TST-T1 `leg` : cycles 1 et 2, séries proposées, `workshops` inactif | route d'atelier → 409 `module_not_enabled` ; activation depuis l'assistant : la route répond |
| **T952** | TST-T2 `lycee_technique` : ateliers et stages actifs, spécialités proposées | séance d'atelier créée ; élève sans protection en observation (RG-ATL-1) |
| **T953** | TST-T3 `ces` : 1er cycle seulement, aucune série | classe de 2nd cycle → 422 ; aucun menu d'orientation |
| **T954** | TST-T4 `cetic` : 1er cycle technique, ateliers actifs | 2nd cycle refusé ; atelier disponible |
| **T955** | TST-T5 `college_prive` et TST-T6 `lycee_prive` : `fees_private`, `authorizations`, `payroll_simple` | inscription sans autorisation → refus ; garde de catalogue public inactive |
| **T956** | TST-T7 `autre` : aucune valeur par défaut au-delà du noyau | propriété et cycles exigés par l'assistant ; modules choisis à la main |
| **T957** | Changement de profil et module coupé (EX-SEC-04) | module coupé avec données : lecture seule, journal complet, rien supprimé |
| **T958** | Architecture : aucun `if` sur un code de type hors `ProfileResolver` ; chaque route de module gardée | échoue sur une violation (même test que T814, jeu élargi aux nouveaux modules) |

## Lot E3 : résultats et technique (BUL, PAS, EXA, ATL, STG, RHP)
| ID | Tâche et fichiers | Dépend | Tests |
|----|-------------------|--------|-------|
| T900 | Councils : options de la stratégie (arrondi par étape, matière non évaluée, composition en fin d'année, distinctions, refus si conduite, passage, pratique) ; version de règles par niveau | T812 | T940 à T943 |
| T901 | Councils : API-09 `POST /secondary/periods/{id}/compute` (façade `RuleCalculator` ou route `/results`), alerte matière sous le seuil paramétré | T900 | — |
| T902 | Councils : fournisseur `assessment.council_line_providers.secondary` (conduite depuis Discipline, heures, pratique, refus de distinction) ; appréciations par matière et banque | T807, T865 | saisie en séance conservée |
| T903 | Councils : API-10 décisions, procès-verbal DOC-13, dossier DOC-12, tableau d'honneur DOC-14 | T902 | — |
| T904 | Councils : bulletins DOC-09, 10, 11 FR/EN (variables `secondary_*`), instantanés `se_report_snapshots`, rectificatif (SS12), publication API-11, notification 05, API-12 liens à durée limitée (SS14) | T903, T804 | RG-BUL-1 ; RG-DOC-3 ; RG-DOC-5 |
| T905 [P] | Councils PAS [`orientation`] : orientation (vœux, avis, DOC-16, entretien), recours (`secondary.appeal_days`), `organisation.closure.promotion.secondary` | T903 | recours hors délai → 422 |
| T906 [P] [B Q3] | OfficialExams : migrations, sessions, candidats (RG-EXA-1), contrôles (photo, matricule, frais via `se_cs_payments`), API-13 export, DOC-19, DOC-20, notification 10 | T829 | T949 |
| T907 [B Q3] | OfficialExams : import des résultats, taux de réussite, comparaison moyenne et examen, DOC-31, notification 13 | T906 | RG-EXA-2 : aucun document « diplôme » |
| T908 [P] [B Q5] | Workshops [`workshops`] : migrations ateliers, postes, séances (API-14), EPI (RG-ATL-1), fiches TP DOC-23, registre de sécurité | T821, T813 | élève sans EPI → observation, pas de poste machine |
| T909 [B Q5] | Workshops : stock de matière d'œuvre (coût par élève), DOC-24 ; évaluation pratique (grille → note, plafond paramétré) alimentant une épreuve du socle | T908 | T943 |
| T910 [P] | Workshops STG [`internships`] : entreprises, conventions DOC-21, visites, DOC-22, note de stage par coefficient | T821, T813 | — |
| T911 [P] | Staff : `se_staff_profiles`, heures validées par le censeur depuis `se_lesson_logs` avec appel fait (`TaughtHoursRecorder`), `staff.vacation.payability_checks.secondary` | T863 | séance sans appel : non payable |
| T912 [B Q9] | Staff [`payroll_simple`] : paie simplifiée `se_payslips`, DOC-30 | T911 | net = brut − retenues paramétrées ; fonctionnaire exclu |
| T913 | Documents E3 : modèles restants ; page de vérification sans notes (RG-DOC-2) | T904 | la page publique ne contient aucune note |
| T914 | OpenAPI `secondary-councils.yaml`, `secondary-exams.yaml`, `secondary-workshops.yaml`, `secondary-staff.yaml` | T900 à T912 | `tests/ApiContract` |

## Lot E4 : pilotage, services, pilote
| ID | Tâche et fichiers | Dépend | Tests |
|----|-------------------|--------|-------|
| T970 [P] | Dashboards : `reporting.indicators.secondary` (couverture, réussite, recouvrement ou frais, absentéisme, O1 à O7), agrégats de réseau [`network_indicators`] | E3 | aucune donnée nominative au réseau |
| T971 [B Q1, Q12] | DOC-32 rapport de rentrée, DOC-33 fichier de fin d'année (API-04), DOC-34 rapport annuel ; alerte RG-BUL-4 | T970, T904 | — |
| T972 [P] | Services [`boarding`, `canteen_transport`, `library`] : internat, abonnements cantine et transport (facturés par `InvoiceIssuer`), bibliothèque | T830 | — |
| T973 [P] | Services [`infirmary`] : infirmerie (données visibles de l'infirmier seul, dispense d'EPS → code `exempt`) | T867 | autre rôle → 403 |
| T974 | Portails élève, parent, enseignant complets (notes publiées, bulletins, absences, devoirs, `portals.grading_todo.secondary`) | T904 | — |
| T975 | Charge : `tests/Load/Secondary/` NF-S1, NF-S2, NF-S3 (`scripts/loadtest/k6-api.js`), sur MySQL (`phpunit.mysql.xml`) | E3 | seuils du CDC à confirmer au pilote |
| T976 | Hors ligne NF-S4 : Playwright `web/e2e/secondary-*.spec.ts` (appel, notes, cahier de textes) | E2 | 0 perte, conflits 409 conservés |
| T977 | Reprise de données (import élèves, matricules, soldes) sur le modèle de `Socle/Imports` et de `Higher/Migration` ; `console.command.secondary-import` | E1 | — |
| T978 | Écrans front `web/src/views/secondary/*` par lot (éditions ciblées de `router/routes.ts`, `i18n/*`) | lots | vue-tsc, eslint, vitest, build |
| T979 | Recette pilote (Q10) : un établissement privé, un public, un technique ; rapport `specs/005-application-secondaire/recette.md` ; **HTTPS actif avant toute donnée réelle** | tout | TST-01 à TST-10 et TST-T1 à TST-T7 verts |

T980 à T999 : réserve (corrections de recette, questions tardives).
