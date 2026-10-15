# Maternelle publique : analyse de couverture

Fichier demandé sous le nom `analysis.md` ; ce nom a été refusé par l'outil d'écriture, d'où `couverture-socle.md`.

Date : 2026-10-15. Source unique : [../cdc-public.md](../cdc-public.md) (CDC v1.0 du 9 octobre 2026). Branche `feat/rewrite-php-laravel`. Partie commune et Primaire : `../primaire/` (autre agent).

## Résumé (20 lignes)
1. Périmètre : modules DEV, SOR, TRA ; exigences [M] des modules communs (EX-COM-04, EX-CAN-03, R11/R12/R14) ; DOC-18 à DOC-21 ; NOT-06, NOT-07, NOT-14 ; RG-DEV-1 à 3, RG-SOR-1 à 3 ; TST-06 ; objectif O7.
2. Bilan : **47 éléments** analysés : **3 couverts** (6 %), **18 partiels** (38 %), **26 à créer** (55 %).
3. Le socle a un **embryon de sortie sécurisée** : table `authorized_pickups`, `PickupService` (autorisation permanente ou du jour), routes `/authorized-pickups` et l'écran `web/src/views/nursery/NurseryPickupView.vue`.
4. Il manque l'essentiel de SOR : remise confirmée, refus, alerte, registre, hors ligne, « non récupéré à 30 min », code à usage unique, restriction de garde.
5. `PickupService` n'est pas un contrat : une application ne peut pas l'appeler (règle `StandardsTest`) ; il faut un contrat dans `Attendance/Contracts` (PM1).
6. `isAuthorized()` compare un **nom saisi** : insuffisant pour O7 ; la remise doit désigner une autorisation par son identifiant.
7. DEV (observations, carnet DOC-18, cahier de vie) : rien côté serveur ; l'écran `NurseryLogbookView.vue` est un « bientôt disponible » qui annonce A/EC/NA, contraire à RG-DEV-1 (« pas encore »).
8. Consentement photo : `ConsentChecker::PHOTO` existe, mais ni preuve écrite, ni contrat d'écriture, ni stockage de médias hors dossier personnel (PM2, PM6).
9. Messagerie : le blocage `consent_missing` de `DeliveryProcessor` empêcherait les messages de sécurité (RG-COM-2) ; aucun canal vocal (appel) n'existe (PM3, PM4).
10. Allergies : stockées chiffrées dans `health_records`, mais lisibles seulement en bloc avec `people.health.read` ; il faut une lecture minimale « allergies alimentaires » pour la section (PM5).
11. Territoires, portées territoriales et `PublicPrimary` (non commités) ne portent rien de propre à la maternelle ; le groupe scolaire (deux établissements liés) n'est pas modélisé.
12. Les familles sont déjà partagées entre établissements d'un même client (`people` sans `establishment_id`) : base du groupe scolaire.
13. Le planificateur de passage est choisi par **type** d'établissement (`TypedPromotionPlanner`) : il toucherait aussi les maternelles privées (PM7).
14. Nom proposé : `src/Modules/Apps/PublicNursery` (voisin de `PublicPrimary`), tables `pn_`, permissions `public_nursery.*`, routes `/public-nursery/*`.
15. Le CDC propose `nu_` : préfixe laissé à la future maternelle privée `Nursery` pour éviter toute collision.
16. Écart voulu (non rediscuté) : CDC en Laravel/PHP 8.4/PostgreSQL/Redis/Gotenberg ; réalisé en PHP 8.1 sans framework, MySQL 8, Dompdf, cron au lieu de files Redis.
17. Hors ligne : le socle sait synchroniser l'appel (`/attendance-sessions/sync`, `client_uuid`) ; le contrôle de sortie a besoin de son propre lot (liste du matin, décisions horodatées).
18. Changements du socle demandés : **PM1 à PM8**, tous additifs et sans effet sur l'existant.
19. Risques majeurs : appel vocal indisponible au pilote, référentiel d'observation non validé, contradiction de durée de conservation des photos (RG-DEV-3 contre partie Sécurité).
20. Démarrable sans réponse : SOR (P1) avec paramètres par défaut « à confirmer » ; DEV et TRA attendent surtout la validation du référentiel.

Légende : **C** couvert par le socle ; **P** partiel ; **N** à créer. Chemins vérifiés le 2026-10-15 (racine `src/Modules/Socle/` sauf mention).

## 1. SOR · Sécurité des arrivées et des sorties (lot P1)

| ID | Exigence ou règle | État | Existant vérifié | Écart |
|---|---|---|---|---|
| EX-SOR-01 | Liste des personnes autorisées tenue par les parents : nom, lien, téléphone, photo | P | `Attendance/Database/Migrations/202610082703_attendance_discipline.sql` (table `authorized_pickups` : `pickup_name`, `relationship`, `phone`, `photo_document_ref`, `grant_type`, `valid_on`, `status`) ; `Attendance/Application/PickupService.php` ; routes `GET/POST /authorized-pickups`, `POST /authorized-pickups/{id}/revoke` (`Attendance/routes.php` l. 46-48) | Écriture réservée au personnel (`attendance.discipline.manage`, `Attendance/Http/PickupController.php`) : **aucune saisie par le parent** ; pas de contrat applicatif (PM1) ; photo = simple référence sans stockage dédié (PM2) |
| EX-SOR-02 | Contrôle à la sortie, personne non autorisée signalée immédiatement | N | Écran `web/src/views/nursery/NurseryPickupView.vue` : affiche les autorisés du jour ; la clé i18n `nursery.handoverText` dit « pas encore disponible côté serveur » | Remise, refus, signalement à créer (`pn_gate_events`) |
| EX-SOR-03 | Autorisation ponctuelle par SMS avec code à usage unique | P | `grant_type = 'day'` + `valid_on` dans `PickupService::grant` | Aucun code, aucun canal parent ; à créer (`pn_pickup_codes`) |
| EX-SOR-04 | Non récupéré 30 min après la fin des cours : appel des parents puis alerte du directeur | N | Aucune heure de fin des cours exploitable ; pas de canal vocal | Commande cron + paramètre ; PM4 (voix) ; heure de fin attendue du commun (ECO) |
| EX-SOR-05 | Registre des arrivées et sorties (DOC-19) | N | — | `pn_gate_events` + DOC-19 |
| RG-SOR-1 | Remise seulement à une personne autorisée ; en cas de doute, l'enfant reste | N | `PickupService::isAuthorized` compare un nom libre | Remise par identifiant d'autorisation ; aucun choix « autre personne » ne remet l'enfant |
| RG-SOR-2 | Décision de justice limitant la garde, visible du seul personnel de la section | N | — | `pn_custody_restrictions`, accès par portée « section » |
| RG-SOR-3 | Fonctionne hors ligne avec la liste du matin | P | PWA hors ligne pour l'appel (`/attendance-sessions/sync`, `Attendance/Http/Requests/SyncItemRequest.php`, `client_uuid`) | Paquet du matin et lot de décisions propres à SOR |
| Arrivées | « arrivées et sorties » (titre du module) | P | Appel du socle (`attendance-sessions`) | Heure d'arrivée et accompagnant facultatif dans `pn_gate_events` |

## 2. DEV · Suivi du développement et cahier de vie (lot P2)

| ID | Exigence ou règle | État | Existant vérifié | Écart |
|---|---|---|---|---|
| EX-DEV-01 | Référentiel d'observation par domaine (national, à valider) | N | Assessment est orienté notes (`Assessment/Contracts/CurriculumCatalog.php`) ; l'échelle qualitative PS2 de `specs/003-application-primaire/socle-reuse.md` n'est **pas** implémentée (aucune occurrence de `qualitative_scale` dans `src/`) | `pn_dev_frames`, `pn_dev_domains`, `pn_dev_items`, versionnés, réglage national |
| EX-DEV-02 | Observation rapide : élève, domaine, item, niveau, commentaire | N | — | `pn_observations`, saisie hors ligne |
| EX-DEV-03 | Carnet de suivi trimestriel (DOC-18), termes positifs | N | Vocabulaire `nursery` → « carnet de suivi » (`Organisation/Application/DefaultApplications.php`) ; écran vide `web/src/views/nursery/NurseryLogbookView.vue` | `pn_progress_books` + DOC-18 |
| EX-DEV-04 | Cahier de vie : photos et activités partagées aux parents consentants | P | Consentement `photo` (`People/Contracts/ConsentChecker.php`) ; fichiers seulement par personne (`People/Application/DocumentService.php`, `People/Infrastructure/FileStorage.php`) | Médias de section (PM2), preuve écrite (PM6), filtrage à chaque lecture |
| EX-DEV-05 | Signalement d'un besoin particulier au directeur et aux parents | N | — | `pn_need_reports` + message |
| RG-DEV-1 | Ni note, ni rang, ni « non acquis » ; niveau le plus bas « pas encore » | N | L'écran annonce « A / EC / NA » (commentaire de `NurseryLogbookView.vue`, clés `nursery.logbookPlan*`) : **à corriger** | Énumération fermée `acquired`, `in_progress`, `not_yet` ; test d'architecture « aucune colonne de note ni de rang » |
| RG-DEV-2 | Photo partagée seulement avec consentement écrit, sinon masquée ou non publiée | P | `ConsentChecker::hasConsent(..., 'photo')` | Étiquetage obligatoire des enfants visibles, preuve écrite, filtre à chaque lecture |
| RG-DEV-3 | Photos conservées jusqu'à la fin de l'année suivante puis supprimées | N | `Audit/Application/RetentionService.php` ne traite **que** les tables d'Audit | Commande de purge propre à l'application ; contradiction avec la partie Sécurité (« au bout d'un an ») → Q-07 |

## 3. TRA · Passage vers le primaire (lot P3)

| ID | Exigence | État | Existant vérifié | Écart |
|---|---|---|---|---|
| EX-TRA-01 | Décision de passage (section supérieure ou SIL/Class 1) avec avis de l'éducatrice ; maintien exceptionnel sur demande des parents et avis de l'inspecteur | P | `Organisation/Contracts/TypedPromotionPlanner.php` (passage à la clôture, choisi par type d'établissement) | `pn_transition_decisions` ; planificateur limité aux établissements publics (PM7) |
| EX-TRA-02 | Fiche de liaison DOC-20 : identité, parcours, points d'appui, besoins | N | `Documents/Contracts/DocumentIssuer.php` | Type DOC-20 + données tirées de DEV |
| EX-TRA-03 | Transfert électronique vers le primaire du groupe ou une autre école | P | Familles partagées par client (`people` sans `establishment_id`, `Identity/Database/Migrations/202610082001_identity_tables.sql`) ; `Documents/Contracts/DocumentCatalog::validFor` | Transfert ELV attendu du commun ; la fiche DOC-20 suit l'enfant comme document émis |

## 4. Exigences [M] des modules communs et rôles

| ID | Exigence | État | Existant vérifié | Écart |
|---|---|---|---|---|
| EX-COM-04 [M] | Portail parent : personnes autorisées | P | Sections enregistrables `portals.sections.<app>` (`Portals/Contracts/PortalSection.php`) ; `Identity/Contracts/UserPersonLink.php` ; `People/Contracts/PersonDirectory::guarantorsOf` | Section et routes parent (lecture, ajout, retrait, code du jour) |
| EX-CAN-03 [M] | Allergies alimentaires signalées par le parent, visibles du seul personnel concerné | P | `People/Application/HealthService.php` (`allergies` dans `FIELDS`, chiffré) ; routes `GET/PUT /people/{id}/health` | Lecture « tout ou rien » sous `people.health.read` ; pas de saisie parent → PM5 |
| R11 | Éducatrice : suivi, cahier de vie, sorties | N | Portée `class` (`Identity/Contracts/Scope.php`, non commité) | Rôle `pn_educator` |
| R12 | Aide maternelle : appel, sorties | N | — | Rôle `pn_assistant` ; appel via PRE (commun) |
| R14 | Personne autorisée sans compte : nom, téléphone, photo, code | P | `authorized_pickups` (sans code) ; `RelationKind::AuthorizedPickup` (`People/Domain/RelationKind.php`) n'est relié à rien | Code de carte (DOC-21) et code du jour |
| Groupe scolaire | Deux établissements liés partageant directeur, familles, infrastructures | P | Familles partagées (ci-dessus) ; rôle affectable dans deux établissements (Identity) ; `pp_school_profiles` sans lien de groupe (`src/Modules/Apps/PublicPrimary/Registry/Database/Migrations/202610151002_primary_public_registry.sql`) | Lien de groupe attendu du commun (ECO) |
| RG-ELV-3 (4 ans) | Avertissement, jamais refus | N | Gardes `organisation.enrolment.guards.<app>` (`Organisation/Contracts/EnrolmentGuard.php`) | Porté par ELV commun, paramètre maternelle |

## 5. Documents et notifications

| ID | Objet | État | Existant vérifié | Écart |
|---|---|---|---|---|
| DOC-18 | Carnet de suivi trimestriel | N | `DocumentIssuer`, `ExtendedDocumentIssuer`, PDF Dompdf | Modèle national bilingue, < 200 Ko (RG-DOC-6) |
| DOC-19 | Registre quotidien des arrivées et sorties | N | — | Génération quotidienne, sans QR |
| DOC-20 | Fiche de liaison | N | — | Voir TRA |
| DOC-21 | Carte des personnes autorisées, avec QR | P | QR et vérification publique du socle (S06) ; `DocumentIssuer::revoke` | Révocation automatique quand une autorisation est retirée |
| NOT-06 | Enfant non récupéré : parents (appel, SMS) puis directeur | P | `Messaging/Contracts/Messenger.php` (canaux `sms`, `email`, `push`, drapeau `urgent`) | Pas de canal vocal (PM4) ; consentement bloquant (PM3) |
| NOT-07 | Personne non autorisée : parents, directeur (SMS, appel) | P | idem | idem ; ne jamais prévenir la personne visée par une restriction (Q-05) |
| NOT-14 | Fermeture exceptionnelle | P | Annonces du socle (S05) | Porté par COM ; côté maternelle : suspendre la veille « non récupéré » ce jour-là |
| Variables de modèle | `child_name`, `time`, `date`, `details`, `reference`, `label` | C | `Messaging/Domain/TemplateRenderer::ALLOWED` | Aucune variable nouvelle nécessaire |

## 6. Évaluation maternelle, protection des données, tests

| Élément | État | Existant ou écart |
|---|---|---|
| Domaines, niveaux, fréquence mensuelle, synthèse trimestrielle | N | Paramètres `public_nursery.dev.*` réglés au niveau national (RG-TER-6) |
| Passage automatique, maintien exceptionnel | N | `pn_transition_decisions` |
| Journal des consultations nominatives par la supervision | C | `Identity/Application/ScopedAccessAudit.php` (non commité) + `Audit/Contracts/AuditLog.php` |
| Masquage des agrégats de moins de 5 élèves | C | `Reporting/Contracts/DisclosurePolicy.php` (non commité) |
| Consentement révocable à tout moment | P | `People/Application/ConsentService.php` (révocation) ; pas de contrat d'écriture côté parent (PM6) |
| Base locale chiffrée, effacement après 5 codes erronés, appareil révocable | N | Attendu du commun (synchronisation API-04) |
| TST-06 personne non autorisée | N | Tests dédiés (tasks.md) |

Décompte : SOR 9, DEV 8, TRA 3, communs et rôles 7, documents et notifications 8, évaluation et données 7, plus 5 éléments transverses (manifeste N, rôles N, garde d'écriture N, portée « section » P via `Scope::CLASS_GROUP`, paquet hors ligne N) = 47 ; soit C 3, P 18, N 26.

## 7. Code non commité inventorié (ce qui me concerne)
- `src/Modules/Apps/PublicPrimary/` : seulement `Registry` (fiche école `pp_school_profiles`, instantanés `pp_infrastructure_snapshots`, routes `/public-primary/registry/*`). Rien de maternelle ; donne la convention `pp_` déclinée en `pn_`.
- `src/Modules/Socle/Organisation/` : arbre territorial versionné (`Database/Migrations/202610151001_organisation_public_territories.sql`, `Contracts/TerritoryDirectory.php`, `Contracts/TerritoryInfo.php`, `Http/Controllers/TerritoryController.php`). Réutilisé tel quel (en-tête territorial des documents).
- `src/Modules/Socle/Identity/Contracts/Scope.php`, `PermissionScopes.php`, `Domain/ScopeType.php` : portée `territory` ajoutée ; la portée `class` sert de « section » pour R11/R12.
- `src/Modules/Socle/Reporting/Contracts/DisclosurePolicy.php`, `ApprovalWorkflow.php` : DisclosurePolicy réutilisée pour les indicateurs SOR/DEV.

## 8. Écart voulu avec le CDC (noté, non rediscuté)
CDC « Conception technique » : PHP 8.4, Laravel 13, PostgreSQL 16, Redis, Gotenberg, `app/Modules/Apps/Nursery`. Réalisation : PHP 8.1 sans framework, MySQL 8 (tests SQLite), Dompdf, tâches planifiées par cron (`console.command.*`), dossier `src/Modules/Apps/PublicNursery`, routes `/api/v1/public-nursery/*` au lieu de `/api/v1/nursery/*` (API-13).
