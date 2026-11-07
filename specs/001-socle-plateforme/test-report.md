# Rapport de recette automatisée — SCHOLARIS (socle et plateforme)

Exécutée les 10 et 12 octobre 2026 sur le poste de développement (Windows 11, 8 processeurs logiques, PHP 8.1.34 ZTS, Node et Chromium Playwright 1.64). Données entièrement fictives, aucun secret, aucun appel réseau hors installation npm. Le code de production (`src/`, `web/src/`, `config/`, `database/`) n'a pas été modifié. D'autres agents modifiaient `src/` et `web/src/` pendant la recette : les résultats valent pour l'état du dépôt au moment de chaque exécution (voir § 6).

## 1. Synthèse

| Volet | Exécuté | Résultat |
|---|---|---|
| Suite PHPUnit par défaut (`php vendor/bin/phpunit`) | oui | **1 136 tests, 10 066 assertions, 0 échec** |
| Tests d'API sur toutes les routes (`tests/ApiContract`) | oui | **2 808 tests, 24 860 assertions, 21 échecs** = 21 écarts réels (§ 3.1) |
| Parcours de bout en bout sur la pile réelle (`tests/E2E`) | oui | **13 tests, 711 assertions** : 10 parcours verts, 3 tests de défauts connus en échec (D1 à D3) |
| Interface Playwright + Chromium + axe-core (`web/e2e`) | oui, vrai navigateur | **36 tests** (18 scénarios × 390 px et 1280 px) : 26 verts, 10 échecs **attendus** marqués `test.fail` (défaut F1) ; 36 captures. Dernière exécution complète : 34 tests (8,3 min) ; les 2 cas `/activation`, ajoutés ensuite, ont été exécutés seuls |
| Charge locale PHP (`tests/Load/run.php`) | oui | résultats chiffrés au § 5 ; **objectif p95 < 300 ms non tenu** au-delà de 10 utilisateurs |
| Mesure p95 en processus (`scripts/loadtest/local-p95.php`) | oui | p95 10,7 ms (`/health`) et 16,4 ms (liste authentifiée, 500 lignes) |
| Script k6 (`scripts/loadtest/k6-api.js`) | **non** : k6 absent du poste | syntaxe vérifiée par `node --check` uniquement |

Défauts les plus graves : **P1** (`GET /me/contexts` fait 3 requêtes SQL par apprenant : 15 024 requêtes et 2,4 s pour un établissement de 5 000 élèves), **F1** (une page publique ouverte directement sans session renvoie vers `/connexion`, y compris le lien de suivi envoyé par e-mail et le lien d'activation), **D2** (le parent ne peut pas payer depuis son portail).

## 2. Commandes et emplacement des tests

```bash
php vendor/bin/phpunit                                   # suite par défaut
php vendor/bin/phpunit tests/ApiContract                 # ~3 min
php vendor/bin/phpunit tests/E2E                         # ~30 s
RECORD_UI_FIXTURES=1 php vendor/bin/phpunit tests/E2E/UiFixturesRecorderTest.php   # régénère web/e2e/fixtures/recorded.json
php -d memory_limit=2G tests/Load/run.php --learners=5000 --levels=10,25,50 --seconds=10 --servers=4
cd web && npx vite build --outDir e2e/.dist --emptyOutDir && cd e2e && npx playwright test
```

**Mise à jour du 13 octobre (§ 9)** : `tests/ApiContract` et `tests/E2E`, désormais entièrement verts, sont déclarés dans `phpunit.xml` (suites `ApiContract` et `E2E`) et font partie de `php vendor/bin/phpunit` ; on peut toujours les lancer seuls par leur chemin ou par `--testsuite ApiContract` / `--testsuite E2E`. Avant cette date, ils ne l'étaient pas et se lançaient par leur chemin. Les serveurs lancés (php -S sur 8101-8104, `vite preview` sur 4317) ont été arrêtés ; aucun port n'écoute après les exécutions.

## 3. Tests d'API (tous les points d'entrée du RouteRegistry, fusion `openapi.yaml` + `openapi.d/*.yaml`, plateforme et exports compris)

Jeu partagé construit par l'API réelle (authentification Argon2id, 2FA TOTP réelle, rôles d'Identity) : client A complet (établissement, année, classe, élèves, parent, enseignant, caissier, facture, paiements, reçus, documents, appel, notes, conversation) et client B. Les identifiants réels de A remplissent les paramètres de chemin.

| Contrôle | Fichier | Résultat |
|---|---|---|
| 401 RFC 9457 (`type`, `title`, `status`, `detail`, `trace_id`) sans authentification + liste blanche des routes publiques | `AllRoutesContractTest` | conforme sur toutes les routes |
| En-têtes de sécurité (nosniff, DENY, no-referrer, CSP, `Cache-Control: no-store`) sur chaque réponse | idem | conforme |
| 403 sans permission (rôle « apprenant » sans droit métier) | idem | **4 écarts** (A1) |
| Isolation client A / client B (établissement de A refusé, aucun identifiant de A renvoyé à B) | idem | **1 écart** (A2) |
| Pagination par curseur (`meta.next_cursor`, page suivante distincte, `per_page` > 200 = 422) | idem | **2 écarts** (A3) |
| `Idempotency-Key` déclarée au contrat et exigée (400 sans clé ou clé invalide) sur `/payments`, `/cash/receipts`, `/documents` | idem | conforme |
| Statut et type de contenu documentés dans openapi.d, xlsx = classeur `PK` | idem + `WriteRoutesContractTest` | **14 écarts** de documentation (A4) |
| Corps vide → 422 avec `errors` par champ ; JSON illisible → 400/422, jamais 500 | `WriteRoutesContractTest` | aucune erreur 500 |
| Schémas de requête d'openapi.d (champs `required` signalés, `additionalProperties: false` appliqué) — 72 routes | `RequestSchemaContractTest` (nouveau) | **72/72 conformes** |
| Appels écrits dans `web/src` ↔ routes de l'API | `FrontendApiCallsTest` | conforme |

Les contrats ne décrivent **aucun schéma de réponse** (0 sur 1 403 réponses documentées, seul `Problem` est défini) : la conformité des corps de réponse au-delà de l'enveloppe `data`/`meta` et du format Problem n'est donc pas vérifiable automatiquement.

### 3.1 Écarts d'API

| # | Écart | Reproduction | Gravité |
|---|---|---|---|
| A1 | La validation passe avant le contrôle de droit : un apprenant reçoit **422** (avec la liste des champs) au lieu de 403 sur `POST /role-assignments`, `POST /role-assignments/delegations`, `PUT /settings`, `POST /vacation-hours` | `phpunit tests/ApiContract/AllRoutesContractTest.php --filter "testUnRoleSansPermissionEstRefuse.*(role-assignments|settings|vacation-hours)"` | Faible (divulgation de la forme des corps, aucune écriture) |
| A2 | `GET /attendance/students/{id}/summary` répond **200** (compteurs à zéro, `person_id` recopié) pour un apprenant d'un autre client au lieu de 404 ; aucune donnée de A n'est lue, seul l'identifiant demandé est renvoyé | `--filter "testLeClientB.*summary"` | Faible |
| A3 | Listes sans pagination par curseur : `GET /attendance/not-allowed-to-sit` (pas de `meta.next_cursor`), `GET /leave-balances` (pas de `meta`) | `--filter "testLesListesSontPagineesParCurseur.*(not-allowed|leave-balances)"` | Faible à moyenne (listes non bornées sur un gros établissement) |
| A4 | Statuts renvoyés mais absents du contrat : 422 sur `POST /data-requests/{id}/approval`, `POST /me/context`, `POST /enrollments/{id}/status` ; 404 sur 11 routes d'action de `vacation-contracts`, `vacation-hours`, `vacation-statements`, `leave-requests` | `phpunit tests/ApiContract/WriteRoutesContractTest.php` | Faible (documentation) |

## 4. Parcours de bout en bout (pile réelle, SQLite en mémoire, seules les passerelles SMS / e-mail et l'horloge sont remplacées ; 2FA TOTP active)

| Parcours | Fichier | Résultat |
|---|---|---|
| Création du client et du super administrateur (outil `admin:create` réel), connexion + enrôlement 2FA, établissement, année, périodes, classe, inscription d'un élève et de son garant, doublon de personne 409, refus d'un enseignant | `SchoolSetupJourneyTest` | vert |
| Grille de frais, facture, référence famille, caisse, encaissement espèces avec `Idempotency-Key` (rejeu = même paiement), reçu PDF, **affectation aux tranches** ([100 000, 20 000, 0]), mobile money par simulateur + webhook signé (doublon ignoré), solde famille, exports CSV et xlsx, portail famille | `BillingJourneyTest` | vert |
| Emploi du temps (conflit 409), appel hors ligne synchronisé (rejeu idempotent), notification d'absence au parent, correction tardive, export | `TeachingJourneyTest` | vert |
| Notes (22/20 refusé, lot, conflit de version 409), verrouillage, conseil de classe, bulletins, publication, lecture par le parent, export xlsx | `TeachingJourneyTest` | vert |
| Document PDF avec QR, vérification publique, révocation, jeton inconnu, lot de documents, registre et export | `DocumentsMessagingClosureJourneyTest` | vert |
| Messagerie interne parent ↔ enseignant, refus d'un tiers | idem | vert |
| Clôture d'année demandée puis approuvée par **une autre personne** (auto-approbation refusée), export complet du client sans donnée d'un autre client | idem | vert |
| **Nouveau** : demande d'accès publique (catalogue, dépôt, suivi par jeton), administrateur de plateforme créé par l'outil console réel, connexion + 2FA TOTP obligatoire (aucun accès avant la 2FA), **2FA récente exigée** (refus 403 `two-factor-recent-required` après 16 min, accepté après nouvelle vérification), acceptation : client, établissement, **essai de 90 jours**, invitation du premier administrateur ; activation par code SMS simulé, connexion 2FA, lanceur `/me/applications` en `trial`, première écriture ; cloisonnement plateforme ↔ client dans les deux sens ; audit | `PlatformAccessJourneyTest` | vert (45 assertions) |
| Défauts connus rejoués (doivent passer une fois corrigés) | `KnownDefectsTest` | 3 échecs = D1, D2, D3 |

### 4.1 Défauts fonctionnels

| # | Défaut | Reproduction | Gravité |
|---|---|---|---|
| D1 | Le portail famille n'affiche pas la facture d'un enfant inscrit **sous condition** (solde 0 au lieu de 300 000) | `phpunit tests/E2E/KnownDefectsTest.php --filter D1` | Moyenne |
| D2 | Le parent ne peut pas payer depuis son portail : le front appelle `GET/POST /payment-references` et `POST /payments`, réservés au personnel (403, 403, 403). Rejoué aussi dans le navigateur (`04-famille-paiement.spec.ts`, capture 11) : l'écran affiche le refus | `--filter D2` | **Élevée** (fonction annoncée inutilisable) |
| D3 | Ouvrir le compte d'un garant déjà enregistré (`POST /users`) crée une **seconde fiche** personne (fiche unique par client, FR-006) | `--filter D3` | Moyenne |
| F1 | **Front** : toute page publique ouverte directement sans session (`/demande-acces`, `/demande-acces/{jeton}` — lien de suivi envoyé par e-mail —, `/activation` — lien de l'invitation —, `/decouvrir`, `/verification/{jeton}`) est renvoyée vers `/connexion?redirect=/`. Cause : `GET /me` répond 401 (normal), et `onUnauthorized` de `web/src/main.ts` teste `router.currentRoute.value.meta.public` alors que la première navigation n'est pas encore résolue (la route courante vaut encore « / », non publique). L'accès par lien interne (depuis l'écran de connexion) fonctionne | `cd web/e2e && npx playwright test specs/06-demande-acces.spec.ts -g "accès direct"` (10 cas marqués `test.fail`) | **Élevée** |
| F2 | Une demande d'accès peut viser et faire ouvrir en essai une application au statut `coming_soon` (`secondary` dans le catalogue initial) : constaté dans `PlatformAccessJourneyTest` ; le contrat ne l'interdit pas explicitement | dépôt + acceptation avec `app_key: secondary` | Information (règle à confirmer) |

## 5. Charge

### 5.1 Test local exécuté (`tests/Load/run.php`)

Base SQLite **fichier** jetable (`tests/Load/.work/charge.sqlite`, WAL, attente de verrou 5 s) remplie en 15,7 s : 7 504 personnes (5 000 apprenants et leurs garants), 19 997 inscriptions sur 4 années, 19 997 factures, 39 994 tranches, 5 000 liens garant. 4 serveurs PHP intégrés (`php -S`, opcache) en répartition circulaire côté client : `PHP_CLI_SERVER_WORKERS` n'est pas pris en charge sous Windows. Client `curl_multi`, 10 s par palier, Argon2id aux paramètres de production, 2FA désactivée pour ce banc.

| Scénario | 10 utilisateurs : débit / p50 / p95 / p99 / erreurs | 25 utilisateurs | 50 utilisateurs |
|---|---|---|---|
| `GET /people` page de 50 + curseur | 81,3 req/s · 93 / **281** / 481 ms · 0 % | 87,6 · 258 / 458 / 631 · 0 % | 80,6 · 574 / 844 / 956 · 0 % |
| `GET /people?filter[q]` recherche | 52,0 · 173 / 339 / 421 · 0 % | 55,5 · 411 / 769 / 845 · 0 % | 76,2 · 631 / 779 / 837 · 0 % |
| `GET /invoices` page de 50 | 70,7 · 92 / **254** / 1 759 · 0 % | 93,2 · 240 / 419 / 497 · 0 % | 63,1 · 502 / 1 982 / 2 635 · 0 % |
| `GET /me/contexts` (accueil) | **1,1** · 7 178 / 12 482 / 12 482 · 0 % | 1,3 · 14 118 / 20 675 / 20 987 · 0 % | 1,1 · 28 336 / 44 656 / 45 920 · 0 % |
| `POST /auth/login` (Argon2id) | 4,7 · 1 822 / 2 836 / 2 942 · 1,75 % | 4,3 · 5 055 / 5 802 / 6 440 · 0 % | 4,6 · 9 623 / 11 150 / 11 979 · 0 % |
| `POST /cash/receipts` + Idempotency-Key | 25,4 · 333 / 698 / 835 · 0 % | 26,7 · 950 / 1 432 / 1 552 · 0 % | 23,8 · 1 881 / 2 906 / 3 129 · 0 % |
| `POST /attendance-sessions/sync` | 30,6 · 292 / 412 / 427 · 0 % (66 req.) | 30,8 · 716 / 1 007 / 1 036 · 0 % | 33,3 · 1 079 / 1 551 / 1 575 · 0 % |
| `POST /grades/batch` (50 notes) | 25,5 · 289 / 770 / 950 · **41,1 %** | 26,4 · 899 / 1 270 / 1 366 · **44,3 %** | 32,3 · 1 466 / 1 819 / 1 903 · **49,3 %** |

Lecture : le plafond est d'environ 80 à 90 req/s en lecture pour 4 processus PHP mono-tâche ; au-delà de 10 utilisateurs, la latence croît linéairement par mise en file. Les erreurs sont des **500 « database is locked »** (SQLite, 418 occurrences dans `Assessment/Infrastructure/Db.php:69`, 1 dans `Audit/Infrastructure/PdoAuditLog.php:75` pour la connexion).

**Lot de 200 documents** (processus séparé, `tests/Load/documents-batch.php`) : création et traitement en file (4 appels de traitement par tranches de 50) en **12,4 s** (62 ms par document), 0 échec ; rendu PDF Dompdf réel des 200 aperçus en **256,5 s** (1 282 ms par document, 4,3 Mio au total), mémoire de pointe **38 Mio** ; projection 1 000 PDF : 22,4 min. (Une exécution antérieure, sur une petite base, donnait 572 ms par PDF : la valeur dépend fortement de la charge du poste.) La mémoire par requête des serveurs HTTP n'a pas été mesurée.

**SQLite local n'est pas représentatif de MySQL 8 en production** : verrou d'écriture global (d'où les 500 sous écritures concurrentes, qui deviendraient attentes de verrou ou interblocages sous InnoDB), pas de pool PHP-FPM, client et serveurs sur la même machine. Les chiffres valent en relatif (comparaison des routes, détection des N+1), pas comme engagement de production.

### 5.2 Requêtes lentes d'après EXPLAIN (`tests/Load/explain.php`, en processus sur la base remplie)

| # | Constat | Mesure | Gravité / recommandation |
|---|---|---|---|
| P1 | `GET /me/contexts` : **N+1 ×3 par apprenant** — `SUM(amount_xaf - paid_xaf) FROM installments … learner_id = ?`, agrégat d'assiduité par `person_id`, `SELECT * FROM results … person_id = ? ORDER BY published_at DESC` (tri temporaire, pas d'index adapté) | **15 024 requêtes, 2 420 ms** pour une seule requête HTTP ; 1,1 req/s sous charge | **Élevée** : agréger en une requête groupée par établissement (ou indicateurs précalculés) ; index `results(tenant_id, person_id, status, published_at)` |
| P2 | `POST /grades/batch` : N+1 ×50 sur `enrollments … person_id = ? ORDER BY id` (tri temporaire), `SELECT grades` / `UPDATE grades` / `INSERT grade_events` ligne à ligne | 264 requêtes, 104 ms pour 50 notes | Moyenne : charger effectif et notes en une requête `IN (…)` ; index `enrollments(tenant_id, person_id, status)` |
| P3 | `GET /grade-sheets` : même N+1 ×50 sur `enrollments`, lecture de paramètre ×12 | 90 requêtes, 55 ms | Faible à moyenne : mise en cache des paramètres par requête |
| P4 | `DELETE FROM idempotency_keys WHERE expires_at < ?` à chaque encaissement : parcours complet | 856 lignes (croît sans fin entre purges) | Faible : index `idempotency_keys(expires_at)` ou purge planifiée |
| P5 | Tris temporaires sur `enrollments … structure_id = ? ORDER BY id LIMIT 51`, `SELECT DISTINCT person_id FROM enrollments … structure_id`, `assessments … ORDER BY id` | < 2 ms chacun à ce volume | Faible : index composites terminant par `id` |

Limites du profil : `GET /billing/families/FAM-0001/balance` et `GET /dashboards/director` y répondent 404 (référence inexistante dans le jeu de charge, route de tableau de bord à paramétrer) et n'ont donc pas été profilés.

### 5.3 k6 (non exécuté)

`scripts/loadtest/k6-api.js` réécrit : six scénarios simultanés (connexion, liste paginée de personnes avec deux pages de curseur et recherche, encaissement avec `Idempotency-Key` et rejeu vérifié, appel synchronisé, saisie de notes par lot, lot de documents jusqu'à « done »), seuils `p(95)<300` en lecture, taux d'erreur `< 1 %`, écritures `p(95)<800` indicatif, connexion `p(95)<1500` indicatif. **Non exécuté** : k6 n'est pas installé ; vérifié seulement par `node --check`. À lancer contre une recette (jamais la production), la limitation de débit de `/auth/login` relevée.

## 6. Interface (Playwright + Chromium + axe-core, vrai navigateur)

Front construit par `vite build` vers `web/e2e/.dist` (jamais `web/dist` ni `public/`), servi par `vite preview` (port 4317, démarré et arrêté par Playwright). Réseau intercepté par un faux backend dont les corps sont **enregistrés depuis la pile PHP réelle** (`fixtures/recorded.json` par `UiFixturesRecorderTest`, `fixtures/platform.json` par `PlatformUiFixturesRecorderTest`, nouveau). Service worker bloqué pour que l'interception soit complète. Chaque scénario en 390 px et 1280 px ; contrôle axe-core WCAG 2.0/2.1 A et AA, échec sur toute violation « serious » ou « critical ».

| Scénario | Résultat (390 / 1280) | axe |
|---|---|---|
| Connexion : mauvais mot de passe, champs vides, page protégée → connexion | vert / vert | 0 violation bloquante |
| Accueil administrateur | vert / vert | idem |
| Appel hors ligne (`context.setOffline`), mise en file, resynchronisation unique au retour du réseau | vert / vert | idem |
| Notes : 22/20 refusé sur place sans envoi, note valide enregistrée ; conflit 409 présentant les deux versions | vert / vert | idem |
| Portail famille et paiement mobile money confirmé ; rejeu du défaut D2 (403 affiché) | vert / vert | idem |
| Administration : création du premier établissement (erreur de saisie puis succès) ; exports CSV (`Identifiant;Prénom;Nom`) et Excel (`PK`) | vert / vert | idem |
| **Nouveau** — demande d'accès publique (écrans de plateforme présents à l'exécution) : erreurs de saisie sans envoi, erreur serveur 422 réelle rattachée au champ, numéro de suivi, page de suivi ; numéro inconnu | vert / vert | idem |
| **Nouveau** — accès direct sans session à 5 pages publiques | échec attendu (F1) ×10 | — |

36 captures dans `web/e2e/screenshots/` (01 à 18, deux largeurs). Interférence avec le chantier parallèle : après reconstruction, le front appelait une nouvelle route `GET /me/applications` absente du faux backend (6 échecs transitoires) ; la réponse réelle a été enregistrée et servie, puis toute la suite est repassée au vert.

## 7. Fichiers ajoutés ou modifiés pendant cette reprise

- `tests/E2E/PlatformAccessJourneyTest.php` (nouveau), `tests/E2E/PlatformUiFixturesRecorderTest.php` (nouveau), `tests/E2E/UiFixturesRecorderTest.php` (enregistre `/me/applications`).
- `tests/ApiContract/RequestSchemaContractTest.php` (nouveau).
- `web/e2e/specs/06-demande-acces.spec.ts` (nouveau), `web/e2e/mock/backend.ts` (`/me/applications`), `web/e2e/fixtures/platform.json` (nouveau), `web/e2e/fixtures/recorded.json` (régénéré).
- `scripts/loadtest/k6-api.js` (réécrit avec les six scénarios demandés).
- Résultats bruts : `tests/Load/.work/results.json` (jetable, contient la base de charge fictive).

Les nouveaux fichiers PHP passent php-cs-fixer et PHPStan (niveau du projet). `tests/` n'est pas dans le périmètre de `phpstan.neon` ; une analyse forcée de `tests/E2E`, `tests/ApiContract`, `tests/Load` signale 48 erreurs de typage préexistantes dans les fichiers de la session précédente (annotations de tableaux), sans effet à l'exécution.

## 8. Recommandations

1. Corriger **P1** avant toute mise en service d'un établissement de plus de quelques centaines d'élèves (l'accueil devient inutilisable) ; ajouter un test de non-régression sur le nombre de requêtes de `/me/contexts`.
2. Corriger **F1** (dans `onUnauthorized`, s'appuyer sur la route cible ou attendre `router.isReady()`), puis retirer les `test.fail` de `06-demande-acces.spec.ts`.
3. Corriger **D2** (routes de paiement en libre-service pour le garant, limitées à ses enfants) puis **D1** et **D3** ; `KnownDefectsTest` passera alors au vert.
4. Placer le contrôle de permission avant la validation (A1) ; paginer les deux listes de A3 ; compléter openapi.d (A4) et y ajouter des schémas de réponse pour rendre la conformité des corps vérifiable.
5. Rejouer la charge sur MySQL 8 + PHP-FPM (recette Camoo ou base jetable), avec `scripts/loadtest/k6-api.js` ; mesurer la mémoire par processus ; prévoir la génération PDF hors requête (file `documents:process-jobs` en cron) au vu de ~0,6 à 1,3 s par document.
6. Déclarer des suites `ApiContract` et `E2E` dans `phpunit.xml` (fichier partagé : demande de changement) ou les lancer en CI par leur chemin.

## 9. Corrections du 13 octobre 2026 (reprise des défauts)

Toutes les mesures ci-dessous sont **réelles**, prises en processus sur la base de charge `tests/Load/.work/charge.sqlite` (5 000 apprenants, 19 997 inscriptions et factures), même méthode que le § 5.2 (compteur d'instructions PDO). SQLite : valeurs relatives.

| # | Statut | Correction | Tests |
|---|---|---|---|
| P1 | **corrigé** | Les fournisseurs de tuiles de Reporting lisent par lots via trois contrats nouveaux, distincts des contrats existants (dont les doublures ne changent pas) : `Billing\Contracts\EstablishmentBalances` (soldes de l'établissement, une requête `GROUP BY learner_id`), `Assessment\Contracts\LatestPublishedAverages` (moyenne publiée la plus récente par personne, une requête), `Attendance\Contracts\AttendanceTotals` (synthèses de la semaine par apprenant, une requête). Implémentés par `PdoDebtorDirectory`, `PdoResultsDirectory`, `PdoAttendanceDirectory` ; les indicateurs se replient sur l'ancien calcul si le fournisseur n'expose pas le contrat de lot. Aucune lecture de table étrangère (`NoForeignTableReadTest` vert). | `tests/Feature/Reporting/ContextsQueryCountTest.php` : nombre de requêtes de `/me/contexts` et `/dashboards/direction` < 50 et identique à 1 et 121 apprenants, soldes exacts |
| D2 | **corrigé** | Migration `database/migrations/202610109101_parent_payment_permissions.sql` : `billing.self.pay` et `billing.self.read` aux rôles `parent` et `apprenant`. Billing (`SelfServiceScope`) : le payeur en libre-service ne lit et n'émet que SA référence, ne paie qu'en mobile money (jamais d'espèces, jamais de `external_reference` fournie par lui) une référence, un apprenant (lui-même ou dont il est garant) ou une facture qui lui appartient, suit SES paiements et lit SES reçus (liste, détail, PDF) ; tout le reste est 404 (objet d'autrui) ou 403 (autres routes de facturation). Le rejeu d'`Idempotency-Key` inclut désormais l'utilisateur dans l'empreinte (`Core\Http\Middleware\Idempotency`) : la clé d'un autre utilisateur ne rejoue jamais sa réponse. | `tests/Feature/Billing/SelfServicePaymentTest.php` (paiement, suivi, webhook, reçu ; autre parent : 404 partout, 403 à l'émission de la référence d'autrui ; espèces, référence forgée, listes et exports : 403) ; `KnownDefectsTest` D2 vert |
| D1 | **corrigé** | Portals (`LearnerCardBuilder`) : une inscription `conditional` compte comme en cours (statut renvoyé dans `enrollments[].status`) ; le solde n'est plus compté deux fois pour deux inscriptions du même établissement. | `FamilyPortalTest` (2 tests) ; `KnownDefectsTest` D1 vert |
| D3 | **corrigé** | `POST /users` (et le contrat `AccountProvisioner`) : rattachement à la fiche `person_id` fournie (404 inconnue, 409 si elle a déjà un compte), sinon à l'**unique** fiche sans compte de même téléphone ou e-mail et de mêmes prénom et nom ; jamais de choix arbitraire ; réponse `person_linked`. Contrat identity.yaml à jour. | `tests/Feature/Identity/ExistingPersonAccountTest.php` (3 tests) ; `KnownDefectsTest` D3 vert |
| A1 | **corrigé** | Contrôle de droit avant validation : `POST /role-assignments` (droit d'attribuer quelque part), `POST /role-assignments/delegations` (exercer en propre un rôle délégable ; parent et apprenant ne se délèguent pas), `PUT /settings` (`settings.manage`), `POST /vacation-hours` (membre du personnel ou `vacations.manage`). | `AllRoutesContractTest` |
| A2 | **corrigé** (Attendance libéré par W0-4) | `GET /attendance/students/{id}/summary` : 404 si la personne n'existe pas dans le client. | `AllRoutesContractTest` (isolation) |
| A3 | **corrigé** | `GET /attendance/not-allowed-to-sit` (tri stable taux puis personne) et `GET /leave-balances` : `per_page`, `cursor`, `meta.next_cursor`. | `AllRoutesContractTest` |
| A4 | **corrigé** | 15 statuts ajoutés aux contrats (audit, identity, people, staff, staff-leave). | `WriteRoutesContractTest` |
| P2 / P3 | **corrigé** (lectures) | Assessment : paramètres de saisie lus une fois par lot, notes existantes de l'épreuve lues en une requête, plus de relecture de chaque note écrite ; `Roster` lit les inscriptions de la classe en une requête via le contrat nouveau `People\Contracts\StructureEnrollments`. Les écritures restent une par note modifiée (UPDATE + événement d'historique). | suites Assessment existantes |
| P4 | **corrigé** | Index `idempotency_keys(expires_at)` (`database/migrations/202610109201_core_idempotency_expiry_index.sql`) : `EXPLAIN` = `SEARCH ... USING INDEX idempotency_keys_expires_at` ; la recherche par clé utilise la clé primaire `(tenant_id, idem_key)`. | — |
| F2 | **corrigé** (règle décidée) | Libre-service : application non `available` → 422 clair sur `app_key`. Acceptation d'une demande d'accès : `coming_soon` ou `retired` → 422 clair, rien n'est créé ; l'administrateur de plateforme peut forcer avec `force_reason` (non vide), tracé dans l'audit (`forced`, `catalog_status`, `force_reason`). L'ouverture directe par l'administrateur (`POST /platform/clients/{tenant}/applications`) est inchangée. platform.yaml à jour. | `AccessRequestTest::testApplicationAVenirOuRetireeRefuseeSaufForcageMotive`, `SubscriptionTest`, `PlatformAccessJourneyTest` (refus puis forçage) |

### 9.1 Mesures avant / après

| Requête (base de charge, 5 000 apprenants) | Avant | Après |
|---|---|---|
| `GET /me/contexts` (super administrateur, 1 établissement) | **15 026 requêtes**, 1 837 à 2 546 ms | **28 à 29 requêtes**, 222 à 280 ms |
| `GET /dashboards/direction` | même N+1 (3 par apprenant) | 29 requêtes, 250 ms |
| `POST /grades/batch` (50 notes) | 452 requêtes, 72 ms | 117 requêtes (dont 88 écritures), 51 ms |
| `GET /grade-sheets` (classe de 50) | 148 requêtes, 83 ms | 99 requêtes, constantes quel que soit l'effectif (lectures de paramètres de calcul) |

Reste après correction (non bloquant) : `/me/contexts` lit 4 fois la liste des inscrits de l'établissement (un appel par fournisseur d'indicateurs) ; une mise en cache par requête l'abaisserait encore. Le coût reste proportionnel au nombre d'**établissements**, plus aux apprenants.

### 9.2 Autres changements liés

- `tests/ApiContract/AllRoutesContractTest.php` : `POST /payment-references` et `POST /payments` rejoignent la liste des usages en libre-service de l'apprenant (D2).
- `web/e2e` : le cas « défaut D2 rejoué » devient « refus du serveur (403) pendant un paiement : l'écran le dit » ; le faux backend produit un refus synthétique au lieu de rejouer le corps enregistré. `specs/04-famille-paiement.spec.ts` exécuté : 4/4 verts (390 et 1280 px).
- `phpunit.xml` : suites `ApiContract` et `E2E` déclarées ; `memory_limit` porté à 1 Gio (la suite par défaut seule dépassait déjà 128 Mio : arrêt fatal dans `CompleteTenantExportTest`, sans lien avec ces corrections).

### 9.3 Résultats des suites après correction (13 octobre 2026)

| Commande | Résultat |
|---|---|
| `php vendor/bin/phpunit tests/ApiContract` | **2 814 tests, 25 157 assertions, 0 échec** (45 s) |
| `php vendor/bin/phpunit tests/E2E` | **13 tests, 714 assertions, 0 échec** (24 s) ; `KnownDefectsTest` D1, D2, D3 verts |
| `php vendor/bin/phpunit` (Unit, Architecture, Isolation, Feature, ApiContract, E2E) | **4 085 tests, 37 500 assertions, 0 échec** (7 min 49 s, 142 Mio) |
| `php vendor/bin/phpstan analyse --memory-limit=2G --no-progress` | 0 erreur, sans baseline |
| `npx playwright test specs/04-famille-paiement.spec.ts` | 4/4 verts |
