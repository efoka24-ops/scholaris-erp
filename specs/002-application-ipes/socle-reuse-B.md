# Réutilisation du socle par l'application IPES : partie B

Périmètre : NOT, DEL, FIN, STG, EXN, TUT, DOC, POR, PIL et rôles R01-R23. L'autre moitié (ACA, ADM, INS, MAQ, EDT, ENS, PRE) est dans `socle-reuse-A.md`.

**Méthode.** Tout ce qui suit a été vérifié dans le code réel (`src/Core`, `src/Modules/Socle/*`, migrations, `routes.php`, `definitions.php`), pas dans les plans. Le CDC IPES (PDF) n'est pas dans le dépôt : les identifiants EX-xxx-nn ne sont connus que par plages (spec 002, FR-001 à FR-022) ; les décomptes ci-dessous sont donc faits à la maille des exigences fonctionnelles et des règles RG, et sont indicatifs. Les maquettes lues : Deliberation, Releve, Bulletin, Frais, DocRegistre, Verification, Notes, Dashboard.

**Constat structurant.** Le `data-model.md` de 002 prévoit des tables `hi_*` qui doublonnent ce que le socle fournit déjà (notes, corrections, jurys, résultats, factures, documents). Le plan 002 parle de Laravel et de `app/Modules` ; le code réel est du PHP 8.1 sans framework sous `src/Modules/Apps/Higher` (dossier vide aujourd'hui), avec `Contracts/`, `Application/`, `Infrastructure/`, `Http/`, `routes.php`, `definitions.php`, migrations SQL portables MySQL/SQLite (`agent-conventions.md`). Les tâches de `tasks.md` doivent suivre cette réalité.

## Inventaire du socle utile à la partie B (vérifié)

| Module | Contrats réels | Routes réelles (préfixe `/api/v1`) | Tables |
|---|---|---|---|
| Assessment | `ActorDirectory`, `AdmissionDirectory`, `PublishedResult`, `RecentSecondFactor`, `ResultsDirectory` ; domaine `Lifecycle`, `RuleSet`, `RuleEngine` | `/subjects`, `/assessments` (+`/transition`, `/reveal`), `/calculation-rules`, `/grade-sheets`, `/grades` (+`/batch`, `/sync`, `/import`, `/import-template`, `/export`, `/{id}/events`), `/grade-corrections` (+approve/reject), `/sequence-statistics`, `/class-councils`, `/deliberations` (+attendance, start, lines, close, sign, reopen), `/results` (+publish, export) | `subjects`, `subject_elements`, `assessments`, `calculation_rules`, `grades`, `grade_events`, `grade_corrections`, `results`, `deliberations`, `deliberation_lines`, `class_councils`, `report_card_requests` |
| Billing | `DebtorDirectory` (`learnerIdsInArrears`, `balanceXaf`, `isBlocked`) | `/fee-items`, `/fee-schedules`, `/invoices` (+discounts), `/discounts/{id}/approve`, `/payments`, `/cash/receipts`, `/receipts` (+pdf), `/cash-sessions`, `/reversals` (+approve/reject), `/reconciliations`, `/payment-references`, `/billing/exports/{kind}`, webhooks | `fee_items`, `fee_schedules`, `invoices`, `invoice_lines`, `installments`, `discounts`, `payments`, `allocations`, `receipts`, `reversals`, `reminders`, `reconciliations`, `balance_carry_overs` |
| Documents | `DocumentIssuer` (`issue`, `revoke`), `DocumentCatalog`, `IssuedDocument` | `/document-types`, `/document-templates` (+validate, versions), `/document-seal`, `/documents` (+preview, duplicate, revoke), `/document-batches`, `/document-requests`, `GET /public/verify/{token}` (public) | `document_types`, `document_templates`, `documents`, `document_batches`, `document_requests`, `document_seals`, `verification_log` |
| Reporting | `IndicatorProvider`, `Indicator`, `IndicatorScope`, `TodoProvider`, `PendingApprovalLister` | `GET /dashboards/{kind}` (kind = `direction`, `censeur`, `groupe` seulement) | `reporting_schedules` |
| Portals | `GradingTodo`, `AdultLearnerCheck` | `/portal/family`, `/portal/learner`, `/portal/teacher` | aucune |
| Audit / Identity | `AuditLog`, `Approvals` (demandeur ≠ validateur), `RecentSecondFactor::hasRecent($tenant, $user, $maxAge)` | `/role-assignments` | `roles`, `role_permissions`, `role_assignments` (scope tenant, establishment, org_unit, class) |
| Autres utiles | `Messenger::send(... $templateKey ...)`, `StaffDirectory`, `TaughtHoursRecorder`, `AttendanceSummaryProvider`, `EnrollmentDirectory`, `ConsentChecker`, `SettingsReader`, `ApplicationRegistry`, `EnrolmentGate`, `ClosureCheck`, `TutelleExporter` (export CSV générique), `PdfRenderer` | | `authorizations` par structure, `pedagogical_structures` (kind `track` réservé au supérieur), `org_units`, `periods` (kind `semester` seul autorisé en supérieur) |

Faits importants relevés dans le code :
- `assessment.lifecycle` (clé de paramètres lue par `AssessmentSettings::lifecycle`) accepte un cycle déclaratif : états, états éditables, état publié, transitions avec permission et `require_recent_2fa`. Un cycle incohérent retombe sur le défaut (brouillon, saisie, verrouillée, publiée). C'est le point d'extension T328 (livré). Les permissions de transition doivent exister dans `role_permissions`.
- Le moteur `RuleEngine` fonctionne avec un seul `RuleSet` par établissement et par année (`calculation_rules`, versionné, jamais modifié). Paramètres connus : `scale`, `pass_mark`, `rounding`, `unjustified_absence`, `weighting` (coefficient ou crédits), `mentions`, `council_proposals`, `compensation_from`, `capitalize_from`, `resit_from`. Toute autre clé est refusée. Décisions produites : Admis, Admis par compensation, Rattrapage, Ajourné (celles de la maquette Deliberation).
- La délibération existe : présents, quorum (`assessment.quorum_percent`), décisions avec motif si différentes de la proposition, clôture, signatures par les membres présents, réouverture (permission `assessment.correction.approve`). Mais la publication (`ResultService::publish`) exige seulement `status = closed`, pas de signatures, pas de 2FA sur sign, close, reopen ; pas d'empreinte SHA-256 du PV.
- L'anonymat est géré (`assessments.anonymous`, code anonyme dérivé, `POST /assessments/{id}/reveal`, journalisé).
- Les corrections de notes passent par `Approvals` (une seule approbation par une autre personne ; la maquette IPES demande chef de département puis directeur d'école).
- `PermissionChecker::can` ne tient compte que de l'établissement : les affectations de rôle de portée `org_unit` ou `class` sont stockées mais pas appliquées comme filtre. Le périmètre école/département/filière/groupe doit donc être imposé par l'application (T208).
- Les types de documents `report_card` et `deliberation_minutes` sont appelés par Assessment mais aucun modèle n'est fourni : « Les modèles Bulletin et Relevé sont fournis par les applications » (seed `attestation_simple` seulement).
- `Documents` : variables en liste blanche par type, numéro par gabarit (`{CODE}`, `{AA}`, `{AAAA}`, `{LETTRE}`, `{N4..N9}` plus littéraux `[A-Za-z0-9-_/.]`), séquence continue par établissement/type, empreinte SHA-256, duplicata, révocation motivée avec `replaced_by`, vérification publique (type, établissement, titulaire masqué, statut valide ou révoqué, motif). Le blocage financier (`blockable`) n'est appliqué que dans `BatchService` (lots), pas dans l'émission unitaire.
- `Billing` : `fee_items.kind` limité à `registration`, `tuition`, `other` ; remises `sibling`, `staff`, `merit`, `waiver` (waiver avec approbation) ; blocages `billing.blocks` sur les seuls scopes `results`, `documents`, `reinscription` (jamais cours, composition, certificat, reçu) ; relances `billing.reminder_offsets` défaut [-7, 1, 15] ; règle d'affectation `registration_overdue_upcoming` ; reçu `R-<CODE>-<AA>-<n>` ; remboursement = `reversals` à double validation.

---

## NOT : notes et évaluations (FR-011, EX-NOT-01 à 09, RG-NOT-1 à 5)

**1. Exigé.** Évaluations par EC ; saisie en grille, import Excel, hors-ligne ; codes ABJ, ABI, NA, FR ; barème 0-20 au quart de point (RG-NOT-1) ; cycle brouillon, soumise, validée, verrouillée, publiée ; verrouillage avec 2FA ; correction motivée à double validation chef de département puis directeur d'école, demandeur ≠ validateur (RG-NOT-2) ; enseignant limité à ses EC affectés, 403 journalisé (TST-03) ; anonymat (Should) ; requête de l'étudiant (Should) ; suivi des retards avec relances NOT-11 ; statistiques par EC (Should).

**2. Réutilisé tel quel.**
- Évaluations, grille, saisie unitaire, lot, `POST /grades/sync` (`client_uuid`, version, conflit signalé jamais écrasé), import et modèle CSV, export, historique append-only `grade_events` : `AssessmentsController`, `GradeController`, `GradeSheetController`.
- Cycle déclaratif via `assessment.lifecycle`, `POST /assessments/{id}/transition` avec permission et `require_recent_2fa` par étape (adapté sur `Identity\RecentSecondFactor`).
- Corrections `POST /grade-corrections` + approve/reject, tracées en `grade_events` et audit `grade.corrected`.
- Anonymat : `anonymous`, `anonymous_code`, `POST /assessments/{id}/reveal`.
- Retards de saisie : `PendingGradingTodo` (contrat `Portals\GradingTodo`), indicateur `assessment.entry_progress`, `TodoProvider` de Reporting.
- Statistiques : `GET /sequence-statistics` (moyenne, taux, min, max).
- Notification : `Messaging\Contracts\Messenger::send` avec une clé de modèle.

**3. Partiel et écarts précis.**
- Codes d'absence : le socle connaît `justified` et `unjustified` (CSV : ABS/ABJ, ABI). Manquent NA (non autorisé à composer, compte 0) et FR (fraude, 0 et saisine du conseil de discipline). Effets d'ABJ (épreuve de remplacement) absents.
- Barème au quart de point : le socle borne 0..`max_score`, sans pas de 0,25. À ajouter comme paramètre `assessment.step` ou contrôle IPES.
- Périmètre enseignant : `ScopeGuard` s'appuie sur `TeachingScope` (élèves des classes de l'enseignant) et `teacher_person_id` de l'épreuve, pas sur une affectation à un EC par le chef de département. Le contrat `People\TeachingScope` doit être branché par l'IPES sur ses affectations (autre moitié, ENS).
- Double validation : une seule approbation (`Approvals`). Deux étapes chef de département puis directeur d'école = à orchestrer au-dessus (deux demandes chaînées) ou à demander au socle (approbation à n niveaux).
- Séparation « celui qui saisit ne verrouille pas » : non vérifiée dans `transitionSheet` (seule la permission compte). À imposer par les permissions des rôles (saisie sans validation) et, si exigé strictement, par un contrôle auteur ≠ verrouilleur.
- Matière/UE/EC : `subjects` (kind `subject` ou `teaching_unit`) + `subject_elements`, unicité par code et établissement, sans version de maquette ni année. Le lien avec les maquettes versionnées est à faire côté MAQ (autre moitié).
- Relances NOT-11 : la liste des retards existe, l'envoi automatique périodique (commande console) n'existe pas pour les notes.

**4. À créer dans `Higher`.**
- Fournisseur `Grades/` : `HigherLifecycle` (déclaration JSON de `assessment.lifecycle` : brouillon, soumise, validée, verrouillée, publiée ; permissions `assessment.submit`, `assessment.validate`, `assessment.lock`, `assessment.publish` ; 2FA sur verrouillage et publication) et son seeder de paramètres.
- Codes NA et FR et leurs effets, via une couche IPES qui traduit vers les absences du socle ou par extension du socle (voir 5).
- Orchestration de la double validation des corrections (chef de département puis directeur d'école) et alerte au directeur général pour une correction après délibération.
- Requête de note de l'étudiant (Should) : table `hi_grade_queries` + routes.
- Commande console de relance NOT-11 (liste des EC en retard, `Messenger`).
- Statistiques par EC (Should) : réutiliser les données, présenter par EC et groupe.

**5. Points d'extension et demandes au socle (à ne pas faire ici).**
- Paramétrer `assessment.lifecycle` pour l'établissement IPES (aucun changement de code).
- Demande S1 : codes d'absence extensibles (`absence` limité à `justified|unjustified` dans `GradeService::ABSENCES`, `grades.absence` VARCHAR(12)), avec effet de calcul déclaratif (`zero`, `excluded`, `retake`).
- Demande S2 : pas de saisie (`step`) par établissement ou épreuve.
- Demande S3 : approbation à plusieurs niveaux dans `Approvals` ou une chaîne de demandes.
- Demande S4 : contrôle optionnel « auteur de la saisie ≠ auteur du verrouillage ».

**6. Risques et questions.**
- La note de passage « éliminatoire » du CDC (7 puis 8 dans l'exemple) est-elle `compensation_from` ? Oui dans le moteur actuel (par UE), mais l'exemple Marie N. détaille un plancher par EC (voir DEL).
- La maquette Notes (Secondaire) montre un cycle en 3 étapes ; la maquette IPES de saisie n'existe pas encore (T225-like).
- Confirmer le sens de NA et FR et l'épreuve de remplacement pour ABJ.

---

## DEL : délibérations et jurys (FR-012, EX-DEL-01 à 08, RG-DEL-1 à 5)

**1. Exigé.** Calculs F1 à F7 ; compensation entre UE ; capitalisation ; rattrapage ; simulation avec cas à moins de 0,5 point d'un seuil ; sessions de jury avec quorum ; PV signé électroniquement avec empreinte ; publication après signature ; décisions de passage (60 crédits admis, 45-59 admis avec dettes, moins de 45 redouble, deuxième redoublement exclu) ; points de jury plafonnés à 0,5 ; figement et réouverture par le directeur académique avec nouveau PV et alerte DG ; règles BTS/HND (0,4 devoirs + 0,6 examen, coefficients, passage ≥ 10, rang) ; exemples TST-04 et TST-05 (Marie N.) ; MGP, lettres et points.

**2. Réutilisé tel quel.**
- Délibération en séance : `DeliberationController` / `DeliberationService` (`POST /deliberations`, attendance, start, `PATCH lines/{id}` avec motif obligatoire si différent de la proposition, close, sign, reopen), quorum `assessment.quorum_percent`.
- Règles versionnées par année : `POST /calculation-rules` (audit `assessment.rules.published`), lecture tolérante, versions jamais modifiées ; `results.rule_version` conservé.
- Moteur : moyenne pondérée par coefficient ou par crédits, arrondi au centième `HALF_UP`, mentions par seuils, rang avec ex æquo (1, 2, 2, 4), crédits acquis avec capitalisation (`capitalize_from`) et compensation (`compensation_from`, conditionnée à la moyenne générale ≥ `pass_mark`).
- Contrôles de clôture d'année : `UnlockedGradesCheck`, `OpenDeliberationsCheck` (`organisation.closure.checks.assessment`).
- Génération du PV par `Documents\DocumentIssuer` via `MinutesIssuer` (type `deliberation_minutes`).
- Tableau, synthèse (admis, compensés, rattrapage, ajournés, modifiés), `POST /results/publish`, `GET /results/export`.

**3. Partiel et écarts précis.**
- Règles par filière : `calculation_rules` est par établissement et année, pas par programme (LMD, BTS, HND coexistent dans un IPES). Écart majeur.
- Formules du CDC absentes du moteur : MGP (points par lettre, exemple 2,40), grille de lettres et points, pondération « 0,4 devoirs + 0,6 examen » par EC (le moteur pondère par `weight` d'épreuve, donc réalisable en configurant les poids, à confirmer), note éliminatoire par EC distincte du seuil de compensation de l'UE, remplacement de la note d'EC par le rattrapage (exemple : rattrapage à 11 donne note d'EC 9,80, soit une règle de combinaison à définir), moyenne annuelle et décisions 60/45/redoublement/exclusion, report des dettes, cumul 180 crédits.
- Session : `results` est unique par (personne, période) ; aucun champ session normale/rattrapage. Le rattrapage ne peut donc pas conserver le résultat de la session normale.
- Points de jury : absents (pas de champ, pas de plafond, pas de liste nominative au PV).
- Simulation : les lignes de délibération sont calculées à l'ouverture (`fillLines`) et `POST /class-councils/{id}/refresh` existe pour les conseils ; pas de mode simulation sans effet, ni mise en évidence des cas à moins de 0,5 point.
- Ouverture : ne vérifie pas que toutes les notes sont verrouillées (RG-DEL-1) ; `compute` lit les notes quel que soit leur état.
- PV : signature simple sans 2FA ; pas d'empreinte SHA-256 du contenu des décisions ; publication possible sans aucune signature ; `sign` n'est pas limité au président ; réouverture sans nouveau PV ni alerte DG ni 2FA ; `close` verrouille mais ne fige pas `results` contre un recalcul (`compute` réécrit moyenne et crédits, y compris après clôture via `refreshIfComputed`).
- Décisions de fin d'année : seules 4 étiquettes (Admis, Admis par compensation, Rattrapage, Ajourné) ; pas « admis avec dettes », « redouble », « exclu ».
- Composition du jury : membres attendus (nombre) et présents (liste de personnes) ; pas de table des membres du jury par filière et année.

**4. À créer dans `Higher/Deliberation`.**
- `HigherRuleSet` : définition JSON versionnée par programme et année (LMD, BTS, HND) chargée par un adaptateur, qui s'appuie sur `RuleEngine::compute` pour le noyau (moyennes, arrondi, rang) et ajoute : lettres et points, MGP, note éliminatoire, combinaison avec le rattrapage, décisions annuelles, capitalisation et dettes, exclusion au deuxième redoublement. Entrée : notes publiées ou verrouillées lues via contrats ; sortie : `hi_results` figés (avec `rule_version`, `session`).
- Table des jurys (`hi_juries`, `hi_jury_members`), points de jury et leur journal, `hi_session_results` (par session), dettes et UE capitalisées (`hi_unit_credits` : personne, UE, crédits, année d'acquisition).
- Service de séance IPES qui enveloppe le socle : refuse l'ouverture si une note n'est pas verrouillée ; plafonne les points de jury ; exige 2FA pour sign, close, publish, reopen ; calcule l'empreinte SHA-256 du PV (contenu normalisé des décisions) et la compare au document ; impose la signature du président avant publication ; réouverture avec nouveau PV et alerte au DG (Messenger).
- Simulation sans écriture (calcul à blanc, liste des cas à moins de 0,5 point).
- Fixtures et tests TST-04, TST-05, décisions annuelles, BTS/HND, ex æquo.

**5. Extensions du socle et demandes (non faites).**
- Paramétrage : `assessment.lifecycle`, `assessment.quorum_percent`, règles de base `assessment.*`, `calculation_rules` pour l'année.
- Demande S5 : `calculation_rules` rattachées à une structure ou un programme (colonne `structure_id` ou `scope_key`), et `RuleEngine` appelable avec un `RuleSet` fourni par l'application (aujourd'hui `ResultService` est câblé sur `RuleService`).
- Demande S6 : point d'extension « stratégie de calcul » (interface `RuleStrategy` enregistrée par le conteneur, comme les `organisation.closure.*`) pour remplacer la formule de crédits et de décision.
- Demande S7 : dimension `session` (normale, rattrapage) dans `results` et `deliberations` (clé d'unicité à élargir).
- Demande S8 : préconditions de publication et de clôture enfichables (contrat `DeliberationGuard`) : notes verrouillées, signatures, 2FA, empreinte.
- Demande S9 : étiquettes de décision paramétrables (aujourd'hui constantes de `RuleEngine`).

**6. Risques et questions.**
- Les règlements, grilles de lettres et seuils réels viennent des universités de tutelle : le CDC les donne comme valeurs par défaut fictives. Ne rien coder en dur ; attendre les textes.
- L'exemple Marie N. donne des résultats exacts attendus (11,55 ; 2,40 ; 30 crédits ; 12,09). Il faut le détail des notes et des formules F1 à F7 du CDC pour écrire les tests ; ils ne sont pas dans le dépôt.
- La maquette Releve utilise un barème de lettres (A 16-20, B 13-15,99, C 10-12,99, D 8-9,99, E < 8) avec des sous-lettres (B+, C+, A-) et CC 30 % / examen 70 %, qui diffèrent de CC/examen 40/60 du CDC. Lequel prévaut ?
- Maquette : décision du relevé « Admise » (accord de genre) : prévoir le genre du titulaire.
- Les points de jury plafonnés à 0,5 s'appliquent-ils par UE, par semestre ou par année ?
- Signature électronique : simple (code de confirmation) par défaut ; qualifiée à décider (question ouverte du CDC).

---

## FIN : frais, bourses, paiements (FR-013, EX-FIN-01 à 06, RG-FIN-1 à 6)

**1. Exigé.** Grilles par école, filière, niveau, mode ; catalogue étendu (dossier, inscription, pension, examen national, soutenance, stage, carte, assurance) ; échéancier par défaut en 3 tranches, individuel validé par le comptable ; bourses et réductions plafonnées, remises sur la pension répartie sur les tranches restantes ; prise en charge par un tiers avec facture au tiers (Should) ; blocages paramétrables (jamais cours, composition, certificat, reçu, relevé de compte, attestation de paiement) ; relances J-7, J+1, J+15 ; remboursement par écriture inverse validée par un second agent ; quitus refusé si solde non nul ; frais d'examen national requis pour la liste des candidats ; DOC-27 à 30 ; paiement mobile money ; écran Caisse (maquette Frais).

**2. Réutilisé tel quel.**
- Grilles : `/fee-items`, `/fee-schedules` (par année, structure, niveau, track, avec `terms` : tranches datées).
- Facturation depuis les grilles : `/invoices` (création par inscription, échéancier en `installments`), remises `/invoices/{id}/discounts` avec types `merit`, `staff`, `sibling`, `waiver` (waiver soumis à approbation), appliquées à la pension par défaut.
- Paiements et affectation : `/payments`, `/cash/receipts` (idempotence), `AllocationPlanner` (règle `registration_overdue_upcoming` : inscription, puis retards, puis à venir), `/receipts` (+pdf, SMS), `/cash-sessions` (arrêt de caisse, écart), `/reversals` (+approve : double validation), `/reconciliations` (mobile money), `/payment-references`, webhooks opérateurs, `/billing/exports/{kind}` (export comptable).
- Relances : `ReminderService`, `billing.reminder_offsets` par défaut [-7, 1, 15] (RG-FIN-3 couvert).
- Blocages : `DebtorDirectory::isBlocked` avec `billing.blocks` limité aux scopes `results`, `documents`, `reinscription` ; les autres scopes ne sont jamais bloqués (RG-FIN-5 couvert par construction).
- Report de solde à la clôture : `YearBalanceCarryOver`.

**3. Partiel et écarts précis.**
- Types de frais : `fee_items.kind` ∈ {registration, tuition, other} ; le catalogue IPES passe par `other` + libellé, sans clé stable pour piloter les règles (frais de dossier non remboursables, frais d'examen national requis pour EXN, quitus). Ajouter des `kind` ou un champ `code`.
- Exemple CDC (pension 450 000, bourse 20 %, 3 tranches de 120 000, paiement 170 000 : 50 000 + 120 000) : le reste du calcul dépend de l'application d'une remise en pourcentage sur la seule pension répartie sur les tranches restantes (RG-FIN-1). `InvoiceService` applique les remises à `tuition` ; la répartition entre tranches et l'exemple chronologique (100 000 puis 20 000) sont à valider par test (T242).
- Plafond des bourses et remises cumulées : non trouvé dans `InvoiceService` (pas de plafond en pourcentage de la pension). À créer.
- Frais de dossier de candidature : un candidat n'est pas encore une personne inscrite (`invoices.enrollment_id` obligatoire). Le paiement de la candidature demande un point d'entrée pour un candidat (voir ADM, autre moitié).
- Blocage `isBlocked(..., 'documents')` : appliqué en lot seulement ; l'émission unitaire n'y recourt pas.
- Blocage des notes : l'étudiant ne voit les notes qu'après publication et sans blocage (`LearnerCardBuilder` utilise le scope `results`) : couvert côté portail, pas côté API de lecture directe.
- Bourse : table `discounts` sans notion de dossier de bourse (critères, durée, renouvellement), sans financeur.
- Tiers payeur : `payment_references` est lié à un garant personne ; pas d'entité organisme, pas de facture à un tiers.
- Reçu : `R-IPES-26-04821` correspond au format de la maquette (`R-<CODE>-<AA>-<5>`), alors que le CDC cite `CODE-ÉCOLE-ANNÉE-ORDRE` (voir DOC).
- Quitus financier et attestation de paiement : pas de calcul « solde nul » exposé comme règle de document (`balanceXaf` existe).

**4. À créer dans `Higher/Billing`.**
- `FeeCatalog` IPES : seeders de `fee_items` (codes dossier, inscription, pension, examen national, soutenance, stage, carte, assurance) et `fee_schedules` par programme/niveau/mode, avec le modèle d'échéancier en 3 tranches (valeurs par défaut du CDC, paramétrables).
- `ScholarshipPolicy` : types de bourses, taux, plafond cumulé (paramètre), validation ; s'appuie sur `/invoices/{id}/discounts`. Table `hi_scholarships` (dossier : bénéficiaire, taux, motif, années) seulement si le CDC l'exige.
- `FinancialHoldPolicy` IPES : traduit les blocages vers notes, documents, réinscription et vers la liste des candidats nationaux (frais d'examen soldés). Lit `DebtorDirectory`.
- Quitus (DOC-30) : garde « solde nul » avant émission ; attestation de paiement et relevé de compte (jamais bloqués).
- Prise en charge par un tiers (Should) : `hi_sponsors`, facture au tiers (ou export).
- Écran Caisse : écrans web (maquette Frais) sur les routes Billing existantes ; rien d'autre côté API.

**5. Extensions du socle et demandes (non faites).**
- Paramétrage : `billing.blocks`, `billing.reminder_offsets`, `billing.allocation_rule`, `billing.receipt_code`.
- Demande S10 : `fee_items.kind` extensible (ou colonne `code`), validé par application.
- Demande S11 : plafond des remises (`billing.discount_cap_percent`), contrôle dans `addDiscount`.
- Demande S12 : application du blocage `documents` à `DocumentIssuer::issue` quand `blockable = true` (aujourd'hui lots seulement).
- Demande S13 : facturation d'un candidat (personne sans inscription) et facture au nom d'un tiers.

**6. Risques et questions.**
- Taux et plafonds de bourses, réduction de fratrie en supérieur (le défaut du socle est 25 000 FCFA fixes), frais non remboursables, date limite de remboursement : valeurs de l'institut à fournir.
- Part reversée à l'université de tutelle (TUT, Should) : base de calcul inconnue.
- Rapprochement mobile money et canaux disponibles en production (Orange Money, MTN MoMo) : dépendent des intégrations réelles (voir `integrations-externes.md`).

---

## STG : stages, mémoires, soutenances (FR-014, EX-STG-01 à 06, RG-STG-1/2)

**1. Exigé.** Convention tripartite, suivi (tuteur entreprise, encadreur), fiche d'évaluation, dépôt de mémoire, planning de soutenances, jury d'au moins 3 membres (réglable) non présidé par l'encadreur, notes et PV de soutenance, soutenance programmable seulement avec convention signée, attestation de stage, mémoire déposé et quitus ; offres de stage et contrôle de similarité (Should) ; DOC-20 à 23.

**2. Réutilisé tel quel.** Rien de métier. Outils transverses : `DocumentIssuer` (convention, PV, fiches), `Messenger` (convocations), `Approvals`, `AuditLog`, `PersonDirectory` pour les personnes, `Billing\DebtorDirectory::balanceXaf` (quitus), `Scheduling` (salles : `rooms` dans Organisation, `RoomCapacity`) pour le planning de salle.

**3. Partiel.** Aucun module de stage dans le socle ; le calendrier (`calendar_events`) et le stockage de pièces (People `documents`) sont des briques, sans pièce de stage.

**4. À créer.** Tout le module `Higher/Internships` : `hi_internships` (convention, tuteur, encadreur, statut), `hi_defenses` (date, salle, jury, notes), `hi_defense_jury_members`, règles RG-STG-1/2 (`DefenseGate`), dépôt de pièces (convention signée, attestation, mémoire), notes de soutenance (calcul ou saisie via `Assessment` : une évaluation `kind = defense` sur une UE de stage permet de réutiliser le cycle, le verrouillage et la délibération), modèles DOC-20 à 23, répertoire d'entreprises (Should), similarité (option, dépend d'un service externe).

**5. Extensions.** Aucune demande au socle ; la note de soutenance peut vivre dans `grades` pour être comptée par DEL (UE de type stage/projet).

**6. Questions.** Le dépôt de fichiers volumineux (mémoire) passe-t-il par le stockage du socle (People `documents`, `FileScanner`) ? Le contrôle de similarité est-il hors version 1 ? Quelle pondération entre rapport, soutenance et appréciation du tuteur ?

---

## EXN : examens, rattrapage, anonymat (FR-015, EX-EXN-01 à 07, RG-EXN-1 à 3)

Remarque : le CDC range sous EXN les sessions d'examens nationaux BTS et HND ; le rattrapage relève de DEL et l'anonymat des copies de NOT. Ils sont traités ici pour la partie « examens ».

**1. Exigé.** Sessions BTS/HND (spécialité, année, calendrier, frais) ; liste des candidats tirée des seules inscriptions valides de l'année de sortie (aucun candidat externe) ; contrôles assiduité ≥ 70 %, frais d'examen, pièces ; exports PDF et Excel au format MINESUP par modèle ; toute modification après export tracée et signalée au DG ; import des résultats ; taux de réussite ; attestation de fin de formation (DOC-12) ; jamais de document intitulé « BTS » ou « HND ». Côté examens internes : planning d'examens, émargement (DOC-08), convocations (DOC-13), anonymat, rattrapage.

**2. Réutilisé tel quel.**
- Anonymat : `assessments.anonymous`, code anonyme, `reveal` (voir NOT).
- Inscriptions valides : `EnrollmentDirectory` (statut `active`) et `AdmissionDirectory`.
- Assiduité : `AttendanceSummaryProvider::forLearner` et `GET /attendance/not-allowed-to-sit`.
- Frais d'examen : `DebtorDirectory`.
- Export fichier : `ExportsList::csvBody` (CSV `;` UTF-8 BOM, protection formule), `Integrations\TutelleExporter` (port générique : effectifs et enseignants seulement).
- Audit et alerte : `AuditLog`, `Messenger`.
- Planning d'examen : `Scheduling` (`calendar_events`, `timetable_*`), salles `rooms`.

**3. Partiel.**
- Pas de session d'examen ni de candidat. L'export `TutelleExporter` n'écrit que deux statistiques (`config/integrations.php`, clé `tutelle`), colonnes à remplacer par le format MINESUP qui n'a pas été fourni.
- Rattrapage : l'épreuve de rattrapage s'enregistre comme une évaluation `kind = resit`, mais sans session distincte dans `results` (voir DEL).

**4. À créer dans `Higher/NationalExams`.** `hi_national_sessions`, `hi_candidates` (états éligible, dossier complet, exporté, convoqué, résultat importé), `EligibilityChecker` (inscription valide en année de sortie, assiduité, frais, pièces), exports Excel/PDF par modèle (adaptateur `MinesupExporter` configurable), import des résultats (CSV), journal des modifications après export + alerte DG (Messenger), garde de libellé « jamais BTS/HND » dans les modèles de documents (RG-EXN-3), DOC-12 et DOC-17. Planning d'examens internes et convocations : s'appuyer sur Scheduling.

**5. Extensions.** Demande S14 : port d'export générique à colonnes configurables par type d'export (aujourd'hui une seule configuration `tutelle`) ; format xlsx : pas de bibliothèque Excel dans le socle (le CSV est le repli documenté), le plan 002 prévoit `maatwebsite/excel`, qui est une bibliothèque Laravel : à remplacer par une bibliothèque indépendante (ex. PhpSpreadsheet) et à faire valider (composer partagé).

**6. Questions.** Format exact des listes MINESUP BTS et HND (colonnes, encodage, gabarit PDF). Calendrier et frais d'examen national. Qui est « année de sortie » pour une filière en alternance ? Règle de rattrapage : un seul rattrapage par EC ? note maximale plafonnée ?

---

## TUT : relations avec la tutelle (FR-016, EX-TUT-01 à 05, RG-TUT-1/2)

**1. Exigé.** Transmissions à l'université de tutelle (listes, notes, relevés, PV) ; suivi des dossiers de diplôme (transmis seulement après PV de délibération finale signé et quitus) ; attestation de réussite LMD mentionnant la tutelle ; accès en lecture pour la tutelle (Could) avec journal des consultations ; frais reversés (Should).

**2. Réutilisé tel quel.** `AuditLog` (journal des consultations), `Identity` pour un compte nominatif à durée limitée (`role_assignments.ends_at`), `Documents` (PV, relevés), `TutelleExporter`/`ExportsList` (fichiers), `GET /results/export`. Alertes d'expiration d'autorisation : `Organisation\AuthorizationExpiryService` (route `/authorizations/expiring`) ; le registre des tutelles par année est de la moitié A (ACA).

**3. Partiel.** Compte externe nominatif à durée limitée : possible par affectation de rôle avec `ends_at`, mais pas de restriction « mes filières seulement » (les permissions sont par établissement) ni de journal de consultation par page (seule la vérification publique de documents a un journal).

**4. À créer dans `Higher/Tutelage`.** `hi_tutelage_transmissions` (nature, tutelle, périmètre, statut, empreinte du lot), `TransmissionGate` (PV final signé + quitus), génération des lots (DOC-18), suivi des dossiers de diplôme (`hi_diploma_files`), politique de lecture de la tutelle filtrée par filières (T208), journal des consultations, frais reversés (Should).

**5. Extensions.** Demande S15 : permission avec portée filière/org_unit effectivement appliquée par `PermissionChecker` (aujourd'hui l'établissement seul) ; sinon l'application filtre elle-même.

**6. Questions.** Canal de transmission (fichier, portail de la tutelle, courrier) et formats ; la tutelle accède-t-elle à SCHOLARIS (R22) en version 1 ? Quelle part de frais est reversée et quand ?

---

## DOC : les 32 documents officiels (FR-017, RG-DOC-1 à 8)

**1. Exigé.** Catalogue DOC-01 à DOC-32 avec signataire et QR selon le tableau du CDC ; numéro unique non réutilisable (`REL-EC-2026-000315`, RG-DOC-1) ; page publique de vérification sans notes (valide, annulé, remplacé) ; duplicata avec mention, date et numéro d'origine ; annulation sans suppression ; version de modèle conservée ; FR ou EN selon la langue de la filière ; émission refusée sans PV signé (RG-DOC-3) ; blocage financier jamais appliqué au certificat de scolarité, au reçu, au relevé de compte, à l'attestation de paiement (RG-DOC-7) ; jamais de document « BTS » ou « HND ».

**2. Réutilisé tel quel (couvre l'essentiel du moteur).** `DocumentIssuer::issue/revoke`, types et modèles versionnés (`/document-types`, `/document-templates`, validation, nouvelles versions), variables en liste blanche, `TemplateGuard` (pas de script), rendu PDF `PdfRenderer` (Dompdf), QR, empreinte SHA-256, `POST /documents/{id}/duplicate` (mention « Duplicata — réimprimé le … » et numéro d'origine), `POST /documents/{id}/revoke` (motif, `replaced_by`), lots `/document-batches` avec file et exclusions motivées, demandes en ligne `/document-requests`, vérification publique limitée en débit avec titulaire masqué et journal, locale `fr|en` avec repli sur le français, cachet et signataire `/document-seal`.

**3. Partiel et écarts précis.**
- Numérotation : le gabarit accepte des littéraux et `{CODE}` (code de l'établissement), mais pas un code d'école interne ni la filière ; `REL-EC-2026-000315` (type, école, année, ordre) se code `REL-EC-{AAAA}-{N6}` par type seulement si le code d'école est un littéral, donc un type par école. Il manque un jeton dynamique `{ECOLE}`. Autre écart : la maquette et le socle utilisent `IPES-26-R-001204` (`{CODE}-{AA}-{LETTRE}-{N6}`) et le CDC `REL-EC-2026-000315` : deux formats, un seul à retenir.
- Signataire : un seul cachet et signataire par établissement (`document_seals` clé (tenant, établissement)) ; le CDC attribue un signataire par document (président du jury, directeur d'école, DG, comptable). À étendre ou à gérer par variables.
- États de vérification : valide et révoqué (avec `replaced_by_number`) ; « annulé » et « remplacé » du CDC sont les deux cas de `revoked` (motif et numéro du remplaçant), à afficher distinctement côté page.
- Précondition d'émission (PV signé, solde nul, éligibilité) : `DocumentIssuer` ne vérifie rien du métier ; c'est à l'appelant (RG-DOC-3 à faire dans `Higher`).
- Blocage financier : appliqué en lot seulement et selon le champ `blockable` du type ; le CDC liste quels documents sont non bloquables : marquer `blockable = false` sur ces types ; il faut y ajouter l'émission unitaire (demande S12).
- Page de vérification : le CDC demande « type, nom, date, statut » sans notes ; le socle renvoie aussi établissement, numéro et empreinte courte (acceptable) ; la mention de l'université de tutelle n'y figure pas.
- Modèles : un seul modèle de démonstration (`attestation_simple`) ; `report_card` et `deliberation_minutes` référencés par Assessment sans modèle.

**4. À créer dans `Higher/Documents`.**
- Seeder `HigherDocumentCatalog` : 32 types (code `DOC-01`…`DOC-32`, libellé, lettre, gabarit de numéro, `qr_enabled`, `blockable`, liste de variables) et modèles HTML FR et EN, avec en-tête officiel, signataire, cachet. Les modèles officiels exigés par la tutelle (relevé, PV, attestation de réussite, diplôme/attestation de fin de formation) ne sont pas dans le dépôt : seule la maquette Releve existe, avec des champs à renseigner (`[UNIVERSITÉ DE TUTELLE]`, `[N° D'ARRÊTÉ]`, `[ADRESSE DE VÉRIFICATION]`).
- `IssuanceGuard` par type : PV signé (relevés, attestations de résultats, liste de résultats), solde nul (quitus), éligibilité (DOC-17), jamais « BTS/HND » dans le titre (RG-EXN-3), choix de langue d'après la filière.
- Fournisseurs de données par document : pour chaque type, un `DocumentDataBuilder` qui produit les variables (relevé : UE, EC, CC, examen, note, lettre, crédits, cumul 90/180, mention, décision, session, délibération).
- Répartition par module (pour le planning des lots) : DOC-01 à 06 (ADM/INS : moitié A), DOC-07, 08, 13 (EDT/EXN), DOC-09, 10, 14, 15, 16 (NOT/DEL), DOC-11, 12, 17 à 19 (EXN/TUT), DOC-20 à 23 (STG), DOC-24 à 26 (ENS : moitié A), DOC-27 à 30 (FIN), DOC-31 (PRE/discipline), DOC-32 (PIL). Cette répartition reprend celle de `tasks.md` ; à confirmer avec le tableau du CDC.

**5. Extensions et demandes (non faites).**
- Paramétrage : types et modèles par `POST /document-types` et `/document-templates` ; un seeder de l'application suffit.
- Demande S16 : jeton `{ECOLE}`/`{CODE2}` (code d'une structure) dans `NumberPattern`, ou numéro fourni par l'appelant ; sinon un type par école.
- Demande S17 : signataire par type de document (ou variable `signer_name`).
- Demande S18 : garde de précondition enfichable avant `issue` (`IssuanceGuard`, enregistrée par le conteneur), et blocage financier appliqué à l'émission unitaire.
- Demande S19 : état « remplacé » distinct dans la réponse publique si utile.

**6. Risques et questions.**
- Les modèles officiels de l'université de tutelle, du MINESUP et de l'ordre (arrêtés, logos, mentions légales, devise « Paix – Travail – Patrie ») doivent être fournis : ne pas les inventer.
- Format de numéro : `REL-EC-2026-000315` (CDC) ou `IPES-26-R-001204` (maquette) ?
- Qui signe chaque DOC ; la signature numérique est-elle une image (cachet du socle) ou une signature électronique qualifiée ?
- Génération de 1 000 relevés en moins de 10 minutes (NF-04) : le socle propose la file par lots de 50 ; à tester avec les relevés IPES.

---

## POR : portails (FR-020)

**1. Exigé.** Portails étudiant, garant, enseignant, membre de jury, université de tutelle ; sélecteur « École » et vue « Toutes les écoles » ; identité visuelle de l'école ; consentement de l'étudiant majeur pour le partage avec le garant (le garant voit toujours la situation financière) ; notes visibles après publication et sans blocage financier ; double cursus (un compte, un matricule, deux parcours) ; comptes externes nominatifs en lecture seule.

**2. Réutilisé tel quel.** `/portal/family` (soldes, enfants, consentement `parent_access` via `AdultLearnerCheck` et `ConsentChecker`), `/portal/learner` (fiche, emploi du temps du jour), `/portal/teacher` (affectations `TeacherWorkload`, heures, saisies de notes à faire par `GradingTodo`), `LearnerCardBuilder` (résultats publiés via `ResultsDirectory`, blocage `results`/`documents` via `DebtorDirectory`), identité visuelle `establishments/{id}/branding`, `GET /me/contexts` (tuiles par établissement).

**3. Partiel.** Pas de portail jury ni tutelle ; portail étudiant sans candidature, réinscription, relevé détaillé par UE, crédits et dettes, requête de note ; sélecteur par école interne : l'école est un `org_unit`, mais le périmètre n'est pas appliqué par les permissions (voir inventaire) ; `AdultLearnerCheck` renvoie null si l'âge n'est pas connu (le portail traite alors l'étudiant en majeur). `PublishedResult` ne porte pas les crédits, la mention ni le détail des UE.

**4. À créer dans `Higher`.** `Portals/` IPES : endpoints `/higher/portal/student` (parcours, UE acquises, dettes, relevés disponibles), `/higher/portal/jury` (délibérations auxquelles le membre est convoqué, PV à signer), `/higher/portal/tutelle` (lecture filtrée par filières, journal), `ScopedBySchool`, sélecteur d'école et vue consolidée, double cursus (lecture de plusieurs inscriptions pour une personne : `EnrollmentDirectory::forPerson`).

**5. Extensions et demandes.** Demande S20 : étendre `PublishedResult` (crédits acquis et totaux, mention) ; enregistrer des « sections de portail » par application (aujourd'hui trois routes fixes) ; Demande S15 (permissions par portée).

**6. Questions.** Nature de l'identifiant de connexion de l'étudiant (matricule, téléphone) ; durée par défaut des comptes tutelle et auditeur ; partage des notes avec le garant pour un étudiant mineur.

---

## PIL : pilotage et tableaux de bord (FR-021, SC-006)

**1. Exigé.** Tableau de bord consolidé (étudiants, recouvrement par école et par canal, assiduité, vacations, avancement de la saisie, taux de réussite, résultats nationaux), statistiques d'effectifs MINESUP annuelles (DOC-32), alertes d'autorisation, calendrier académique, dernières inscriptions (maquette Dashboard) ; destinataires et fréquences du CDC.

**2. Réutilisé tel quel.** `GET /dashboards/direction` avec indicateurs `people.enrolled`, `people.enrolment_growth`, `billing.learners_in_arrears`, `billing.outstanding_xaf`, `billing.recovery_rate`, `attendance.absent_today`, `attendance.week_unjustified_rate`, `staff.teachers_active`, `staff.vacation_hours`, `scheduling.slots_today` ; vue `groupe` consolidée sur les établissements ; `AssessmentIndicators` ; rapports planifiés (`reporting-schedules`) ; `RegulatoryStatistics` (effectifs par année, structure, sexe) ; `TodoProvider` ; tuiles `TileIndicators` ; `/authorizations/expiring`.

**3. Partiel.** Les clés attendues sont figées dans l'enum `DashboardKind` (direction, censeur, groupe). Pas de ventilation par école interne ni par canal de paiement, pas de taux de réussite par UE, pas de coût des vacations, pas de résultats nationaux, pas de niveau dans `RegulatoryStatistics` (indisponible, motif écrit), pas de statistiques MINESUP.

**4. À créer dans `Higher/Dashboards`.** Fournisseurs `IndicatorProvider` (enregistrés sous `reporting.indicators.higher`, module `higher`) : recouvrement par école (factures filtrées par structure), taux de réussite par UE et par filière, avancement de la saisie par EC, candidatures, heures et coût des vacations, résultats nationaux, alerte d'autorisation. Export des statistiques d'effectifs MINESUP (DOC-32), modèle configurable.

**5. Extensions et demandes.** Demande S21 : `DashboardKind` extensible (types `ecole`, `academique`, `tutelle`) ou route générique avec la liste des indicateurs attendus fournie par l'application ; `ADDITIVE` à ouvrir aux clés des applications ; ventilation `by_level` dans `RegulatoryStatistics` si un contrat expose le niveau.

**6. Questions.** Définitions exactes : taux de réussite (inscrits ou présents ? session normale seule ?), recouvrement (facturé net ou brut ?). Fraîcheur « à jour chaque jour » (SC-006) : calcul à la volée ou instantané nocturne ?

---

## Rôles R01-R23

**Ce qui est connu.** Le socle seed 8 rôles (`roles` : `super_admin`, `direction_groupe`, `directeur`, `comptable`, `caissier`, `enseignant`, `parent`, `apprenant`). Le code distingue deux niveaux : `tenant` (tous les établissements) et `establishment` ; `is_sensitive = 1` impose la 2FA. Les libellés alignés avec la maquette : R02 Directeur général (= `direction_groupe`), R04 Directeur d'école (T329). Le CDC (tableau des 23 rôles et matrice lecture/écriture/validation) n'est pas dans le dépôt : seuls sont nommés dans la spec 002 et le plan : R02, R04, R09/R10 (enseignants), R12 (étudiant), R13 (garant), R21 (jury), R22 (tutelle), R23 (auditeur), et en liste non numérotée : chef de département, responsable de filière, scolarité, examens, vacataire. **Les autres numéros ne sont pas devinés.**

| Rôle CDC | Équivalent socle | Permissions socle déjà existantes | Permissions à créer | État |
|---|---|---|---|---|
| R01 (à confirmer : sans doute administrateur plateforme) | `super_admin` | `settings.manage`, `identity.*`, `organisation.*` | aucune | à confirmer |
| R02 Directeur général | `direction_groupe` (tenant) | lecture people/billing/documents, approbations | `higher.national_list.export`, `assessment.reopen.alert` (réception d'alertes) | libellé aligné (T329) |
| R03 (non défini dans le dépôt) | à confirmer | | | à demander |
| R04 Directeur d'école | `directeur` (establishment) | `assessment.*`, `billing.*`, `documents.*`, `staff.*` | portée école (applicative), `assessment.reopen` (directeur académique), `higher.program.manage`, `higher.admission.decide` | partiel |
| Directeur académique (numéro à confirmer) | `directeur` | idem | `assessment.reopen`, `higher.regulation.change` | à créer |
| Chef de département | à créer (proche de `directeur` restreint) | `assessment.validate`, `staff.read`, `vacations.validate` | `assessment.lock`, `higher.assignment.manage` | à créer |
| Responsable de filière | à créer | `assessment.read`, `scheduling.read` | `higher.curriculum.manage` | à créer |
| Scolarité | à créer | `people.manage`, `documents.issue`, `assessment.publish` | `higher.enrol`, `higher.document.issue` | à créer |
| Comptable / Caissier | `comptable`, `caissier` | `billing.manage`, `billing.reversal.approve`, `billing.cash`, `billing.reversal.request` | `higher.quitus.issue` | couvert |
| Examens | à créer | `assessment.publish` | `higher.exam.manage`, `higher.national_list.manage` | à créer |
| Président et membre de jury (R21) | à créer | `assessment.deliberation.manage` | `assessment.deliberation.sign` (président), accès `/higher/portal/jury` | à créer |
| Enseignant permanent, vacataire (R09/R10) | `enseignant` | `grades.enter`, `attendance.take` (seed) ; `assessment.enter` à vérifier | `assessment.submit` | partiel |
| Étudiant (R12) | `apprenant` | `self.read` | portail étudiant | couvert |
| Garant (R13) | `parent` | `self.read`, `documents.request` | consentement géré par People | couvert |
| Université de tutelle (R22) | à créer (niveau establishment, durée limitée) | `documents.read` | `higher.tutelage.read` | à créer |
| Auditeur externe (R23) | à créer (lecture seule) | `audit.read` si existant | idem, durée limitée | à créer |
| Autres R05-R08, R11, R14-R20 | à confirmer avec le CDC | | | à demander |

**Comment les déclarer.** Les rôles et leurs permissions sont des données globales (`roles`, `role_permissions`), écrites aujourd'hui par des migrations du noyau (`database/migrations/20261008…_role_permissions_*.sql`), jamais par un module. `Higher` ne peut donc pas les créer lui-même. Approche proposée :
1. Une migration partagée `database/migrations/AAAAMMJJHHMM_roles_higher.sql` (hors périmètre de cet agent : demande de changement partagé) qui insère les rôles R04 à R23 avec `level = 'establishment'`, `is_sensitive` selon la matrice, puis leurs `role_permissions`.
2. Les permissions utilisées par `Higher` sont préfixées `higher.*` ; celles du socle (`assessment.*`, `billing.*`, `documents.*`) sont réutilisées.
3. Les affectations passent par `POST /role-assignments` avec `scope_type = org_unit` (école ou département) ou `class` (groupe), avec `scope_id`.
4. Le filtre école, département, filière, groupe n'existant pas dans `PermissionChecker` (établissement seul), `Higher/Policies/ScopedBySchool` (T208) et `RoleMatrix` doivent le faire en lisant `Identity\RoleDirectory` ; un test d'isolation (TST-03, TST-12) est obligatoire.
5. `RolePermissionsTest` du socle devra être étendu par la migration.
6. Séparation des tâches (saisie ≠ verrouillage, demande ≠ validation) : par les permissions distinctes `assessment.enter`, `assessment.lock`, `assessment.correction.approve` et le contrôle auteur ≠ validateur de `Approvals`.

**Questions.** Fournir le tableau complet des 23 rôles (numéro, libellé, niveau, sensibilité 2FA) et la matrice L/E/V. Un utilisateur peut-il cumuler plusieurs rôles par école ? Le « directeur académique » est-il un rôle distinct du directeur d'école ?

---

## Synthèse chiffrée (partie B, maille fonctionnelle, indicative)

| Module | Exigences retenues | Couvert par le socle | Partiel | À créer |
|---|---|---|---|---|
| NOT | 9 | 3 | 4 | 2 |
| DEL | 8 | 2 | 3 | 3 |
| FIN | 6 | 2 | 2 | 2 |
| STG | 6 | 0 | 0 | 6 |
| EXN | 7 | 0 | 1 | 6 |
| TUT | 5 | 0 | 1 | 4 |
| DOC (RG-DOC 1-8) | 8 | 5 | 2 | 1 |
| DOC (modèles) | 32 | 0 | 0 | 32 (moteur prêt) |
| POR | 5 | 0 | 2 | 3 |
| PIL | 4 | 0 | 3 | 1 |
| Rôles | 23 | 2 | 4 | 17 |
| **Total hors modèles de documents** | **81** | **14** | **22** | **45** |

## Les cinq écarts les plus importants

1. Règles de calcul : une seule version par établissement et année, pas par programme (LMD, BTS, HND), et le moteur ne connaît ni MGP, ni lettres et points, ni rattrapage combiné, ni décisions annuelles (60/45/redoublement/exclusion), ni dettes, ni session (demandes S5 à S7).
2. PV et publication : le socle publie sur simple clôture, sans signature obligatoire, sans 2FA, sans empreinte SHA-256, sans points de jury plafonnés, sans réouverture avec nouveau PV (garde enfichable S8 + service d'enveloppe IPES).
3. Modèles de documents officiels (32 types FR/EN, relevé, PV, attestations) : absents et dépendants des textes de la tutelle ; numérotation `REL-EC-…` non réalisable sans jeton d'école ; préconditions d'émission non enfichables (S16 à S18).
4. Rôles et périmètres : 8 rôles au socle pour 23 attendus, matrice du CDC absente du dépôt, périmètre école/département/filière non appliqué par `PermissionChecker` (migration partagée de rôles, S15).
5. Modules sans aucun socle : stages et soutenances, examens nationaux BTS/HND avec export MINESUP, transmissions à la tutelle (le seul export est un CSV générique d'effectifs).

## Questions à poser à l'utilisateur

1. Fournir le CDC IPES (PDF) ou au moins : tableau des 32 documents avec signataires, matrice des 23 rôles, formules F1 à F7, tableau des tâches.
2. Quels modèles officiels (relevé, PV, attestations, convention de stage) l'université de tutelle et le MINESUP imposent-ils ? Sans eux, les modèles resteront des maquettes.
3. Format de numéro : `REL-EC-2026-000315` (CDC) ou `IPES-26-R-001204` (maquette) ? Code d'école dans le numéro ?
4. Pondération CC/examen : 30/70 (maquette Releve), 40/60 (CDC BTS/HND) ou par règlement de tutelle ? Barème de lettres retenu ?
5. Points de jury : plafond par UE, semestre ou année ? Qui peut les accorder ?
6. Format des listes MINESUP BTS et HND ; canal de transmission à la tutelle ; accès direct de la tutelle (R22) en version 1 ?
7. Signature du PV et des documents : code de confirmation simple ou certificat qualifié ?
8. Autoriser les demandes de changement du socle S1 à S21 (listées ci-dessus), qui touchent `Assessment`, `Documents`, `Billing`, `Reporting`, `Identity` et `database/migrations` ?
9. Plafond de bourses, frais non remboursables, règles de tiers payeur, part reversée à la tutelle.
10. Bibliothèque d'export Excel : accepter PhpSpreadsheet (le plan cite `maatwebsite/excel`, propre à Laravel) ?
