# Réutilisation du socle et de la maternelle publique par `Nursery`, et demandes de changement

Date : 2026-10-20. Modèles : `specs/003-application-primaire/socle-reuse.md`, `specs/004-applications-primaire-maternelle-publiques/maternelle/socle-reuse.md`. Analyse en lecture seule du dépôt au 2026-10-20. **Légende** : C = couvert, P = partiel, N = à créer ; **NSn** = demande de changement du socle (« Nursery Socle »), faite par l'agent propriétaire du module concerné, jamais par un agent `Nursery`.

## 0. Constats transverses
1. **Règle d'architecture** : `Apps/Nursery` n'utilise que les `Contracts/` du socle (`tests/Architecture/StandardsTest`), ne lit aucune table d'un autre module (`NoForeignTableReadTest`) et **ne référence aucune classe d'une autre application** (`PublicNursery`, `Primary`…) : RG-MAT-10, test d'architecture dédié.
2. **Contrats déjà présents au dépôt** (vérifiés) et utilisables tels quels : `Attendance\Contracts\AuthorizedPickupRegistry` (PM1), `People\Contracts\MediaStore` (PM2), `Messaging\Contracts\SafetyMessenger` et `VoiceGateway` (PM3, PM4), `People\Contracts\FoodAllergies` (PM5), `ConsentRecorder` (PM6), `ConsentChecker`, `Organisation\Contracts\TypedPromotionPlanner` et `ConditionalPromotionPlanner` (PM7), `Attendance\Contracts\DailyPresence` (PM8). Point d'entrée commun des lots hors ligne : `Sync\Contracts\BatchItemHandler`, `POST /sync/batches` (signature d'appareil, idempotence), gestionnaires sous `sync.handlers.<kind>`.
3. **Contrats d'écriture et mécanismes déjà éprouvés** (IPES, primaire) : `Organisation\Contracts\StructureWriter`, `EnrolmentGuard`, `Billing\Contracts\InvoiceIssuer`, `PaymentRequester`, `Documents\Contracts\DocumentIssuer`, `IssuanceGuard`, `DocumentCatalog`, `Platform\Contracts\ApplicationAccess`, `ApplicationManifest`, `Staff\Contracts\AssignmentWriter`, `Audit\Contracts\AuditLog`.
4. **Points d'extension par clé de liste du conteneur** (découverts par `getKnownEntryNames()`, jamais par liaison unique) : `platform.applications.nursery`, `billing.fee_kinds.nursery`, `portals.sections.nursery`, `documents.issuance_guards.nursery`, `reporting.indicators.nursery`, `reporting.dashboards.nursery`, `reporting.todo.nursery`, `integrations.export_types.nursery`, `organisation.closure.checks.nursery`, `organisation.closure.promotion.nursery`, `privacy.tenant_data_source.nursery`, `privacy.subject_data_source.nursery`, `sync.handlers.nursery.pickup`, `sync.handlers.nursery.observation`, `console.command.nursery_<nom>`.
5. **Mécanismes à recopier, pas à partager** : `WriteGate`, `GatedAction`, `NurseryRoutes`, `SectionScope`, `RoleMatrix`, `NurseryApplication` (comme `PrimaryRoutes` pour le primaire). Une factorisation commune dans `src/Core` n'est pas demandée avant que quatre applications existent (`Higher`, `Primary`, `PublicNursery`, `Nursery`) ; à reprendre dans la décision MQ-01.
6. **SQL** : préfixes `nu_` et `ey_`, règles de [data-model.md](data-model.md).

## 1. Couverture par domaine
| Domaine maternelle privée | C | P | N | Commentaire |
|---|---|---|---|---|
| Organisation (année, trimestres, sections, paramètres) | années, `/periods`, `/structures`, `/settings`, calendrier | — | — | fermeture d'un jour lisible par le calendrier |
| Enfant, inscription, parents, santé, consentements | `/people`, `/enrollments`, `/consents`, relations, santé | transfert vers le primaire (fiche jointe) | — | `ConsentChecker`, `ConsentRecorder` |
| Appel | `attendance.mode=day_part`, `sync` hors ligne, `AbsenteeDirectory`, `DailyPresence` | — | — | demi-journée ; l'aide fait l'appel |
| Personnes autorisées | `authorized_pickups`, `AuthorizedPickupRegistry`, `MediaStore` | — | — | source unique |
| Sortie : remise, refus, restriction, code, veille, registre, hors ligne | `SafetyMessenger`, `Sync` | — | **journal et règles (existent dans `PublicNursery/Pickup`)** | **mutualisation NS1** |
| Développement : référentiel, observation, carnet, cahier de vie, besoins | `MediaStore`, `ConsentChecker`, `DocumentIssuer` | — | **existent dans `PublicNursery/Development`** | **mutualisation NS2** |
| Passage et fiche de liaison | `YearPromotion`, `TypedPromotionPlanner`, `ConditionalPromotionPlanner`, `DocumentIssuer` | — | décisions et fiche propres à `nursery` | `nu_transition_decisions` |
| Frais, paiements | tout Billing | natures de frais `nursery` | abonnements de services | `InvoiceIssuer::forCharges` |
| Messages | `Messaging`, `/portal/family` | sections `nursery` | modèles NOT-MAT | PS6 équivalent (NS4) |
| Documents | `Documents` complet, QR, réédition | types `nursery_*` | modèles carnet et fiche | RG-MAT-8 |
| Pilotage | `/dashboards/{kind}`, `IndicatorProvider` | indicateurs `nursery` | — | |
| Rôles | portées `class`, `org_unit` | — | rôles `nursery_*` | NS3 |
| Catalogue, essai, abonnement | `EntitlementService`, `platform:billing-run` | manifeste `nursery` | — | `coming_soon` jusqu'à la recette |

## 2. Ce qui existe dans `PublicNursery` et comment le reprendre
Constaté dans `src/Modules/Apps/PublicNursery/` (non commité, en cours de stabilisation par M-001 et M-002) : `Pickup/Application/{AuthorizedPersonsService,HandoverService,CustodyRestrictionService}`, `Pickup/Domain/HandoverDecision`, `Development/Application/{FrameService,ObservationService,LifeBookService,ProgressBookService,NeedReportService}`, `Transition/Application/{TransitionService,LiaisonSheetService}`, `Support/{NurseryWriteGate,GatedAction,NurseryRoutes,Paging,SectionScope,SectionScopeResolver,EstablishmentGuard}`, `Development/Console/PurgeMediaCommand`.
| Élément | Décision pour `nursery` |
|---|---|
| Règle de remise (`HandoverDecision`), restrictions, codes, veille, journal | **Extraire en contrat partagé NS1** (option A). Ne pas importer `PublicNursery` |
| Observation, carnet, cahier de vie, besoins, référentiel | **Extraire en contrat partagé NS2** (option A) |
| `NurseryWriteGate`, `GatedAction`, `NurseryRoutes`, `Paging`, `SectionScope` | **Recopier** sous `Nursery/Support` (mécanismes d'application, pas de socle) |
| `TransitionService`, `LiaisonSheetService` | **Non repris** : le privé n'a ni inspecteur ni règle nationale ; logique propre `nu_*` (les règles de maintien dépendent de MQ-09) |
| Écrans `web/src/views/public-nursery/*` | **Non réutilisés tels quels** (permissions `public_nursery.*`) ; recopiés dans `web/src/apps/nursery/` après leur stabilisation (M-002) |
| Écrans `web/src/views/nursery/*` | **Base du front privé** ; le tableau de bord cesse de renvoyer vers des écrans publics |
| Tables `pn_*` | **Jamais lues** par `nursery` ; migration vers `ey_*` seulement par NS1 et NS2 |
| Modèles de messages `pn.*` | **Non partagés** : modèles `nursery.*` propres (contenu et ton propres au privé) |
| Écarts à ne pas reproduire | garde d'écriture non branchée, portée de section non appliquée, route HTTP du carnet absente, `v-html`, migrations datées dans le futur, listes non paginées, documents hors catalogue `Documents` (audit-maternelle-publique.md) |

## 3. Changements du socle demandés (sans les faire)
| N° | Module propriétaire | Changement (additif) | Sans effet sur l'existant parce que | Exigences | Dépend | Prio |
|---|---|---|---|---|---|---|
| **NS1** | Attendance (ou nouveau `Socle/PickupGate`, au choix de l'orchestrateur) | Contrat `PickupGate` : `handOver(tenantId, establishmentId, learnerId, pickupId\|code, actorId, origin, clientUuid): HandoverResult`, `refuse(...)`, `restrictions(...)`, `watch(date): list<LateAlert>`, `register(date): list<GateEvent>` ; déplace dans le socle la logique et les tables de `PublicNursery/Pickup` (`ey_pickup_*`) ; `app_key` écrit par l'appelant ; `PublicNursery` devient un client du contrat avec le **même jeu de tests rejoué sans retouche** ; migration `pn_*` → `ey_pickup_*` avec comptage ligne à ligne | `PublicNursery` garde ses routes et permissions ; seule la logique descend ; si NS1 est retardé, repli B de plan.md §3 | EX-MAT-10 à 15 | phase 0 (public stabilisé et commité) | P1 |
| **NS2** | People ou nouveau `Socle/EarlyYears` | Contrat `EarlyYears` : référentiel (`scope`, `origin`), observation sans note, carnet, cahier de vie filtré par consentement, besoins ; tables `ey_*` ; migration `pn_dev_*` → `ey_*` | idem ; `PublicNursery` garde routes, permissions `public_nursery.*`, référentiel `scope=national` | EX-MAT-16 à 19 | NS1 (même méthode), phase 0 | P2 |
| **NS3** | Identity (données partagées) | Rôles `nursery_*` et leurs `role_permissions` : nouvelle migration `roles_nursery.sql` ; `RolePermissionsTest` étendu ; miroir `web/src/lib/permissions.ts` avec test de parité (comme `permissions-public-nursery.test.ts`) | nouvelle migration, jamais dans une existante | EX-MAT-07 | — | P0 |
| **NS4** | Messaging (données partagées) | Modèles NOT-MAT-01 à 11 (nouvelle migration) ; ajouter à `TemplateRenderer::ALLOWED` les variables manquantes (`section_label`, `period_label`…) | données seules | EX-MAT-25 | NS3 | P1 |
| **NS5** | Organisation | Réutilisation de `ConditionalPromotionPlanner` (PM7 déjà livré) : `appliesTo` vrai seulement si l'établissement a l'application `nursery` ; aucun changement de code attendu ; **vérifier** qu'un client avec `public_nursery` n'active pas le planificateur privé | le contrat existe, aucune modification | EX-MAT-21, 37 | — | P3 |
| **NS6** | Platform | Catalogue : ligne `nursery` laissée `coming_soon` ; le filtre de menu du front par **application ouverte** (et non par type d'établissement) est décrit dans `GET /me/applications` s'il ne l'est pas déjà ; **aucun changement du catalogue public** (Q-A1) | lecture seule | EX-MAT-01, 40 | — | P1 |
| **NS7** | Documents | Types et gardes `nursery_*` déclarés par le fournisseur de l'application (clé `documents.*.nursery`) : aucun changement du socle attendu ; vérifier que le catalogue accepte la clé | extension par liste de conteneur | EX-MAT-17, 22, 27 | — | P2 |
| **NS8** | Billing | Natures de frais `billing.fee_kinds.nursery` : extension par clé ; aucun changement attendu | idem | EX-MAT-28 | — | P1 |

NS5, NS7 et NS8 ne demandent probablement **aucun** changement : ils sont listés pour qu'un agent le vérifie en lecture et le consigne.

## 4. Ce qui n'est pas demandé au socle
- Pas d'extension d'Assessment : une observation de maternelle n'a ni note ni coefficient (RG-MAT-1). Le référentiel de compétences partagé maternelle/primaire (PS9 de 003) reste non décidé ; si NS2 est retenu, `EarlyYears` couvre la maternelle seulement.
- Pas de nouveau canal de messagerie : la voix réutilise `VoiceGateway`, désactivée par défaut.
- Pas d'extension de `RetentionService` : la purge des photos est une commande de l'application ou de `EarlyYears`.
- Pas de nouveau point d'entrée de synchronisation : `Sync\BatchItemHandler` et `POST /sync/batches` suffisent (MQ-17 tranche la route propre en repli).

## 5. Ordre et regroupement
- **Tour a** (propriétaires de modules, en parallèle) : NS3 et NS4 (données), vérification NS5, NS6, NS7, NS8 en lecture.
- **Tour b** (après stabilisation et commit du public, phase 0) : NS1, puis NS2.
- **Tour c** : `Nursery` consomme NS1 et NS2 ; en cas de retard, repli B encadré par un test de parité.
- Toutes les demandes sont rétrocompatibles : les tests existants restent verts sans retouche, y compris `tests/Feature/PublicNursery`.
