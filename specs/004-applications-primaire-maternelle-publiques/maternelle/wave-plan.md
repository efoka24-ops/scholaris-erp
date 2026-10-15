# Plan par vagues : Maternelle publique

Date : 2026-10-15. Entrées : [tasks.md](tasks.md), [socle-reuse.md](socle-reuse.md). Alignement CDC : **P1 = SOR** (S36–S41), **P2 = DEV** (S42–S47), **P3 = TRA** (S48–S52), P4 = pilote.

## Règles
1. **Au plus 3 agents en même temps**, y compris ceux du commun et du Primaire : cette maternelle partage la limite avec `../primaire/wave-plan.md`. Si 3 agents du commun tournent, la vague maternelle attend.
2. **Dossiers exclusifs** : un dossier = un agent par vague. `database/migrations/**` : un seul agent par vague (rôles, modèles de messages). `web/src/router/routes.ts`, `permissions.ts`, `i18n/fr.ts|en.ts` : éditions ciblées seulement, relire juste avant.
3. Les PM se font dans le dossier du module socle concerné, avec ses tests, sans toucher d'autre module (comme W0 de l'IPES).
4. Contrôles qualité avant rendu (HANDOVER §7). Aucun commit sans demande de l'utilisateur.

## Vague M0 · Socle et squelette (P1, en tête)
| Agent | Dossiers exclusifs | Tâches |
|---|---|---|
| M0-a Attendance + Messaging | `src/Modules/Socle/Attendance/**`, `src/Modules/Socle/Messaging/**`, leurs tests | T700, T703, T702, T704 |
| M0-b People | `src/Modules/Socle/People/**`, tests | T701 (puis T705, T706 en avance si créneau) |
| M0-c Squelette | `src/Modules/Apps/PublicNursery/{definitions.php,routes.php,PublicNurseryApplication.php,Support/}`, `database/migrations/*public_nursery*`, `tests/Architecture/PublicNursery*`, `tests/Isolation/PublicNursery*` | T710, T711, T712, T713, T714 (T714 après T702) |
Sortie : contrats PM1, PM2, PM3, PM8 et repli PM4 disponibles ; squelette vert. Prérequis du commun : A4 (sections), A8 (garde) ; sinon replis locaux (plan.md §4).

## Vague M1 · SOR serveur (P1)
| Agent | Dossiers | Tâches |
|---|---|---|
| M1-a Autorisés, restrictions, remise | `PN/Pickup/{Application,Domain,Http}` (hors Sync/Watch), `PN/Portal/Http/PortalPickupController.php` | T720, T721, T722, T723, T724 |
| M1-b Documents SOR | `PN/Documents/**`, `PN/Pickup/Console/DailyRegisterCommand.php`, `PickupAuditCommand.php` | T727, T728, T729 (après T723 : démarre sur les gabarits) |
| M1-c Notifications | `PN/Notifications/**` | adaptateurs NOT-06/NOT-07 utilisés par T723 et T726 |
Tour 2 (créneaux libérés) : M1-d Hors ligne et veille (`PN/Pickup/Application/{MorningPackageService,SyncService,LateWatchService}.php`, `Http/SyncController.php`, `Console/PickupWatchCommand.php`) → T725, T726 ; puis T730 (OpenAPI).

## Vague M2 · Écrans SOR et recette P1
| Agent | Dossiers | Tâches |
|---|---|---|
| M2-a Front sortie | `web/src/views/public-nursery/Pickup*`, `web/src/stores/pickupOffline.ts`, `web/src/api/publicNursery.ts`, `web/e2e/public-nursery-pickup.spec.ts` | T735 |
| M2-b Front parent et directeur | `web/src/views/public-nursery/{ParentPickupView,Register*,Restriction*}.vue`, `web/src/i18n/public-nursery-*.ts` | T736, T737 |
| M2-c Recette P1 | `tests/E2E/PublicNursery/**` | T790 (partie SOR), contrôle SC-1 à SC-4 |
**Jalon P1** : TST-06 vert, O7 contrôlé, hors ligne 10 jours sans doublon.

## Vague M3 · DEV (P2)
Prérequis : T705 (PM6) ; référentiel officiel ou exemple du CDC marqué « à valider » (Q-10).
| Agent | Dossiers | Tâches |
|---|---|---|
| M3-a Référentiel et observations | `PN/Development/{Database,Application/Frame*,Application/Observation*,Http/Frame*,Http/Observation*,Console/ObservationReminders*}` | T740, T741, T742, T743 |
| M3-b Cahier de vie | `PN/Development/{Application,Http}/LifeBook*`, `Console/PurgeMedia*`, `Application/NeedReport*` | T745, T746, T747 |
| M3-c Carnet DOC-18 | `PN/Development/Application/ProgressBook*`, `PN/Documents/Templates/doc18_*` | T744 (après T742) |
Tour 2 : M3-d Front DEV et portail → T748, T749.
**Jalon P2** : aucune photo sans consentement servie (SC-5), DOC-18 < 200 Ko.

## Vague M4 · TRA et allergies (P3)
| Agent | Dossiers | Tâches |
|---|---|---|
| M4-a Organisation + People | `src/Modules/Socle/Organisation/**`, `src/Modules/Socle/People/**` | T707, T706 (si pas déjà faits) |
| M4-b Transition | `PN/Transition/**`, `PN/Documents/Templates/doc20_*` | T760, T761, T762, T763 (T763 attend le transfert ELV du commun) |
| M4-c Allergies, voix, écrans | `PN/Portal/Http/AllergyController.php`, `web/src/views/public-nursery/{Transition*,Allergy*}.vue` | T764, T766, T765 si Q-09 tranchée |

## Vague M5 · Recette et préparation du pilote
Un agent : T790 à T793 ; formation et données de pilote hors code (P4).

## Démarrable sans réponse aux questions
M0 complet, M1 (avec paramètres par défaut « à confirmer »), M2. M3 peut démarrer sur l'exemple du CDC marqué « à valider ». M4 attend PM7 et le transfert commun ; le vrai appel vocal attend Q-09.
