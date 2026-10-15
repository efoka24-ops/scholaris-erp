# Tâches T600-T699 : modules communs publics et Primaire publique

Date : 2026-10-15. Entrées : [spec.md](spec.md), [plan.md](plan.md), [data-model.md](data-model.md), [socle-reuse.md](socle-reuse.md), [questions.md](questions.md). Format : `[P]` parallélisable ; **F** fichiers ; **D** dépendances ; **T** tests. Chemins abrégés : `PS/` = `src/Modules/Apps/PublicSchools/`, `PP/` = `src/Modules/Apps/PublicPrimary/`, `So/` = `src/Modules/Socle/`. Toute tâche : php-cs-fixer sur ses fichiers, phpstan 0 erreur, phpunit vert, contrat `specs/001-socle-plateforme/contracts/openapi.d/public-<module>.yaml` pour toute route.

## Phase 0 : socle (agents propriétaires de module)
- [ ] T600 Décision d'architecture actée (Q4) ; consigner dans `specs/004-.../primaire/plan.md` §2. **D** : réponse Q4.
- [ ] T601 [P] PP1 : migration `So/Organisation/Database/Migrations/<date>_organisation_establishment_territories.sql`, `TerritoryDirectory::territoryOf/establishmentsIn`, `PdoTerritoryDirectory`, route `PUT /territories/{id}/establishments`, permission `organisation.territories.manage` dans `TerritoryPolicy`. **T** : `tests/Feature/Organisation/EstablishmentTerritoryTest.php` (rattachement daté ; changement d'arrondissement en 2027 : `territoryOf(..., '2026-06-30')` rend l'ancien, RG-TER-4 ; autre client → 404).
- [ ] T602 [P] PP16 : `territory_settings`, héritage client > région > département > établissement, `lock_level`. **F** : `So/Organisation/Application/Settings*`. **T** : un paramètre `national` refusé en écriture par une région (403) ; calendrier ajusté par la région hérité par ses écoles.
- [ ] T603 [P] PP8 : `people.learner_number` + `LearnerNumbering` + `people.learner_number.providers.<app>` + `people.duplicate_criteria.<app>`. **T** : identifiant attribué une fois, seconde attribution refusée ; sans fournisseur, `DuplicateMatcherTest` inchangé.
- [ ] T604 [P] PP9 : `People/Contracts/LearnerWriter` + implémentation. **T** : création + garants + inscription ; `closeEnrollment(transferred)` ; une seconde inscription active dans un autre établissement refusée (RG-ELV-4).
- [ ] T605 [P] PP17 : mode `day_part` de l'appel (reprend PS3 de la spec 003). **T** : `POST /attendance-sessions` sans `slot_id` en mode `day_part` ; mode `slot` inchangé.
- [ ] T606 PP2 : affectation `territory` sans établissement, `AccessibleEstablishments`, `PermissionScopes`, rôles `aggregate_only`, retrait de `ContainerInterface`. **D** : T601. **T** : `tests/Feature/Identity/TerritoryAssignmentTest.php` (inspecteur de l'arrondissement A voit ses 3 écoles, pas celle de B ; délégué régional refusé sur `*.named.*` = TST-05).
- [ ] T607 PP3 : `Identity/Contracts/NamedAccessJournal` (déplacement de `ScopedAccessAudit`), route `GET /audit/named-access`. **D** : T606. **T** : consultation nominative par un inspecteur visible du directeur ; finalité obligatoire.
- [ ] T608 PP7 : code personnel sur appareil partagé, verrouillage à 5 échecs, révocation. **D** : T609. **T** : 5 codes erronés → appareil verrouillé, ordre d'effacement rendu.
- [ ] T609 PP6 : module `So/Sync` (`sync_devices`, `sync_batches`, `sync_items`, `POST /sync/batches`, `BatchItemHandler`, `sync:process`). **T** : lot gzip signé appliqué ; lot rejoué = `duplicate` ; signature fausse = 401 ; appareil révoqué = 401 ; conflit = 409 par élément sans écrasement.
- [ ] T610 PP18 : données : catalogue `public_schools`, `public_primary`, `public_nursery` ; `database/migrations/<date>_roles_public_schools.sql` (R01-R21 et permissions de plan.md §5) ; `RolePermissionsTest` étendu ; `web/src/lib/permissions.ts`. **D** : T600.
- [ ] T611 [P] PP19 : `Staff/Contracts/StaffMover`. **T** : mutation A → B : affectation A close, rattachement B, historique.
- [ ] T612 [P] PP5 : `PdoApprovalWorkflow` + tables. **T** : 5 étapes, retour motivé au niveau 2, gel, correction après gel = version 2 motivée (RG-STA-1).
- [ ] T613 [P] PP4 : `reporting_territory_aggregates`, `reporting:aggregate-territories`, `ThresholdDisclosurePolicy`, `GET /public/dashboards/{territory}`. **D** : T601. **T** : agrégat de 3 élèves masqué pour R19 (TST-10) ; 4 masqué, 5 affiché (seuil paramétré à 5).
- [ ] T614 [P] PP10 + PP12 : catégorie `essential`, `messaging_opt_outs`, annonces territoriales. **T** : parent désinscrit reçoit NOT-14 mais pas un message standard ; annonce de l'inspection reçue par les 3 écoles de l'arrondissement seulement.
- [ ] T615 [P] PP15 : `DocumentIssuer::reissue` (« Rectificatif », même numéro), lot multi-établissements, profil PDF léger. **T** : numéro conservé, ancienne version révoquée ; page de vérification sans mention ni adresse (RG-DOC-2).
- [ ] T616 [P] PP11 : plafond SMS par élève et par an. **T** : 13e SMS standard refusé avec plafond 12 ; NOT-14 envoyé.
- [ ] T617 [P] PP13 : `VoiceGateway` désactivé + simulateur. (Could)
- [ ] T618 [P] PP14 : `POST /ussd/session`, menus `messaging.ussd_menus.<app>`, code parent. **T** : sans code, aucune donnée ; avec code, absences du jour sans donnée sensible.
- [ ] T619 Revue de la phase 0 : relancer `tests/Feature/Organisation/TerritoryHistoryTest.php`, `ScopedAccessAuditTest`, `ApprovalWorkflowTest`, `SmallCellDisclosureTest` ; suite complète verte.

## Phase 1 : squelettes
- [ ] T620 `PS/` : `PublicSchoolsApplication`, `definitions.php`, `routes.php` (glob), `Support/{WriteGate,GatedAction,PublicRoutes,Perimeter,ScopedByTerritory}`, `Policies/{RoleMatrix,ScopeResolver}`, manifeste `platform.applications.public_schools`. **D** : T606, T610.
- [ ] T621 `PP/` : `PublicPrimaryApplication`, `definitions.php`, `routes.php` ; suppression de `PP/Registry` après T625. **D** : T620.
- [ ] T622 [P] `PS/Contracts/` : `SchoolRegistry`, `PupilRegistry`, `ClassRoster`, `PupilTransfer`, `CensusSectionProvider`, `PublicMessages` (signatures seules) — **à remettre à l'agent Maternelle**.
- [ ] T623 `tests/Architecture/PublicSchoolsConventionsTest.php` : contrats seuls (exception `PS/Contracts`), préfixes, WriteGate, aucun littéral de seuil dans `Domain/`, aucune colonne `apee` hors `PS/Apee`, aucune variable `apee_*` dans les modèles de documents d'élève (SC-02..04).
- [ ] T624 `tests/Isolation/PublicTerritoryTest.php` (autre client, autre arrondissement, autre classe, région → agrégats seuls). **D** : T620.

## Phase 2 : lot P1 (ECO, ELV, CLA, PRE, PER, COM, Sync, Territory)
- [ ] T625 ECO : `PS/Schools` (tables `pub_schools`, `pub_infrastructure_years`, `pub_classrooms`), reprise de la logique de `InfrastructureSnapshotService` ; suppression de la migration `202610151002_primary_public_registry.sql` et des tests `tests/Feature/PublicSchools/Registry/*` déplacés. **T** : RG-TER-5 (école sans acte exclue des agrégats officiels) ; version close immuable ; correction = version n+1 motivée.
- [ ] T626 [P] EX-ECO-04 photos géolocalisées (`pub_school_photos`). **D** : T625.
- [ ] T627 ELV inscription `POST /public/pupils` (API-02) : `LearnerWriter`, `pub_pupil_profiles`, identifiant (PP8), doublons (Q7). **D** : T603, T604. **T** : TST-01 (aucun champ APEE dans la requête ni le schéma) ; TST-02 (sans acte : accepté, signalé) ; âge 5 ans en SIL au 31/12 → avertissement, pas de refus (RG-ELV-3) ; NOT-01.
- [ ] T628 [P] Situations particulières (EX-ELV-04) et liste DOC-07 trimestrielle. **D** : T627.
- [ ] T629 Transferts `POST /public/transfers` (API-03) : demande, accord, clôture à la date d'accueil, dossier utile, DOC-03. **D** : T604. **T** : RG-ELV-4, RG-ELV-5.
- [ ] T630 [P] Abandon déclaré/présumé + relance (`pub_dropouts`, `public:absence-alerts`). **D** : T605.
- [ ] T631 [P] Inscription groupée R20 depuis registre papier (EX-ELV-07) : photo jointe, saisie assistée, lot. **D** : T609, T627.
- [ ] T632 CLA : `pub_class_profiles`, jumelées, double flux, titulaire daté, effectifs sexe/âge, alerte (seuil 60). **T** : 61 élèves → alerte.
- [ ] T633 [P] Répartition proposée (EX-CLA-04, Could).
- [ ] T634 PRE appel `PUT /public/classes/{id}/attendance/{date}` (API-05) sur le socle + PP17 ; tous présents par défaut ; RG-PRE-1. **D** : T605. **T** : correction par l'enseignant le jour J, refus J+1 sauf directeur avec motif.
- [ ] T635 Gestionnaire de lots `sync.handlers.public.attendance`. **D** : T609, T634. **T** : TST-04 (10 jours hors ligne puis synchro : 10 × 2 demi-journées, aucun doublon) ; SC-06 (< 50 Ko pour 60 élèves × 1 semaine).
- [ ] T636 Présence des enseignants (`pub_teacher_presence`, `pub_uncovered_classes`), contrôle de l'inspection, NOT-13. **T** : RG-PRE-2.
- [ ] T637 [P] DOC-06 cahier d'appel, DOC-22 synthèse mensuelle. **D** : T636.
- [ ] T638 Alertes NOT-02, NOT-03 (5 jours/mois), NOT-12 (3 jours ouvrés sans appel). **D** : T614, T634.
- [ ] T639 PER : `pub_staff_profiles`, `pub_trainings`, mutations (PP19), besoins (élèves par enseignant, classes sans titulaire) ; RG-PER-2. **D** : T611.
- [ ] T640 COM : SMS par classe/niveau/école, modèles FR/EN (migration de données NOT-01..05, 08..14), annonces territoriales. **D** : T614.
- [ ] T641 [P] Portail famille : sections `portals.sections.public_schools` (absences, messages, reçus APEE).
- [ ] T642 Territory : `GET /public/territories/{id}/schools` (API-01) ; consultation nominative avec finalité journalisée. **D** : T606, T607.
- [ ] T643 [P] Documents communs DOC-01, 02, 04, 05 (modèles FR/EN, en-tête République/ministère/délégation/école). **D** : T615.
- [ ] T644 Front P1 : `web/src/apps/public-schools/` (registre déplacé, inscription, appel mobile, présence enseignants, transferts). Playwright `web/e2e/public/p1.spec.ts`.
- [ ] T645 [P] PWA : base IndexedDB chiffrée par code personnel, effacement après 5 échecs. **D** : T608.
- [ ] T646 E2E lot P1 `tests/E2E/PublicSchools/P1Test.php` (TST-01, 02, 04, 05).

## Phase 3 : lot P2 (EVA, PAS, APE, STA)
- [ ] T650 APE : `pub_apee_years`, `pub_apee_entries`, `pub_apee_exemptions` ; encaissement espèces et mobile money (`PaymentRequester`), reçu DOC-24 + NOT-10 ; dépenses avec pièce. **T** : RG-APE-1 (non-contributeur : bulletin et documents émis) ; RG-APE-2 (liste des non-contributeurs : 403 pour directeur et enseignant) ; RG-APE-3 (dépense sans pièce = `pending`) ; RG-APE-4.
- [ ] T651 État financier DOC-25. **D** : T650. **T chiffré** : contribution 5 000, 420 élèves → attendu 2 100 000 ; 344 familles encaissées → 1 720 000 ; taux 81,9 % (arrondi au dixième) ; dépenses 2 × 25 000 × 9 = 450 000 + 300 000 + 250 000 = 1 000 000 ; solde 720 000 ; 76 familles non contributrices (TST-09).
- [ ] T652 STA : questionnaire pré-rempli (DOC-23), contrôles (âge = niveau, élèves par salle, extrêmes), circuit 5 niveaux (PP5), NOT-11, saisie par R20 (RG-STA-2), gel régional. **D** : T612. **T** : TST-08 ; export au format Q3 (gabarit par défaut sinon).
- [ ] T653 [P] Données de fin d'année (EX-STA-06) : promus, redoublants, abandons, résultats. **D** : T665.
- [ ] T654 [P] Fournisseurs d'agrégats `reporting.territory_aggregates.public_schools` (10 indicateurs du CDC). **D** : T613.
- [ ] T655 [P] Paquet minimum et dons (DOC-27), exonérations (EX-APE-05, 06).
- [ ] T660 EVA référentiel : `pp_frames`, `pp_criteria`, import d'un référentiel d'exemple `example_*` (Q8), versions. 
- [ ] T661 Calendrier des unités (`pp_learning_units`, 8 par défaut, semaine d'évaluation) rattaché aux trimestres. **D** : T660.
- [ ] T662 `PP/Evaluation/Domain/MentionCalculator` (paramètres fournis). **T chiffré** (TST-03, Paul E., CE1 B, unité 3, occasions 3, maîtrise ≥ 2) : Français C1 [1,1,0]=2 oui, C2 [1,0,1]=2 oui, C3 [0,1,0]=1 non → **ECA** ; Mathématiques [1,1,1], [1,1,0], [1,0,1] → 3/3 → **A** (critère de perfectionnement examiné) ; English [0,0,1], [1,0,0], [0,1,0] → 0/3 → **NA** ; absent à toutes les occasions → `not_assessed` (RG-EVA-4).
- [ ] T663 Saisie `PUT /public-primary/units/{id}/criterion-checks` (API-06) + gestionnaire de lots ; remédiation (DOC-11) ; message au parent si NA. **D** : T609, T662. **T** : listes « Français C3 » et « English C1, C2, C3 » pour Paul.
- [ ] T664 Bulletins DOC-08, DOC-09, DOC-10, appréciation et banque de formulations, publication par le directeur (API-07), figé, rectificatif (PP15), NOT-04. **D** : T615, T663. **T** : RG-EVA-1 (aucun rang), RG-EVA-2, RG-EVA-3, RG-DOC-3, SC-08 (< 200 Ko).
- [ ] T665 PAS : politique nationale versionnée verrouillée (PP16), propositions, conseil (API-08), PV DOC-12, avis de l'inspecteur au 2e redoublement (`Approvals`), recours (Q13), statistiques. **T** : NA en Français seul → « passe avec remédiation » ; NA en 2 des 3 disciplines clés → « examen par le conseil » ; RG-PAS-1 (école → 403), RG-PAS-2.
- [ ] T666 [P] Option notes chiffrées (EX-EVA-08) : lien vers `/assessments` du socle pour les épreuves blanches.
- [ ] T667 Front P2 (grille critère × élève mobile, bulletins, conseil, APEE, questionnaire). Playwright.
- [ ] T668 E2E lot P2 (TST-03, 08, 09).

## Phase 4 : lot P3 (EXA, INS, CAN, tableaux, USSD)
- [ ] T680 EXA listes CEP/FSLC (classe d'examen paramétrée), 6e, Common Entrance (DOC-13, 15), contrôle d'état civil, NOT-08 en janvier. **T** : TST-07 (candidat sans acte vérifié : validation de l'inspection bloquée pour lui seul).
- [ ] T681 Circuit directeur → inspection → délégation (API-09), gel, ajout tracé (RG-EXA-2), export au format Q10. **D** : T612.
- [ ] T682 [P] Fiche candidat DOC-14 (photo, identifiant).
- [ ] T683 Import des résultats (API-10, modèle `Apps/Higher/NationalExams`), taux de réussite, DOC-17, NOT-09. **T** : taux = admis ÷ candidats présents.
- [ ] T684 [P] Attestations DOC-16 à signer par R06 ; RG-EXA-3 (jamais de diplôme).
- [ ] T685 [P] Transfert vers le Secondaire (EX-EXA-07, Q18).
- [ ] T686 INS : planning, grilles paramétrables, visites hors ligne (lots), rapport DOC-26, recommandations suivies, comptage sur place (O1), signalement de frais illégal.
- [ ] T687 [P] CAN : repas, stocks, campagnes de santé avec consentements.
- [ ] T688 [P] Menus USSD du public (absences du jour, mention du dernier bulletin, convocations). **D** : T618.
- [ ] T689 [P] Messages vocaux NOT-05, NOT-14 (Could). **D** : T617.
- [ ] T690 Tableau de bord territorial DOC-28 et écrans R01-R07, R19. **D** : T654. **T** : SC-07 (< 3 s, 500 écoles simulées).

## Phase 5 : recette
- [ ] T691 Suite d'acceptation `tests/E2E/PublicSchools/AcceptanceTest.php` : TST-01..TST-10.
- [ ] T692 `tests/ApiContract` pour toutes les routes `/public*` (401, 403, isolation, RFC 9457).
- [ ] T693 Charge : `scripts/loadtest/k6-api.js` étendu (`POST /sync/batches`, `GET /public/dashboards/{territory}`) ; cible pilote Q20.
- [ ] T694 Fixtures pilote `SYN-*` (Q1) : 1 région, 1 département, 1 arrondissement, 50 écoles.
- [ ] T695 Exécution sur MySQL réel (`phpunit.mysql.xml`, `artisan db:check`).
- [ ] T696 Revue de sécurité des données de mineurs (minimisation, consentements, journal, masquage, appareils) dans `specs/001-socle-plateforme/security-review.md`.
- [ ] T697 Paramètres par défaut « à confirmer » semés et listés dans un écran d'administration nationale.
- [ ] T698 Guide de formation d'une demi-journée (NF-P5) — livrable de l'analyste, hors code.
- [ ] T699 Bilan de couverture : mettre à jour [couverture.md](couverture.md).
