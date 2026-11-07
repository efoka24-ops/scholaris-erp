# Audit de réutilisation : ce qui existe déjà pour l'application Secondaire

Date : 2026-10-22. Méthode : lecture seule du dépôt (chemins vérifiés ce jour), comme `specs/006-audit-primaire-maternelle/`. Complète [socle-reuse.md](socle-reuse.md) (rédigé le 15 octobre) en indiquant **ce qui a changé depuis** et **ce qui manque réellement**. Aucune ligne de code n'est écrite ici.

## Résumé pour les non-techniciens
- **Beaucoup existe déjà** et ne sera pas refait : le moteur de notes avec ses cycles (brouillon, validé, verrouillé, publié), la double vérification au verrouillage, les corrections motivées, le conseil de classe, les bulletins en file avec QR, l'appel hors ligne (par créneau horaire), les frais avec remise fratrie, le paiement mobile, les relances, les documents numérotés, les portails parent et élève.
- **Depuis le 15 octobre**, le primaire privé (`primary`) et la maternelle ont livré des briques que le secondaire réutilise : périodes imbriquées (séquence dans trimestre), agrégat de périodes, préremplissage des lignes de conseil, variables de bulletin, copie de squelette d'application (garde d'écriture, matrice des rôles, portée par classe).
- **Il manque encore** (aucun code au dépôt) : l'application elle-même (`src/Modules/Apps/Secondary/` ne contient qu'un `.gitkeep`), la période « année », le rang par matière, la règle d'affectation des paiements du CDC, le garde de catalogue des frais publics, le lien de téléchargement signé des bulletins, l'expiration des comptes externes, les rôles et modèles de messages du secondaire, et tout l'enseignement technique (ateliers, stages) côté secondaire.
- **Nouveau avec la décision « un seul produit »** : le type d'établissement est déjà disponible aux autres modules (`EstablishmentInfo::$subtype`) ; il faut seulement un résolveur de profil et une garde de module.

Légende : **E** = existe et réutilisable tel quel ; **P** = existe, écart décrit ; **A** = absent. Les identifiants SSn renvoient à [socle-reuse.md](socle-reuse.md) §2.

## 1. Le type d'établissement (nouveau besoin)
| Besoin | État | Chemin vérifié | Reste à faire |
|---|---|---|---|
| Liste fermée des 7 types, codes stables | E | `src/Modules/Socle/Organisation/Contracts/EstablishmentSubtypes.php` (`CODES`, `allowedFor`) | rien |
| Type demandé à l'accès, obligatoire pour le secondaire | E | `src/Modules/Socle/Platform/Application/AccessRequestService.php`, `.../Platform/Http/PublicAccessController.php`, migration `.../Platform/Database/Migrations/202610211100_platform_access_request_subtype.sql`, écran `web/src/views/platform/AccessRequestView.vue` | rien |
| Type stocké sur l'établissement et lisible par les autres modules | E | migration `.../Organisation/Database/Migrations/202610211101_organisation_establishment_subtype.sql`, `.../Organisation/Application/ClientOnboarding.php`, `.../Organisation/Contracts/EstablishmentInfo.php` (`$subtype`), `.../Organisation/Infrastructure/EstablishmentMapper.php` | l'application lit `EstablishmentDirectory::find()` puis `->subtype` |
| Libellés des 7 types côté public | E | `web/src/i18n/platform-fr.ts` (`pub.schoolType.*`), `web/src/views/platform/catalogGroups.ts` (`SCHOOL_TYPES`) | réutiliser pour l'assistant de paramétrage |
| Profil par type, modules activables, garde de module | A | — | `Setup/` : `ProfileResolver`, `ModuleGate` (tâches T813, T814) |

## 2. Évaluation, conseil de classe, bulletins
| Besoin | État | Chemin vérifié | Écart |
|---|---|---|---|
| Notes, épreuves, cycle de vie, 2FA au verrouillage | E | `src/Modules/Socle/Assessment/Domain/Lifecycle.php`, paramètres `assessment.lifecycle`, `assessment.segregate_lock`, `assessment.step`, `assessment.absence_codes` ; contrat `Identity/Contracts/RecentSecondFactor.php` | rien |
| Corrections motivées à plusieurs validations | E | `src/Modules/Socle/Assessment/Application/CorrectionService.php` | rien |
| Moteur de calcul, rangs avec ex æquo | E | `src/Modules/Socle/Assessment/Domain/RuleEngine.php` (`ranks`), contrat `Assessment/Contracts/RuleStrategy.php`, `RuleCalculator.php`, `RuleScopeLineage.php` | stratégie `secondary` à écrire (arrondi à chaque étape, matières non évaluées, pratique) |
| Calcul d'une période parente (trimestre = séquences, année = trimestres) | P | `.../Assessment/Contracts/PeriodAggregatingStrategy.php`, appel dans `.../Assessment/Application/ResultService.php` ; hiérarchie des périodes : migration `.../Organisation/Database/Migrations/202610201101_organisation_period_parent.sql` | la signature réelle `aggregatePeriods(rules, periods)` ne reçoit pas les unités propres de la période (SS3 la prévoyait) ; la **période « année »** n'existe pas : `PeriodKind` = trimestre, séquence, semestre (SS2) |
| Rang et statistiques par matière | A | — | SS4 (`assessment.subject_ranks`) |
| Conseil de classe, décisions, refus de valider si épreuve éditable | E | `src/Modules/Socle/Assessment/Application/CouncilService.php` | lignes enrichies (conduite, alertes) : voir ci-dessous |
| Préremplissage des lignes de conseil par une application | P | `.../Assessment/Contracts/CouncilLineProvider.php` (prefill : conduite, appréciation, extras) ; modèle : `src/Modules/Apps/Primary/Grades/Infrastructure/PrimaryCouncilLines.php` | colonne `line_extras` présente pour les délibérations de l'IPES seulement (`deliberation_lines`), pas pour `council_lines` (SS6) |
| Variables de bulletin fournies par une application | P | `.../Assessment/Contracts/ReportCardVariables.php` | la doc du contrat impose le préfixe `primary_` : à généraliser en `<application>_` (SS5) ; type de document par nature de période (`assessment.report_card_types`) absent |
| Bulletins en file, QR, publication | E | `src/Modules/Socle/Assessment/Application/ReportCardService.php`, `src/Modules/Socle/Documents/**` | modèles `secondary_*` à écrire |
| Rectificatif et duplicata | P | `.../Documents/Contracts/DocumentReissuer.php` (`reissue` avec motif), `.../Documents/Http/Controllers/DocumentController.php` (`duplicate`) | route `rectify` et état « remplacé » à la vérification publique (SS12) à confirmer |
| Lien de téléchargement signé 24 h | A | — | SS14 |
| Passage, montée de classe | E | `src/Modules/Socle/People/Application/YearPromotion.php`, `.../Organisation/Contracts/TypedPromotionPlanner.php`, clés `organisation.closure.*` | propositions selon paramètres du secondaire |
| Modèle d'application qui branche tout cela | E | `src/Modules/Apps/Primary/Grades/Domain/Strategy/PrimaryRuleStrategy.php`, `.../Grades/Infrastructure/PrimaryRuleScopes.php` | à copier dans l'esprit, pas à partager (règle : une application ne dépend pas d'une autre) |

## 3. Appel, présences, discipline
| Besoin | État | Chemin vérifié | Écart |
|---|---|---|---|
| Appel par créneau horaire, hors ligne, idempotent | E | `src/Modules/Socle/Attendance/Application/SessionService.php`, `.../Attendance/Contracts/AttendanceCodes.php`, `web/src/offline/attendance.ts`, `web/src/offline/queue.ts`, écran `web/src/views/AttendanceView.vue` | le secondaire utilise le mode `slot` (défaut) ; le mode `day_part` est réservé aux écoles primaires et maternelles |
| Cumul d'absences par période | E | `.../Attendance/Contracts/AttendanceSummaryProvider.php` | barème du règlement intérieur : paramètre `secondary.discipline.scale` |
| Heures enseignées tirées de l'appel | E | `.../Attendance/Application/TaughtHoursReporter.php`, `.../Staff/Contracts/TaughtHoursRecorder.php` | validation par le censeur à ajouter côté Secondary |
| Incidents, sanctions avec approbation | E | module `Attendance` du socle (routes `/incidents`, `/sanctions`) | propositions automatiques et conseil de discipline à créer |
| Statuts d'appel « exclu de cours », « infirmerie » | A | — | SS10 facultatif ou tables propres (Q14) |
| Un seul SMS par élève et par jour à la 1re heure | à vérifier | `.../Attendance/**` (`AbsenceNotifier`) | tâche T884 |

## 4. Frais, paiements, documents
| Besoin | État | Chemin vérifié | Écart |
|---|---|---|---|
| Postes de frais, échéanciers, factures, remises fratrie | E | `src/Modules/Socle/Billing/Contracts/InvoiceIssuer.php`, `.../Billing/Application/SiblingDiscountResolver.php`, natures `billing.fee_kinds.<application>` | natures `secondary` à déclarer |
| Paiement mobile, reçus, rapprochement, relances | E | `.../Billing/Contracts/InvoicePayer.php`, `PaymentRequester.php`, commandes `reminders:send`, `payments` | rien |
| Règle d'affectation d'un paiement | P | `.../Billing/Domain/AllocationRules.php`, `RegistrationOverdueUpcomingRule.php` | règle « par nature » du CDC : SS7 (`KindPriorityRule` absente) |
| Garde de création d'un frais (catalogue public) | A | — | SS8 (`FeeItemGuard` absente) |
| Documents numérotés avec QR, gardes de délivrance | E | `.../Documents/Contracts/DocumentIssuer.php`, `DocumentCatalog.php`, clés `documents.issuance_guards.<application>` | 34 types `secondary_*` à déclarer |
| Export de fichiers (CSV, XLSX), types configurables | E | `.../Integrations/Contracts/TabularExporter.php`, `ConfiguredExportType.php`, `CartescolaireExporter.php` | formats réels du site de la carte scolaire : Q1 |
| Imports en masse de personnes (reprise de données) | E | `src/Modules/Socle/Imports/Application/PeopleImportService.php` ; écrans `web/src/views/admin/**` | colonnes propres au secondaire : matricule, série, classe |

## 5. Organisation, personnel, emplois du temps, portails, messages
| Besoin | État | Chemin vérifié | Écart |
|---|---|---|---|
| Structures (niveaux, classes, groupes), salles, autorisations d'ouverture | E | `.../Organisation/Contracts/StructureWriter.php`, `AuthorizationRegistry.php`, `EnrolmentGuard.php` ; `rooms` | séries, spécialités, coefficients par niveau : tables propres |
| Paramètres hérités plateforme > client > établissement | E | `.../Organisation/Contracts/SettingsReader.php` | clés `secondary.*` |
| Créneaux, conflits, écriture d'un emploi du temps | E | `.../Scheduling/Contracts/TimetableWriter.php` ; écran `web/src/views/TimetableView.vue` | générateur assisté : A (propre au secondaire) |
| Personnel, vacations, congés | E | `.../Staff/Contracts/VacationCalculator.php`, `VacationPayabilityCheck.php`, `TaughtHoursRecorder.php` | paie simplifiée du privé : A |
| Messages, SMS, catégorie essentielle | E | `.../Messaging/Contracts/Messenger.php` | modèles NOT-01 à 14 : A (SS11) |
| Portails parent et élève, « à remplir » enseignant | E | `.../Portals/Contracts/PortalSection.php`, `GradingTodo.php` ; écrans `web/src/views/FamilyPortalView.vue`, `LearnerPortalView.vue`, `teacher/TeacherPortalView.vue` | sections et todo `secondary` |
| Tableaux de bord, indicateurs | E | `.../Reporting/Contracts/IndicatorProvider.php` | indicateurs du CDC (O1 à O7) |
| Lots hors ligne signés | E | `src/Modules/Socle/Sync/Contracts/BatchItemHandler.php`, `POST /sync/batches` | gestionnaires `sync.handlers.secondary.*` si besoin |
| Rôles et droits | P | gabarit : `src/Modules/Apps/Primary/Policies/RoleMatrix.php`, `ScopeResolver.php` ; service `Identity/Contracts/RoleAssignmentGateway.php` | rôles du secondaire (SS9) et expiration (SS13) : A |
| Abonnement : suspension (423), fermeture (403) | E | `.../Platform/Contracts/ApplicationAccess.php` ; gabarit `src/Modules/Apps/Primary/Support/{WriteGate,GatedAction,PrimaryRoutes}.php` | à copier sous `Secondary/Support` |
| Catalogue | E | migration `.../Platform/Database/Migrations/202610083451_platform_catalog_seed.sql` : ligne `secondary`, `coming_soon`, 60 000 XAF par mois, 90 jours, 15 jours de grâce | un seul manifeste pour les 7 types |

## 6. Ce que les autres applications apportent
| Source | Réutilisation | Chemins |
|---|---|---|
| **IPES (`Higher`)** | gabarit d'une application complète : garde d'écriture, routes gardées, matrice de rôles, tableau de bord, reprise de données | `src/Modules/Apps/Higher/{HigherApplication.php,Support,Policies,Dashboards,Migration}` |
| IPES : stages et conventions | **idée et écrans** à reprendre pour les stages techniques (entreprises, conventions, suivi, soutenance) : le code lui-même reste propre à l'IPES (tables `hi_*`) | `src/Modules/Apps/Higher/Internships/Application/{ConventionService,CompanyService,FollowUpService}.php` |
| IPES : examens nationaux | idée : sessions, candidats, contrôle d'éligibilité, export, résultats | `src/Modules/Apps/Higher/NationalExams/Application/{SessionService,CandidateService,EligibilityChecker,ExportService,ResultService}.php` |
| IPES : cahier de séance et heures payables | idée : séances signées, heures validées, vacations | `src/Modules/Apps/Higher/Teaching/Application/{SessionLogService,HigherPayabilityCheck}.php`, `.../Vacations/Application/{StatementCalculator,PayabilityGate}.php` |
| IPES : reprise de données | modèle de lots avec contrôle ligne à ligne | `src/Modules/Apps/Higher/Migration/Application/{BatchService,PilotSetup}.php` |
| **Primaire privé (`Primary`)** | squelette récent le plus proche, stratégie paramétrée sans seuil codé, feuilles hors ligne, cahier de liaison, portail parent, tests d'architecture | `src/Modules/Apps/Primary/**`, `web/src/views/primary/**`, `tests/Architecture/PrimaryConventionsTest.php` |
| Primaire privé : facture d'inscription et documents avec QR | modèle direct pour DOC-02 à 06 et la facture d'inscription | `src/Modules/Apps/Primary/{Billing,Documents,Enrolment}/**` |
| **Maternelle privée (spécification)** | méthode de mutualisation (contrats partagés plutôt que code copié) et critères d'acceptation de la sortie sécurisée si l'internat la demande | `specs/007-application-maternelle-privee/` |
| Écoles publiques (`PublicSchools`) | alertes d'assiduité, cahier d'appel mensuel : idées pour DOC-15 et DOC-26 ; **aucun code partagé** | `src/Modules/Apps/PublicSchools/Attendance/**` |

Règle rappelée : une application n'utilise que les `Contracts/` du socle et jamais le code d'une autre application (`tests/Architecture/StandardsTest`). « Réutiliser » ici veut dire : même méthode, même gabarit, ou contrat de socle partagé, pas un `use` croisé.

## 7. Front : écrans existants et nouveaux
| Besoin | Existant | À créer sous `web/src/views/secondary/` |
|---|---|---|
| Appel par heure, hors ligne | `web/src/views/AttendanceView.vue`, `web/src/offline/attendance.ts` | habillage secondaire (série, matière, heure), cahier de textes |
| Saisie de notes, hors ligne | `web/src/views/GradesView.vue`, `web/src/offline/grades.ts` | séquences, épreuves communes, lettres [EN] |
| Emploi du temps | `web/src/views/TimetableView.vue` | générateur assisté |
| Bulletins | `web/src/views/ReportCardsView.vue` | dossier de conseil, appréciations, orientation |
| Frais et paiements | `web/src/views/BillingView.vue` | grille, catalogue public, demandes d'ajout |
| Portails | `FamilyPortalView.vue`, `LearnerPortalView.vue`, `teacher/TeacherPortalView.vue` | sections `secondary` |
| Assistant de paramétrage selon le type | — | **nouveau** : assistant de profil (type, modules, cycles) et menu qui suit les modules actifs |
| Ateliers, stages | — | **nouveau** (modules `workshops`, `internships`) |

## 8. Pièges constatés (à ne pas refaire)
1. **Numérotation des tâches** : T800 à T979 appartiennent à ce dossier (secondaire) ; la maternelle privée utilise T1000 et suivants.
2. **Tables préfixées `se_`** : ne pas dupliquer `assessments`, `grades`, `results`, `class_councils`, `attendance_*`, `sanctions` du CDC : ils restent dans le socle (décision D1 de [plan.md](plan.md)).
3. **Variables de bulletin** : la doc du contrat parle de `primary_` ; ne pas inventer un second contrat, généraliser le préfixe.
4. **`PeriodAggregatingStrategy`** : ne pas supposer la signature de SS3 ; lire le contrat actuel avant d'écrire la stratégie.
5. **Mode d'appel** : ne pas activer `day_part` pour le secondaire (réservé aux petits niveaux).
6. **Valeurs du CDC** : les chiffres du CDC servent de jeu de test `example_cdc` ; jamais de défaut silencieux dans le code ni la base livrée.
7. **Un type = des paramètres** : pas de `if` sur le code de type en dehors du résolveur de profil.
