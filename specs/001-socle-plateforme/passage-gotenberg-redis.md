# Passage Dompdf vers Gotenberg et files vers Redis (T145)

**Non fait, préparé.** Les contraintes actuelles (hébergement mutualisé, ni Redis ni SSH, MySQL joignable seulement depuis le serveur) imposent Dompdf et des files en base ; ce document décrit le jour où une infrastructure le permettra.

## Interfaces déjà en place
| Besoin | Interface | Implémentation actuelle | Remplaçant |
|---|---|---|---|
| Rendu PDF | `Integrations\Contracts\PdfRenderer::render(string $html): string` | `Documents\Infrastructure\DompdfRenderer`, `SimulatedPdfRenderer` (tests) | `GotenbergRenderer` : POST HTML multipart vers `/forms/chromium/convert/html` du service Gotenberg |
| Choix du moteur | `config/documents.php` (`PDF_RENDERER`) et liaison dans `Documents/definitions.php` | `dompdf` / `simulated` | ajouter `gotenberg` |
| File de lots de documents | table `document_jobs` + `documents:process-jobs` (cron chaque minute) | MySQL | file Redis + processus permanent |
| Relances / messages | table `message_deliveries` + `queue:send` | MySQL | idem |
| Limitation de débit | `Core\RateLimit\RateLimiter` (table `rate_limits`) | MySQL | compteurs Redis (`INCR` + `EXPIRE`) |
| Idempotence | `Core\Idempotency\IdempotencyStore` (table `idempotency_keys`) | MySQL | Redis `SET NX EX` |

## Étapes
1. **Gotenberg** : déployer le conteneur (réseau privé, aucun accès public), URL et délai dans `.env` (`GOTENBERG_URL`) ; écrire `GotenbergRenderer` (HTTP, délai 30 s, taille maximale, erreurs mappées sur l'exception existante de rendu) ; test unitaire avec transport simulé comme pour `SungkuClient` ; mesurer la tenue de l'objectif « 1 000 PDF en lot en moins de 10 minutes » (voir [qualite-et-charge.md](qualite-et-charge.md)).
2. **Redis** : choisir un client (extension `redis` ou bibliothèque) et l'ajouter à `composer.json` ; implémenter `RedisRateLimiter` et `RedisIdempotencyStore` derrière les mêmes classes/contrats (aujourd'hui des classes concrètes du noyau : extraire d'abord une interface).
3. **Files** : remplacer les commandes cron par un processus de travail (superviseur) qui dépile `document_jobs` / `message_deliveries` ; conserver les tables comme journal d'état. Reprise des tâches abandonnées (`stale_after`) déjà codée.
4. Bascule par variable d'environnement, avec retour immédiat aux implémentations base de données.

## Prérequis humains
Hébergement capable d'exécuter Docker ou deux services (Gotenberg, Redis) ; accès SSH ; supervision des processus ; budget mémoire (Chromium dans Gotenberg).
