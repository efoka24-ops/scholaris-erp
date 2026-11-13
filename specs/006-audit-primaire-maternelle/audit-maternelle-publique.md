# Audit : Maternelle publique (`public_nursery`)

Racine : `src/Modules/Apps/PublicNursery/` (non commitée). Spécification : `specs/004-.../maternelle/spec.md` (FR-M01 à FR-M21). Tâches : `maternelle/tasks.md`, 32 cochées sur 48.

## État exigence par exigence
Légende : **T** fait et testé · **S** fait sans test · **P** partiel · **A** absent.
| Exig. | État | Code vérifié | Tests | Remarque |
|---|---|---|---|---|
| FR-M01 autorisés (parent, directeur) | T | `Pickup/Application/AuthorizedPersonsService.php`, `Portal/Http/PortalPickupController.php`, socle `Attendance/Contracts/AuthorizedPickupRegistry.php`, photos par `MediaStore` | `AuthorizedPersonsTest` (2) | la révocation de la carte DOC-21 manque (DOC-21 absent) |
| FR-M02 remise par autorisation valide | T | `Pickup/Application/HandoverService.php`, `Domain/HandoverDecision.php` | `HandoverTest` (3) | la voie « code du jour » est absente (FR-M07) |
| FR-M03 refus, alerte NOT-07 non désactivable | T | `HandoverService` → `Messaging/Contracts/SafetyMessenger` (catégorie `essential`) | `HandoverTest`, `tests/Feature/Messaging/SafetyMessengerTest.php` | |
| FR-M04 restrictions de garde | T | `Pickup/Application/CustodyRestrictionService.php` (lecture journalisée `custody_restriction.read`) | `CustodyRestrictionTest` (2) | |
| FR-M05 veille de non-récupération | A | — (T726) | — | `LateWatchService` et `public-nursery:pickup-watch` absents |
| FR-M06 paquet du matin, lot hors ligne | A | — (T725) | — | aucun gestionnaire `sync.handlers.public_nursery.pickup` |
| FR-M07 code à usage unique | A | — (T724) | — | |
| FR-M08 registre et DOC-19 | A | — (T727, T729) | — | |
| FR-M09 carte DOC-21 avec QR | A | — (T727) | — | |
| FR-M10 référentiel national versionné | T | `Development/Application/FrameService.php`, import CSV | `DevelopmentFrameTest` (2) | écriture ouverte à `settings.manage` du client, et non au seul R21 national (RG-TER-6) ; **500 sur corps vide** (`AllRoutesContractTest`) |
| FR-M11 observation à 3 niveaux | T | `ObservationService.php`, `POST /public-nursery/sync/observations` (`client_uuid`) | `ObservationTest` (2) | route de synchronisation propre, hors `So/Sync` (écart à A9 et T609) |
| FR-M12 carnet DOC-18 | P | `ProgressBookService.php` (brouillon, validation, publication, réédition) | `ProgressBookTest` (2) | **aucune route HTTP** ; écran inactif ; PDF rendu sans numéro ni QR (hors catalogue `Documents`) |
| FR-M13 cahier de vie filtré par consentement | T | `LifeBookService.php`, `LifeBookController.php`, `People/Contracts/ConsentRecorder` | `LifeBookTest` (2) | |
| FR-M14 purge des médias | T | `Development/Console/PurgeMediaCommand.php` | `PurgeMediaTest` (1) | |
| FR-M15 besoins particuliers | T | `NeedReportService.php` | `NeedReportTest` (2) | |
| FR-M16 décision de passage | T | `Transition/Application/TransitionService.php`, `NurseryPromotionPlanner.php` | `TransitionDecisionTest` (3) | |
| FR-M17 fiche de liaison DOC-20 | P | `LiaisonSheetService.php`, gabarits `doc20_*.php` | `LiaisonSheetTest` (1) | signée, mais sans numéro, QR ni vérification ; servie en HTML (`v-html`) |
| FR-M18 transfert vers le primaire | A | — (T763) | — | dépend de `PublicSchools` (ELV) |
| FR-M19 allergies | T | `Portal/Http/AllergyController.php` → `People/Contracts/FoodAllergies` | `AllergyControllerTest` (1) | |
| FR-M20 NOT-14, aucune alerte un jour de fermeture | A | — | — | dépend de FR-M05 |
| FR-M21 journal des consultations nominatives | A | — (T606, T607 au socle) | — | |

**Bilan : T 11, S 0, P 2, A 8.** Portails : sections `portals.sections.public_nursery` (`ProgressSection`, `LifeBookSection`) testées par `ParentDevelopmentPortalTest`. Modèles de messages : `database/migrations/202610161255_message_templates_public_nursery.sql` (testé).

## Qualité
- **Garde d'écriture non branchée** : `Support/NurseryWriteGate.php` est testé en isolation (`GateTest`), mais aucun contrôleur n'appelle `assertWritable`. L'IPES enveloppe au contraire toute route d'écriture (`HigherRoutes`, `GatedAction`). Le contrôle « toute route non GET passe par la garde » annoncé par T713 n'est pas dans `tests/Architecture/PublicNurseryConventionsTest.php`, qui a 4 tests.
- **Portée de section non appliquée** : `EstablishmentGuard::assertSection` et `SectionScope` ne sont appelés par aucun contrôleur. Une éducatrice R11 voit toutes les sections de l'établissement.
- **Contrôleurs Développement** (`FrameController`, `LifeBookController`, `ObservationController`, `NeedReportController`) : ils vérifient la permission seulement, pas `EstablishmentGuard` (type `nursery`, application ouverte). Repli `settings.manage` partout.
- Contrats : les imports du socle passent par `Contracts/`, sauf les utilitaires du noyau (`Core\Database\ConnectionFactory`, `Core\Security\PermissionChecker`), comme dans l'IPES. SQL écrit à la main avec un filtre `tenant_id` explicite, sans `TenantTable`.
- SQL portable : les tables sont préfixées `pn_` avec `tenant_id`, et le test d'architecture contrôle les mots réservés. Jamais exécuté sur MySQL réel.
- PHPStan : 51 erreurs dans 12 fichiers (18 types itérables manquants, 11 types de retour, 7 types d'argument, 1 code inatteignable, 3 `??` inutiles). La PHPDoc de `HandoverService::text()` est fausse (`array|null` au lieu de `mixed`) : phpstan conclut que la méthode lève toujours une exception.
- Front : `vue-tsc` donne 10 erreurs dans `TransitionDecisionsView.vue` ; ESLint signale `v-html` dans `TransitionLiaisonView.vue`. `NurseryLogbookView.vue` affiche encore « bientôt » alors que T748 est cochée. Les routes Transition n'ont pas de filtre `establishmentTypes`. Pas de test vitest pour les vues, pas de Playwright.
- Migrations datées dans le futur : `202610270900_public_nursery_development.sql`, `202611030900_public_nursery_transition.sql`.

## Écarts au CDC
DOC-18, DOC-20 et DOC-21 sont hors du catalogue `Documents` (RG-DOC-2, RG-DOC-5) ; la synchronisation des observations est propre au module (A9) ; le référentiel n'est pas verrouillé au niveau national (RG-TER-6) ; il n'y a pas de canal vocal réel (Q-09, défaut accepté) ; les dépendances A1, A2, A3, A5, A8 et A10 envers le commun `PublicSchools` ne sont pas livrées.

## Dette et risques
1. Code non commité, en cours d'édition par une autre session (`maternelle/tasks.md` modifié).
2. Le catalogue public affiche `public_nursery` comme `available` (`PublicNurseryApplication::manifest()`), ce qui casse `AccessRequestTest`.
3. Le lot P1 (sécurité de la sortie : SC-1 à SC-3) est inachevé alors que les lots P2 et P3 sont faits. L'ordre de valeur du CDC est inversé.
