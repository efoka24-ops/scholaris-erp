# SCHOLARIS Constitution

Plateforme éducative à interface unique et applications spécialisées (Maternelle, Primaire, Secondaire, Supérieur/IPES) construites sur un socle commun. Source : `CDC_Plateforme_SCHOLARIS.pdf` v1.0 du 8 octobre 2026.

## Core Principles

### I. Socle d'abord, applications ensuite (NON-NEGOTIABLE)
Tout service commun (comptes, organisation, personnes, frais, communication, documents, présences, évaluation, personnel, audit, intégrations) est développé **une seule fois** dans `src/Modules/Socle/*`. Un module `src/Modules/Apps/*` n'accède aux données que par les **interfaces publiques** (`Contracts`) du socle et ses propres tables, jamais par les classes internes d'un autre module. Cette règle est vérifiée par un test d'architecture automatique (tests d'architecture PHPUnit ou Deptrac) qui fait échouer l'intégration continue.

### II. Cloisonnement des données (NON-NEGOTIABLE)
Toute table métier porte `tenant_id` ; les tables rattachées à un établissement portent aussi `establishment_id`. Un filtre tenant obligatoire dans les dépôts (`TenantContext`, refus de toute requête sans contexte) est appliqué automatiquement ; l'établissement actif vient de l'en-tête `X-Establishment-Id`, vérifié contre les rôles de l'utilisateur. Une suite de tests appelle chaque route avec un utilisateur d'un autre client et d'un autre établissement : toute réponse autre que 403/404 bloque la livraison. La sécurité au niveau des lignes PostgreSQL s'ajoute en seconde barrière dès le passage à PostgreSQL.

### III. Test-first sur les règles métier et la sécurité
Les calculs (moyennes, crédits, compensation, affectation des paiements, numérotation des reçus), les autorisations et l'isolation des tenants sont écrits **test d'abord** (rouge, vert, refactor). Un module sans test d'autorisation et d'isolation n'est pas livrable. PHPStan au niveau maximal visé ; `composer audit` à chaque intégration.

### IV. API REST versionnée, contrat explicite
Toutes les fonctions passent par `/api/v1`. Entrées validées par une classe `Request` par cas d'usage, sorties par une classe `Resource` à liste blanche de champs, autorisation par une classe `Policy` (refus par défaut), erreurs au format RFC 9457 (Problem Details) avec `trace_id`, pagination par curseur (50 par défaut, 200 max), filtres et tris en liste blanche, `Idempotency-Key` obligatoire sur paiements, encaissements et documents officiels. Contrat OpenAPI 3.1 maintenu dans `specs/` et vérifié par des tests de contrat. Une version majeure n'est retirée qu'après 12 mois de préavis.

### V. Paramétrage avant développement
Frais, barèmes, calendriers, modèles de documents, règles de calcul, relances et blocages se règlent **sans programmer**, à trois niveaux (plateforme, client, établissement) avec surcharge visible et tracée. Les règles de calcul sont déclaratives et versionnées par année.

### VI. Traçabilité et conformité par conception
Journal d'audit inaltérable (chaînage par empreinte) : qui, quoi, quand, d'où, avant/après. Double validation pour les opérations sensibles (correction de note verrouillée, annulation de paiement, modification de barème). Consentements horodatés et révocables, export et suppression des données d'une personne, réversibilité (export complet d'un client). Hébergement conforme à la loi 2024/017 (Cameroun).

### VII. Simplicité d'exploitation
Monolithe modulaire, un seul déploiement. Pas de microservice, pas d'abstraction sans besoin présent. Toute exception (voir « Contraintes d'environnement ») est justifiée dans le tableau « Complexity Tracking » du plan.

## Contraintes techniques

- **Langage et architecture** : PHP 8.1 en recette (seule version offerte par l'hébergeur Camoo), code compatible PHP 8.4 (CI sur 8.1 et 8.4), **sans framework applicatif**, standards PSR-1, 3, 4, 7, 11, 12, 15, 17 (voir ADR-0001). Laravel du CDC est abandonné faute de version maintenue installable sur PHP 8.1.
- **Base de données** : cible CDC = PostgreSQL 16 ; **phase recette actuelle = MySQL 8.0** (hébergement Camoo). Le code reste portable : pas de SQL propre à un moteur hors de migrations isolées et signalées.
- **Montants** : entiers en XAF, sans décimales. **Dates** : ISO 8601, fuseau `Africa/Douala`. **Langues** : français et anglais partout (`Accept-Language`).
- **Mots de passe** : 10 caractères minimum, Argon2id, contrôle des fuites ; OTP 6 chiffres, 5 minutes, 3 essais ; verrouillage après 5 échecs ; 2FA obligatoire pour direction, finances, notes, administration.
- **Secrets** : jamais dans le dépôt Git. `.env` ignoré, `.env.example` sans valeur réelle ; en production, coffre de secrets.
- **Fichiers déposés** : types et tailles contrôlés, hors racine web, accès par URL signée temporaire.
- **Qualité** : PHPStan, PHP-CS-Fixer, PHPUnit, revue par un pair, OWASP ASVS niveau 2 et API Top 10 (2023).
- **Performance** : 95 % des appels API < 300 ms hors génération de documents ; 1 000 bulletins en < 10 min ; dimensionnement initial 200 établissements, 150 000 apprenants.
- **Hors-ligne** : appel, cahier de textes, saisie de notes, encaissements utilisables 3 jours sans réseau (PWA, synchronisation idempotente).

## Contraintes d'environnement (recette Camoo)

PHP 8.1.34 uniquement ; aucun accès SSH (FTP et terminal web restreint) ; `.htaccess` limité à `mod_rewrite` ; pas de Redis ni Docker ; MySQL 8.0. Conséquences : files sur la table `jobs` avec worker lancé par cron ; PDF via DomPDF ; déploiement par FTP du dossier compilé (`vendor/` inclus) ; console nommée `artisan` (nom imposé par le terminal de l'hébergeur). **PHP 8.1 n'est plus maintenu** : aucune donnée réelle en production tant qu'une version PHP maintenue (8.3 ou plus) n'est pas disponible. La production visée (Docker, Redis, PostgreSQL, Gotenberg, Cameroun) reste celle du CDC.

## Workflow de développement

1. Spécification (`/speckit-specify`) → plan → tâches → analyse de cohérence → implémentation par histoire d'utilisateur, MVP d'abord.
2. Une tâche = un commit ; messages en français, format `type(module): sujet`.
3. Les migrations vivent dans le module propriétaire ; aucune lecture croisée de tables entre applications.
4. Aucune fonctionnalité fusionnée sans : tests verts, PHPStan vert, style PSR-12 conforme, test d'isolation du tenant pour toute nouvelle ressource.
5. Identifiants de règles : préfixer `S-` pour celles du CDC plateforme et `H-` pour celles du CDC IPES dans le code et les tests (par exemple `S-RG-ORG-3`, `H-RG-ORG-3`), car les deux documents réutilisent les mêmes numéros.
6. L'ancien code NestJS/Next.js n'est pas migré ligne à ligne : seuls les choix fonctionnels (rôles, périmètres, cloisonnement) sont repris.

## Governance

Cette constitution prime sur les autres pratiques. Toute modification est documentée (motif, impact, plan de migration) et incrémente la version. Le CDC reste la source de vérité fonctionnelle ; un écart (ex. MySQL au lieu de PostgreSQL) est consigné dans le plan et amende le CDC.

**Version**: 1.1.0 | **Ratified**: 2026-10-08 | **Last Amended**: 2026-10-08 (PHP 8.1 sans framework, ADR-0001)
