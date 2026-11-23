# Recherche et décisions — Primaire/Maternelle publiques

**Date** : 2026-10-14. Lecture seule du dépôt local, de la constitution, des documents 001/002/003 et de la branche `main` du dépôt GitHub partagé. Aucun accès FTP/MySQL, aucune écriture code et aucun test exécuté.

## Décisions de conception vérifiées

### R1 — Architecture d'exécution
- **Décision** : suivre le code courant du workspace, PHP 8.1 sans framework, monolithe modulaire, PSR, API `/api/v1`, PDO, MySQL 8 en hébergement actuel et SQLite pour tests, frontend Vue 3/TypeScript/PWA, Dompdf. Pas de Laravel, Redis, PostgreSQL ou Gotenberg dans le plan immédiat.
- **Rationnel** : c'est l'architecture imposée par l'ADR-0001 et l'hébergement Camoo décrit dans `specs/HANDOVER.md` et `.specify/memory/constitution.md`.
- **Alternatives écartées** : recopier le paragraphe Laravel/PostgreSQL du cahier ; repartir sur l'ancienne stack NestJS/Next.js. Ce serait une réarchitecture, pas la prochaine application du workspace.

### R2 — Dépôt GitHub et version de référence
- **Décision** : le code local actuel de référence est le monolithe PHP. La page publique `efoka24-ops/scholaris-erp` sur `main` consultée le 14 octobre 2026 présente SCHOLARIS ERP v2, monorepo Turborepo `apps/api` (NestJS), `apps/web` (Next.js 14), `packages/prisma|shared|ui`, PostgreSQL/Redis et déploiement Railway. Il s'agit d'une génération/branche historique distincte de l'implémentation PHP locale.
- **Rationnel** : ne pas mélanger manifests, migrations ni secrets de deux stacks. L'ancien produit peut éclairer des parcours seulement, jamais être pris pour le backend PHP déployé.
- **À confirmer** : quel dépôt/branche GitHub est le remote officiel de la réécriture PHP et quels comptes sont autorisés à publier.

### R3 — Modèle multi-client public
- **Décision proposée, à confirmer** : un tenant MINEDUB/programme pilote contient les établissements-écoles ; région, département et arrondissement sont des périmètres de supervision rattachés à ce tenant. Les données des élèves restent rattachées aux tenants/établissements ; les permissions territoriales s'ajoutent aux contrôles existants sans élargir l'accès inter-tenant.
- **Rationnel** : le cahier prévoit un commanditaire national ou programme territorial et des milliers d'écoles ; le noyau impose un cloisonnement tenant/établissement.
- **À rechercher/valider** : attribution des comptes administratifs, délégations multi-territoires, transferts entre tenants/programmes, autorité de création des codes territoriaux, historisation des limites administratives.

### R4 — Périmètre produit et collision avec la feature privée 003
- **Décision** : conserver séparément la feature publique 004 et la feature privée/ERP 003 tant qu'un arbitrage produit ne déclare pas qu'il s'agit d'un seul produit configurable. Ne pas fusionner leurs règles d'inscription/frais, d'évaluation ou leurs tables par défaut.
- **Rationnel** : les exigences publiques interdisent tout frais scolaire/conditionnement par APEE et demandent supervision territoriale ; 003 décrit une école privée facturant inscription/pension et prévoit une app `primary` différente.
- **Bloquant** : arbitrer clés catalogue et noms de modules finaux (`primary`, `nursery`, variante publique, ou profil multi-modèle) avant le code et les migrations partagées.

### R5 — Réutilisation du socle constatée
- **Organisation** : `StructureWriter`, `OrgUnitTree`, `EstablishmentDirectory`, `ModuleAvailability`, années/périodes/structures sont disponibles, mais le tree est interne au tenant/établissement, pas une hiérarchie territoriale nationale. Voir `src/Modules/Socle/Organisation/Contracts/` et `routes.php`.
- **People** : `PersonDirectory`, `EnrollmentDirectory`, `BirthInfo`, `ConsentChecker`, dossiers, relations, consentements et inscriptions existent ; exigences d'élève public sans acte et identifiant national inter-écoles à compléter.
- **Attendance** : présence et synchro en lots avec `client_uuid` existent (`POST /api/v1/attendance-sessions/sync`), capacités bornées par la mise en œuvre actuelle ; personnes autorisées à récupérer l'enfant existent mais pas la remise effective complète.
- **Staff** : personnels, affectations, workload et congés ; pas de paie de l'État (hors scope).
- **Messaging** : `Messenger`, annonces et modèles de messages ; passerelles vocales/USSD et budget de SMS institutionnel non établis.
- **Billing** : `InvoiceIssuer`, `PaymentRequester`, `DebtorDirectory`; pas de grand livre APEE public ni dons/paquet minimum. Les APIs de frais génériques ne doivent pas permettre de frais de scolarité aux établissements publics.
- **Documents** : émission en lot, modèles/versioning, révocation et vérification QR publique ; types, gabarits publics et règles de minimisation à ajouter.
- **Assessment** : `CurriculumCatalog`, `RuleStrategy`, `RuleScopeLineage`, `DeliberationGuard`, grades/sync/conseil/publication existent ; les règles MINEDUB APC, référentiels et bulletin public doivent être confirmés et implémentés en stratégies/versionnées.
- **Reporting** : `IndicatorProvider`, `DashboardDefinition`, `IndicatorScope`; pas de hiérarchie de territoires, de snapshots publics annuels gelés, ni de contrôle anti-réidentification/suppression des petits effectifs vérifiés.
- **Platform** : `ApplicationManifest`, `ApplicationAccess` et catalogue existent ; seul Higher a une application réellement câblée, Primary/Nursery sont des squelettes/placeholder au moment de l'inspection.

Les contrats sont des interfaces internes aux modules : leur existence n'implique pas que toute la fonctionnalité soit déjà exposée ou convenable au public.

### R6 — Hors ligne et terminaux
- **Décision** : prolonger la PWA Vue existante et la file IndexedDB pour les parcours testés d'abord (appel, observations/évaluation, listes de sortie et formulaires d'inspection). Utiliser `client_uuid`, lots idempotents et conflits explicites ; aucun conflit de saisie ne doit être écrasé silencieusement.
- **Rationnel** : c'est la technologie et convention déjà présentes ; une app Android native de moins de 30 Mo n'est pas dans le repo de production PHP.
- **Bloquant** : valider chiffrement/gestion de clés locale, verrouillage et révocation hors ligne, appareil partagé, authentification par personne, limites de conservation, 10 vs 30 jours de stockage et protocole de récupération après perte. Aucun niveau de sécurité mobile ne doit être promis avant revue de menace et preuve technique.

### R7 — Sécurité, environnements et secrets
- **Décision** : documenter GitHub pour le contrôle de version et le transfert FTP seulement comme deux opérations séparées : commit/push du code vers remote officiel, build reproductible, puis FTP du paquet filtré ; MySQL de production est local au serveur Camoo, pas une base distante accessible au poste. Les tests de développement restent SQLite/DB locale isolée.
- **Secrets** : les captures reçues contiennent des identifiants/mots de passe. Ils ne sont recopiés ni dans les documents, ni dans Git, ni dans des scripts. Les valeurs doivent être changées avant tout accès ; stockage ensuite uniquement dans un gestionnaire de secrets/variables d'environnement hors dépôt. Aucun accès réseau/deploiement n'a été tenté.
- **Blocage sécurité** : la production décrite est HTTP et PHP 8.1. La constitution interdit toute donnée réelle en production tant qu'un PHP maintenu et HTTPS ne sont pas disponibles. Le pilote technique doit utiliser données synthétiques/anonymisées en attendant résolution formelle.

## Questions non résolues (à garder « à confirmer »)

1. Quel est le dépôt et la branche GitHub officiels de la réécriture PHP ? La page fournie désigne actuellement la v2 TypeScript.
2. La feature publique est-elle une app catalogue séparée de Primary privée, ou un seul produit avec modes privé/public ? Même question pour groupes scolaires et établissement commun Primaire/Maternelle.
3. Commanditaire/tenant pilote et règles d'accès entre zones administratives ; délégation administrative officielle.
4. Textes MINEDUB courants : calendrier, référentiels, échelles APC, bulletin, passage/redoublement, documents d'examens et formats statistiques.
5. Limites légales réelles de consentement, conservation, transferts/hébergement de données de mineurs et photos.
6. Appareils et modèle de compte partagé hors ligne compatible avec authentification individualisée.
7. Capacité d'hébergement, HTTPS et cible runtime maintenue avant données réelles ; charge nationale non validée sur Camoo.
8. APEE : votes, exemptions, dépenses, pièces et séparation comptable attendue ; frais interdits explicitement en public.

## R8 — Décisions techniques provisoires pour le développement local

Suite à l'instruction de poursuivre de façon autonome, le périmètre de travail local est fixé ainsi, sans prétendre remplacer les approbations des autorités :

- Construire **PublicPrimary et PublicNursery comme applications distinctes**, sans réutiliser ni modifier le produit privé 003. Choix provisoire et réversible ; clés `primary_public` et `nursery_public` proposées.
- Utiliser uniquement un tenant/établissement et des codes territoriaux **synthétiques**, identifiables comme fixtures. L'identité du commanditaire, les codes officiels et le périmètre du pilote restent ouverts.
- Ne pas pousser vers GitHub ni déployer pendant cette exécution ; le remote PHP officiel reste à confirmer.
- US4/US7 ne reçoivent aucune règle officielle, seuil pédagogique, calendrier ou gabarit inventé. Ces livrables restent bloqués jusqu'à réception des sources approuvées.
- Aucune donnée réelle de mineur, capture d'identifiants, accès Camoo/FTP ou base de production. Toute validation locale utilise des fixtures synthétiques ; les portes légales, HTTPS, runtime et restauration demeurent ouvertes pour un pilote réel.
- Pour la remise maternelle, appliquer le refus par défaut prévu par FR-005 : en cas de doute, ne pas autoriser la sortie. Ce choix technique ne définit pas une procédure officielle d'urgence.
