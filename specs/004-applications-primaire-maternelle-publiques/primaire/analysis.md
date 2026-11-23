# Analyse de couverture : application Primaire publique et modules communs Primaire/Maternelle

(Livrable « analysis.md » : ce nom a été refusé par l'outil d'écriture, le fichier s'appelle `couverture.md`.)

Date : 2026-10-15. Source qui fait foi : [../cdc-public.md](../cdc-public.md) (CDC v1.0 du 9 octobre 2026). Analyse en lecture seule du code réel de la branche `feat/rewrite-php-laravel` (y compris le code non commité du 14/10). Légende : **C** couvert par le socle, **P** partiel, **N** à créer. PP = changement demandé au socle ([socle-reuse.md](socle-reuse.md)).

## Résumé (20 lignes)
1. Périmètre : 10 modules communs (ECO, ELV, CLA, PRE, PER, COM, APE, STA, INS, CAN), 3 modules primaire (EVA, PAS, EXA), RG-TER-1..6, rôles R01-R21, 5 évolutions du socle, DOC-01..17 et 22..28, NOT-01..05 et 08..14, API-01..12, 14, 15.
2. Couverture des 69 exigences EX- du périmètre : **1 C, 35 P, 33 N** (détail §2). Des 39 règles RG- (TER, ELV, PRE, PER, COM, APE, STA, EVA, PAS, EXA, DOC) : 4 C, 16 P, 19 N.
3. Le socle couvre bien le générique : personnes, inscriptions, appel hors ligne, documents numérotés avec QR, SMS, portail famille, personnel, journal d'audit.
4. Il ne couvre pas le public : chaîne territoriale multi-écoles, agrégats territoriaux, questionnaire statistique, APEE, inspection, évaluation par critères A/ECA/NA, examens CEP/FSLC.
5. **Écart majeur 1** : un inspecteur ou un délégué doit voir des centaines d'établissements ; le périmètre `territory` ajouté le 14/10 reste borné à **un** établissement et ne résout pas RG-TER-2 (PP2).
6. **Écart majeur 2** : le rattachement école → arrondissement est dans une table d'application (`pp_school_profiles`) alors que le socle en a besoin pour les droits et les agrégats (PP1).
7. **Écart majeur 3** : pas de synchronisation par lots signés et compressés, ni d'appareils révocables, ni de code personnel sur téléphone partagé (PP6, PP7) ; seuls `/attendance-sessions/sync` et `/grades/sync` existent.
8. **Écart majeur 4** : pas d'identifiant national de l'élève, pas de contrat d'écriture des personnes et inscriptions utilisable par une application (PP8, PP9).
9. **Écart majeur 5** : pas d'agrégats pré-calculés par territoire ni de masquage des petits effectifs en production ; `DisclosurePolicy` et `ApprovalWorkflow` ne sont que des contrats (PP4, PP5).
10. Messagerie : pas de refus des SMS non essentiels, ni de plafond de coût par élève, ni d'annonces territoriales, ni de voix, ni d'USSD (PP10-PP14).
11. Documents : « Duplicata » existe, « Rectificatif » non (PP15) ; numérotation par établissement et année déjà possible (`{CODE}-{AAAA}` dans `Documents/Domain/NumberPattern.php`).
12. L'évaluation par critères (3 occasions, deux tiers, A/ECA/NA) ne rentre pas dans le modèle de notes du socle : elle est portée par l'application, règles **paramétrées** (aucun seuil codé en dur).
13. Décision proposée : `Apps/PublicSchools` (communs, préfixe `pub_`), `Apps/PublicPrimary` (EVA, PAS, EXA, préfixe `pp_`), `Apps/PublicNursery` (maternelle, autre agent). `Apps/Primary` reste au primaire privé (spec 003). Voir [plan.md](plan.md) §2.
14. Code du 14/10 : territoires **à garder et compléter** ; `Scope::TERRITORY` **à revoir** ; `ScopedAccessAudit` **à déplacer** derrière un contrat ; `ApprovalWorkflow` et `DisclosurePolicy` **à garder** et implémenter ; `PublicPrimary/Registry` **à déplacer** dans `PublicSchools/Schools` et à compléter (§1).
15. Écart voulu, noté une fois : le CDC prévoit Laravel 13, PHP 8.4, PostgreSQL, Redis, Gotenberg et `app/Modules/...` ; la réalisation est PHP 8.1 sans framework, MySQL 8, Dompdf, cron (ADR-0001).
16. Écart à arbitrer : le CDC demande une application Android de moins de 30 Mo ; le front actuel est une PWA hors ligne (Q2).
17. Charge du CDC : 452 j-p ; la reprise du socle réduit surtout P1 (personnes, appel, documents), peu P2 et P3.
18. 23 questions dans [questions.md](questions.md), dont 6 sur des règles officielles ; chacune a une valeur par défaut paramétrable « à confirmer ».
19. Peuvent démarrer sans réponse : PP1-PP9, squelettes, ECO, ELV, CLA, PRE, PER, COM (lot P1).
20. Tâches T600-T699 dans [tasks.md](tasks.md), vagues de 3 agents au plus dans [wave-plan.md](wave-plan.md).

## 1. Inventaire du code existant lié au public (écrit le 14/10, non commité)

| Élément | Chemin | Constat | Verdict |
|---|---|---|---|
| Arbre territorial versionné | `src/Modules/Socle/Organisation/Database/Migrations/202610151001_organisation_public_territories.sql` (`organisation_territory_versions`) | portable, ULID, `valid_from/valid_to`, aucune donnée officielle semée | **Garder** ; ajouter le lien établissement (PP1). Les versions servent RG-TER-4 |
| `TerritoryDirectory`, `TerritoryInfo` | `src/Modules/Socle/Organisation/Contracts/` | `find`, `lineage`, `descendantsOf` à une date | **Garder** ; ajouter `establishmentsIn`, `territoryOf` (PP1) |
| `PdoTerritoryDirectory`, `TerritoryController`, `TerritoryPolicy` | `Organisation/Infrastructure/`, `Organisation/Http/Controllers/`, `Organisation/Http/Policies/` | routes `/territories*` ; droit = `organisation.read/manage` **de l'établissement courant** | **Revoir** la politique : tenir l'arbre est un droit national (R21), permission `organisation.territories.manage` de niveau client |
| `Scope::TERRITORY`, `ScopeType::Territory` | `Identity/Contracts/Scope.php`, `Identity/Domain/ScopeType.php` | scope borné à un tenant **et un établissement** | **Revoir** (PP2) : affectation au territoire sans établissement, établissements accessibles déduits du territoire |
| `RoleBasedPermissionScopes`, `RoleAssignmentService` | `Identity/Infrastructure/`, `Identity/Application/` | lisent `TerritoryDirectory` via `ContainerInterface` | **Revoir** : injecter le contrat (dépendance explicite, testable) |
| `ScopedAccessAudit` | `Identity/Application/ScopedAccessAudit.php` | écrit `identity.named_record.accessed` dans `AuditLog` | **Déplacer** derrière `Identity\Contracts\NamedAccessJournal` : une application n'a droit qu'aux `Contracts/` (`tests/Architecture/StandardsTest.php`) ; ajouter la lecture par le directeur (PP3) |
| `ApprovalWorkflow` | `Reporting/Contracts/ApprovalWorkflow.php` | contrat seul ; testé avec une implémentation de test (`tests/Feature/Reporting/ApprovalWorkflowTest.php`) | **Garder** ; implémentation de production (PP5) |
| `DisclosurePolicy` | `Reporting/Contracts/DisclosurePolicy.php` | contrat seul (`tests/Feature/Reporting/SmallCellDisclosureTest.php`) | **Garder** ; implémentation, seuil en paramètre (PP4) |
| Registre école | `src/Modules/Apps/PublicPrimary/Registry/**`, migration `202610151002_primary_public_registry.sql` (`pp_school_profiles`, `pp_infrastructure_snapshots`) | pas de manifeste ni de `WriteGate`, pas de `Contracts/` ni `Domain/`, permissions `public_primary.registry.*` non semées, profil sans acte de création, sous-système, GPS, statut ; infrastructures incomplètes ; ECO est commun et non propre au primaire | **Déplacer et compléter** dans `Apps/PublicSchools/Schools` (tables `pub_*`) ; reprendre la logique versions/correction/clôture de `InfrastructureSnapshotService`, qui est saine. Migration jamais déployée : la supprimer plutôt que la corriger |
| Tests | `tests/Feature/Organisation/TerritoryHistoryTest.php`, `tests/Feature/PublicSchools/Registry/*`, `tests/Feature/Identity/ScopedAccessAuditTest.php` | non relancés ici | Garder ceux du socle ; déplacer ceux du registre |
| Front | `web/src/apps/public-primary/registry/RegistryView.vue`, route `/public-primary/registry` | un écran | **Déplacer** vers `web/src/apps/public-schools/` |

## 2. Couverture exigence par exigence

### ECO · École et carte scolaire
| ID | Statut | Existant vérifié | Manque |
|---|---|---|---|
| EX-ECO-01 | P | `establishments` typés (`Organisation/Domain/EstablishmentType.php` : `nursery`, `primary`) ; prototype `pp_school_profiles` | acte de création, sous-système, cycle, statut, GPS, contacts ; lien territoire dans le socle |
| EX-ECO-02 | P | `pp_infrastructure_snapshots` (salles, eau, latrines, électricité) | matériau/état par salle, tables-bancs, latrines séparées, clôture, cantine, bibliothèque |
| EX-ECO-03 | P | champ `connectivity` | équipements numériques |
| EX-ECO-04 | N | dépôt de fichiers People (`People/Contracts/FileScanner.php`) | photos géolocalisées de visite |
| EX-ECO-05 | P | versions + clôture (`InfrastructureSnapshotService::closeSnapshot`) | gel déclenché par la clôture du questionnaire |

### ELV · Élèves, inscriptions, transferts
| ID | Statut | Existant | Manque |
|---|---|---|---|
| EX-ELV-01 | P | `people` (`birth_date`, `birth_place`, `gender`), garants (`PersonDirectory::guarantorsOf`) | référence d'acte de naissance, langue de la maison |
| EX-ELV-02 | N | `people.national_id` unique par client, pensé pour la CNI | identifiant national élève immuable (PP8) |
| EX-ELV-03 | P | `People/Domain/DuplicateMatcher.php` (nom + date ou téléphone, sur tout le client) | critère « mêmes parents » (PP8) |
| EX-ELV-04 | N | — | situations particulières (déplacé, réfugié, aménagement, orphelin, sans acte) |
| EX-ELV-05 | P | statut `transferred` (`People/Domain/EnrollmentStatus.php`), `schooling_history` | demande/accord, dossier utile, DOC-03, contrat d'écriture (PP9) |
| EX-ELV-06 | N | — | abandon déclaré/présumé, relance |
| EX-ELV-07 | P | `People/Application/ImportService.php` (CSV) | lots hors ligne (PP6), photo de registre, saisie R20 |

### CLA · Classes et effectifs
| ID | Statut | Existant | Manque |
|---|---|---|---|
| EX-CLA-01 | P | structures hiérarchiques (`Organisation/Contracts/StructureWriter.php`) | classe jumelée multi-niveaux, double flux |
| EX-CLA-02 | P | `Staff/Contracts/AssignmentWriter.php` | titulaire unique par classe et date d'effet |
| EX-CLA-03 | P | `People/Contracts/StructureEnrollments.php` | ventilation sexe/âge, alerte au-delà de 60 (paramètre) |
| EX-CLA-04 | N | — | répartition proposée |

### PRE · Présences
| ID | Statut | Existant | Manque |
|---|---|---|---|
| EX-PRE-01 | P | appel hors ligne `POST /attendance-sessions/sync` (`Attendance/routes.php`) | appel par demi-journée sans créneau (= PS3 de la spec 003, non livré, PP17) ; lots (PP6) |
| EX-PRE-02 | P | `Attendance/Application/JustificationService.php` | motif reçu par SMS |
| EX-PRE-03 | N | Attendance ne gère que les apprenants | présence des enseignants, contrôle aléatoire |
| EX-PRE-04 | P | `leave_requests` (Staff) | pièce, synthèse DOC-22 |
| EX-PRE-05 | P | `AssiduityService` (seuils en %), `AbsenceNotifier` | règle « N jours en un mois » |

### PER · Personnel
| ID | Statut | Existant | Manque |
|---|---|---|---|
| EX-PER-01 | P | `staff_members` (statut, grade, matricule, `hired_on`), `staff_qualifications` | statuts du public (liste paramétrée) |
| EX-PER-02 | P | `staff_assignments` | mutations entre écoles du territoire, historique |
| EX-PER-03 | N | — | formations |
| EX-PER-04 | N | — | besoins en enseignants |

### COM · Communication
| ID | Statut | Existant | Manque |
|---|---|---|---|
| EX-COM-01 | P | `Messaging/Contracts/Messenger.php`, modèles FR/EN, `sms_usage` | ciblage classe/niveau/école, catégorie essentielle |
| EX-COM-02 | N | — | voix (PP13) |
| EX-COM-03 | N | — | USSD (PP14) |
| EX-COM-04 | P | portail famille, clé `portals.sections.<app>` | sections publiques |
| EX-COM-05 | N | `announcements` par établissement | annonces territoriales (PP12) |

### APE · APEE
| ID | Statut | Existant | Manque |
|---|---|---|---|
| EX-APE-01 | N | — | vote, compte rendu |
| EX-APE-02 | P | `Billing/Contracts/PaymentRequester.php`, `Documents/Contracts/DocumentIssuer.php` | grand livre APEE hors facturation (RG-ELV-1) |
| EX-APE-03 | N | — | dépenses avec pièce, « en attente » |
| EX-APE-04 | N | — | état financier DOC-25 |
| EX-APE-05 | N | — | paquet minimum, dons, DOC-27 |
| EX-APE-06 | N | — | exonérations confidentielles |

### STA · Statistiques
| ID | Statut | Existant | Manque |
|---|---|---|---|
| EX-STA-01 | N | — | questionnaire pré-rempli |
| EX-STA-02 | N | — | contrôles de cohérence |
| EX-STA-03 | P | `ApprovalWorkflow` (contrat seul) | circuit à 5 niveaux (PP5) |
| EX-STA-04 | P | `Integrations/Contracts/TutelleExporter.php`, `integrations.export_types.<app>` | format MINEDUB (Q8) |
| EX-STA-05 | P | `Reporting/Contracts/IndicatorProvider.php` par établissement | agrégats territoriaux (PP4) |
| EX-STA-06 | N | — | données de fin d'année |

### INS · Inspection et CAN · Cantine et santé
| ID | Statut | Existant | Manque |
|---|---|---|---|
| EX-INS-01 à 05 | N (5) | `DocumentIssuer` pour DOC-26 | planning, grille hors ligne, rapport, recommandations, comptage, signalement de frais illégal |
| EX-CAN-01 | N | — | repas, stocks |
| EX-CAN-02 | P | `People/Contracts/ConsentChecker.php` | campagnes en effectifs |
| EX-CAN-03 | P | santé chiffrée People | visibilité restreinte (point de contact Maternelle) |

### EVA, PAS, EXA (primaire)
| ID | Statut | Existant | Manque |
|---|---|---|---|
| EX-EVA-01 | P | `Assessment/Contracts/CurriculumCatalog.php` (disciplines) | compétences, critères versionnés |
| EX-EVA-02 | P | périodes Organisation (trimestres) | unités dans le trimestre |
| EX-EVA-03 | N | — | saisie critère × occasion hors ligne |
| EX-EVA-04 | N | — | mention paramétrée |
| EX-EVA-05 | N | — | remédiation |
| EX-EVA-06 | P | `DocumentIssuer`, lots, QR | DOC-08/09/10 |
| EX-EVA-07 | P | appréciations du conseil (Assessment) | banque de formulations |
| EX-EVA-08 | C | notes chiffrées du socle (`/assessments`, `/grades`) | — |
| EX-PAS-01 | P | `RuleStrategy::decideYear`, `Organisation/Contracts/PromotionPlanner.php` | politique sur mentions |
| EX-PAS-02 | P | conseils Assessment | PV DOC-12 |
| EX-PAS-03 | P | `Messenger` | recours avec délai |
| EX-PAS-04 | N | — | statistiques par territoire |
| EX-EXA-01, 02 | N (2) | — | listes CEP/FSLC, 6e, Common Entrance |
| EX-EXA-03 | P | `ApprovalWorkflow` | circuit et gel |
| EX-EXA-04, 06 | P (2) | `DocumentIssuer` | DOC-14, DOC-16 |
| EX-EXA-05 | N | modèle : `src/Modules/Apps/Higher/NationalExams` | import des résultats |
| EX-EXA-07 | N | — | transfert vers Secondaire |

### Règles territoriales
| ID | Statut | Constat |
|---|---|---|
| RG-TER-1 | P | arbre OK ; lien école → arrondissement à porter par le socle (PP1) |
| RG-TER-2 | P | scope et journal existent mais bornés à un établissement (PP2, PP3) |
| RG-TER-3 | N | aucune séparation nominatif/agrégat par niveau (PP2, PP4) |
| RG-TER-4 | P | versions de territoire ; gel des statistiques à ajouter (PP5) |
| RG-TER-5 | N | acte de création (ECO) |
| RG-TER-6 | P | héritage plateforme > client > établissement > unité ; niveau région manquant (PP16) |

### Autres règles (synthèse)
- **C (4)** : RG-DOC-1 (numéro par établissement et année), RG-PRE-3 (aucune sanction automatique sans paramétrage), RG-COM-1 (rien ne facture le SMS au parent), RG-EVA-1 (rang : aucune application publique ne le calcule).
- **P (12)** : RG-DOC-2 (page de vérification à restreindre), RG-DOC-4, RG-DOC-5 (rectificatif), RG-DOC-6 (poids PDF), RG-ELV-3, RG-ELV-4, RG-ELV-5, RG-PRE-1, RG-COM-2, RG-STA-1, RG-EVA-2, RG-PER-2.
- **N (17)** : RG-ELV-1 et RG-APE-1 (garanties à prouver par test), RG-ELV-2, RG-PRE-2, RG-PER-1, RG-APE-2, RG-APE-3, RG-APE-4, RG-STA-2, RG-EVA-3, RG-EVA-4, RG-PAS-1, RG-PAS-2, RG-EXA-1, RG-EXA-2, RG-EXA-3, RG-DOC-3.

## 3. Notifications, documents, API, sécurité, exigences non fonctionnelles
| Sujet | Statut | Constat |
|---|---|---|
| NOT-01..05, 08..14 | P | `Messenger` + modèles en données (`database/migrations/*message_templates*.sql`, liste blanche `Messaging/Domain/TemplateRenderer::ALLOWED`) ; manquent catégorie essentielle, voix (NOT-05, 14), ciblage territorial (NOT-11..13) |
| DOC-01..17, 22..28 | P | moteur complet (`DocumentIssuer`, lots, QR, Dompdf, `{ECOLE}`) ; 24 modèles à écrire ; rectificatif (PP15) ; impression groupée par l'inspection pour plusieurs écoles (PP15) |
| API-01..12, 14, 15 | N | à créer ; API-04 (lots) dans le socle (PP6), API-15 (USSD) dans le socle (PP14) |
| Sécurité mineurs | P | 2FA, audit chaîné, rétention (`retention:run`), sauvegardes chiffrées, consentements ; manquent journal de consultation nominative lisible par le directeur, masquage < 5, appareils révocables, base locale chiffrée et effacement après 5 codes erronés |
| NF-P1..P6 | N | jamais mesuré à cette échelle ; mutualisé Camoo sans Redis : agrégats nocturnes obligatoires (PP4), traitement des lots par file cron (PP6) ; 40 000 lots/heure ≈ 11/s à vérifier (Q20) |
| TST-01..10 | N | à écrire (T690-T699) |

## 4. Points de contact pour la Maternelle
Les modules communs offrent à `Apps/PublicNursery`, par `Apps/PublicSchools/Contracts/` : registre de l'école et groupe scolaire (`SchoolRegistry`), élève et identifiant national (`PupilRegistry`), classes/sections et effectifs (`ClassRoster`), appel par demi-journée et présence des enseignants (`AttendanceFacade` = socle + PP17), catégories de messages (sécurité = essentiel, RG-COM-2, pour NOT-06/07), consentements photos (socle `ConsentChecker`), APEE, sections maternelles du questionnaire STA (`CensusSectionProvider`, clé `public_schools.census_sections.<app>`), inspection, allergies visibles du seul personnel concerné (EX-CAN-03), lots hors ligne (PP6) pour le contrôle des sorties, transfert maternelle → primaire (`PupilTransfer`) pour TRA, voix (PP13) pour NOT-06.
