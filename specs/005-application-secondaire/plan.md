# Plan technique : application Secondaire

Date : 2026-10-15. Entrées : [spec.md](spec.md), [etat-des-lieux.md](etat-des-lieux.md), [socle-reuse.md](socle-reuse.md), [data-model.md](data-model.md). Modèle : `specs/002-application-ipes/plan.md` et le code de `src/Modules/Apps/Higher`.

## 1. Cadre (écart voulu avec le CDC)
PHP 8.1 sans framework, PSR, php-di, fast-route, MySQL 8 (tests SQLite), Dompdf, file `document_jobs` traitée par `documents:process-jobs` (cron), messages par `queue:send`. Pas de Laravel, PostgreSQL, Redis, Horizon, Gotenberg. Module : `src/Modules/Apps/Secondary` (le CDC dit `app/Modules/Apps/Secondary`).

## 2. Arborescence
```
src/Modules/Apps/Secondary/
  SecondaryApplication.php   manifeste platform.applications.secondary, vocabulaire, rôles, permissions secondary.*
  definitions.php            racine : manifeste + array_merge des */definitions.php (glob)
  routes.php                 racine : require des */routes.php (glob)
  Support/                   WriteGate, GatedAction, SecondaryRoutes, Perimeter, ScopedByClass, Clock
  Policies/                  RoleMatrix, ScopeResolver
  Setup/          ETB  profils d'établissement et de classe, séries/spécialités, coefficients, salles, calendrier
  Enrolment/      INS  préinscriptions, profils élèves, transferts, réinscription, garde d'inscription
  CarteScolaire/  CSC  imports de matricules et paiements, rapprochement, écarts, export de fin d'année
  Fees/           FIN  catalogue public, demandes d'ajout, garde SS8, plans de paiement, natures de frais
  Timetable/      EDT  générateur assisté, profils de salles, dédoublement
  Syllabus/       CTX  progressions, cahier de textes, visas, couverture, heures pour RHP
  Discipline/     PRE  barème, propositions, conseils de discipline, exclusions de cours
  Grades/         NOT  épreuves communes, lettres EN, saisine fraude, todo enseignant
  Councils/       BUL, PAS  stratégie secondary, fournisseurs SS5/SS6, appréciations, instantanés, orientation, recours
  OfficialExams/  EXA  sessions, candidats, contrôles, exports, résultats
  Workshops/      ATL, STG  ateliers, postes, séances, EPI, stock, grilles pratiques, sécurité, stages
  Staff/          RHP  statuts, heures validées, paie simplifiée [PRI]
  Services/       SVC  internat, abonnements cantine/transport, bibliothèque, infirmerie
  Documents/      DocumentTypeProvider (34 types secondary_*), gardes de délivrance, variables
  Notifications/  déclencheurs NOT-01 à NOT-14
  Dashboards/     indicateurs (PIL), DOC-32 à DOC-34
  Portals/        sections élève/parent/enseignant
```
Chaque sous-module : `Application`, `Contracts` (interfaces internes partagées entre sous-modules Secondary), `Domain`, `Http`, `Infrastructure`, `Database/Migrations/AAAAMMJJHHMM_secondary_<module>_*.sql`, `routes.php`, `definitions.php`.

## 3. Décisions
| # | Décision | Motif |
|---|----------|-------|
| D1 | Notes, résultats, conseils de classe, présences, sanctions, frais restent dans le **socle** (pas de `se_marks`, `se_absences`, `se_sanctions`, `se_period_results` du CDC) | le socle a été conçu pour le secondaire ; appel et notes hors ligne déjà au front ; une seule vérité ; contraire à IPES qui a ses tables `hi_grade_*` pour son cycle propre |
| D2 | Les calculs passent par une stratégie `secondary` (`assessment.rule_strategies.secondary`) implémentant `RuleStrategy` + `PeriodAggregatingStrategy` (SS3) | arrondi à chaque étape, agrégats, pratique professionnelle, conduite |
| D3 | 6 périodes `sequence` filles de 3 `trimester`, filles d'une période `year` (SS1, SS2) ; épreuves rattachées aux séquences | conseil trimestriel et annuel sur les périodes du socle |
| D4 | Les API du CDC qui ne font qu'envelopper le socle (API-05, 07, 08, 09, 11) sont exposées sous `/secondary/...` comme **façades** qui vérifient le périmètre `ScopedByClass` puis appellent le socle par contrat ; si le contrat d'écriture n'existe pas, l'écran appelle la route du socle et la façade n'est pas créée (noté dans l'OpenAPI) | ne jamais lire ni écrire les tables du socle |
| D5 | Le matricule national est stocké dans `se_student_profiles.national_id` (+ `person_identifiers` kind `cs_mat` via la route du socle) ; jamais dans une URL ni une page publique ; les routes utilisent l'ULID interne | sécurité du CDC |
| D6 | cartescolaire.cm : fichiers seulement (import CSV/XLSX, export CSV) ; colonnes en paramètres `secondary.carte_scolaire.columns.*` ; aucune automatisation de navigateur | RG-CSC-1, Q1 |
| D7 | Référentiels (niveaux, séries, spécialités, examens, coefficients officiels) = paramètres de niveau plateforme `secondary.referentials.*` (héritage plateforme > client > établissement) + copie versionnée dans `se_subject_coefficients` | « listes tenues au niveau de la plateforme » |
| D8 | Une application pour tous les types : le type et le statut (`se_establishment_profiles`) activent les fonctions [PRI]/[PUB]/[TEC]/[EN] ; aucune route n'est cachée, elle répond 409 `not_applicable` | « une seule application qui s'adapte » |
| D9 | Paie simplifiée du privé portée par Secondary (`se_payslips`) ; remontée au socle si une 2e application la demande | absente du socle |
| D10 | Valeurs du CDC = paramètres par défaut marqués « à confirmer » ; `SecondaryConventionsTest` interdit les littéraux de seuil dans `Domain/` hors classes `Defaults` | ne jamais inventer une règle officielle |

## 4. Dépendances
- Vers le socle : SS1, SS2, SS3 (bloquent Councils), SS4, SS5 (bulletins), SS6 (conduite), SS7 (FIN privé), SS8 (FIN public), SS9 (tous les écrans), SS11 (notifications), SS12, SS14 (bulletins publiés), SS10 et SS13 (facultatifs).
- Entre sous-modules Secondary (interfaces dans `<Module>/Contracts`) : Setup → tous ; Enrolment → CarteScolaire, OfficialExams, Fees ; Syllabus → Staff (heures), Councils (couverture DOC-12) ; Discipline → Councils (conduite) ; Workshops → Councils (pratique), OfficialExams (épreuves pratiques) ; Councils → OfficialExams (moyenne annuelle), CarteScolaire (fin d'année).
- Vers d'autres applications : Primaire (EX-INS-04) par fichier d'échange, pas de dépendance de code.

## 5. Ordre (aligné sur les lots du CDC)
1. Vague 0 : SS1-SS14, squelette, rôles (SS9), conventions.
2. E1 (S24-S28) : Setup, Enrolment, CarteScolaire, Fees, Documents DOC-01 à 06 et 27 à 29, portail inscription/paiement.
3. E2 (S29-S33) : Timetable, Syllabus, Discipline, Grades, DOC-07, 08, 15, 17, 18, 25.
4. E3 (S34-S38) : Councils (BUL, PAS), OfficialExams, Workshops (ATL, STG), Staff, DOC-09 à 14, 16, 19 à 24, 26, 30, 31.
5. E4 (S39-S40 + pilote) : Dashboards, Services, DOC-32 à 34, reprise de données, recette, charge.

## 6. Tests
`tests/Unit/Secondary/<Module>/`, `tests/Feature/Secondary/<Module>/`, `tests/Architecture/SecondaryConventionsTest.php`, `tests/Isolation/SecondaryScopeTest.php`, `tests/Acceptance/Secondary/` (TST-01 à TST-10 et exemples E1-E7), `tests/Load/Secondary/` (NF-S1, NF-S2), OpenAPI `specs/001-socle-plateforme/contracts/openapi.d/secondary-<module>.yaml`, `tests/ApiContract` étendu automatiquement.

## 7. Charge
Le CDC estime 412 jours-personne (E1 106, E2 117, E3 121, E4 68) en plus du socle. La réutilisation du socle (D1) réduit surtout NOT/PRE/BUL ; les SS ajoutent environ 15 jours au socle. Pas de réestimation chiffrée sans les réponses Q1-Q5.
