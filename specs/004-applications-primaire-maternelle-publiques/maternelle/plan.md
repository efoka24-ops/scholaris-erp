# Plan technique : Maternelle publique (`PublicNursery`)

Date : 2026-10-15. Entrées : [spec.md](spec.md), [couverture-socle.md](couverture-socle.md), [socle-reuse.md](socle-reuse.md), [data-model.md](data-model.md).

## 1. Contexte technique (écart voulu avec le CDC, noté)
PHP 8.1 sans framework (ADR-0001), MySQL 8 / SQLite pour les tests, Dompdf, PWA Vue 3 hors ligne (IndexedDB), cron Camoo pour les tâches planifiées. Le CDC parle de Laravel 13, PostgreSQL 16, Redis, Gotenberg et de `app/Modules/Apps/Nursery` : non retenu, sans rediscussion.

## 2. Nom et emplacement
- Dossier : **`src/Modules/Apps/PublicNursery/`**, voisin de `src/Modules/Apps/PublicPrimary/` (commun et primaire publics). `src/Modules/Apps/Nursery/` (vide) reste réservé à la maternelle privée.
- Tables `pn_*`, permissions `public_nursery.*`, routes `/api/v1/public-nursery/*`, clé de manifeste `platform.applications.public_nursery` (Q-01), commandes `public-nursery:*`.

## 3. Arborescence
```
src/Modules/Apps/PublicNursery/
├── definitions.php            # fusionne les definitions.php des sous-modules (glob), manifeste, rôles
├── routes.php                 # inclut */routes.php (même modèle que PublicPrimary/routes.php)
├── PublicNurseryApplication.php  # descripteur, vocabulaire (enfant, section, trimestre, carnet de suivi)
├── Support/                   # NurseryWriteGate (423/403), SectionScope (portée class), EstablishmentGuard (type nursery + public), Clock
├── Pickup/                    # SOR : autorisés (via PM1), remise, refus, restrictions, codes, veille, registre, paquet du matin, lot hors ligne
├── Development/               # DEV : référentiel, observations, carnet, cahier de vie, besoins
├── Transition/                # TRA : décisions, fiche de liaison, planificateur (PM7)
├── Documents/                 # fournisseur DOC-18 à DOC-21, gabarits Dompdf FR/EN
├── Notifications/             # NOT-06, NOT-07, codes ; appelle SafetyMessenger (PM3) et Messenger
└── Portal/                    # sections du portail parent : autorisés, carnet, cahier de vie, allergies
```
Chaque sous-module : `Application/ Domain/ Infrastructure/ Http/ Database/Migrations/ routes.php definitions.php` ; pas de `Contracts/` exposé aux autres applications (la fiche de liaison circule comme document émis, lu par `DocumentCatalog`).

## 4. Dépendances
| Vers | Ce qui est utilisé | Bloquant pour |
|---|---|---|
| Socle existant | voir socle-reuse.md §1 | — |
| PM1 (Attendance, contrat autorisés) | remise, portail parent, codes | P1 |
| PM2 (MediaStore) | photo des autorisés, cahier de vie, pièce de restriction | P1 (photo) / P2 |
| PM3 (messages de sécurité) | NOT-06, NOT-07 malgré refus SMS | P1 |
| PM4 (canal voix) | « appel » de NOT-06/07 ; repli SMS en attendant | P1 repli, P3 vrai appel |
| PM8 (absents du jour) | veille de non-récupération | P1 |
| PM6 (consentement écrit) | cahier de vie | P2 |
| PM5 (allergies) | EX-CAN-03 | P3 |
| PM7 (planificateur conditionnel) | passage | P3 |
| Commun A1, A2, A4, A5, A8, A10 (spec.md §6) | groupe scolaire, horaires, sections, appel, garde d'écriture, rôles | P1 |
| Commun A3 (transfert ELV) | EX-TRA-03 | P3 |

Repli si le commun est en retard : garde d'écriture et horaires portés localement dans `Support/` (paramètre `public_nursery.pickup.day_end_time`), remplacés dès que le contrat commun existe.

## 5. Ordre de réalisation
1. **P1 socle** : PM1, PM2, PM3, PM8 (+ PM4 repli SMS) en parallèle avec le squelette `PublicNursery` (rôles, garde, portée section).
2. **P1 SOR** : autorisés et portail parent → remise/refus/restrictions → paquet du matin et lot hors ligne → veille 30 min → registre DOC-19 et carte DOC-21 → écrans.
3. **P2 DEV** : PM6 → référentiel → observations hors ligne → carnet DOC-18 → cahier de vie et purge → besoins.
4. **P3 TRA** : PM5, PM7 → décisions → fiche DOC-20 → transfert (selon commun) → allergies portail ; PM4 vrai appel si une passerelle est choisie.

## 6. Paramètres (SettingsReader, niveau national sauf mention ; valeurs « à confirmer »)
| Clé | Défaut | Source |
|---|---|---|
| `public_nursery.pickup.late_after_minutes` | 30 | EX-SOR-04 |
| `public_nursery.pickup.director_after_minutes` | 15 (après l'alerte parent) | à confirmer (Q-02) |
| `public_nursery.pickup.day_end_time` | aucun (repli si ECO absent ; saisi par l'école, RG-TER-6) | Q-02 |
| `public_nursery.pickup.offline_max_age_days` | 1 pour le mode « doute » ; lecture possible jusqu'à 30 | RG-SOR-3, CDC mobile 30 jours (Q-08) |
| `public_nursery.pickup.code_max_attempts` | 5 | aligné sur « 5 codes erronés » (CDC sécurité), à confirmer |
| `public_nursery.pickup.parent_entries_need_validation` | false | Q-03 |
| `public_nursery.dev.levels` | `acquired`, `in_progress`, `not_yet` (libellés « acquis », « en cours », « pas encore ») | CDC Règles maternelle |
| `public_nursery.dev.min_observations_per_domain_per_month` | 1 | CDC |
| `public_nursery.dev.discouraged_terms` | liste vide (à fournir par l'Inspection générale) | Q-10 |
| `public_nursery.lifebook.retention` | `end_of_next_school_year` | RG-DEV-3 contre « un an » (Q-07) |
| `public_nursery.transition.auto_promote` | true | CDC |
| `messaging.safety_templates` | `pn.not06.*`, `pn.not07.*` (posé par la migration de modèles) | RG-COM-2, PM3 |

## 7. Mode hors ligne du contrôle de sortie (RG-SOR-3)
1. **Paquet du matin** `GET /public-nursery/sections/{id}/pickup-package?date=` (R11/R12 de la section) : enfants de la section, absents connus (PM8), autorisés valables ce jour avec vignette photo réduite (≤ 15 Ko, signée), restrictions actives (nom et nature seulement), empreintes HMAC des codes du jour, numéro du directeur, `generated_at`, empreinte du paquet. Taille cible < 300 Ko pour 40 enfants.
2. **Stockage** : IndexedDB chiffrée par la PWA (mécanisme commun, A9) ; effacée au changement d'utilisateur ; aucune donnée de restriction affichée hors de l'écran de sortie.
3. **Décision hors ligne** : remise seulement à une autorisation du paquet ou à un code dont l'empreinte correspond ; restriction → refus ; sinon refus. Chaque décision a un `client_uuid` et `occurred_at`.
4. **Refus hors ligne** : l'écran propose `tel:` (directeur) et `sms:` prérempli vers les parents ; NOT-07 serveur part à la synchronisation.
5. **Synchronisation** `POST /public-nursery/sync/pickups` (lot signé par la session ; si le point commun API-04 existe, enregistrement d'un gestionnaire à la place) : idempotent par `client_uuid` (`pn_sync_batches`, index uniques) ; remise citant une autorisation révoquée entre-temps → acceptée mais `stale_authorization = 1` + alerte directeur ; jamais d'écrasement d'un événement serveur (409 renvoyé à l'appareil pour affichage).
6. **Paquet périmé** : au-delà de `offline_max_age_days`, l'écran passe en mode « doute » : aucune remise sans appel au directeur (RG-SOR-1).
7. Le hors ligne ne couvre **pas** la veille des 30 minutes (serveur) : un appareil hors ligne affiche toutefois, à H+30, la liste locale des enfants non sortis.

## 8. Tâches planifiées (cron Camoo)
| Commande | Fréquence | Rôle |
|---|---|---|
| `public-nursery:pickup-watch` | chaque minute | NOT-06 parents puis directeur (idempotent par `pn_late_alerts`) |
| `public-nursery:daily-register` | chaque jour 20 h | DOC-19 du jour, contrôle O7 (`pickup-audit`) |
| `public-nursery:purge-media` | chaque jour | RG-DEV-3 |
| `public-nursery:observation-reminders` | chaque lundi | enfants sans observation du mois |

## 9. Sécurité
- Toute route : établissement `nursery` public (sinon 404), portée section pour R11/R12, parent limité à ses enfants (`guarantorsOf`), garde d'écriture 423/403.
- Restrictions et allergies : jamais dans les listes générales, jamais au parent, jamais à la supervision ; lecture journalisée.
- Codes : HMAC avec secret par établissement, jamais affichés après création, 5 essais.
- Photos : URL signées à courte durée (PM2) ; filtre de consentement à chaque lecture.

## 10. Tests
`tests/Feature/PublicNursery/<Module>/`, `tests/Unit/PublicNursery/`, `tests/Architecture/PublicNurseryConventionsTest.php` (contrats seulement, tables `pn_` avec `tenant_id`, aucun mot réservé, aucune colonne de note), `tests/Isolation/PublicNurseryScopeTest.php`, contrat OpenAPI `specs/001-socle-plateforme/contracts/openapi.d/public-nursery.yaml`, Playwright `web/e2e/public-nursery-*.spec.ts`.
