# Plan technique : application Maternelle privée (`nursery`)

Date : 2026-10-20. Entrées : [spec.md](spec.md), [data-model.md](data-model.md), [socle-reuse.md](socle-reuse.md), [questions.md](questions.md), ADR-0001, `specs/001-socle-plateforme/agent-conventions.md`, modèles : `src/Modules/Apps/Higher` (mécanismes d'application), `specs/003-application-primaire/plan.md` (application privée), `src/Modules/Apps/PublicNursery` (règles de la maternelle). Aucun code n'est écrit par cette spécification.

## 1. Contexte technique (réel, non négociable)
PHP **8.1 sans framework** (pipeline PSR-15, PHP-DI, FastRoute), MySQL 8 en production, SQLite en test, Dompdf, PHPUnit 10.5, PHPStan niveau 8 sans baseline, PHP-CS-Fixer. Hébergement mutualisé : pas de Redis, de Node ni de SSH ; tâches par cron. Front Vue 3 + Vite + Pinia + PWA (hors ligne IndexedDB, `client_uuid`, conflits 409 jamais écrasés). Namespace `Scholaris\Modules\Apps\Nursery`. SQL portable MySQL 8 / SQLite : ULID `CHAR(26)`, dates `VARCHAR`, montants XAF entiers, aucune clé étrangère, aucun mot réservé. Les documents de conception qui parlent de Laravel ou PostgreSQL sont périmés.

## 2. Nom, emplacement et identifiants
- Dossier : `src/Modules/Apps/Nursery/` (aujourd'hui `.gitkeep`). Clé de manifeste `platform.applications.nursery`, **statut `coming_soon` jusqu'à la recette** (T1096). Tables `nu_*` (privé seul) ; tables partagées `ey_*` si l'option de mutualisation A est retenue (§3). Permissions `nursery.*`. Routes `/api/v1/nursery/*`. Commandes `nursery:*`. Paramètres `nursery.*`. Rôles `nursery_*`.
- Prix et essai : 30 000 XAF par mois, 90 jours, grâce 15 jours [H, Q-A9, MQ-16] ; le catalogue en base fait foi.
- Distinction public/privé : par l'application ouverte au client (Q-A4). Le filtre de menu du front se fait par application ouverte, pas par type d'établissement (voir §6).

## 3. Mutualisation avec la maternelle publique : décision à prendre (MQ-01)
Le risque identifié par l'audit : deux implémentations divergentes de la sortie sécurisée. Q-A5 propose par défaut une application `nursery` distincte qui réutilise les briques SOR, DEV et TRA par des **contrats du socle partagés**. Trois options :

| Option | Principe | Avantages | Inconvénients |
|---|---|---|---|
| **A (défaut, Q-A5)** | Extraire la logique commune de `PublicNursery` vers de nouveaux modules du socle : `Socle/PickupGate` (sortie sécurisée : autorisation valable, remise, refus, restriction, code, veille, journal) et `Socle/EarlyYears` (référentiel d'observation, observation, carnet, cahier de vie, besoins). Les deux applications ne gardent que routes, permissions, garde d'écriture, notifications et portée | une seule règle de sortie (SC-MAT-1 testé une fois) ; pas de dépendance d'application à application ; corrections partagées | refactorisation de `PublicNursery` (code non commité à ce jour, voir phase 0) ; migration `pn_*` → `ey_*` pour les données publiques ; deux propriétaires de tests |
| B | `Nursery` recopie `PublicNursery` (sans les ajouts publics) et les deux évoluent séparément | rapide, sans toucher au public | divergence de la sécurité de la sortie, double maintenance, double audit |
| C | Une seule application paramétrée par client (`nursery` public ou privé) | un seul code | contredit Q-A4 et le catalogue (clés distinctes), mélange territoire public et facturation privée |

**Recommandation** : A, mais **séquencée** pour ne pas bloquer le privé :
1. Tant que `PublicNursery` n'est pas commité et stabilisé (M-001, M-002 terminées et validées, phase 0), **aucune extraction** : on spécifie les contrats ([socle-reuse.md](socle-reuse.md) §3, demandes NS1 à NS6) et on construit le squelette `Nursery`, les rôles, les frais, les documents administratifs et l'appel, qui ne dépendent pas de l'extraction.
2. Les extractions (NS1 `PickupGate`, NS2 `EarlyYears`) sont faites par l'agent propriétaire du socle, avec le même jeu de tests de `tests/Feature/PublicNursery` rejoué sans retouche comme test de non-régression.
3. Si l'extraction n'est pas prête quand arrive la vague de sortie sécurisée (vague 2), **repli B encadré** : recopie de `Pickup/Domain` dans `Nursery/Pickup/Domain` avec un test d'architecture qui compare les décisions des deux implémentations sur la même table de cas (voir T1032), puis bascule sur le contrat partagé à la livraison de NS1. Le repli est une décision de l'utilisateur (MQ-01).

Dans tous les cas : jamais de `use` d'une classe de `PublicNursery` depuis `Nursery` (RG-MAT-10, test d'architecture).

## 4. Arborescence cible
```
src/Modules/Apps/Nursery/
  NurseryApplication.php        manifeste (clé nursery), descripteur, vocabulaire, constantes de permissions
  definitions.php               platform.applications.nursery + array_merge des */definitions.php (glob)
  routes.php                    inclut */routes.php (glob), toutes les routes sous /nursery/...
  Support/    WriteGate, GatedAction, NurseryRoutes, SectionScope (portée class), EstablishmentGuard (type nursery + application ouverte)
  Policies/   RoleMatrix, ScopeResolver
  Pickup/     routes, contrôleurs, permissions, notifications NOT-MAT-02 à 05 ; logique : contrat PickupGate (option A) ou Domain recopié (repli)
  Development/ routes, contrôleurs, permissions ; logique : contrat EarlyYears (option A)
  Transition/ décisions de passage nu_transition_decisions, fiche de liaison, planificateur conditionnel
  Services/   garderie, cantine, transport : plans et abonnements
  Billing/    natures de frais nursery, facturation des services
  Documents/  types et modèles : DOC-MAT-01 à 08, garde d'émission
  Portals/    sections du portail parent : autorisés, carnet, cahier de vie, allergies, solde
  Dashboards/ indicateurs, tableau de bord du directeur
  Tutelage/   export tutelle (Could)
  Notifications/ modèles NOT-MAT-xx, appelle Messenger / SafetyMessenger
```
Chaque sous-module : `Application/ Domain/ Infrastructure/ Http/ Database/Migrations/ routes.php definitions.php` ; `Contracts/` seulement si un autre sous-module de `Nursery` en a besoin. Migrations : `src/Modules/Apps/Nursery/<Module>/Database/Migrations/AAAAMMJJHHMM_nursery_<module>_*.sql` ; **horodatages ≤ date du jour de la fusion, jamais dans le futur** (leçon M-001).

## 5. Mécanismes repris de Higher et de PublicNursery
| Mécanisme | Higher | PublicNursery (après M-001) | Nursery |
|---|---|---|---|
| Manifeste | `platform.applications.higher` | `PublicNurseryApplication` | `NurseryApplication`, `requiredModules` : people, billing, messaging, documents, attendance, scheduling, staff, reporting (à confirmer au plan de socle-reuse) |
| Écriture | `WriteGate`, `GatedAction`, `HigherRoutes` | `NurseryWriteGate`, `GatedAction`, `NurseryRoutes` enveloppent tout GET/POST/PUT/PATCH/DELETE | `WriteGate` (`ApplicationAccess::allowsWrites($tenantId, 'nursery')`), `NurseryRoutes` ; `NurseryConventionsTest` : toute route, y compris les lectures, passe par la garde (suspendu : lecture seule ; fermé : refus) |
| Périmètre | `ScopeResolver` + `Perimeter` | `SectionScope`, `SectionScopeResolver` (établissement, unité, structure, classe) | `SectionScope` : lecture hors section 404, écriture 403 journalisé |
| Pagination | `ListQuery` | `Paging` : curseur, `per_page` 50/200, `meta.next_cursor` | idem dès la première route |
| Rôles | `RoleMatrix` + test | migration `roles_public_nursery.sql` | `roles_nursery.sql`, `RoleMatrix`, `RoleMatrixTest` : 1 cas par ligne de spec §2 |
| Console | `higher:*` | `public-nursery:*` | `nursery:*` via `console.command.nursery_<nom>` |

## 6. Conception par sous-module
- **Support** : `WriteGate`, `GatedAction`, `NurseryRoutes`, `SectionScope`, `EstablishmentGuard` (établissement de type `nursery` ET application `nursery` ouverte, sinon 404). Toute liste est paginée par curseur.
- **Pickup** (EX-MAT-09 à 15, 36) :
  - Autorisés : lecture/écriture par le contrat partagé « autorisés » (`Attendance\Contracts\AuthorizedPickupRegistry`, PM1 de 004), table `authorized_pickups` du socle comme source unique ; photo par `MediaStore`.
  - Remise : `POST /nursery/pickup/handovers` ne reçoit que `learner_id` + `pickup_id` ou `code` ; refus : `POST /nursery/pickup/refusals` (nom déclaré facultatif, jamais utilisé pour remettre).
  - Restrictions : `/nursery/pickup/restrictions` (directeur) ; consultation journalisée.
  - Veille : commande `nursery:pickup-watch` chaque minute, idempotente par niveau et jour ; heure de fin lue de `SettingsReader` (`nursery.pickup.day_end_time`, saisie par l'école), repli : aucune alerte tant que l'heure n'est pas fixée.
  - Hors ligne : `GET /nursery/sections/{id}/pickup-package?date=` ; lots : gestionnaire `sync.handlers.nursery.pickup` (interface `Sync\Contracts\BatchItemHandler`) sur le point d'entrée commun `POST /sync/batches` (lots signés par l'appareil, déjà au socle) ; route propre `/nursery/sync/pickups` seulement en repli décidé par l'utilisateur, avec la même idempotence (MQ-17).
  - Registre quotidien : `nursery:daily-register` à une heure paramétrée, contrôle de cohérence (aucune remise sans autorisation, équivalent du contrôle quotidien O7).
- **Development** (EX-MAT-16 à 19) : référentiel d'observation (`/nursery/frames`, versionné, trois niveaux), observations (`POST /nursery/sync/observations` ou lot commun), carnet trimestriel (brouillon, soumis, validé, publié, figé ; **les routes HTTP du carnet sont livrées dès la première version**, écart relevé sur le public), cahier de vie (brouillon, médias étiquetés, publication, filtre de consentement à chaque lecture, purge `nursery:purge-media`), besoins. **Qui écrit le référentiel** : MQ-08 ; par défaut la plateforme livre un référentiel « exemple » marqué à valider et l'école le copie et l'adapte (privé) au lieu d'un référentiel national verrouillé.
- **Transition** (EX-MAT-21, 22) : `nu_transition_decisions` (proposition, avis, décision, motif), planificateur de clôture conditionnel (PM7 : n'agit que si l'établissement a l'application `nursery`), fiche de liaison par `DocumentIssuer`, lecture par le primaire du client ou d'un autre client par le transfert d'inscription du socle ; la fiche exclut restrictions et allergies.
- **Services** (EX-MAT-29) : `nu_service_plans`, `nu_service_subscriptions` ; facture par `InvoiceIssuer::forCharges` une fois par cycle.
- **Billing** : `billing.fee_kinds.nursery` = `registration`, `tuition`, `daycare`, `canteen`, `transport`, `uniform`, `supplies`, `other` (à confirmer, MQ-10) ; aucune garde de blocage du carnet par défaut.
- **Documents** : types `nursery_progress_book`, `nursery_liaison_sheet`, `nursery_pickup_card`, `nursery_daily_register` + modèles du socle (certificat, attestation, carte, fiche) ; modèles HTML Dompdf FR et EN éditables ; garde `documents.issuance_guards.nursery`.
- **Portals** : `portals.sections.nursery` : « Autorisés », « Carnet de suivi », « Cahier de vie », « Allergies » (famille) ; « Ma section » (éducatrice) ; solde et paiement via le portail du socle.
- **Dashboards** : `reporting.indicators.nursery`, `reporting.dashboards.nursery` (spec §9), agrégats sans donnée nominative pour le promoteur.
- **Front** (`web/src/apps/nursery/`, dossier exclusif ; éditions ciblées de `router/routes.ts`, `lib/permissions.ts`, `i18n/*`) : accueil et menu par rôle, sortie (liste du jour avec photo, hors ligne), registre, observation (un geste, hors ligne), carnet, cahier de vie, passage, services, tableau de bord. **Les écrans de `web/src/views/nursery/` (socle de la maquette) sont réutilisés comme base ; le tableau de bord ne renvoie plus vers des écrans `public-nursery`** : le filtre de menu se fait par application ouverte. Accessibilité (étiquettes, focus, contraste) et mobile d'abord.

## 7. Paramètres (SettingsReader, au niveau de l'école ; valeurs « à confirmer »)
| Clé | Valeur proposée | Source / question |
|---|---|---|
| `nursery.pickup.day_end_time` | aucune : saisie par l'école | MQ-02 |
| `nursery.pickup.late_after_minutes` | 30 (repris de 004, proposition) | MQ-02 |
| `nursery.pickup.director_after_minutes` | 15 (proposition) | MQ-02 |
| `nursery.pickup.offline_max_age_days` | 1 pour le mode « doute » ; lecture jusqu'à 30 (propositions) | MQ-03 |
| `nursery.pickup.code_max_attempts` | 5 (proposition) | MQ-04 |
| `nursery.pickup.parent_entries_need_validation` | false | MQ-05 |
| `nursery.dev.levels` | `acquired`, `in_progress`, `not_yet` | MQ-08 |
| `nursery.dev.min_observations_per_domain_per_month` | 1 (proposition) | MQ-08 |
| `nursery.dev.discouraged_terms` | liste vide | MQ-08 |
| `nursery.lifebook.retention` | `end_of_next_school_year` (proposition) | MQ-07 |
| `nursery.transition.auto_promote` | true | MQ-09 |
| `nursery.progress_book.block_on_debt` | false | MQ-10 |
Aucune de ces valeurs n'est codée dans le source (SC-MAT-5) ; elles sont des défauts de données, étiquetés « exemple » dans les fixtures.

## 8. Tâches planifiées (cron mutualisé)
`nursery:pickup-watch` (chaque minute), `nursery:daily-register` (quotidien), `nursery:purge-media` (quotidien), `nursery:observation-reminders` (hebdomadaire), `nursery:consistency-check` (contrôle quotidien des remises).

## 9. Sécurité
Toute route : établissement `nursery` et application ouverte (sinon 404) ; portée section ; parent limité à ses enfants (`guarantorsOf`) ; garde d'écriture. Restrictions et allergies hors listes générales ; codes HMAC avec secret par établissement, jamais affichés après création ; URL de photos signées à courte durée ; `ErrorNotice` du front ne montre jamais le détail technique ; aucun `v-html` (aperçu de document dans une iframe `sandbox`). Rappel : **HTTPS actif avant toute donnée réelle d'enfant** (Q23 de 004 ; données synthétiques sinon).

## 10. Tests
- `tests/Architecture/NurseryConventionsTest.php` : contrats seulement, aucun `use` de `PublicNursery` ni d'une autre application, tables `nu_*` avec `tenant_id` et `establishment_id`, aucun mot réservé, aucune colonne de note, **toute route gardée**, aucun littéral de seuil dans `Domain`, migrations non datées dans le futur et dans l'ordre.
- `tests/Isolation/NurseryScopeTest.php` : autre client, autre établissement, autre section, parent d'un autre enfant.
- `tests/Feature/Nursery/<Module>/`, `tests/Unit/Nursery/`, `tests/ApiContract` (401, 403, 423, isolation, pagination, RFC 9457), `tests/E2E/Nursery/` (parcours §3 de spec.md), contrats `specs/001-socle-plateforme/contracts/openapi.d/nursery-<module>.yaml`.
- Front : Vitest des écrans (sortie avec autorisé, refus, carnet, cahier de vie, hors ligne), Playwright `web/e2e/nursery/*`.
- Les tests de seuils chiffrés sont écrits **après** les réponses, à partir de valeurs fournies par le client ; avant, règles `example_*` nommées.

## 11. Risques
| Risque | Mitigation |
|---|---|
| Divergence de la sortie sécurisée entre public et privé | Option A ou, à défaut, test de parité (T1032) |
| Extraction de `PublicNursery` avant sa stabilisation | Séquençage du §3 ; non-régression par les tests publics inchangés |
| Menus qui renvoient vers des écrans refusés | Filtre par application ouverte ; test de routes (T1070) |
| Valeurs de sécurité inventées | Tout paramétré, étiqueté « exemple », question au client |
| Données réelles avant HTTPS | Interdit ; données `SYN-*` seulement |
| Jamais testé sur MySQL réel | `phpunit.mysql.xml` en recette (T1092) |
