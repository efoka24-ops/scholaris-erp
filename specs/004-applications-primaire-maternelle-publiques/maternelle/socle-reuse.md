# Maternelle publique : réutilisation du socle et changements demandés (PM1 à PM8)

Date : 2026-10-15. Règle (HANDOVER §3, `tests/Architecture/StandardsTest`) : `PublicNursery` n'utilise **que** les `Contracts/` du socle. Chaque changement PMn est **additif** : aucune route, colonne, valeur par défaut ni comportement existant n'est modifié ; les tests actuels (5130 verts) restent verts sans retouche.

## 1. Services du socle réutilisés tels quels
| Besoin maternelle | Contrat du socle (vérifié) | Usage |
|---|---|---|
| Établissement, type `nursery` | `Organisation/Contracts/EstablishmentDirectory`, `EstablishmentInfo` | Refuser toute route `public-nursery` hors d'un établissement `nursery` (404) |
| Territoire de l'école (en-tête des documents) | `Organisation/Contracts/TerritoryDirectory::lineage` (non commité) | Arrondissement, département, région sur DOC-18 à 21 |
| Paramètres nationaux | `Organisation/Contracts/SettingsReader::value` | Clés `public_nursery.*` (liste dans plan.md §6) |
| Périodes (trimestres), année | `Organisation/Contracts/CurrentPeriod`, `PeriodInfo` | Carnet trimestriel, fréquence mensuelle |
| Sections (classes) | `Organisation/Contracts/StructureInfo`, `People/Contracts/EnrollmentDirectory::personIdsInStructure` | Liste des enfants d'une section |
| Passage de fin d'année | `Organisation/Contracts/TypedPromotionPlanner` (+ PM7) | Décisions TRA appliquées à la clôture |
| Portée « section » de R11/R12 | `Identity/Contracts/PermissionScopes::scopesFor` (type `class`) | Filtrage de toutes les lectures nominatives |
| Lien compte → personne, parents | `Identity/Contracts/UserPersonLink`, `People/Contracts/PersonDirectory::guarantorsOf`, `learnersOf` | Portail parent |
| Identité de l'enfant | `People/Contracts/PersonDirectory::find`, `BirthInfo` | Fiche de liaison |
| Consentement photo | `People/Contracts/ConsentChecker::hasConsent(..., ConsentChecker::PHOTO)` | Filtre du cahier de vie (+ PM6 pour la preuve écrite) |
| Messages | `Messaging/Contracts/Messenger::send` (`urgent = true`) ; variables existantes `child_name`, `time`, `date`, `details`, `reference`, `label` | NOT-06, NOT-07, codes du jour, signalements |
| Documents | `Documents/Contracts/DocumentIssuer`, `ExtendedDocumentIssuer`, `IssuanceGuard`, `DocumentCatalog::validFor` | DOC-18 à DOC-21 ; DOC-20 lisible par le primaire |
| Journal | `Audit/Contracts/AuditLog::append` | Remises, refus, restrictions, consultations |
| Données personnelles | `Audit/Contracts/TableExportSource`, `SubjectDataSource` | Export et droit d'accès pour les tables `pn_*` |
| Agrégats masqués | `Reporting/Contracts/DisclosurePolicy` (non commité), `IndicatorProvider` | Indicateurs de sortie et d'observation pour la supervision |
| Portail | `Portals/Contracts/PortalSection` (clé `portals.sections.public_nursery`) | Autorisés, carnet, cahier de vie, allergies |
| Accès à l'application | `Platform/Contracts/ApplicationAccess`, `ApplicationManifest` (clé `platform.applications.public_nursery`) | Garde d'écriture 423/403 |
| Commandes | clé `console.command.<nom>` | Veille de sortie, registre quotidien, purge des photos |

## 2. Ce qui existe déjà pour la maternelle et comment le reprendre
| Élément | Chemin | Décision |
|---|---|---|
| Table `authorized_pickups` | `src/Modules/Socle/Attendance/Database/Migrations/202610082703_attendance_discipline.sql` | **Source unique** des personnes autorisées ; lue et écrite via PM1 |
| `PickupService` (grant, revoke, isAuthorized par nom) | `src/Modules/Socle/Attendance/Application/PickupService.php` | Encapsulé par PM1 ; `isAuthorized` par nom **n'est pas utilisé** pour la remise |
| Routes `/authorized-pickups` | `src/Modules/Socle/Attendance/routes.php` l. 46-48 | Conservées pour le personnel ; le parent passe par `/public-nursery/portal/*` |
| `RelationKind::AuthorizedPickup` | `src/Modules/Socle/People/Domain/RelationKind.php` | Non utilisé (R14 n'a pas de fiche personne) ; à documenter comme hérité |
| Vocabulaire `nursery` | `src/Modules/Socle/Organisation/Application/DefaultApplications.php` | Repris (enfant, section, trimestre, carnet de suivi) |
| Écrans `NurseryPickupView.vue`, `NurseryDashboardView.vue`, `NurseryLogbookView.vue` | `web/src/views/nursery/` | Réutilisés comme base ; `NurseryLogbookView` corrigé (trois niveaux dont « pas encore », RG-DEV-1) |
| Appel hors ligne | `src/Modules/Socle/Attendance/Http/Requests/SyncItemRequest.php`, `POST /attendance-sessions/sync` | Modèle du lot SOR (`client_uuid`, conflits jamais écrasés) |

## 3. Changements du socle demandés
| N° | Module | Changement (additif) | Sans effet sur l'existant parce que | Exigences | Lot |
|---|---|---|---|---|---|
| **PM1** | Attendance | Contrat `Attendance\Contracts\AuthorizedPickupRegistry` : `validOn(tenantId, establishmentId, list $learnerIds, string $date): list<AuthorizedPickupInfo>` ; `find(tenantId, establishmentId, id): ?AuthorizedPickupInfo` ; `grant(tenantId, establishmentId, learnerId, array $data, string $actorId): string` ; `revoke(tenantId, establishmentId, id, string $actorId): void`. DTO `AuthorizedPickupInfo` (id, learnerId, name, relationship, phone, photoRef, grantType, validOn, status). Clés de `$data` : `name`, `relationship`, `phone`, `photo_ref`, `grant_type`, `valid_on`. Implémentation `Infrastructure/PdoAuthorizedPickupRegistry` : lecture SQL bornée au client et à l'établissement ; `grant` et `revoke` passent par `PickupService` (mêmes validations et mêmes règles que les routes). La remise désigne l'autorisation par son identifiant (`find`, `validOn`, `AuthorizedPickupInfo::isValidOn`) : le contrat n'offre aucune recherche par nom (RG-SOR-1). Liaison dans `Attendance/definitions.php`. | Nouvelle interface et nouvelle liaison ; `PickupService`, routes et table inchangés | EX-SOR-01, 02, 03 | P1 |
| **PM2** | People | Contrat `People\Contracts\MediaStore` : `put(tenantId, string $namespace, string $ownerId, string $content): MediaRef`, `signedUrl(tenantId, MediaRef, int $ttl, string $route): string`, `readSigned(tenantId, MediaRef, int $expires, string $signature): MediaContent` (403 lien falsifié ou expiré, 404 introuvable), `delete(tenantId, MediaRef): void`. `Infrastructure/FileMediaStore` est construit **par composition au-dessus d'`AttachmentStore`** : type réel, `FileScanner` (refus des fichiers actifs) et stockage privé ne sont pas réimplémentés ; il ajoute seulement la taille max `people.media.max_bytes` (défaut 2 Mo, jamais au-dessus du plafond d'`AttachmentStore`), l'espace de noms imposé (domaine `media-<espace>`, jamais `people` ni le domaine d'une autre application), la référence opaque (80 caractères au plus, sans client ni chemin), l'URL signée (HMAC `UrlSigner`, durée bornée à 1 h) et la suppression. | Nouvelle interface ; `DocumentService` et `person_documents` inchangés | EX-SOR-01 (photo), EX-DEV-04 | P1 |
| **PM3** | Messaging | Messages de **sécurité** (RG-COM-2) : **aucune colonne `safety` ni mécanisme parallèle**. NOT-06 et NOT-07 utilisent la catégorie `essential` livrée par la tâche T614 du Primaire (PP10, `messaging_opt_outs`) ; `Messaging\Contracts\SafetyMessenger` n'est au plus qu'une façade mince sur cette catégorie. Dépend de T614. | Aucune migration ni branche dans `DeliveryProcessor` au titre de PM3 : comportement actuel strictement identique | RG-COM-2, NOT-06, NOT-07 | P1 |
| **PM4** | Messaging | Canal `voice` : la Maternelle est **propriétaire unique** de la constante `Channel::VOICE` et de l'interface `Messaging\Contracts\VoiceGateway` (désactivée par défaut, simulateur pour les tests) ; le Primaire (T617) les réutilise. Repli automatique en `sms` avec motif `voice_unavailable` dans la livraison quand la passerelle est désactivée. | `Channel::all()` n'inclut `voice` que si la passerelle est activée ; les envois existants ne demandent pas `voice` | NOT-06, NOT-07 (« appel ») ; EX-COM-02 (commun) | P1 (repli SMS) / P3 (vrai appel) |
| **PM5** | People | Contrat `People\Contracts\FoodAllergies` : `forLearners(tenantId, list $learnerIds): array<string, ?string>` (ne déchiffre et ne renvoie **que** le champ `allergies`) ; `declare(tenantId, learnerId, ?string $text, string $actorId): void` (ne modifie que ce champ, journalisé). | `HealthService`, routes `/people/{id}/health` et permission `people.health.read` inchangés | EX-CAN-03 | P3 |
| **PM6** | People | Contrat `People\Contracts\ConsentRecorder` : `grant(tenantId, personId, purpose, actorId, ?string $evidenceRef)`, `revoke(...)` ; méthode `hasWrittenConsent(tenantId, personId, purpose): bool` (consentement actif **avec** preuve : `evidence_ref` non nul ou accord donné par un compte parent authentifié, selon Q-06). Nouvelle colonne nullable `consents.evidence_ref VARCHAR(80)`. | Colonne nullable, `ConsentChecker::hasConsent` inchangé | RG-DEV-2, EX-DEV-04 | P2 |
| **PM7** | Organisation | Interface `Organisation\Contracts\ConditionalPromotionPlanner extends TypedPromotionPlanner` avec `appliesTo(tenantId, establishmentId): bool` ; la clôture ne retient un planificateur conditionnel que s'il s'applique. | Les planificateurs actuels n'implémentent pas l'interface et sont appelés comme avant | EX-TRA-01 (ne pas toucher les maternelles privées) | P3 |
| **PM8** | Attendance | Contrat `Attendance\Contracts\DailyPresence::absentOn(tenantId, establishmentId, string $date, ?string $structureId = null, ?string $dayPart = null): list<string>` (enfants absents dans les appels validés du jour ; `dayPart` = `morning` ou `afternoon`, sinon absent à au moins un appel). Couvre les modes `slot` (demi-journée déduite de l'heure du créneau, paramètre `attendance.afternoon_starts_at`, défaut 12:00 à confirmer) et `day_part` (clé `dp:<classe>:<moment>`, `full_day` compté dans les deux). | Lecture seule, nouvelle interface ; `AbsenteeDirectory` inchangé | EX-SOR-04 (ne surveiller que les présents) | P1 |

Si le commun livre l'équivalent de PM3, PM4 (COM) ou PM8 (PRE), le PM correspondant est retiré et la maternelle consomme le contrat commun (voir spec.md §6, A5 et A6).

## 4. Données de plateforme (pas un changement de code du socle)
- Nouvelle migration `database/migrations/202610<jjhhmm>_roles_public_nursery.sql` : rôles `pn_educator`, `pn_assistant` et `role_permissions` des permissions `public_nursery.*` (règle HANDOVER §10 : jamais dans une migration existante).
- Nouvelle migration `database/migrations/202610<jjhhmm>_message_templates_public_nursery.sql` : modèles de plateforme bilingues `pn.not06.parent`, `pn.not06.director`, `pn.not07.parent`, `pn.not07.director`, `pn.pickup_code`, `pn.need_report`, `pn.progress_book_ready`, avec les seules variables de `TemplateRenderer::ALLOWED`.

## 5. Ce qui n'est **pas** demandé au socle
- Pas de table socle pour le journal des remises (la proposition PS8 de `specs/003-application-primaire/socle-reuse.md` n'est pas nécessaire ici) : le journal reste dans `pn_gate_events`, car il porte des données propres au public (lot hors ligne, restriction, escalade).
- Pas d'extension d'Assessment : les observations de maternelle n'ont ni note ni coefficient (RG-DEV-1) et vivent dans `pn_*`.
- Pas d'extension de `RetentionService` : la purge des photos est une commande de l'application (`public-nursery:purge-media`).
