# ADR-0001 : PHP 8.1 sans framework, architecture standard PSR

**Statut** : accepté le 2026-10-08 (choix de l'utilisateur, option 3)
**Remplace** : le choix « PHP 8.4 + Laravel 12/13 » des cahiers des charges (partie « Choix technologiques »)

## Contexte

L'hébergement de recette (Camoo, compte `trugro9159`) ne fournit que **PHP 8.1.34** : les gestionnaires PHP 8.2 à 8.4 ont été essayés sans effet (constat consigné dans le `DEPLOIEMENT.md` présent sur le serveur). Laravel 12 exige PHP 8.2. Les branches Laravel compatibles 8.1 (10 et 11) sont refusées par Composer en raison d'avis de sécurité ouverts. Une tentative Laravel précédente sur ce serveur a déjà été abandonnée pour cette raison.

Autres contraintes du serveur : pas d'accès SSH ; `.htaccess` limité à `mod_rewrite` (`Options`, `FilesMatch`, `Require` provoquent une erreur 500) ; terminal web ne lançant que `artisan`, `composer`, `git`, `unzip` ; pas de Redis ni Docker ; MySQL 8.0.

## Décision

1. Développer en **PHP 8.1**, la version de la recette, tout en gardant le code compatible 8.4 (la CI teste 8.1 et 8.4).
2. **Ne pas utiliser de framework applicatif.** S'appuyer sur les **standards PSR** et sur de petites bibliothèques maintenues qui les implémentent.
3. Conserver le style d'architecture du CDC : monolithe modulaire, API REST `/api/v1`, socle de 14 services, applications spécialisées qui n'utilisent que les `Contracts` du socle.
4. Garder MySQL 8 (PDO, requêtes préparées, émulation désactivée) ; migrations SQL versionnées par module.

## Standards suivis

| Standard | Mise en œuvre |
|----------|---------------|
| PSR-1, PSR-12 | `php-cs-fixer` (règle `@PSR12`, `declare(strict_types=1)` partout), vérifié en CI |
| PSR-4 | Espace de noms `Scholaris\` → `src/` |
| PSR-3 | Journalisation Monolog 3 |
| PSR-7, PSR-17 | `nyholm/psr7` (requêtes, réponses, fabriques) |
| PSR-11 | Conteneur `php-di/php-di` 7 (autowiring) |
| PSR-15 | Chaîne de middlewares `Pipeline` ; contrôleurs retournant des réponses PSR-7 |
| RFC 9457 | Erreurs `application/problem+json` avec `trace_id` (middleware `ErrorHandler`) |
| OpenAPI 3.1 | Document maintenu à la main dans `specs/…/contracts/` puis vérifié par test de contrat |
| Semver / Composer | `composer.lock` versionné, `composer audit` en CI |

## Équivalences avec les paquets Laravel du CDC

| CDC (Laravel) | Remplacement |
|---------------|--------------|
| Eloquent, portée globale `tenant_id` | Dépôts (`Infrastructure/*Repository`) qui reçoivent un `TenantContext` et ajoutent `tenant_id = ?` à toute requête ; refus si aucun contexte |
| FormRequest | Classe `Request` par cas d'usage avec règles de validation, lève `HttpException::validation` |
| Policy / Gate | Classes `Policy` par ressource, appelées par le contrôleur ; refus par défaut |
| JsonResource | Classe `Resource` par ressource, liste blanche de champs |
| Sanctum | Jetons opaques hachés (SHA-256), durée limitée, révocables, table `access_tokens` |
| Fortify (TOTP) | `spomky-labs/otphp` (TOTP RFC 6238) + codes de secours hachés |
| spatie/laravel-permission | Module `Identity` : `role_assignments` avec périmètre, `PermissionResolver` |
| spatie/laravel-activitylog + journal inaltérable | `AuditLogger` du module `Audit` (journal chaîné SHA-256) |
| Horizon, files Redis | Table `jobs` + commande `artisan queue:work` lancée par cron ; Redis/Horizon en production |
| Scramble | Contrat OpenAPI écrit et testé |
| Artisan, Tinker, migrations | Console maison `artisan` (nom imposé par l'hébergeur) ; migrations SQL ; pas de Tinker |
| Pest | PHPUnit 10.5 (essai : Pest 2 n'a pas pu être installé sur PHP 8.1 faute de combinaison de versions compatible avec PHPUnit ; Pest 3 et suivants exigent PHP 8.2 ou plus) |
| Larastan | PHPStan (niveau 6, relevé progressivement jusqu'à 10) |
| Pint | PHP-CS-Fixer (règles PSR-12) |
| gotenberg / Browsershot | Port `PdfRenderer` ; DomPDF en recette, Gotenberg en production |

## Conséquences

**Positives** : déploiement possible sur le serveur disponible ; peu de dépendances donc surface d'attaque réduite ; architecture explicite, testable et portable vers PHP 8.4 ; sécurité traitée par la conception (filtre tenant obligatoire, refus par défaut).

**Négatives et risques** :
- **PHP 8.1 n'est plus maintenu depuis le 31 décembre 2025** (aucun correctif de sécurité de la part de php.net). Risque accepté pour la recette seulement. Obligation : demander PHP 8.3 ou plus à Camoo, et ne pas mettre en production de données réelles sur PHP 8.1.
- Davantage de code d'infrastructure à écrire et à tester (authentification, files, migrations, validation) : la charge du socle augmente par rapport à l'estimation du CDC (682,5 jours-personne), à réévaluer en fin de phase 2.
- Les spécifications 001 et 002 emploient des termes Laravel (FormRequest, Policy, JsonResource, Eloquent) : lire ces termes selon le tableau d'équivalences ci-dessus.
- Écart au CDC consigné ; le CDC doit être amendé (tâche T147).

## Réversibilité

Les modules n'appellent que des interfaces (`Contracts`) et des classes PSR : une migration vers Laravel ou Symfony reste possible sans réécrire le domaine. Dès que PHP 8.3 ou plus est disponible, réévaluer Symfony 7.4 LTS ou Laravel 13 au regard du coût de maintien du code d'infrastructure.
