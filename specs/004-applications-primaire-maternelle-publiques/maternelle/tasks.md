# Tâches : Maternelle publique (T700 à T799)

Date : 2026-10-15. Format : `[ID] [P?] [Lot] Titre` — fichiers ; dépendances ; tests. `[P]` = parallélisable. Racine app : `src/Modules/Apps/PublicNursery/` (noté `PN/`). Tests : `tests/Feature/PublicNursery/`, `tests/Unit/PublicNursery/`.
Contrôles avant rendu de chaque tâche : php-cs-fixer sur ses fichiers, phpstan niveau 8 sans erreur, phpunit vert, et pour le front `vue-tsc`, `eslint`, `vitest`.

## Phase 0 · Socle (lot P1 sauf mention)
- **T700 [P] [P1] PM1 contrat des autorisés** — `src/Modules/Socle/Attendance/Contracts/AuthorizedPickupRegistry.php`, `AuthorizedPickupInfo.php`, `Infrastructure/PdoAuthorizedPickupRegistry.php`, `Attendance/definitions.php` ; dép. aucune ; tests `tests/Feature/Attendance/AuthorizedPickupRegistryTest.php` (validOn permanent/jour/révoqué, isolation client, routes existantes inchangées).
- **T701 [P] [P1] PM2 MediaStore** — `src/Modules/Socle/People/Contracts/MediaStore.php`, `MediaRef.php`, `Infrastructure/FileMediaStore.php`, `People/definitions.php` ; tests : fichier actif refusé, taille max, URL signée expirée → 403, espace de noms forcé.
- **T702 [P] [P1] PM3 messages de sécurité** — `Messaging/Contracts/SafetyMessenger.php`, `Application/SafetyMessengerImpl.php`, migration `Messaging/Database/Migrations/2026102xxxxx_messaging_safety.sql` (colonne `safety`), `DeliveryProcessor.php` (branche conditionnelle) ; tests : parent sans consentement COMMUNICATION reçoit un modèle listé dans `messaging.safety_templates` ; modèle non listé reste bloqué ; tous les tests Messaging existants verts.
- **T703 [P] [P1] PM8 absents du jour** — `Attendance/Contracts/DailyPresence.php`, implémentation PDO ; tests : demi-journées, structure filtrée.
- **T704 [P1] PM4 canal voix, repli SMS** — `Messaging/Domain/Channel.php` (constante), `Contracts/VoiceGateway.php`, `Infrastructure/DisabledVoiceGateway.php`, simulateur de test ; dép. T702 ; tests : `voice` demandé sans passerelle → livraison SMS motif `voice_unavailable`.
- **T705 [P] [P2] PM6 consentement écrit** — `People/Contracts/ConsentRecorder.php`, migration colonne `consents.evidence_ref`, `PdoConsentChecker` inchangé ; tests : `hasWrittenConsent` vrai seulement avec preuve (règle Q-06 par défaut), révocation immédiate.
- **T706 [P] [P3] PM5 allergies** — `People/Contracts/FoodAllergies.php`, implémentation sur `HealthCipher` ; tests : seul le champ `allergies` est lu/écrit, autres champs intacts, journal.
- **T707 [P] [P3] PM7 planificateur conditionnel** — `Organisation/Contracts/ConditionalPromotionPlanner.php`, clôture d'année ; tests : planificateur non applicable ignoré, planificateurs existants inchangés.

## Phase 1 · Squelette (P1)
- **T710 [P1] Squelette PublicNursery** — `PN/definitions.php`, `PN/routes.php`, `PN/PublicNurseryApplication.php`, manifeste `platform.applications.public_nursery` ; dép. aucune ; tests : manifeste listé, vocabulaire.
- **T711 [P1] Garde et portée** — `PN/Support/NurseryWriteGate.php`, `SectionScope.php`, `EstablishmentGuard.php` ; dép. T710 ; tests : app suspendue 423, fermée 403, établissement `primary` → 404, R11 hors section → 404.
- **T712 [P1] Rôles et permissions** — `database/migrations/202610xxxxxx_roles_public_nursery.sql`, `web/src/lib/permissions.ts` (édition ciblée) ; dép. T710 ; tests `tests/Feature/PublicNursery/RolePermissionsTest.php`.
- **T713 [P1] Tests d'architecture et d'isolation** — `tests/Architecture/PublicNurseryConventionsTest.php`, `tests/Isolation/PublicNurseryScopeTest.php` ; dép. T710 ; contrôle : contrats seulement, `pn_` + `tenant_id`, aucun mot réservé, aucune colonne `score|mark|grade|rank|average`, toute route non GET passe par la garde.
- **T714 [P1] Modèles de messages** — `database/migrations/202610xxxxxx_message_templates_public_nursery.sql` (FR/EN, variables autorisées) ; dép. T702 ; test : variables ⊂ `TemplateRenderer::ALLOWED`.

## Phase 2 · SOR (P1)
- **T720 [P1] Migration SOR** — `PN/Pickup/Database/Migrations/202610200900_public_nursery_pickup.sql` (data-model §1) ; dép. T710 ; test MySQL-compat.
- **T721 [P1] Autorisés côté personnel et parent** — `PN/Pickup/Application/AuthorizedPersonsService.php`, `Http/AuthorizedPersonsController.php`, `PN/Portal/Http/PortalPickupController.php` ; dép. T700, T701, T711 ; tests : parent ne gère que ses enfants (403/404), photo via MediaStore, retrait → carte révoquée (après T727).
- **T722 [P1] Restrictions de garde** — `PN/Pickup/Application/CustodyRestrictionService.php`, contrôleur ; dép. T720, T711 ; tests : visible section + directeur seulement ; parent et supervision → 404 ; consultation journalisée.
- **T723 [P1] Remise et refus** — `PN/Pickup/Application/HandoverService.php`, `Domain/HandoverDecision.php`, `Http/HandoverController.php` ; dép. T721, T722, T702 ; tests (TST-06) :
  - **enfant remis à une personne non autorisée → refus (422, aucun `handed_over`) + événement `refused` + NOT-07 aux parents et au directeur** ;
  - autorisation révoquée, expirée, d'un autre jour → 422 ;
  - personne visée par une restriction → refus même si autorisée, NOT-07 au directeur et au seul parent non visé ;
  - parent ayant refusé les SMS non essentiels reçoit quand même NOT-07 ;
  - aucune voie (API, lot, directeur) ne crée `handed_over` sans autorisation ni code (SC-1).
- **T724 [P1] Codes à usage unique** — `PN/Pickup/Application/PickupCodeService.php` ; dép. T723, T714 ; tests : code valable un jour, une seule remise, 5 erreurs → blocage + alerte directeur, code jamais stocké en clair.
- **T725 [P1] Paquet du matin et lot hors ligne** — `PN/Pickup/Application/MorningPackageService.php`, `SyncService.php`, `Http/SyncController.php` ; dép. T723, T703 ; tests : lot rejoué deux fois sans doublon ; autorisation révoquée après le paquet → `stale_authorization` + alerte ; paquet périmé → refus des remises « hors doute » ; 10 jours de lots hors ligne remontés sans doublon (TST-04 appliqué à SOR).
- **T726 [P1] Veille de non-récupération** — `PN/Pickup/Application/LateWatchService.php`, `Console/PickupWatchCommand.php` (`public-nursery:pickup-watch`) ; dép. T723, T703, T704 ; tests :
  - **enfant non récupéré 30 min après la fin des cours → appel (voix, repli SMS) des parents, puis alerte du directeur après le second délai** ;
  - une seule alerte par niveau et par jour (commande lancée 10 fois) ;
  - enfant absent, déjà remis ou jour de fermeture (NOT-14) → aucune alerte.
- **T727 [P1] DOC-19 et DOC-21** — `PN/Documents/Domain/PickupDocumentTypes.php`, gabarits `PN/Documents/Templates/doc19_*.php`, `doc21_*.php`, `Console/DailyRegisterCommand.php` ; dép. T723 ; tests : DOC-19 sans QR ni signataire ; DOC-21 avec numéro et QR, page de vérification sans nom ni téléphone (RG-DOC-2), révoquée au retrait.
- **T728 [P1] Contrôle O7 quotidien** — `PN/Pickup/Console/PickupAuditCommand.php` ; dép. T723 ; test : un `handed_over` injecté sans autorisation est signalé.
- **T729 [P1] Registre du directeur** — `PN/Pickup/Http/RegisterController.php` (liste, export XLSX) ; dép. T723 ; tests : filtres, isolation.
- **T730 [P1] Contrat OpenAPI SOR** — `specs/001-socle-plateforme/contracts/openapi.d/public-nursery.yaml` ; dép. T721–T729 ; `OpenApiContractTest`.

## Phase 3 · Écrans SOR (P1)
- **T735 [P1] Écran de sortie hors ligne** — `web/src/views/public-nursery/PickupGateView.vue`, `web/src/stores/pickupOffline.ts`, `web/src/api/publicNursery.ts` (réutilise `NurseryPickupView.vue`) ; dép. T725 ; tests vitest (file, conflit 409), Playwright `web/e2e/public-nursery-pickup.spec.ts` (refus → alerte, mode doute).
- **T736 [P1] Portail parent : autorisés et code du jour** — `web/src/views/public-nursery/ParentPickupView.vue` ; dép. T721, T724.
- **T737 [P1] Registre et restrictions (directeur)** — vues dédiées ; dép. T722, T729 ; i18n `web/src/i18n/public-nursery-fr.ts`, `-en.ts`.

## Phase 4 · DEV (P2)
- **T740 [P2] Migration DEV** — `PN/Development/Database/Migrations/202610270900_public_nursery_development.sql` ; dép. T710.
- **T741 [P2] Référentiel national** — `PN/Development/Application/FrameService.php`, contrôleur admin, import CSV ; dép. T740 ; tests : versionnement, seul R21 écrit, l'école ne modifie rien (RG-TER-6).
- **T742 [P2] Observations** — `ObservationService.php`, `Http/ObservationController.php`, `/public-nursery/sync/observations` ; dép. T741 ; tests : niveau hors liste → 422, aucun champ calculé, hors ligne idempotent.
- **T743 [P2] Rappels de fréquence** — `Console/ObservationRemindersCommand.php` ; dép. T742.
- **T744 [P2] Carnet trimestriel DOC-18** — `ProgressBookService.php`, gabarit DOC-18 FR/EN ; dép. T742 ; tests : non visible avant validation ; terme déconseillé → blocage ; PDF < 200 Ko ; réédition « Duplicata/Rectificatif ».
- **T745 [P2] Cahier de vie** — `LifeBookService.php`, `LifeBookController.php` ; dép. T701, T705 ; tests :
  - **photo avec un enfant sans consentement écrit → jamais servie** (liste, détail, URL signée, portail) ;
  - photo non étiquetée → publication refusée ;
  - consentement retiré → photo retirée à la lecture suivante ;
  - version masquée publiée à la place de l'originale retenue.
- **T746 [P2] Purge des médias** — `Console/PurgeMediaCommand.php` ; dép. T745 ; tests : fichier et ligne supprimés à échéance, rien avant.
- **T747 [P2] Besoins particuliers** — `NeedReportService.php` ; dép. T740, T714 ; tests : visibilité éducatrice, directeur, parents seulement.
- **T748 [P2] Écrans DEV** — `web/src/views/public-nursery/ObservationView.vue`, `ProgressBookView.vue`, `LifeBookView.vue` ; corrige `web/src/views/nursery/NurseryLogbookView.vue` (« pas encore ») ; dép. T742–T747.
- **T749 [P2] Portail parent DEV** — `PN/Portal/Sections/ProgressSection.php`, `LifeBookSection.php` (`portals.sections.public_nursery`) ; dép. T744, T745.

## Phase 5 · TRA et allergies (P3)
- **T760 [P3] Migration TRA** — `PN/Transition/Database/Migrations/202611030900_public_nursery_transition.sql`.
- **T761 [P3] Décisions de passage** — `TransitionService.php`, `NurseryPromotionPlanner.php` (PM7) ; dép. T707, T760 ; tests : proposition automatique, maintien sans demande parentale ni avis inspecteur → 422, maternelle privée non touchée.
- **T762 [P3] Fiche de liaison DOC-20** — `LiaisonSheetService.php`, gabarit ; dép. T761, T742 ; tests : points d'appui issus de « acquis/en cours » seulement, aucune restriction ni allergie copiée.
- **T763 [P3] Transfert vers le primaire** — branchement sur le transfert ELV commun (A3) ; dép. T762 ; test : le primaire du groupe lit DOC-20 via `DocumentCatalog::validFor`.
- **T764 [P3] Allergies** — `PN/Portal/Http/AllergyController.php`, colonne « allergies » de la liste de section ; dép. T706 ; tests : parent déclare, section et cantine voient, autres rôles non.
- **T765 [P3] Vrai appel vocal** — branchement d'une passerelle (si Q-09 tranchée) ; dép. T704.
- **T766 [P3] Écrans TRA** — vues décisions et fiche ; dép. T761, T762.

## Phase 6 · Recette (avant pilote P4)
- **T790** Parcours E2E `tests/E2E/PublicNursery/DayAtGateTest.php` : arrivée → refus → remise → registre.
- **T791** Test de charge du paquet du matin et de la synchronisation (`tests/Load`).
- **T792** Revue de sécurité (restrictions, codes, photos) consignée dans `specs/001-socle-plateforme/security-review.md`.
- **T793** Rapport de recette O7 (SC-1 à SC-7) dans `specs/001-socle-plateforme/test-report.md`.
