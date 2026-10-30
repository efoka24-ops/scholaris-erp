# Tâches : applications Primaire et Maternelle publiques

**Entrées** : `spec.md`, `plan.md`, `research.md`, `data-model.md`, `socle-reuse.md`, `quickstart.md`, `contracts/openapi-public-schools.yaml`, constitution.

**Statut** : l'utilisateur a autorisé une implémentation locale limitée aux décisions provisoires documentées dans `research.md` R8 et `plan.md` § Périmètre autonome. `[B]` signifie qu'une approbation externe reste nécessaire pour le comportement officiel ou l'exploitation réelle ; elle n'est pas réputée obtenue par cette exécution. `[P]` = tâche indépendante sur des fichiers/dossiers distincts. Aucun accès FTP/MySQL production, push, secret ou donnée réelle n'est autorisé.

**Format strict** : `- [ ] Txxx [P?] [USn] Description — chemin exact`. Tests inclus car la constitution impose du test-first pour règles métier et isolation.

## Phase 1 : cadrage documentaire et environnement

**But** : résoudre l'identité du produit et préparer un référentiel de conception non ambigu.

- [ ] T001 [B] Confirmer le dépôt et la branche PHP avant tout commit/push ; pendant l'exécution garder tous les changements locaux non publiés et consigner ce report dans `specs/004-applications-primaire-maternelle-publiques/research.md`.
	- **Non résolue** : aucune confirmation écrite du remote/branche PHP officiel ; aucun commit ni push effectué.
- [X] T002 Choisir provisoirement des applications `PublicPrimary`/`PublicNursery` distinctes de 003 et consigner que ce choix ne modifie pas les modules privés dans `specs/004-applications-primaire-maternelle-publiques/plan.md`.
- [X] T003 Définir le périmètre de tests local comme fixtures synthétiques uniquement, en laissant commanditaire, tenant pilote et délégation réels à confirmer dans `specs/004-applications-primaire-maternelle-publiques/research.md`.
- [ ] T004 [B] Recueillir textes, questionnaires, référentiels, bulletins, calendrier, passage et formats d'examen MINEDUB approuvés dans `specs/004-applications-primaire-maternelle-publiques/research.md`.
- [ ] T005 [B] Faire valider conservation, consentement, identifiants de mineurs, données sensibles et hébergement avant données réelles dans `specs/004-applications-primaire-maternelle-publiques/plan.md`.
- [ ] T006 [B] Confirmer cible HTTPS/runtime maintenu et renouveler les secrets des captures avant tout accès opérationnel, décision dans `specs/004-applications-primaire-maternelle-publiques/quickstart.md`.
- [ ] T007 [P] Compléter l'inventaire des contrats réels du socle et vérifier chaque exemple de route contre les routeurs dans `specs/004-applications-primaire-maternelle-publiques/socle-reuse.md`.

## Phase 2 : fondations communes (pré-requis du MVP local synthétique)

**But** : contrats de sécurité, territoire, audit, workflow et synchronisation partagés ; ne pas créer de tables avant décision de modèle.

- [X] T008 Écrire d'abord les tests de périmètre tenant/établissement/territoire avec fixtures synthétiques et refus par défaut dans `tests/Isolation/PublicSchoolsScopeTest.php`.
- [X] T009 Ajouter le contrat `TerritoryDirectory` et la hiérarchie versionnée sans codes réels dans `src/Modules/Socle/Organisation/Contracts/TerritoryDirectory.php`.
- [X] T010 Étendre l'affectation et la résolution des scopes territoriaux, validés dans le tenant et toujours bornés à l'établissement, dans `src/Modules/Socle/Identity/Domain/ScopeType.php`, `src/Modules/Socle/Identity/Application/RoleAssignmentService.php` et `src/Modules/Socle/Identity/Infrastructure/RoleBasedPermissionScopes.php`.
	- Validation API d'une affectation sur un territoire actif du même tenant ; la résolution ignore les territoires absents/inactifs et émet `Scope::TERRITORY` avec tenant et établissement. Tests Identity/isolation synthétiques verts.
- [X] T011 Spécifier et tester la lignée historique des territoires et snapshots clôturés synthétiques dans `tests/Feature/Organisation/TerritoryHistoryTest.php`.
- [X] T012 Définir une politique paramétrable de suppression primaire/secondaire ; utiliser 5 uniquement comme valeur de fixture, jamais comme seuil officiel activé, dans `src/Modules/Socle/Reporting/Contracts/DisclosurePolicy.php`.
- [X] T013 Ajouter audit d'accès nominatif ponctuel en réutilisant le module Audit existant dans `src/Modules/Socle/Identity/Application/ScopedAccessAudit.php`.
- [X] T014 Définir un contrat générique de workflow en cascade et snapshots gelés dans `src/Modules/Socle/Reporting/Contracts/ApprovalWorkflow.php`.
- [X] T015 Ajouter au test de synchronisation Attendance la vérification du rejeu identique et du conflit payload différent dans `tests/Feature/Attendance/OfflineSyncTest.php`.
- [X] T016 Documenter les limites réellement observées et maintenir explicitement l'objectif 30 jours comme non validé dans `specs/004-applications-primaire-maternelle-publiques/quickstart.md`.
- [X] T017 Compléter le contrat candidat avec erreurs RFC 9457, whitelist et pagination uniquement si une route réellement implémentée la prend en charge dans `specs/004-applications-primaire-maternelle-publiques/contracts/openapi-public-schools.yaml`.
	- Les collections locales observées sont complètes et non paginées ; aucun paramètre/schéma de pagination n'est donc déclaré.
- [ ] T018 Ajouter les tests d'architecture Apps → `Contracts/` et migrations module-propriétaire dans `tests/Architecture/PublicSchoolsConventionsTest.php`.
	- **Partiel / restant** : aucun `PublicSchoolsConventionsTest.php` n'existe ; les tests d'architecture génériques ne remplacent pas ce contrôle ciblé.

## Phase 3 : US1 — Registre public et territoires (P1)

**But** : rattacher les écoles au territoire officiel, tenir l'identité/infrastructure et préserver les données de périodes clôturées.

**Test indépendant** : un gestionnaire central affecte une école synthétique à l'arrondissement autorisé ; modification ultérieure du découpage ne change pas le snapshot/clôture historique ; accès cross-tenant refusé.

- [X] T019 [US1] Ajouter tests API/feature de hiérarchie avec fixtures synthétiques et contrôle de rattachement dans `tests/Feature/PublicSchools/Registry/TerritoryRegistryTest.php`.
- [X] T020 [US1] Ajouter tests de snapshot infrastructure annuel, correction versionnée et clôture immutable dans `tests/Feature/PublicSchools/Registry/InfrastructureSnapshotTest.php`.
- [X] T021 [US1] Créer la migration SQL portable propriétaire pour territoires de démonstration sans seeds officiels dans `src/Modules/Socle/Organisation/Database/Migrations/202610151001_organisation_public_territories.sql`.
- [X] T022 [US1] Implémenter l'adaptateur `TerritoryDirectory` sans lecture de tables d'autres modules dans `src/Modules/Socle/Organisation/Infrastructure/PdoTerritoryDirectory.php`.
- [X] T023 [US1] Ajouter routes et policies de consultation/affectation des territoires de test dans `src/Modules/Socle/Organisation/routes.php`.
- [X] T024 [US1] Créer le profil d'école public et la migration/snapshot infrastructure dans `src/Modules/Apps/PublicPrimary/Registry/Database/Migrations/202610151002_primary_public_registry.sql`.
- [X] T025 [US1] Implémenter saisie, validation bilingue et versionnement annuel des infrastructures dans `src/Modules/Apps/PublicPrimary/Registry/Application/InfrastructureSnapshotService.php`.
- [X] T026 [US1] Ajouter contrôleurs, Request/Resource whitelistées et API du registre dans `src/Modules/Apps/PublicPrimary/Registry/routes.php`.
- [ ] T027 [P] [US1] Ajouter les vues de consultation école/carte et état d'infrastructure dans `web/src/apps/public-primary/registry/`.
	- **Partiel** : le profil, la hiérarchie et l'état annuel sont consultables ; l'interface précise qu'aucun contour géographique/carte n'est disponible.
- [X] T028 [US1] Mettre à jour uniquement la surface candidate OpenAPI après validation route par route dans `specs/004-applications-primaire-maternelle-publiques/contracts/openapi-public-schools.yaml`.

## Phase 4 : US2 — Élèves, classes, personnel et présence (P1)

**But** : gérer le parcours scolaire inclusif, les affectations et les présences synchronisées sans perte ni doublon.

**Test indépendant** : inscription synthétique sans acte et sans paiement APEE acceptée avec suivi de régularisation ; transfert historisé ; appel offline rejoué sans doublon et conflit non écrasé.

- [ ] T029 [P] [US2] Écrire tests d'admission inclusive, absence d'acte et absence de toute condition liée à paiement dans `tests/Feature/PublicSchools/Learners/PublicEnrolmentTest.php`.
- [ ] T030 [P] [US2] Écrire tests de classe jumelée/double flux et historisation des enseignants dans `tests/Feature/PublicSchools/Classes/ClassAssignmentHistoryTest.php`.
- [ ] T031 [P] [US2] Écrire tests de synchronisation présence élèves/enseignants, rejeu et conflit durable dans `tests/Feature/PublicSchools/Attendance/AttendanceSyncTest.php`.
- [X] T032 [US2] Pour ce pilote de code, réutiliser l'identifiant Person du socle et différer tout identifiant national/déduplication inter-écoles jusqu'à approbation officielle dans `specs/004-applications-primaire-maternelle-publiques/data-model.md`.
- [ ] T033 [US2] Étendre les contrats People pour inscription publique sans acte bloquant et transfert daté dans `src/Modules/Socle/People/Contracts/EnrollmentDirectory.php`.
- [ ] T034 [US2] Étendre les contrats Organisation/Staff pour classes jumelées, flux et affectation historisée dans `src/Modules/Socle/Staff/Contracts/AssignmentWriter.php`.
- [ ] T035 [US2] Ajouter validation de présence enseignant tout en réutilisant le service Attendance dans `src/Modules/Socle/Attendance/Contracts/TeacherAttendanceRecorder.php`.
- [ ] T036 [US2] Implémenter écrans d'inscription, dossiers à régulariser, transferts et classes dans `web/src/apps/public-primary/learners/`.
- [ ] T037 [US2] Implémenter capture hors ligne d'appel et file de synchronisation PWA via le mécanisme existant dans `web/src/offline/public-primary/attendance/`.
- [ ] T038 [US2] Mesurer limites de lot et capacité 10 jours puis 30 jours avec données synthétiques dans `tests/Load/PublicSchools/AttendanceSyncCapacityTest.php`.

## Phase 5 : US3 — Protection maternelle et accès famille (P1)

**But** : valider autorisations/consentements et assurer la remise sécurisée même sans réseau.

**Test indépendant** : remise à une personne autorisée et valide enregistrée ; personne inconnue/refusée n'emporte pas l'enfant, journal/alerte créés ; les droits famille sont révocables.

- [ ] T039 [P] [US3] Écrire tests d'autorisation valide, expirée, révoquée et refus par défaut dans `tests/Feature/PublicSchools/Nursery/AuthorizedPickupTest.php`.
- [ ] T040 [P] [US3] Écrire tests d'événements de remise offline, rejoués et auditables dans `tests/Feature/PublicSchools/Nursery/PickupHandoverTest.php`.
- [ ] T041 [B] [US3] Valider procédure de doute, urgence, autorité parentale, minimisation photo/santé et usages offline dans `specs/004-applications-primaire-maternelle-publiques/data-model.md`.
- [ ] T042 [US3] Étendre le contrat de personnes autorisées et révocation dans `src/Modules/Socle/People/Contracts/AuthorizedPickupDirectory.php`.
- [ ] T043 [US3] Ajouter le service append-only de remise, idempotence et alerte dans `src/Modules/Socle/Attendance/Application/PickupHandoverService.php`.
- [ ] T044 [US3] Ajouter interface maternelle de contrôle hors ligne avec cache minimisé et expiration dans `web/src/apps/public-nursery/pickup/`.
- [ ] T045 [US3] Ajouter portail/fonctions familiales de consentement et personnes autorisées sans compte enfant dans `web/src/apps/public-nursery/family/`.

## Phase 6 : US4 — Référentiel, compétences et bulletins (P1 — source officielle bloquante)

**But** : gérer observations Primary/Nursery, référentiels versionnés, validations et bulletins immuables sans inventer de règles nationales.

**Test indépendant** : référentiel `example` reste signalé non officiel ; aucune publication officielle sans approbation/source ; rectificatif conserve l'original ; bulletin Nursery sans note ni rang.

- [ ] T046 [B] [US4] Obtenir et faire approuver référentiels, modèles, calendrier, échelles, calcul, passage et règles de publication MINEDUB dans `specs/004-applications-primaire-maternelle-publiques/research.md`.
- [ ] T047 [P] [US4] Écrire tests de versionnement, calcul parametrable, états et interdiction d'émettre depuis référentiel `example` dans `tests/Feature/PublicSchools/Assessment/OfficialFrameworkGateTest.php`.
- [ ] T048 [P] [US4] Écrire tests de bulletin snapshot, publication, rectificatif et contraintes maternelle sans note/rang dans `tests/Feature/PublicSchools/Assessment/ReportCardSnapshotTest.php`.
- [ ] T049 [B] [US4] Ajouter stratégie déclarative et contrat de catalogue versionné dans `src/Modules/Socle/Assessment/Contracts/PublicFrameworkCatalog.php`.
- [ ] T050 [US4] Créer services distincts d'observation compétences Primaire et développement Maternelle dans `src/Modules/Apps/PublicPrimary/Assessment/Application/CompetencyObservationService.php` et `src/Modules/Apps/PublicNursery/Development/Application/DevelopmentObservationService.php`.
- [ ] T051 [US4] Implémenter les saisies hors ligne et remédiation à partir des règles approuvées dans `web/src/apps/public-primary/assessment/` et `web/src/apps/public-nursery/development/`.
- [ ] T052 [B] [US4] Implémenter génération, signature autorisée, snapshot et rectificatif par service Documents seulement après validation des gabarits dans `src/Modules/Apps/PublicPrimary/Assessment/Application/ReportCardService.php` et `src/Modules/Apps/PublicNursery/Development/Application/DevelopmentReportService.php`.
- [ ] T053 [B] [US4] Ajouter tests de non-régression de règles nationales et exemples arithmétiques fournis par les autorités dans `tests/Feature/PublicSchools/Assessment/OfficialRuleRegressionTest.php`.

## Phase 7 : US5 — APEE et dons (P2)

**But** : transparence et traçabilité financière associative sans frais scolaires ni accès enseignant aux listes nominatives.

**Test indépendant** : reçu, don et dépense avec justificatif traçables ; dépense sans pièce en attente ; aucun service scolaire affecté au paiement ; enseignants ne peuvent lire les données nominatives.

- [ ] T054 [P] [US5] Écrire tests de séparation APEE/BillingFees et absence de conditionnement inscription/présence/bulletin dans `tests/Feature/PublicSchools/Apee/ApeeNonConditioningTest.php`.
- [ ] T055 [P] [US5] Écrire tests de justificatif, approbation et confidentialité des payeurs dans `tests/Feature/PublicSchools/Apee/ApeeLedgerTest.php`.
- [ ] T056 [B] [US5] Faire approuver rôles APEE, pièces, exemptions, publication et traitement comptable dans `specs/004-applications-primaire-maternelle-publiques/data-model.md`.
- [ ] T057 [US5] Créer ledger associatif isolé des factures scolaires et sa migration propriétaire dans `src/Modules/Apps/PublicPrimary/Apee/Database/Migrations/202610151003_primary_apee_ledger.sql`.
- [ ] T058 [US5] Implémenter reçus, dons, dépenses, justificatifs et approbations avec idempotence dans `src/Modules/Apps/PublicPrimary/Apee/Application/ApeeLedgerService.php`.
- [ ] T059 [US5] Ajouter policies financières explicites, refus enseignant et agrégats publiables dans `src/Modules/Apps/PublicPrimary/Apee/Policies/ApeePolicy.php`.
- [ ] T060 [US5] Ajouter interfaces trésorerie, reçu et rapport AG uniquement aux rôles validés dans `web/src/apps/public-primary/apee/`.

## Phase 8 : US6 — Questionnaire annuel et supervision (P2)

**But** : collecte assistée offline, retours motivés, validation hiérarchique et agrégats privés d'identification.

**Test indépendant** : formulaire incomplet rejeté avec erreurs ; progression ordonnée ; gel régional immuable ; petit groupe et résultats différentiables supprimés.

- [ ] T061 [P] [US6] Écrire tests de workflow de soumission, retour motivé, validations ordonnées et gel/version dans `tests/Feature/PublicSchools/Reporting/CensusWorkflowTest.php`.
- [ ] T062 [P] [US6] Écrire tests contre divulgation par petits groupes et soustraction de requêtes croisées dans `tests/Feature/Socle/Reporting/SmallCellDisclosureTest.php`.
- [ ] T063 [B] [US6] Obtenir les questionnaires, dictionnaires, rôles valideurs et seuils anti-réidentification agréés dans `specs/004-applications-primaire-maternelle-publiques/research.md`.
- [ ] T064 [US6] Implémenter questionnaire versionné et snapshot annuel propriétaire Reporting dans `src/Modules/Socle/Reporting/Application/TerritorialCensusService.php`.
- [ ] T065 [US6] Implémenter validation en cascade et saisie d'inspection déléguée dans `src/Modules/Socle/Reporting/Application/ApprovalWorkflowService.php`.
- [ ] T066 [US6] Construire formulaires offline, contrôles et motif de renvoi dans `web/src/apps/public-primary/supervision/census/`.
- [ ] T067 [US6] Exposer tableaux de bord avec suppression secondaire et filtrage de rôle dans `src/Modules/Socle/Reporting/Http/PublicStatisticsController.php`.

## Phase 9 : US7 — Examens et décisions de passage (P2/P3, sources officielles bloquantes)

**But** : préparer les listes candidats, tracer les validations et importer les résultats reçus, sans délivrer de diplôme officiel.

**Test indépendant** : listes figées après approbation ; ajout tardif motivé ; import résultat audité ; toute route de diplôme officiel absente/refusée.

- [ ] T068 [B] [US7] Obtenir règles, formats et responsabilités officielles des examens et décisions de passage dans `specs/004-applications-primaire-maternelle-publiques/research.md`.
- [ ] T069 [P] [US7] Écrire tests d'éligibilité configurable, gel, ajout tardif audité et refus de diplôme officiel dans `tests/Feature/PublicSchools/Exams/ExamCandidateListTest.php`.
- [ ] T070 [P] [US7] Écrire tests de décision de passage versionnée et approbations requises dans `tests/Feature/PublicSchools/Promotion/PromotionDecisionTest.php`.
- [ ] T071 [B] [US7] Implémenter moteur de règles uniquement après validation, versionné par année et sans seuil par défaut inventé dans `src/Modules/Apps/PublicPrimary/Assessment/Application/PromotionDecisionService.php`.
- [ ] T072 [US7] Implémenter préparation/soumission/gel des listes et import traçable de résultats dans `src/Modules/Apps/PublicPrimary/Exams/Application/ExamCandidateService.php`.
- [ ] T073 [US7] Implémenter les écrans de contrôle des listes et décisions dans `web/src/apps/public-primary/exams/`.
- [ ] T074 [US7] Ajouter garde architecture/API interdisant toute émission du CEP/FSLC dans `tests/Architecture/PublicSchoolsNoOfficialDiplomaTest.php`.

## Phase 10 : US8 — Inspection, documents et pilotage (P2/P3)

**But** : organiser visites et recommandations, délivrer des documents autorisés et rendre disponibles des indicateurs sécurisés.

**Test indépendant** : inspection hors ligne retrouvée et suivie ; seuls documents explicitement autorisés sont vérifiables ; indicateurs limités au périmètre et agrégés.

- [ ] T075 [P] [US8] Écrire tests de visite, checklist versionnée, recommandation et suivi hors ligne dans `tests/Feature/PublicSchools/Inspection/InspectionVisitTest.php`.
- [ ] T076 [P] [US8] Écrire tests d'émission/révocation et vérification publique avec données minimales dans `tests/Feature/PublicSchools/Documents/PublicDocumentTest.php`.
- [ ] T077 [US8] Implémenter visite et recommandations via les contrats Documents/Reporting/Organisation sans accès direct aux tables externes dans `src/Modules/Apps/PublicPrimary/Inspection/Application/InspectionVisitService.php`.
- [ ] T078 [US8] Ajouter les formulaires de visite, synchronisation et suivi de recommandations dans `web/src/apps/public-primary/inspection/`.
- [ ] T079 [B] [US8] Créer les types et gabarits publics seulement après signature des modèles par l'autorité compétente dans `src/Modules/Apps/PublicPrimary/Documents/`.
- [ ] T080 [US8] Ajouter tableaux de bord et exports d'indicateurs agrégés avec scopes territoriaux dans `web/src/apps/public-primary/dashboards/`.

## Phase 11 : qualité, sécurité et portes de pilote

**But** : validation transversale avant pilote synthétique, puis gate distinct pour toute donnée réelle.

- [ ] T081 [P] Auditer toutes les routes, policies, Resource whitelistées, RFC 9457 et OpenAPI dans `tests/ApiContract/PublicSchoolsOpenApiContractTest.php`.
- [ ] T082 [P] Vérifier isolation tenant/établissement/territoire sur les parcours US1-US8 dans `tests/Isolation/PublicSchoolsScopeTest.php`.
- [ ] T083 [P] Vérifier consentement, minimisation, rétention, cache offline et effacement conformément à la décision légale dans `tests/Feature/PublicSchools/Privacy/MinorDataLifecycleTest.php`.
- [ ] T084 [P] Exécuter recette synthétique MySQL et SQLite dans l'environnement de test désigné, avec instructions consignées dans `specs/004-applications-primaire-maternelle-publiques/quickstart.md`.
- [ ] T085 [B] Mesurer capacité pilote/nationale sur infrastructure explicitement approuvée, sans extrapoler les limites Camoo dans `specs/004-applications-primaire-maternelle-publiques/plan.md`.
- [ ] T086 [B] Autoriser séparément le pilote avec données réelles après HTTPS, runtime maintenu, secrets renouvelés, conformité, restauration et accord écrit dans `specs/004-applications-primaire-maternelle-publiques/quickstart.md`.
- [ ] T087 [B] Confirmer remote/branche et procédure de livraison, puis seulement documenter le paquet FTP filtré et les secrets hors dépôt dans `specs/004-applications-primaire-maternelle-publiques/quickstart.md`.

## Dépendances et ordre d'exécution

- Le périmètre provisoire R8 autorise uniquement des changements locaux testés avec fixtures synthétiques. T001 bloque tout commit/push ; T004 bloque toute règle officielle US4/US7 ; T005/T006 bloquent toute donnée réelle, recette de production ou déploiement, mais n'interdisent pas les tests synthétiques locaux. Le choix séparé T002 et la portée de fixtures T003 sont consignés comme décisions d'ingénierie provisoires.
- Phase 2 bloque toutes les stories d'implémentation. Au sein de celle-ci, scopes tenant/territoire (T008–T011) précèdent les services métiers ; workflow/audit (T012–T014) et sync (T015–T016) sont aussi des prérequis pour les stories qui les consomment.
- US1 et US2 sont les incréments MVP fonctionnels, mais US1 dépend des décisions de territoire et US2 des règles d'identité/transfert. US3 dépend des contrats People/Attendance et de la revue sécurité offline. US4 dépend de la réception des textes officiels. US5 nécessite arbitrage APEE et séparation de Billing. US6 dépend des questionnaires officiels et du filtre anti-divulgation. US7 dépend des règles/formats d'examens. US8 réutilise Reporting/Documents et scope territorial.
- Les incréments peuvent se paralléliser après fondations : US1 avec US2 (propriétaires de dossiers différents), US3 et US4 ensuite ; US5, US6, US7, US8 en sous-vagues après API/contrats communs. Aucun agent ne partage un même fichier `routes.php`, `definitions.php`, migration/config ou dossier exclusif au même tour ; maximum 3 agents simultanés.
- La Phase 11 dépend de toutes les stories requises pour pilote. T086 reste bloquée même si la recette synthétique réussit.

## Occasions de parallélisme

- Phase 1 : T001–T006 sont des validations auprès d'interlocuteurs différents ; T007 peut être réalisé indépendamment en lecture seule.
- Phase 2 : T008/T011/T015/T018 sont des tests séparés ; les contrats T009/T010/T012/T013/T014 doivent garder des propriétaires de module distincts.
- Par story : tests `[P]` d'abord ; US2 classes vs présence, US3 autorisations vs remise, US4 gate référentiel vs snapshot de bulletin, US5 séparation financière vs justificatifs, US6 workflow vs divulgation, US7 listes vs passage, US8 inspection vs documents peuvent se dérouler en parallèle avec dossiers isolés.
- Max 3 agents par tour. Toute modification aux routeurs racine, manifests catalogue, migrations partagées ou configuration est attribuée à un seul propriétaire ; les autres livrent des demandes documentées.

## Stratégie d'incrémentation

1. Réaliser localement les fondations T008–T018 avec tenants/écoles/territoires synthétiques ; ne pas effectuer de push pendant cette exécution.
2. MVP technique synthétique : US1 + US2, sans bulletin officiel, identifiant national, donnée réelle de mineur ni livraison FTP.
3. Garder US3 bloquée par T041 jusqu'à spécification de la procédure de doute ; toute sortie doit rester fail-closed.
4. Garder US4 et US7 sans comportement officiel jusqu'à réception des sources T046/T068 ; US5/US6/US8 restent soumis à leurs approbations métier propres.
5. Valider isolation, contrat API et sécurité, faire recette synthétique, puis solliciter une autorisation distincte pour pilote réel. Aucun push ou déploiement n'est implicite dans l'achèvement des tâches.
