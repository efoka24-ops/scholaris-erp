# Tâches : application Maternelle privée (`nursery`)

**Entrées** : [spec.md](spec.md), [plan.md](plan.md), [data-model.md](data-model.md), [socle-reuse.md](socle-reuse.md), [questions.md](questions.md). **Numérotation** : **T1000 à T1099**. Écart au brief M-003 (« T8xx ») noté : `specs/005-application-secondaire/tasks.md` occupe déjà T800 à T979 ; la plage T1000+ évite toute collision. **Format** : `[ID] [P?] [B MQ-n] Description — dépendances — exigences`. `[P]` = parallélisable avec les autres `[P]` de la même vague. **[B MQ-n]** = bloquée par une réponse (questions.md) ; elle démarre avec la valeur par défaut, étiquetée « exemple ». **[D]** = décision de l'utilisateur requise avant de commencer. Tests d'abord quand la règle est connue. **Aucune tâche ne code une valeur réglementaire.**

Qualité exigée avant chaque rendu (de [../006-audit-primaire-maternelle/phases.md](../006-audit-primaire-maternelle/phases.md)) : php-cs-fixer sur ses fichiers seulement, phpstan niveau 8 sans baseline (0 erreur sur le périmètre), phpunit complet vert, `vue-tsc`, `eslint`, `vitest`, `vite build` si le front est touché, fichiers en LF avec saut de ligne final, aucune migration datée dans le futur.

## Vague 0 : décision, squelette et données partagées
| ID | Tâche | Dépend | Exig. |
|---|---|---|---|
| T1000 [D] | Décision MQ-01 (A, B ou C) et MQ-21 (catalogue) consignée dans `specs/007-application-maternelle-privee/decisions.md` par l'utilisateur ; sans réponse : option A séquencée, repli B | — | EX-MAT-10 à 19 |
| T1001 [P] | NS3 Identity : migration `roles_nursery.sql` (rôles `nursery_*` et `role_permissions`), `RolePermissionsTest` étendu, test de parité avec `web/src/lib/permissions.ts` (modèle `permissions-public-nursery.test.ts`) | — | EX-MAT-07 |
| T1002 [P] | NS4 Messaging : migration `message_templates_nursery.sql` (NOT-MAT-01 à 11, FR et EN), variables manquantes de `TemplateRenderer::ALLOWED`, test de rendu | T1001 | EX-MAT-25 |
| T1003 [P] | Vérification en lecture seule de NS5, NS6, NS7, NS8 (planificateur conditionnel, `GET /me/applications`, clés de documents et de frais) ; consigne « aucun changement » ou demande précise | — | EX-MAT-01, 21, 28 |
| T1004 | Squelette `src/Modules/Apps/Nursery` : `NurseryApplication` (manifeste `coming_soon`, 30 000 XAF, 90 jours, grâce 15 jours, vocabulaire), `definitions.php` et `routes.php` racine (glob), `Support/` (`WriteGate`, `GatedAction`, `NurseryRoutes`, `SectionScope`, `EstablishmentGuard`, `Paging`), `Policies/` (`ScopeResolver`, `RoleMatrix`) | T1001 | EX-MAT-01, 07, 31 |
| T1005 | Tests de squelette : `tests/Architecture/NurseryConventionsTest.php` (contrats seulement, aucune classe d'une autre application, tables `nu_*` avec `tenant_id` et `establishment_id`, mots réservés, aucune colonne de note, **toute route gardée y compris GET**, aucun littéral de seuil, migrations non futures), `tests/Isolation/NurseryScopeTest.php`, `tests/Feature/Nursery/Core/WriteGateTest.php` (`trial`, `active`, `past_due` écrivent ; `suspended` lit et exporte, 423 en écriture ; `closed` ni l'un ni l'autre), `tests/Support/Nursery/` | T1004 | EX-MAT-31 |
| T1006 | `RoleMatrixTest` : un cas par ligne de spec.md §2 ; éducatrice d'une autre section → 404 en lecture, 403 journalisé en écriture | T1001, T1004 | EX-MAT-07 |
| T1007 | Contrat OpenAPI `specs/001-socle-plateforme/contracts/openapi.d/nursery-core.yaml` (423, 403, 404 d'isolation, pagination par curseur) lu par `OpenApiContractTest` | T1004 | EX-MAT-31 |
| T1008 [P] [B MQ-11] | Fixtures `tests/Fixtures/Nursery/` : école synthétique `SYN-NUR-*`, 4 sections étiquetées « exemple », enfants fictifs, autorisés fictifs ; paramètres `nursery.*` de plan.md §7 en défauts de données | T1004 | SC-MAT-5 |

## Vague 1 : mutualisation avec la maternelle publique (option A ; prérequis : phase 0 de phases.md, `PublicNursery` stabilisé et commité)
| ID | Tâche | Dépend | Exig. |
|---|---|---|---|
| T1010 [D] | NS1 : contrat `PickupGate` et modules de socle (`ey_pickup_*`), logique reprise de `PublicNursery/Pickup` ; `PublicNursery` devient client du contrat ; `app_key` écrit par l'appelant | T1000 | EX-MAT-10 à 15 |
| T1011 | NS1 : migration des données `pn_gate_events`, `pn_late_alerts`, `pn_custody_restrictions`, `pn_pickup_codes`, `pn_pickup_cards`, `pn_sync_batches` vers `ey_pickup_*` avec comptage ligne à ligne et retour arrière documenté | T1010 | EX-MAT-10 à 15 |
| T1012 | NS2 : contrat `EarlyYears` (`ey_dev_*`, `ey_observations`, `ey_progress_books`, `ey_lifebook_*`, `ey_need_reports`), colonnes `scope` et `origin` du référentiel ; migration des données `pn_*` correspondantes avec comptage ligne à ligne | T1010 | EX-MAT-16 à 19 |
| T1013 | Non-régression : `tests/Feature/PublicNursery`, `tests/Isolation/PublicNurseryScopeTest.php`, `tests/Architecture/PublicNurseryConventionsTest.php` rejoués **sans retouche** après T1010 et T1012 ; suite complète verte | T1011, T1012 | RG-MAT-10 |
| T1014 [D] | Repli B si T1010 est retardé : décision écrite de recopier `Pickup/Domain` dans `Nursery` (voir T1032) | T1000 | EX-MAT-10 |

## Vague 2 : sortie sécurisée côté `Nursery` (prérequis : T1005 ; T1010 ou T1014)
| ID | Tâche | Dépend | Exig. |
|---|---|---|---|
| T1020 [P] | Pickup : routes des autorisés (`AuthorizedPickupRegistry`, photo par `MediaStore`), portée section, pagination ; tests d'isolation (parent d'un autre enfant → 404) | T1005 | EX-MAT-09 |
| T1021 | Pickup : `POST /nursery/pickup/handovers` (identifiant d'autorisation ou code, **aucun nom libre**) et `/refusals` ; toutes les voies d'écriture testées (API, lot, saisie directeur) | T1020, T1010 ou T1014 | EX-MAT-10 |
| T1022 [P] | Pickup : restrictions de garde (directeur), invisibles du parent et de la supervision, consultation journalisée | T1005 | EX-MAT-11, 33 |
| T1023 [P] | Pickup : code à usage unique (HMAC, essais limités par paramètre, jamais affiché après création) | T1020 | EX-MAT-14 |
| T1024 | Pickup : commande `nursery:pickup-watch` (idempotente par niveau, enfant, jour ; jour de fermeture et absents exclus) | T1021 | EX-MAT-12, 36 |
| T1025 | Pickup : paquet du matin `GET /nursery/sections/{id}/pickup-package` ; gestionnaire `sync.handlers.nursery.pickup` sur `Sync\BatchItemHandler` ; `stale_authorization` ; mode « doute » | T1021 | EX-MAT-13 |
| T1026 | Pickup : registre quotidien (`nursery:daily-register`, DOC-MAT-04) et `nursery:consistency-check` (aucune remise sans autorisation, SC-MAT-1) | T1021 | EX-MAT-15 |
| T1027 | Pickup : carte des autorisés DOC-MAT-03 avec QR, révoquée avec l'autorisation | T1020, T1053 | EX-MAT-09, 27 |
| T1028 | Notifications NOT-MAT-02 à 05 et 11 par `SafetyMessenger` (catégorie `essential`) ; test : parent ayant refusé les SMS non essentiels reçoit quand même le refus | T1002, T1021 | EX-MAT-25 |
| T1029 [P] | Contrat OpenAPI `nursery-pickup.yaml` ; `ApiContract` des routes `/nursery/pickup/*` | T1021 | EX-MAT-10 |
| T1030 | Front : écran de sortie (liste du jour avec photo, remise, « personne non listée », mode « doute », hors ligne) ; test Vitest du refus et de l'autorisé | T1025 | EX-MAT-10, 13 |
| T1031 | Front : autorisés pour parent, secrétaire et directrice ; registre du jour ; test Vitest | T1020, T1026 | EX-MAT-09, 15 |
| T1032 [B T1014] | Test de parité (repli B uniquement) : mêmes cas pour la décision de `Nursery` et celle de `PublicNursery` (table de cas), échec si divergence ; supprimé à la bascule sur NS1 | T1014 | RG-MAT-2 |
| T1033 | `tests/E2E/Nursery/DayAtGateTest.php` : appel, arrivée, remise, refus, veille de non-récupération, hors ligne sans doublon sur 10 jours de lots | T1025, T1026 | SC-MAT-1 à 3 |

## Vague 3 : suivi du développement (prérequis : T1005 ; T1012 ou repli B)
| ID | Tâche | Dépend | Exig. |
|---|---|---|---|
| T1040 [B MQ-08] | Development : routes du référentiel (copie « tenant » d'un référentiel « exemple », versions, `validation_ref` pour `official`) ; aucune écriture sur un référentiel `national` | T1012 ou T1014 | EX-MAT-16 |
| T1041 [B MQ-08] | Development : observation à trois niveaux paramétrés, sans note ni rang, lot hors ligne (`client_uuid`), enfants sans observation du mois | T1040 | EX-MAT-16 |
| T1042 [B MQ-14] | Development : carnet trimestriel (brouillon, soumis, validé, publié, figé), **routes HTTP livrées**, blocage par termes à éviter, réédition Duplicata ou Rectificatif | T1041 | EX-MAT-17 |
| T1043 [B MQ-14] | Documents : DOC-MAT-01 (Dompdf FR et EN, numéro, QR, PDF léger) | T1042, T1053 | EX-MAT-17 |
| T1044 [B MQ-06, MQ-07] | Development : cahier de vie (médias étiquetés, `pending_tagging` → `ready` → `published`, filtre de consentement à chaque lecture, `withheld`, `ConsentChecker` et `ConsentRecorder`) | T1012 ou T1014 | EX-MAT-18 |
| T1045 | Development : `nursery:purge-media` (conservation paramétrée) | T1044 | EX-MAT-18 |
| T1046 [P] | Development : besoins particuliers (visibles éducatrice, direction, parents) | T1005 | EX-MAT-19 |
| T1047 [P] | Portail : allergies par `FoodAllergies` (déclaration du parent, vue de la section et de la cantine) | T1005 | EX-MAT-20 |
| T1048 | Front : observation en un geste (hors ligne), carnet, cahier de vie ; test Vitest du filtre de consentement et du rendu texte sans `v-html` | T1041, T1042, T1044 | EX-MAT-16 à 18 |
| T1049 [P] | `nursery:observation-reminders` (hebdomadaire) | T1041 | EX-MAT-16 |

## Vague 4 : école, frais, services, passage, portail, pilotage (prérequis : T1005 ; plusieurs tâches peuvent démarrer dès la vague 0)
| ID | Tâche | Dépend | Exig. |
|---|---|---|---|
| T1050 [P] [B MQ-10] | Billing : `billing.fee_kinds.nursery` ; tests de facture d'inscription et première tranche par `InvoiceIssuer` avec remise fratrie | T1005 | EX-MAT-28, 30 |
| T1051 [P] [B MQ-22] | Services : `nu_service_plans`, `nu_service_subscriptions`, routes ; abonnement facturé une fois par cycle | T1050 | EX-MAT-29 |
| T1052 [P] | Appel : préréglage `attendance.mode=day_part` à l'ouverture de l'application ; compteurs de présents pour le tableau de bord | T1005 | EX-MAT-23, 24 |
| T1053 [P] | Documents : types et modèles DOC-MAT-02, 03, 04, 05, 06, 08 (FR et EN) ; garde `documents.issuance_guards.nursery` ; aucun document hors catalogue | T1005 | EX-MAT-27 |
| T1054 [B MQ-09] | Transition : `nu_transition_decisions` (proposition, avis, décision, motif), règle de maintien paramétrée, aucune règle officielle codée | T1005 | EX-MAT-21 |
| T1055 [B MQ-24] | Transition : fiche de liaison signée (DOC-MAT-02), lecture par le primaire destinataire, aucune restriction ni allergie copiée | T1054, T1053 | EX-MAT-22 |
| T1056 | Transition : planificateur de clôture conditionnel (`appliesTo` si application `nursery`) ; test : un client `public_nursery` n'active pas ce planificateur | T1054 | EX-MAT-21, 37 |
| T1057 | Clôture d'année : `organisation.closure.checks.nursery` (carnets validés, décisions prises), passage en brouillon, report des soldes | T1056 | EX-MAT-37 |
| T1058 [P] | Portail : `portals.sections.nursery` (autorisés, carnet, cahier de vie, allergies) et « Ma section » ; parent limité à ses enfants | T1020, T1042, T1044 | EX-MAT-26 |
| T1059 [P] | Dashboards : `reporting.indicators.nursery`, `reporting.dashboards.nursery`, `reporting.todo.nursery` ; indicateurs exacts sur fixtures ; agrégats sans donnée nominative pour le promoteur | T1052 | EX-MAT-32 |
| T1060 [P] | Privacy : `privacy.tenant_data_source.nursery`, `privacy.subject_data_source.nursery` (tables `nu_*`, lignes `ey_*` du client, photos) | T1005 | EX-MAT-34 |
| T1061 [B MQ-15] | Tutelle (Could) : `integrations.export_types.nursery` | T1059 | EX-MAT-38 |
| T1062 | Reprise de données : adaptation de l'import existant (enfants, parents, soldes d'ouverture `Billing/Contracts/OpeningBalances`) | T1050 | EX-MAT-35 |
| T1063 | Front : accueil et menu par rôle (filtre par application ouverte, aucun lien vers `public_nursery.*`), décisions de passage, fiche de liaison | T1054, T1055 | EX-MAT-40 |
| T1064 [B MQ-22] | Front : services (plans, abonnements) et solde de la famille | T1051 | EX-MAT-29 |
| T1065 | Front : tableau de bord du directeur et du promoteur (maquettes `MatDashboard.html`) | T1059 | EX-MAT-32 |
| T1066 [P] [B MQ-18] | Voix (Could) : `VoiceGateway` activable par paramètre, repli SMS testé | T1028 | EX-MAT-39 |

## Vague 5 : recette
| ID | Tâche | Dépend |
|---|---|---|
| T1070 | Test de routes du front : aucune entrée de menu d'une application `nursery` ne cible un écran `public-nursery` ; filtre par application ouverte (modèle `permissions-public-nursery.test.ts`) | T1063 |
| T1071 | `tests/ApiContract` étendu à toutes les routes `/nursery/*` (401, 403, 423, isolation, pagination, RFC 9457) | vagues 2 à 4 |
| T1072 | `tests/E2E/Nursery/` : parcours 1 à 8 de spec.md §3 | vagues 2 à 4 |
| T1073 | Playwright `web/e2e/nursery/*` (mocks fidèles ; mobile 390 px ; hors ligne simulé) | T1030, T1031, T1048, T1063 à T1065 |
| T1074 | Revue d'accessibilité et mobile d'abord des écrans (étiquettes, focus, contraste, cibles tactiles) | T1073 |
| T1090 | Export client complet et demande de personne concernée sur `nu_*` et `ey_*` | T1060 |
| T1091 | Charge : SC-MAT-7 (contrôle de sortie < 15 s sur téléphone d'entrée de gamme), école de 100 enfants et 10 jours de lots | T1033 |
| T1092 | Suite complète sur MySQL réel (`phpunit.mysql.xml`, `artisan db:check`) | tout |
| T1093 | Guide de formation de la directrice et de l'éducatrice ; guide de sortie sécurisée (une page) | T1072 |
| T1094 | Recette pilote sur une école cible, **données synthétiques** tant que HTTPS n'est pas actif ; rapport `specs/007-application-maternelle-privee/test-report.md` | T1092, MQ-25 |
| T1095 | Contrôle des critères SC-MAT-1 à SC-MAT-7, consigné | T1094 |
| T1096 [D] | Passage de `nursery` à `available` par migration de données **seulement après accord écrit de l'utilisateur** et HTTPS actif | T1095 |
| T1097 | Passation : `specs/HANDOVER.md` mis à jour, état des demandes NS1 à NS8 | T1096 |

## Synthèse
68 tâches numérotées dans T1000 à T1099 (T1015 à T1019, T1034 à T1039, T1067 à T1069, T1075 à T1089 et T1098, T1099 réservés). Bloquées par une réponse : T1008, T1032, T1040, T1041, T1042, T1043, T1044, T1050, T1051, T1054, T1055, T1061, T1064, T1066 ; elles démarrent avec les défauts de [questions.md](questions.md). Décisions de l'utilisateur : T1000, T1010, T1014, T1096.
