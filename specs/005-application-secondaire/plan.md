# Plan technique : application Enseignement secondaire (un produit, 7 types d'établissement)

Date : 2026-10-22 (remplace la version du 2026-10-15). Entrées : [spec.md](spec.md), [decision-un-produit-secondaire.md](decision-un-produit-secondaire.md), [audit-reutilisation.md](audit-reutilisation.md), [socle-reuse.md](socle-reuse.md), [data-model.md](data-model.md). Modèles : `src/Modules/Apps/Higher` (application complète), `src/Modules/Apps/Primary` (squelette récent), `specs/003-application-primaire/plan.md`, `specs/007-application-maternelle-privee/plan.md`. **Aucun code n'est écrit par ce document.**

## Résumé pour les non-techniciens
- On construit **une seule application** sous `src/Modules/Apps/Secondary/`. Elle apprend à lire le **type d'établissement** déjà enregistré et en déduit un **profil** (cycles, enseignements, modules). Chaque grande fonction est un **module** qu'on peut allumer ou éteindre : si un module est éteint, l'établissement ne le voit pas et le serveur répond « module non activé ».
- On **ne refait pas** ce qui existe : notes, bulletins, appel, frais, documents et portails viennent du socle (voir [audit-reutilisation.md](audit-reutilisation.md)). On copie la méthode de l'IPES et du primaire privé, pas leur code.
- Deux garde-fous automatiques : (1) toute route est protégée par l'abonnement (423 si suspendu, 403 si fermé) et par le module concerné ; (2) aucune valeur réglementaire n'est écrite dans le code.
- Le travail se fait en **vagues de 3 agents au plus**, chacun dans ses propres dossiers ([wave-plan.md](wave-plan.md)).

## 1. Cadre (réel, non négociable)
PHP 8.1 sans framework (PSR, php-di, fast-route), MySQL 8 en production et SQLite en test, Dompdf, file `document_jobs` traitée par `documents:process-jobs` (cron), messages par `queue:send`. Pas de Laravel, PostgreSQL, Redis, Horizon, Gotenberg (écart voulu avec le CDC, déjà acté). Module : `src/Modules/Apps/Secondary` (le CDC dit `app/Modules/Apps/Secondary`). Tables préfixées `se_`. Front : Vue 3, Vite, Pinia, PWA hors ligne (IndexedDB), `web/src/views/secondary/`.

## 2. Arborescence
```
src/Modules/Apps/Secondary/
  SecondaryApplication.php   manifeste platform.applications.secondary, vocabulaire, permissions secondary.*
  definitions.php            racine : manifeste + array_merge des */definitions.php (glob)
  routes.php                 racine : require des */routes.php (glob)
  Support/                   WriteGate, GatedAction, ModuleGate, SecondaryRoutes, Perimeter, ScopedByClass, Clock
  Policies/                  RoleMatrix, ScopeResolver
  Setup/          ETB  PROFIL (ProfileResolver, copie du profil de type, journal), séries/spécialités, coefficients, salles, calendrier
  Enrolment/      INS  préinscriptions, profils élèves, transferts, réinscription, garde d'inscription
  CarteScolaire/  CSC  imports de matricules et de paiements, rapprochement, écarts, export de fin d'année
  Fees/           FIN  grille et remises [fees_private], catalogue public [fees_public_catalog], plans de paiement
  Timetable/      EDT  générateur assisté, profils de salles, dédoublement
  Syllabus/       CTX  progressions, cahier de textes, visas, couverture, heures pour RHP
  Discipline/     PRE  barème, propositions, conseils de discipline, exclusions de cours
  Grades/         NOT  épreuves communes, lettres EN, saisine fraude, « à remplir » enseignant
  Councils/       BUL, PAS  stratégie secondary, fournisseurs de lignes et de variables, appréciations, instantanés, orientation, recours
  OfficialExams/  EXA  sessions, candidats, contrôles, exports, résultats
  Workshops/      ATL, STG  [workshops, internships] ateliers, postes, séances, EPI, stock, grilles pratiques, sécurité, stages
  Staff/          RHP  statuts, heures validées, paie simplifiée [payroll_simple]
  Services/       SVC  internat, cantine et transport, bibliothèque, infirmerie [un module chacun]
  Documents/      DocumentTypeProvider (34 types secondary_*), gardes de délivrance, variables
  Notifications/  déclencheurs des 14 notifications du CDC
  Dashboards/     indicateurs (PIL), rapports de rentrée et annuel
  Portals/        sections élève, parent, enseignant
```
Chaque sous-module : `Application`, `Contracts` (interfaces internes entre sous-modules Secondary), `Domain`, `Http`, `Infrastructure`, `Database/Migrations/AAAAMMJJHHMM_secondary_<module>_*.sql` (horodatage ≤ date de fusion, jamais dans le futur), `routes.php`, `definitions.php`.

## 3. Décisions
| # | Décision | Motif |
|---|----------|-------|
| D1 | Notes, résultats, conseils de classe, présences, sanctions, frais restent dans le **socle** (pas de `se_marks`, `se_absences`, `se_sanctions`, `se_period_results` du CDC) | une seule vérité ; appel et notes hors ligne déjà au front ; conforme au primaire privé |
| D2 | Les calculs passent par une stratégie `secondary` (`assessment.rule_strategies.secondary`) implémentant `RuleStrategy` et `PeriodAggregatingStrategy` | arrondi à chaque étape (paramètre), agrégats, pratique professionnelle, conduite |
| D3 | Périodes : séquences filles de trimestres, filles d'une période année ; épreuves rattachées aux séquences | conseil trimestriel et annuel ; la hiérarchie existe (migration `periods.parent_id`) ; la **période année** reste à créer (SS2) |
| D4 | Les API du CDC qui enveloppent le socle sont des **façades** `/secondary/...` qui vérifient le périmètre puis appellent le socle par contrat ; si le contrat d'écriture manque, l'écran appelle la route du socle | ne jamais lire ni écrire les tables du socle |
| D5 | Le matricule national est stocké dans `se_student_profiles.national_id` ; jamais dans une adresse ni une page publique ; les routes utilisent l'identifiant interne | sécurité du CDC |
| D6 | Site de la carte scolaire : fichiers seulement (import CSV/XLSX, export CSV) ; colonnes en paramètres ; aucune automatisation de navigateur | RG-CSC-1, Q1 |
| D7 | Référentiels (niveaux, séries, spécialités, examens, coefficients) = paramètres de niveau plateforme `secondary.referentials.*` (héritage plateforme > client > établissement), copie versionnée dans `se_subject_coefficients`, marqués « exemple » tant que non fournis | listes tenues au niveau de la plateforme |
| **D8** | **Un produit, 7 types** : le type lu sur l'établissement sélectionne un **profil de type** (`secondary.type_profiles.<code>`, paramètre de plateforme) copié à l'ouverture dans `se_establishment_profiles` ; des **modules activables** (liste fermée, [spec.md](spec.md) §1.3) ; une route de module inactif répond **409 `module_not_enabled`** | décision du propriétaire ; remplace l'ancienne règle « 409 `not_applicable` » |
| **D9** | `ProfileResolver` est le **seul** endroit qui lit le code de type ; un test d'architecture interdit `if`/`match` sur les 7 codes ailleurs | éviter 7 applications cachées |
| **D10** | Un module coupé qui a des données passe en lecture seule (aucune suppression) ; chaque changement de profil écrit une ligne dans `se_profile_changes` avec motif | EX-SEC-04 |
| D11 | Paie simplifiée du privé portée par Secondary (`se_payslips`) ; remontée au socle si une deuxième application la demande | absente du socle |
| D12 | Valeurs du CDC = jeux de test `example_cdc` ; `SecondaryConventionsTest` interdit les littéraux de seuil dans `Domain/` hors classes `Defaults` étiquetées « exemple » | ne jamais inventer une règle officielle |
| D13 | Appel : mode `slot` du socle (par heure de cours) ; le mode `day_part` n'est pas activé au secondaire | EX-SEC-34 |
| D14 | Pas d'application `public_secondary` ; le modèle commercial des établissements publics est un réglage du client, pas du code | décision du propriétaire |

## 4. Dépendances
- **Vers le socle** (état au 22 octobre, détail dans [audit-reutilisation.md](audit-reutilisation.md)) :
  - déjà livrés : hiérarchie des périodes (SS1), contrat d'agrégat de périodes (SS3, signature à lire avant usage), contrats de lignes de conseil (SS6) et de variables de bulletin (SS5, préfixe à généraliser), `DocumentReissuer` (SS12 en partie) ;
  - à faire : période année (SS2), rang par matière (SS4), règle d'affectation des paiements (SS7), garde de création de frais (SS8), rôles du secondaire (SS9), modèles de messages (SS11), lien de téléchargement signé (SS14), expiration des affectations externes (SS13), colonne `council_lines.line_extras`, variables de bulletin au préfixe `secondary_`.
- **Entre sous-modules** (interfaces dans `<Module>/Contracts`) : Setup → tous ; Enrolment → CarteScolaire, OfficialExams, Fees ; Syllabus → Staff (heures), Councils (couverture) ; Discipline → Councils (conduite) ; Workshops → Councils (pratique), OfficialExams (épreuves pratiques) ; Councils → OfficialExams (moyenne annuelle), CarteScolaire (fin d'année).
- **Vers d'autres applications** : aucune dépendance de code ; l'entrée depuis le primaire passe par un fichier d'échange versionné.

## 5. Ordre (lots du CDC, adaptés)
1. **Vague 0** : changements de socle SS2, SS4, SS7, SS8, SS9, SS11, SS12 à SS14 et ajouts à SS5/SS6 ; squelette `Secondary` avec **profil de type et garde de module** (T813 à T815) ; conventions et fabrique de test des 7 types.
2. **E1** : Setup (profil, établissement, coefficients, calendrier), Enrolment, CarteScolaire, Fees (privé et public), documents d'inscription et de paiement, portail inscription et paiement.
3. **E2** : Timetable, Syllabus, Discipline, Grades, documents associés.
4. **E3** : Councils (bulletins, passage, orientation), OfficialExams, Workshops (ateliers, stages), Staff, documents restants.
5. **E4** : Dashboards, Services, rapports, reprise de données, recette, charge, pilote.
Les tâches qui attendent une réponse du client démarrent avec la valeur par défaut « à confirmer » ([questions.md](questions.md)).

## 6. Front
Dossier exclusif `web/src/views/secondary/` ; éditions ciblées de `router/routes.ts`, `lib/permissions.ts`, `i18n/*`, relues juste avant d'éditer. Nouveautés : **assistant de profil** (type, propriété, cycles, modules, avec explication en langage clair), **menu qui suit les modules actifs**, vocabulaire par type (proviseur ou principal). Réutilisation des écrans du socle pour l'appel, les notes, l'emploi du temps, les bulletins, les frais et les portails (habillage secondaire). Écrans neufs : cahier de textes, discipline, conseils, orientation, examens, ateliers, stages, tableau de bord. Accessibilité et mobile d'abord (390 px) ; textes dans `secondary-{fr,en}.ts`.

## 7. Tests
- `tests/Architecture/SecondaryConventionsTest.php` : préfixe `se_`, `tenant_id` et `establishment_id`, mots réservés, contrats seulement, **aucun littéral de seuil hors `Defaults`**, **toute route de module passe par `ModuleGate`**, **aucun test du code de type hors `ProfileResolver`**, toute route d'écriture passe par `WriteGate`.
- `tests/Isolation/SecondaryScopeTest.php` : autre client, autre établissement, autre classe, enseignant d'une autre matière.
- `tests/Feature/Secondary/<Module>/`, `tests/Unit/Secondary/<Module>/`, `tests/Acceptance/Secondary/` (TST-01 à TST-10, exemples E1 à E7 en jeu `example_cdc`, tests par type TST-T1 à TST-T7), `tests/Load/Secondary/` (NF-S1, NF-S2), contrats `specs/001-socle-plateforme/contracts/openapi.d/secondary-<module>.yaml`.
- Fabrique de test : un établissement de chacun des 7 types, public et privé (`tests/Support/Secondary/ProfileFactory`).

## 8. Charge et risques
Le CDC estime 412 jours-personne (E1 106, E2 117, E3 121, E4 68) en plus du socle ; la réutilisation du socle réduit surtout NOT, PRE et BUL ; le profil de type et les modules ajoutent un petit lot (T813 à T815, T836 à T838, T950 à T958). Pas de réestimation chiffrée sans les réponses Q1, Q2, Q18.
| Risque | Mitigation |
|---|---|
| Sept applications cachées derrière des `if` sur le type | D9 et test d'architecture |
| Valeurs officielles figées par erreur | D12, jeux `example_*`, questions Q2, Q8, Q15 à Q17 |
| Profils de type faux (cycles, modules par défaut) | tout en paramètres de plateforme, modifiables sans développement ; Q18, Q28 |
| Changement de profil qui perd des données | D10 : lecture seule, journal, jamais de suppression |
| Collisions avec les autres agents (primaire, maternelle, imports) | dossiers exclusifs, 3 agents au plus, éditions ciblées des fichiers partagés |
| Jamais testé sur MySQL réel | `phpunit.mysql.xml` en recette (T975) |
| Données réelles avant HTTPS | interdit ; données synthétiques seulement |
