# Modèle de données candidat — Primaire/Maternelle publiques

**Statut** : proposition de conception, pas de schéma approuvé ni migration. Le point de départ est le cahier fourni, les contrats du socle observés et ses conventions SQL portables. Les champs personnels, durées, identifiants nationaux, territoire et modalités de transfert demandent validation métier/légale.

## Principes de propriété

1. Réutiliser les objets S02/S03/S05/S06/S08/S09/S10/S12/S14 via `Contracts/`; ne pas répliquer personnes, identités, inscriptions, périodes, présence générique, notes, documents et messages.
2. Chaque nouvelle table applicative métier devra avoir `tenant_id`, et `establishment_id` si elle appartient à une école, ULID, dates texte ISO comme conventions locales ; SQL MySQL 8 + SQLite et aucun mot réservé.
3. Les territoires sont dans le noyau Organisation (proposition) car ils sont une hiérarchie partagée de supervision ; les tables métier demeurent des propriétaires modulaires.
4. Ne créer aucune table de données enfants réelle avant clarification de l'identifiant national, consentement, rétention, droits territoriaux et frontière du produit privé/public.

## Concepts candidats

| Concept | Propriétaire envisagé | Données essentielles (à valider) | Historique / règles |
|---|---|---|---|
| `Territory` | Socle Organisation | id, type (region/departement/arrondissement), code officiel, label FR/EN, parent_id, statut | dates d'effet et versions ; réaffectation d'école n'altère pas les snapshots clôturés |
| `SchoolProfile` | App public (ou extension Organisation) | establishment_id, territory_id, code officiel, référence acte, sous-système, coordonnées, contacts | l'identité officielle et historique d'infrastructures datés ; choix propriétaire à arbitrer |
| `SchoolInfrastructureSnapshot` | App public | école, année, salles/état, eau, latrines, électricité, équipements et connectivité | immuable une fois questionnaire annuel validé, correction versionnée |
| `EnrolmentCase` | People/Socle | person_id, établissement/classe/année, statut, dates, acte absent/à régulariser, transfert | aucune exigence de refus faute d'acte ou paiement ; éviter d'encoder un paiement comme condition |
| `PublicLearnerIdentifier` | People/Socle si confirmé | identifiant national et liens historiques vers personne/enrolment | unicité et déduplication territoriale ; accès fortement restreint ; stratégie à confirmer avec MINEDUB |
| `ClassAssignment` | Organisation + Staff contracts | class structure, teacher/person, rôle titulaire/intervenant, matières, dates d'effet, flux | historique sans écraser une affectation passée |
| `AttendanceBatch` | Attendance/Socle | client_uuid, établissement, auteur, type, date/période, checksum, état | idempotent ; conflits conservés pour décision humaine ; pas d'écrasement silencieux |
| `TeacherAttendance` | Attendance/Socle ou app selon API existante | staff, école, jour, statut/motif, auteur déclarant, contrôle inspection | corrections motivées et événements audités |
| `TerritorialCensus` | Reporting/Socle | territoire, année, formulaire versionné, agrégats et champs formulaire, état de workflow | soumission, renvoi motivé, validation en cascade, snapshot figé après niveau final |
| `TerritoryScopeAssignment` | Identity/Organisation | personne/rôle, permission, type/id territoire, valid_from/to, assigner | empêche l'accès hors tenant ; accès nominatif explicite, temporaire si applicable, audité |
| `SmallCellPolicy` | Reporting/Socle | seuil, dimensions, rôles bénéficiaires, politique secondaire suppression | seuil initial 5 proposé par le CDC mais à confirmer et à tester anti-réidentification |
| `CompetencyFramework` | App Primaire + Assessment contract | sous-système, niveau, matières/domaines, critères et libellés bilingues, version/source | origine `example` / `official` ; pas de modèle officiel sans source approuvée |
| `CompetencyObservation` | App Primaire ou Nursery | personne, classe/section, critère/domaine, période, code d'échelle, auteur, version | hors ligne avec UUID ; corrections selon état/version ; règles propres à Primary vs Nursery |
| `ReportCardSnapshot` | Documents/Assessment | personne, période, données copiées, template/rule version, numéro, état, document_id | publication figée ; rectificatif lié à l'original sans suppression |
| `PromotionDecision` | App Primaire | personne, année, proposition, décision du conseil, motif, approbations | règle versionnée ; redoublement exceptionnel et avis inspection paramétrables après validation |
| `AuthorizedPickup` | People/Attendance contracts existants | enfant, contact autorisé, photo facultative/minimisée, valid_from/to, restriction | révocable par parent/autorité ; accès réduit au personnel concerné |
| `PickupHandover` | Attendance/Socle | enfant, personne vérifiée, jour/heure, agent, mode offline, issue/refus, alerte | append-only/audité ; décision d'urgence et traitement du conflit offline restent à spécifier |
| `ApeeLedgerEntry` | App Finance publique (pas BillingFees) | école, exercice, type contribution/don/dépense, montant XAF, payeur restreint, justificatif, approbations | aucun couplage aux factures de scolarité, état financier publié agrégé ; confidentialité du non-paiement |
| `InspectionVisit` | App publique/Inspection | école/classe, inspecteur, date, checklist version, recommandations, destinataires | saisie offline, suivi d'état et audit |

## Machines à états candidates

- Questionnaire : `draft → submitted → returned | validated_by_inspection → validated_department → validated_region → frozen`; toute correction post-freeze crée une version motivée.
- Référentiel : `example | draft → reviewed → official → superseded`; seuls rôles autorisés publient une version officielle.
- Bulletin : `draft → ready_for_director → published → replaced/revoked`; une correction crée un document rectificatif.
- Examen : `draft → candidates_open → submitted_for_review → validated → frozen → results_imported`; aucune émission officielle de diplôme.
- Remise maternelle : `planned/authorized → confirmed | refused`; journaux non effaçables, sync idempotente et conflit traité explicitement.
- APEE : `draft → supported | pending_evidence → approved → reported`; dépense sans pièce reste identifiable comme non justifiée.

## Règles d'intégrité à spécifier/tester

- Toute clé d'objet fournie par le client est relue sous tenant/établissement/perimeter avant effet ; 404/403 selon conventions d'API et test d'isolation.
- Une période clôturée et son rattachement territorial sont immuables ; corrections sont versions distinctes.
- Une synchronisation rejouée avec le même UUID ne crée pas de double effet ; UUID identique avec contenu différent est conflit.
- Les agrégations ne divulguent pas un groupe de moins que le seuil configuré, ni par soustraction via résultats totaux/découpages croisés.
- Une contribution APEE ne conditionne aucune transaction d'inscription, présence, bulletin, examen ou document.
- Photos et informations sensibles ne sont servies qu'aux destinataires autorisés et selon consentement valide ; minimiser les données locales offline.

## Décisions nécessaires avant migration

Périmètre précis du pilote et volumes ; choix Primary public distinct vs profil unique avec 003 ; codes officiels et source de vérité territorial ; identifiant élève inter-écoles ; limites d'un dataset école/tenant ; propriétaire des snapshots et validations ; contenu exact de l'APEE ; mécanisme sécurité offline ; durées et suppression légales ; possibilités de données de santé (le cahier les exclut pour certains usages mais mentionne allergies maternelle). À défaut, les tables restent conceptuelles et les tâches de migration demeurent bloquées.

## Choix temporaire de fixture locale

Pour les tests et le MVP synthétique uniquement, réutiliser l'identifiant interne `Person` fourni par People ; ne pas créer d'identifiant national, de rapprochement inter-écoles ni de déduplication externe. Les fixtures doivent utiliser des identités factices et des codes territoriaux préfixés `SYN-`. Ce choix n'est pas une décision MINEDUB et ne permet aucune donnée réelle.
