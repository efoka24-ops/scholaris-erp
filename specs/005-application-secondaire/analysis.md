# Application Secondaire : couverture du CDC par le socle et écarts

Ce fichier tient lieu de `analysis.md` : l'outil d'écriture a refusé ce nom ; à renommer si besoin.

Date : 2026-10-15. Source qui fait foi : [cdc-secondaire.md](cdc-secondaire.md) (CDC v1.0 du 9 octobre 2026). Analyse en lecture seule du code réel (`src/Modules/Socle/*`, `src/Modules/Apps/Higher`, migrations). Écart voulu avec le CDC : PHP 8.1 sans framework, MySQL 8, Dompdf, pas de Redis ni Gotenberg, module `src/Modules/Apps/Secondary` (et non `app/Modules/...`), tables `se_*` ([HANDOVER](../HANDOVER.md) §2) ; noté, non rediscuté.

## Résumé (20 lignes)
1. Le socle a été pensé d'abord pour le secondaire : type `secondary`, vocabulaire (`élève`, `classe`, `trimestre`, `bulletin`), périodes `trimester`/`sequence`, appel par créneau horaire hors ligne, notes avec cycle déclarable et 2FA, corrections à plusieurs validations, conseil de classe, bulletins en file, frais, mobile money, relances, documents à QR.
2. Le catalogue contient déjà `secondary` (« Enseignement secondaire », `coming_soon`, 60 000 XAF/mois, 90 jours d'essai, 15 jours de grâce) : `Platform/Database/Migrations/202610083451_platform_catalog_seed.sql` ; `ApplicationManifest::TARGETS` l'inclut ; `src/Modules/Apps/Secondary/` est vide.
3. Couverture des 117 exigences et règles du CDC (81 EX + 36 RG) : **25 couvertes** par le socle, **45 partielles**, **47 à créer** (détail §2 et §3).
4. Écart 1 (bloquant BUL) : les périodes n'ont pas de hiérarchie (`periods` sans `parent_id`) ; le moteur calcule une période sur ses seules épreuves ; ni moyenne trimestrielle = moyenne des deux séquences, ni annuelle = moyenne des trois trimestres (SS1 à SS3, SS1 et SS3 communs avec PS1a/PS1b du primaire).
5. Écart 2 : le moteur standard (`RuleEngine::compute`) n'arrondit que le résultat final ; le CDC arrondit au centième « à chaque étape » : stratégie `secondary` (`assessment.rule_strategies.secondary`).
6. Écart 3 : pas de rang par matière ni de statistiques de classe par matière dans `results.details` (SS4).
7. Écart 4 : l'affectation des paiements ne connaît que inscription > retard > à venir (`Billing\Domain\RegistrationOverdueUpcomingRule`) ; RG-FIN-4 exige inscription > APEE > tranches > services, famille répartie tranche par tranche (SS7).
8. Écart 5 : aucun garde sur la création d'un frais (`/fee-items`) : le catalogue limité du public (RG-FIN-2, TST-06) demande `billing.fee_item_guards.*` (SS8).
9. Écart 6 : cartescolaire.cm : seul un export générique des inscrits existe (`Integrations\Contracts\CartescolaireExporter`) ; matricules à générer, import des paiements, rapprochement, fichier des conseils de fin d'année et contrôle de cohérence sont à créer, par fichiers seulement (RG-CSC-1).
10. Écart 7 : enseignement technique entièrement à créer (ateliers, postes, EPI, matière d'œuvre, grilles pratiques, plafond sécurité, stages).
11. Écart 8 : cahier de textes et couverture des programmes absents du socle (Higher a les siens dans `hi_session_logs`, non partageables).
12. Écart 9 : paie simplifiée du privé (EX-RHP-03, DOC-30) absente du socle malgré la mention « socle S10 » du CDC.
13. Réutilisés tels quels : `AuthorizationRegistry::covers` + `EnrolmentGuard` (RG-ORG-3), `Lifecycle` déclarable (`assessment.lifecycle`) avec `require_recent_2fa` (API-08), `assessment.segregate_lock` (qui saisit ne verrouille pas), `assessment.absence_codes` (fraude, dispensé), `assessment.step` (quart de point), chaîne de corrections (`CorrectionService`), `RuleEngine::ranks` (TST-04), `CouncilService::validate` refusé si une épreuve reste éditable (TST-05).
14. Mécanismes de Higher recopiés dans `Apps/Secondary/Support` : `WriteGate`, `GatedAction`, `SecondaryRoutes`, `Perimeter`, `ScopedByClass`, `Policies/RoleMatrix`.
15. Changements du socle demandés : 14 (SS1 à SS14, [socle-reuse.md](socle-reuse.md)), tous rétrocompatibles ; SS1 et SS3 sont PS1a/PS1b du primaire (une seule réalisation).
16. Tables propres : 50 tables `se_*` ([data-model.md](data-model.md)) ; notes, résultats, conseils, présences et sanctions restent dans les tables du socle.
17. Tâches T800 à T999 ([tasks.md](tasks.md)), 6 vagues de 3 agents au plus alignées sur E1 à E4 ([wave-plan.md](wave-plan.md)).
18. Les exemples chiffrés du CDC (Aïcha 248,50/22 = 11,30 ; annuelle 11,52 ; Brice 221,50/22 = 10,07 ; grille 12/20 ; 20 h → blâme ; 120 000 FCFA → reste 21 000) sont vérifiés à la main (§1) et deviennent des tests (T940 à T949).
19. Questions prioritaires : Q1 formats cartescolaire.cm, Q2 textes MINESEC (coefficients, passage, distinctions), Q3 formats des listes d'examen, Q4 catalogue des frais autorisés du public, Q5 spécialités et grilles du pilote ([questions.md](questions.md)).
20. Peut démarrer sans réponse : vague 0 (socle, squelette, rôles), ETB, INS, FIN privé, EDT, CTX, PRE, NOT et toute la stratégie de calcul (valeurs du CDC posées comme paramètres par défaut « à confirmer »).

**Légende** : C = couvert par le socle tel quel ; P = partiel (écart décrit) ; N = à créer dans `src/Modules/Apps/Secondary` ; SSn = changement du socle demandé.

## 1. Vérification des exemples chiffrés du CDC
| Exemple | Calcul refait | Conforme |
|---------|---------------|----------|
| Aïcha M., 4e B, T1 | 45 + 30 + 31 + 24 + 26,5 + 22 + 12 + 14,5 + 15,5 + 13 + 15 = 248,50 ; Σ coef = 22 ; 248,50 / 22 = 11,2954… → **11,30** (centième, demi vers le haut, `PHP_ROUND_HALF_UP`) | oui |
| Aïcha, année | (11,30 + 11,20 + 12,05) / 3 = 34,55 / 3 = 11,5166… → **11,52** ≥ 10 → « admise » | oui |
| Brice T., CETIC électricité | 21 + 19 + 30 + 20,5 + 47 + 37,5 + 46,5 = 221,50 ; / 22 = 10,068… → **10,07** ; pratique 7,75 < 8 → alerte | oui |
| Grille pratique | 3 + 2 + 4 + 2 + 1 = **12/20** ; plafond 8 si manquement grave (non coché dans l'exemple) | oui |
| Frais du privé | Enfant 1 : 3 500 + 150 000 + 10 000 = 163 500 ; enfant 2 : 3 500 + 135 000 + 10 000 = 148 500 ; tranches 60 000/50 000/40 000 et 54 000/45 000/36 000 | oui |
| 120 000 FCFA le 25/09 | inscriptions 7 000 + APEE 20 000 = 27 000 ; 93 000 sur les tranches 1 (60 000 + 54 000 = 114 000) ; reste dû au 30/09 : **21 000** | oui |

Points d'interprétation (voir questions.md) : ordre entre enfants d'une même famille (Q11) ; la bande 10 à moins de 12 ne donne aucune proposition (11,30 → « aucune ») ; la pratique sous 8 au trimestre donne une alerte, le refus n'est proposé qu'en fin d'année (sur la moyenne annuelle de pratique, Q8).

## 2. Exigences fonctionnelles (81)
| ID | St. | Ce qui existe (chemin vérifié) | Écart / à créer |
|----|-----|-------------------------------|-----------------|
| EX-ETB-01 | P | `Organisation\Domain\EstablishmentType::Secondary` ; `/org-units` (sections FR/EN) ; branding | statut public/privé laïc/confessionnel, code officiel, enseignement, séries, spécialités : `se_establishment_profiles`, `se_streams` |
| EX-ETB-02 | C | `/authorizations`, `AuthorizationRegistry` (`covered_levels`, `document_id` : `202610101101_organisation_higher_extensions.sql`), `/authorizations/expiring` | — |
| EX-ETB-03 | P | `/subjects` (un coefficient par matière), `CurriculumCatalog`, règles par structure (`RuleScopeLineage`) | coefficients et volumes par niveau/série/spécialité, versionnés : `se_subject_coefficients` |
| EX-ETB-04 | P | `/periods` (`Organisation\Domain\PeriodKind` trimester/sequence), `/calendar-events` | séquence ⊂ trimestre (SS1), période annuelle (SS2) |
| EX-ETB-05 | P | `/settings` (héritage), `/calculation-rules` (mentions, `councilProposals`, décisions) | barème d'absences et sanctions : paramètres `secondary.discipline.*` |
| EX-ETB-06 | P | `rooms` (capacité, `equipment`) : `202610082102_organisation_structures.sql`, route `/rooms` | nature (salle, labo, atelier) : `se_room_profiles`, postes `se_workstations` |
| EX-INS-01 | P | `/people`, `/people/{id}/documents`, `/enrollments` (+ `validate`) | préinscription, test d'entrée [PRI], pièces : `se_preregistrations` |
| EX-INS-02 | P | `AdmissionDirectory`, `People\Application\YearPromotion`, `TypedPromotionPlanner` | réinscription des autorisés, RG-INS-3 : `organisation.closure.promotion.secondary` |
| EX-INS-03 | C | `/people` (contacts, relations, santé chiffrée, documents, consentements, `schooling-history`, `identifiers`) | matricule national porté par `se_student_profiles` |
| EX-INS-04 | N | — | dossier venu de l'application Primaire (non construite) : fichier d'échange, `se_transfers` |
| EX-INS-05 | P | `schooling_history` | exeat DOC-05, contrôle du matricule : `se_transfers` |
| EX-INS-06 | N | — | choix de série/spécialité : `se_orientations` |
| EX-INS-07 | P | `DocumentIssuer`, QR | modèle `secondary_student_card` (DOC-04) |
| EX-CSC-01 | P | `person_identifiers` | saisie, import : `se_cs_imports`, `se_student_profiles.national_id` |
| EX-CSC-02 | N | — | liste 6e / Form 1 / 1re année sans matricule |
| EX-CSC-03 | N | — | import des paiements constatés `se_cs_payments`, rapprochement par matricule |
| EX-CSC-04 | P | `CartescolaireExporter` (colonnes `config/integrations.php`), `ConfiguredExportType` | fichier des conseils de fin d'année DOC-33 (format Q1) |
| EX-CSC-05 | N | — | écarts application ↔ cartescolaire : `se_cs_discrepancies` |
| EX-FIN-01 | C | `/fee-items` (`structure_id`), `/fee-schedules`, `billing.fee_kinds.<app>` (`Billing\Application\FeeKinds`) | natures `apee`, `workshop`, `uniform`, `boarding`, `canteen`, `transport` déclarées |
| EX-FIN-02 | C | `/invoices/{id}/discounts`, `/discounts/{id}/approve`, `SiblingDiscountResolver` | plafonds par motif en paramètres |
| EX-FIN-03 | N | — | catalogue autorisé verrouillé [PUB] : `se_fee_catalog`, garde SS8 |
| EX-FIN-04 | C | `/payments`, `/cash/receipts`, `/reconciliations`, webhook, `Idempotency-Key` | — |
| EX-FIN-05 | P | `reminders:send` | plan de paiement individuel : `se_payment_plans` |
| EX-FIN-06 | P | `IssuanceGuard` (`documents.issuance_guards.*`), `EnrolmentGuard` | blocage du bulletin en ligne [PRI] par section de portail |
| EX-FIN-07 | P | `EstablishmentBalances`, `/billing/exports/{kind}` | par classe/niveau, prévisions : `reporting.indicators.secondary` |
| EX-EDT-01 | N | `TimetableWriter` (écriture, conflits) | générateur assisté dans `Secondary/Timetable` |
| EX-EDT-02 | C | `/timetable-slots/check`, `TimetableConflict` (enseignant, salle, classe) | atelier/labo = salle du socle |
| EX-EDT-03 | P | structures `group` avec `parent_id` | dédoublement atelier par groupes |
| EX-EDT-04 | P | `/timetable-changes` | NOT-14 (SS11) |
| EX-CTX-01 | N | — | `se_progressions`, `se_progression_lessons` |
| EX-CTX-02 | N | (Higher : `hi_session_logs`, non réutilisable) | `se_lesson_logs` |
| EX-CTX-03 | N | — | taux de couverture |
| EX-CTX-04 | N | portée `territory` en cours (`Identity\Domain\ScopeType::Territory`, non commitée) | `se_lesson_visas`, lecture R21 |
| EX-PRE-01 | C | `/attendance-sessions` par créneau, `sync` hors ligne `client_uuid` | — |
| EX-PRE-02 | P | statuts P/A/R (`Attendance\Contracts\AttendanceCodes`), `/absence-justifications` | exclusion de cours, infirmerie : `se_class_exclusions`, `se_infirmary_visits` (ou SS10) |
| EX-PRE-03 | P | `Attendance\Application\AbsenceNotifier`, `attendance.notify_parents` | « dès la 1re heure » : un SMS par jour et par élève, à vérifier (T884) |
| EX-PRE-04 | P | `/sanctions` (+ `approve`), `/incidents` | barème et propositions : `se_conduct_proposals` |
| EX-PRE-05 | N | (Higher : `hi_discipline_councils`, non réutilisable) | `se_discipline_councils`, DOC-17/18 |
| EX-PRE-06 | P | `council_lines.conduct` (texte) | préremplissage par fournisseur (SS6) |
| EX-NOT-01 | C | `/assessments` (`kind`, `weight`, `period_id`) | 6 périodes `sequence` |
| EX-NOT-02 | C | `/grades` (`batch`, `sync`, `import`, `import-template`) | — |
| EX-NOT-03 | C | `assessment.absence_codes` (`AssessmentSettings::BUILT_IN_ABSENCES` + ajouts) | `fraud` (zero), `exempt` (excluded) |
| EX-NOT-04 | N | — | `se_common_tests` |
| EX-NOT-05 | C | `Assessment\Domain\Lifecycle::fromArray` via `assessment.lifecycle` | cycle `draft, submitted, validated, locked, published` |
| EX-NOT-06 | C | `/grade-corrections`, chaîne (`CorrectionService`, `approval_step`) | chaîne censeur puis chef |
| EX-NOT-07 | C | `/results/publish`, `/sequence-statistics` | — |
| EX-NOT-08 | N | — | correspondance note → lettre [EN] |
| EX-BUL-01 | P | `ResultService::compute`, `RuleEngine::ranks` | SS1-SS4 + stratégie |
| EX-BUL-02 | P | `Assessment\Application\ReportCardService` (type `report_card`) | DOC-09/10/11 bilingues, variables (SS5) |
| EX-BUL-03 | P | `council_lines.appraisal` | appréciation par matière + banque : `se_subject_appraisals`, `se_appraisal_bank` |
| EX-BUL-04 | P | résumé du conseil (`CouncilService::summaryJson`) | distribution, couverture, assiduité, cas : DOC-12 |
| EX-BUL-05 | P | décisions et PV (`MinutesIssuer`) | distinctions, sanctions, orientation |
| EX-BUL-06 | P | `/results/publish`, `Messenger` | SMS résumé NOT-05 (SS11) |
| EX-BUL-07 | N | — | DOC-14 |
| EX-PAS-01 | P | `RuleStrategy::decideYear`, `YearPromotion` | politique de passage dans la stratégie |
| EX-PAS-02 | N | — | `se_orientations` |
| EX-PAS-03 | N | — | DOC-16, entretien |
| EX-PAS-04 | N | — | `se_decision_appeals` |
| EX-EXA-01 | N | — | `se_exam_sessions`, `se_exam_candidates` |
| EX-EXA-02 | N | — | contrôles (photo, matricule, frais constatés) |
| EX-EXA-03 | P | `TabularExporter`, `integrations.export_types.*` | formats des organisateurs (Q3), DOC-19/20 |
| EX-EXA-04 | N | — | épreuves pratiques, rapports [TEC] |
| EX-EXA-05 | N | — | import des résultats, taux de réussite |
| EX-EXA-06 | N | — | comparaison moyenne / examen |
| EX-ATL-01 à 06 | N | `rooms` seulement | ateliers, postes, séances, fiches TP, stock, grille, registre |
| EX-STG-01 à 03 | N | (Higher : `Internships`, non réutilisable) | entreprises, conventions, visites, note |
| EX-RHP-01 | P | `/staff`, `staff_members.kind` | statut fonctionnaire/contractuel/salarié/vacataire : `se_staff_profiles` |
| EX-RHP-02 | P | `Staff\Contracts\TaughtHoursRecorder`, `Attendance\Application\TaughtHoursReporter` | heures du cahier de textes, validation censeur |
| EX-RHP-03 | P | `vacation_*`, `VacationCalculator` | paie simplifiée du privé : `se_payslips` |
| EX-RHP-04 | C | `/leave-requests`, `/timetable-changes` | — |
| EX-SVC-01 à 03 | N | natures de frais | internat, cantine, transport, bibliothèque |
| EX-SVC-04 | P | `health_records` chiffré | passages, dispenses EPS |

Comptes EX : C 14, P 32, N 35 (ATL, STG, SVC-01 à 03 comptés un par un).

## 3. Règles de gestion (36)
| ID | St. | Commentaire |
|----|-----|-------------|
| RG-ORG-1 | P | CES/CETIC sans 2nd cycle : garde `organisation.enrolment.guards.secondary` + validation des classes |
| RG-ORG-2 | P | `pedagogical_structures` + `org_units` ; série/spécialité dans `se_class_profiles` |
| RG-ORG-3 | C | `AuthorizationRegistry::covers` + garde (modèle `Higher\Academic\Application\HigherEnrolmentGuard`) |
| RG-ORG-4 | P | coefficients officiels verrouillés `se_subject_coefficients.locked` ; matières de projet coefficient 0 |
| RG-ORG-5 | P | rôle `direction_groupe`, `reporting.indicators.*` ; agrégats seulement |
| RG-INS-1 | N | statut « en attente » et liste à régulariser |
| RG-INS-2 | P | `BirthInfo` ; âge limite par niveau en paramètre, alerte + décision |
| RG-INS-3 | N | exclusion définitive → décision motivée |
| RG-INS-4 | C | le socle ne conditionne pas l'inscription au paiement ; le garde Secondary ne l'ajoute jamais en public |
| RG-CSC-1 | C | fichiers (`CartescolaireExporter`, imports) ; aucune automatisation de navigateur |
| RG-CSC-2 | N | écart d'identité signalé, correction après vérification |
| RG-FIN-1 | C | aucune action de renvoi ; test d'absence de route (T949) |
| RG-FIN-2 | N | SS8 + `se_fee_requests` |
| RG-FIN-3 | N | frais d'examen affichés seulement (`se_cs_payments`) |
| RG-FIN-4 | P | SS7 |
| RG-PRE-1 | N | barème 10/20/30 h en paramètres |
| RG-PRE-2 | C | `sanctions.approval_id` (`DisciplineService`) |
| RG-PRE-3 | N | conseil de discipline, PV, audition |
| RG-NOT-1 | C | `RuleSet::scale` 20, `assessment.step` 0,25 |
| RG-NOT-2 | C | effets `excluded`, `retake`, `zero` (`RuleEngine::normalise`) |
| RG-NOT-3 | P | zéro par code `fraud` ; saisine : incident créé par Secondary |
| RG-NOT-4 | C | `grade_corrections` versionnées |
| RG-BUL-1 | P | `CouncilService::validate` refuse si épreuve éditable ; « non évalué » = état `not_evaluated` du cycle déclaré |
| RG-BUL-2 | P | `councilProposals` ; refus de distinction si sanction de conduite (stratégie + SS6) |
| RG-BUL-3 | P | conseil validé ; rectificatif : `se_report_snapshots` + SS12 |
| RG-BUL-4 | N | échéance en paramètre, alerte |
| RG-EXA-1 | N | contrôle d'inscription dans la classe d'examen |
| RG-EXA-2 | N | attestations internes (DOC-31) seulement |
| RG-ATL-1 | N | EPI manquant → observation |
| RG-ATL-2 | N | lié à SS8 |
| RG-DOC-1 | C | numérotation unique (Documents) |
| RG-DOC-2 | C | `/public/verify/{token}` ; vérifier l'absence de notes (T913) |
| RG-DOC-3 | C | `ReportCardService` n'émet qu'après validation du conseil |
| RG-DOC-4 | P | `IssuanceGuard` retarde ; modèles sans variable de dette |
| RG-DOC-5 | P | modèles FR/EN par section |
| RG-DOC-6 | P | `DocumentService::duplicate` (« Duplicata ») ; « Rectificatif » : SS12 |

Comptes RG : C 11, P 13, N 12. **Total : C 25, P 45, N 47 sur 117.**

## 4. Documents (34), notifications (14), API (15)
- Documents : couvert DOC-27 ; partiels (moteur présent, modèle à fournir) DOC-02, 03, 06, 07, 09, 10, 11, 13, 15, 28, 29 ; à créer les 22 autres. Tous des types `secondary_*` déclarés par un `DocumentTypeProvider` (modèle Higher `<app>.document_types`).
- Notifications : couvertes NOT-02, 07, 08, 09 ; partielles NOT-04, 05, 14 ; à créer NOT-01, 03, 06, 10, 11, 12, 13 (modèles SS11 + déclencheurs Secondary).
- API-01 à API-15 : routes `/api/v1/secondary/...` par `SecondaryRoutes`. API-05, 07, 08, 09, 11 délèguent au socle (voir [plan.md](plan.md) §3).

## 5. Sécurité et ENF
- Couverts : verrouillage avec 2FA (`Lifecycle` `require_recent_2fa`), séparation saisie/verrouillage (`assessment.segregate_lock`), journal chaîné (Audit), idempotence des paiements, aucune suppression d'encaissement (`/reversals` avec approbation), santé chiffrée.
- Partiels : liens de bulletins signés expirant en 24 h (absent, SS14) ; comptes externes R21-R23 en lecture seule à durée limitée (portée `territory` en cours d'ajout, expiration du rôle à vérifier, Q13) ; journal des consultations de dossiers de mineurs (`ScopedAccessAudit` en cours, non commité).
- ENF : NF-S1 (3 000 élèves < 60 s) et NF-S2 (3 000 PDF < 15 min, Dompdf en file `documents:process-jobs`) sont à mesurer sur Camoo (aucun Redis) ; NF-S5 (100 classes, calcul de référence indépendant) = T947.

## 6. Écarts majeurs classés
1. Agrégats séquence → trimestre → année, arrondi par étape, rang par matière (SS1-SS4 + stratégie) : bloque BUL, PAS, TST-01 à TST-04.
2. cartescolaire.cm : formats inconnus (Q1), tout par fichiers.
3. Frais : ordre d'affectation RG-FIN-4 (SS7) et catalogue verrouillé du public (SS8).
4. Enseignement technique (ATL, STG, pratique < 8, plafond sécurité) : entièrement neuf.
5. Cahier de textes, couverture, heures pour la paie (CTX, RHP-02) ; paie du privé absente du socle.
