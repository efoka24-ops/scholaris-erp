# Plan d'implémentation : applications Primaire et Maternelle publiques

**Feature** : `004-applications-primaire-maternelle-publiques`  
**Date** : 2026-10-14  
**Spécification** : [spec.md](spec.md)  
**Recherche** : [research.md](research.md)

## Résumé

Préparer les applications publiques Primaire et Maternelle pour un pilote MINEDUB, en étendant d'abord les contrats du socle réellement nécessaires (territoires, présence hors ligne, supervision agrégée, documents publics), puis en livrant les parcours école par vagues indépendamment validables. Réutiliser les contrats du socle, les capacités PWA et les mécanismes testés dans Higher ; ne pas recopier les tables ni implémenter une deuxième pile technique.

Ce plan est **documentaire** et ne signifie pas autorisation d'implémenter ou de déployer. Les règles officielles demeurent à confirmer. La distinction produit avec Primary privé de la feature 003, le dépôt GitHub officiel de la réécriture PHP et le droit d'utiliser la cible Camoo en pilote sont des portes de décision.

## Contexte technique

| Sujet | Décision |
|---|---|
| Langage | PHP 8.1 compatible avec l'hébergement actuel, sans framework applicatif, PSR, PDO |
| Dépendances | dépendances présentes dans le workspace ; FastRoute, PSR-7, PHP-DI, PHPUnit, PHPStan ; aucune dépendance technique publique nouvelle avant validation |
| Web | Vue 3, TypeScript, Pinia, Vue Router, Vite, PWA/IndexedDB existants |
| Données | MySQL 8 sur Camoo, SQLite tests ; migrations SQL portables, ULID, tenant + établissement ; DB Camoo joignable localement par l'application seulement |
| Documents | Dompdf et service Documents du socle |
| Tests | PHPUnit (contrats/API, architecture, isolation, feature, unit, E2E), Vitest, Playwright ; tests MySQL uniquement dans environnement dédié autorisé |
| Déploiement | GitHub pour commits vers le remote/branche approuvés ; build release local ; FTP Camoo séparément. Les secrets sont uniquement des variables externes au dépôt ; aucun secret en clair dans les artefacts |
| Performance | Objectifs nationaux du cahier non validés sur Camoo ; limites pilote à mesurer ; ne pas annoncer capacité nationale sans environnement de charge approprié |
| Échelle | pilote initial à préciser ; chiffres nationaux fournis par cahier comme objectif cible, pas comme capacité garantie |

## Portes de décision et clarification obligatoire

1. **Produit** : 003 décrit Primary privée avec frais ; 004 décrit écoles publiques sans frais scolaires. Avant toute migration ou catalogue, choisir des applications distinctes (recommandé provisoirement : clés distinctes `primary_public` et `nursery_public`) ou un modèle unique configurable. Ne pas fusionner par défaut.
2. **Tenant/territoire** : faire approuver le tenant commanditaire, la hiérarchie région-département-arrondissement et l'autorité d'attribution des agents. Toute permission territoriale reste subordonnée à tenant et établissement.
3. **Règles pédagogiques** : obtenir référentiels MINEDUB, bulletin, calendrier, passage et formats examens ; les valeurs d'exemple restent marquées `example` et ne deviennent jamais seed officiel.
4. **Données des mineurs / exploitation** : secrets des captures à révoquer/renouveler avant usage ; obtenir HTTPS et runtime PHP maintenu pour toute donnée réelle. Tant que ces prérequis ne sont pas satisfaits, uniquement données synthétiques ou anonymisées.
5. **GitHub** : confirmer le remote de la réécriture PHP. Le lien communiqué expose sur `main` une ancienne stack TypeScript/NestJS/Next.js ; ne pas envoyer le code PHP sur cette branche sans confirmation explicite.

### Périmètre autonome de cette exécution (local uniquement)

- Choix d'ingénierie provisoire : garder `PublicPrimary` et `PublicNursery` distincts de l'application privée décrite dans 003 ; ne pas modifier les squelettes `Apps/Primary` ou `Apps/Nursery`.
- Le seul jeu de données autorisé est synthétique, tenant/établissement/codes inclus. L'autorité commanditaire et la hiérarchie réelle restent à approuver ; aucun seed officiel ne sera ajouté.
- On peut développer et tester localement les fondations génériques, le registre, le parcours élève inclusif et la remise fail-closed sur fixtures. Cela n'autorise ni données réelles, ni git commit/push, ni FTP, ni connexion MySQL Camoo.
- Les règles MINEDUB et gabarits officiels US4/US7 restent bloqués jusqu'à réception de leurs sources. Les exigences légales et d'exploitation restent une porte obligatoire avant pilote réel.
- Le lot offline n'annonce pas 30 jours et n'ajoute pas de cache local de données réelles ; tout test offline de cette exécution utilise uniquement des fixtures synthétiques.

## Vérification constitutionnelle

| Principe | Statut | Conditions |
|---|---|---|
| I. Socle d'abord | Conforme sous condition | services partagés via `Contracts/`, extensions dans modules propriétaires ; territoires et statistiques d'abord au socle |
| II. Cloisonnement | À concevoir / gate | chaque ligne métier `tenant_id`, plus `establishment_id` quand établissement-scopée ; périmètre territorial n'ouvre jamais une frontière tenant |
| III. Test-first | Obligatoire | règles d'évaluation, droits, sync, sorties, confidentialité et facturation APEE testés avant code ; aucune règle officielle avant validation |
| IV. API versionnée | Conforme | `/api/v1`, RFC 9457, validation, ressources whitelistées, OpenAPI et contrats API |
| V. Paramétrage | Conforme sous validation | référentiels, calendrier et politique versionnés ; seules personnes nationales autorisées modifient les règles nationales |
| VI. Traçabilité et conformité | Gate sécurité | journal des accès nominatifs et approbations ; consentement révocable ; rétention/hébergement à valider juridiquement |
| VII. Simplicité d'exploitation | Conforme | monolithe modulaire ; aucune pile mobile/backend indépendante sans décision distincte |
| Données réelles sur Camoo | **Bloqué** | PHP 8.1 et HTTP documentés dans HANDOVER ; pas de donnée réelle avant runtime maintenu et HTTPS, tel que l'exige la constitution |

## Architecture visée (proposition non encore arbitrée)

- Le noyau charge les modules et leurs routes/définitions par conventions existantes.
- Services partagés (personnes, inscriptions, personnel, présence, messagerie, documents, évaluation, reporting, plateforme) sont réutilisés via contrats publics.
- Extensions du socle pressenties : service territorial versionné et filtres de périmètre, agrégats avec suppression des petits effectifs, workflow de validation statistique, extension du sync batch pour nouveaux types de saisie, remise effective à une personne autorisée, ledger APEE/dons isolé du parcours scolaire.
- Apps métier pressenties : Public Primary (référentiels validés, évaluations APC, conseils, examens/listes, exports) et Public Nursery (observations, photos à consentement, sortie/handover et transition). Le chemin et les clés de catalogue restent provisoires jusqu'à arbitrage 003/004.
- Les routes catalogue spécifiques seront décrites dans `contracts/openapi-public-schools.yaml` comme surface candidate ; elles ne constituent pas une autorisation de les créer immédiatement.

### Structure documentaire

```text
specs/004-applications-primaire-maternelle-publiques/
├── spec.md
├── research.md
├── plan.md
├── data-model.md
├── socle-reuse.md
├── quickstart.md
├── contracts/openapi-public-schools.yaml
├── tasks.md
└── wave-plan.md
```

### Structure de code candidate (après portes de décision)

```text
src/Modules/Socle/Organisation/      # contrat territorial et historique, si validé
src/Modules/Socle/Reporting/         # agrégats publics et protection des petits effectifs
src/Modules/Socle/Attendance/        # extensions offline/handover portées par le propriétaire
src/Modules/Apps/PublicPrimary/      # proposition de nom, référentiel, APC, passage, examens
src/Modules/Apps/PublicNursery/      # proposition de nom, développement, pickup/transition
web/src/apps/public-primary/         # si l'arbitrage produit confirme une app dédiée
web/src/apps/public-nursery/
tests/Architecture/ tests/Isolation/ tests/Feature/ tests/Unit/ tests/E2E/
```

**Note de compatibilité** : les dossiers `src/Modules/Apps/Primary` et `Nursery` existent comme squelettes selon HANDOVER ; ne pas les réaffecter à l'usage public sans arbitrage explicite avec la feature 003.

## Phases de planification/livraison

### Phase 0 — Décisions métier et environnement (bloquante pour les données réelles)

Confirmer dépôt/branche, commanditaire/tenant, relation public/privé, rôles territoriaux, appareil/identité offline, HTTPS/runtime maintenu, périmètre du pilote. Collecter et versionner les sources officielles reçues. Aucune valeur prétendument officielle n'est encodée ici.

### Phase 1 — Contrats et fondations communes

Concevoir et tester contrats territoriaux, lignée/historique des périmètres, politique d'agrégation et suppression petits effectifs, workflow d'approbation statistique, compatibilité des lots PWA/idempotence, suivi des remises maternelles, audit de consultation nominative. Ajouter les tests d'architecture/tenant avant les stories métier.

### Phase 2 — Pilote école : registre, élèves, personnel, présence

Données minimales de l'école et snapshots d'infrastructures ; gestion des élèves sans acte comme dossier incomplet mais admissible ; classes jumelées/double flux ; présence élèves et enseignants hors ligne ; notifications selon consentement. MVP d'usage école, sans bulletin officiel tant que les sources ne sont pas approuvées.

### Phase 3 — Protection maternelle et famille

Droits et restrictions de garde minimisés, personnes autorisées, contrôle de remise et journal hors ligne, portail parent et consentements photo. La sécurité physique ne peut pas dépendre seulement d'une synchronisation distante.

### Phase 4 — Évaluation, conseil, bulletins et transition

Import contrôlé des référentiels, règles versionnées, observations/critères, remédiation, conseil, publication/rectificatifs et passage. **Gate** : tests chiffrés seulement après validation officielle et modèles de bulletin fournis.

### Phase 5 — Supervision, statistiques, APEE, inspection et examens

Questionnaires avec contrôles et validation en cascade, exports officiels, visites, APEE/dons séparés des frais scolaires, préparation de listes d'examens et import de résultats. L'outil ne délivre pas les certificats officiels.

### Phase 6 — Qualité, pilote et montée d'échelle

Tests API/E2E/isolation/sécurité, recette MySQL sur base dédiée, charge correspondant à l'environnement réel, audit de confidentialité et pilote synthétique puis réel seulement après levée du gate HTTPS/PHP. Étude d'architecture nationale séparée de la mise en production Camoo.

## Validation

Les scénarios exécutables sont décrits dans [quickstart.md](quickstart.md). Les objets métier et leurs propriétaires figurent dans [data-model.md](data-model.md), la couverture observée du socle dans [socle-reuse.md](socle-reuse.md), les routes proposées dans `contracts/`. Les tâches réparties par histoire se trouvent dans [tasks.md](tasks.md), l'ordonnancement d'agents dans [wave-plan.md](wave-plan.md).

## Complexité

| Écart | Justification | Alternative rejetée |
|---|---|---|
| Périmètre national au-dessus des établissements | exigences de supervision MINEDUB ; à construire dans le socle | requêtes directes multi-établissements dans chaque app, qui violent le principe socle d'abord |
| Double app publique distincte | évite de confondre frais privés et règles publiques | fusion silencieuse avec Primary 003 ; décision finale encore à confirmer |
| PWA batch durable | connectivité intermittente des écoles et stack web existante | nouvelle application native avant preuve de besoin et validation sécurité |
