# Réutilisation du socle : état observé

**Lecture seule** du code local le 2026-10-14. « Existe » indique un contrat ou une route repéré, pas la complétude métier publique. Les contrats sont internes, protégés par l'authentification et les scopes.

| Domaine | Capacité réelle utile | Exemples de routes `/api/v1` | État pour public |
|---|---|---|---|
| Organisation | `EstablishmentDirectory`, `StructureWriter`, `OrgUnitTree`, `ModuleAvailability`, années/périodes/structures, paramétrage | `GET /establishments`, `GET /org-units`, `GET /structures`, `POST /academic-years/{id}/close` | Partiel : arborescence bornée au tenant/établissement ; territoires administratifs absents |
| People | `PersonDirectory`, `EnrollmentDirectory`, `BirthInfo`, `ConsentChecker`, relations, inscription | `POST /people/import`, `POST /enrollments`, `POST /people/{id}/relations`, `POST /consents` | Partiel : base utilisable, identifiant national inter-écoles et processus public de régularisation à définir |
| Attendance | `AttendanceSummaryProvider`, `AttendanceTotals`, sync idempotente | `POST /attendance-sessions/sync`, `PUT /attendance-sessions/{id}/records`, `GET /authorized-pickups` | Partiel : sync actuelle bornée (50 sessions par demande/500 lignes par session selon audit) ; pas de remise effective pickup complète |
| Staff | `StaffDirectory`, `AssignmentWriter`, `TeacherWorkload`, `TaughtHoursRecorder` | `GET /staff`, `POST /staff/{id}/assignments`, `GET /leave-requests` | Partiel : personnel/affectations existent ; pas de paie étatique |
| Messaging | `Messenger`, `AbsenteeDirectory`, `DirectionDirectory` | `POST /messages/audience-preview`, `POST /messages/{id}/send`, `GET /announcements` | Partiel : messages/SMS existants ; vocal/USSD et financement programme à traiter séparément |
| Billing | `InvoiceIssuer`, `PaymentRequester`, `DebtorDirectory`, comptes/recettes | `POST /invoices`, `POST /payments`, `POST /webhooks/payments/{provider}` | Partiel mais à isoler : pas de ledger APEE/dons public ; prévenir toute facturation de frais scolaires publics |
| Documents | `DocumentIssuer`, `ExtendedDocumentIssuer`, `IssuanceGuard`, `DocumentCatalog` | `POST /document-batches`, `POST /document-batches/{id}/process`, `GET /public/verify/{token}` | Partiel : moteur disponible, documents publics à créer et modèle de minimisation à valider |
| Assessment | `CurriculumCatalog`, `RuleStrategy`, `RuleScopeLineage`, `DeliberationGuard` | `POST /grades/batch`, `POST /grades/sync`, `POST /results/publish` | Partiel : moteur générique ; APC, bulletin et règles MINEDUB ne sont pas fournis |
| Reporting | `IndicatorProvider`, `DashboardDefinition`, `IndicatorScope` | `GET /dashboards/{kind}`, `GET /regulatory-statistics`, `POST /report-schedules` | Partiel : aucune hiérarchie administrative ni snapshots régionaux versionnés/petits effectifs sécurisés repérés |
| Platform | `ApplicationManifest`, `ApplicationAccess` | `GET /public/applications`, `POST /public/access-requests`, `GET /platform/applications` | Partiel : catalogue et WriteGate existent ; Higher seul câblé en application à la date auditée |

## Hors ligne / mineurs

- L'interface web contient IndexedDB et synchronisation d'attendance/grades sous `web/src/offline/`; conflits conservés pour résolution, synchronisation existante non preuve d'une conservation garantie 30 jours.
- Les relations parent-enfant, consentements et personnes autorisées sont partiellement couverts. La vue maternelle observée gère des autorisations, mais le workflow de remise effective et notification n'est pas encore livré.
- Aucun scope territorial, cache local chiffré, révocation garantie hors ligne ou politique d'effacement des données offline n'a été confirmé par l'inspection. Ces items demandent un threat model et une preuve technique.

## Conclusion d'architecture

L'application publique peut consommer plusieurs contrats existants, mais sa mission différenciante réside dans les territoires, gouvernance publique, snapshots statistiques, APEE/dons, flux de sortie et règles pédagogiques validées. Ces capacités manquantes exigent des contrats du socle et tests d'isolation avant les applications. Ne pas appeler une route du cahier « réelle » tant qu'elle n'est pas effectivement déclarée dans le code.
