# Qualité statique et charge (T140, T141)

## T140 : PHPStan
- Niveau atteint : **8** (`phpstan.neon`), 0 erreur, **sans baseline ni `@phpstan-ignore`** (analyse de `src`, `config`, `public`). Commande : `php vendor/bin/phpstan analyse --memory-limit=2G --no-progress`.
- Le CDC vise le niveau maximal (10). Le niveau 9 (interdit de passer du `mixed` à un paramètre typé) produit environ **1 690 erreurs**, presque toutes issues de lignes PDO et de tableaux de requêtes non typés : le corriger sans `@var` ni cast de complaisance demande de typer les dépôts un par un, ce qui n'est pas raisonnable en une passe. Niveau 10 non évalué. T140 reste donc **ouverte** (niveau 8 atteint sur 10).
- Corrections de la montée de 6 à 8 : `fetchAll` enveloppé dans `array_values` (listes), derniers éléments de page, lecture `PDO::query` vérifiée, options cURL, normalisation des listes de configuration du `Kernel`, types de retour inutiles.

## T141 : charge
**Aucun résultat k6 n'a été obtenu** (k6 n'est pas installé ici et il n'y a pas de recette accessible).
- `scripts/loadtest/k6-api.js` : scénario k6 (santé + routes authentifiées avec un jeton de recette), seuils `http_req_duration p(95)<300` ; commande en tête du fichier. À lancer par un humain contre la **recette**, jamais la production.
- Objectif « 1 000 PDF en lot en moins de 10 min » : non mesurable avec k6 ; à mesurer sur la recette en créant un lot de 1 000 documents puis en laissant tourner `documents:process-jobs` (cron chaque minute) ; Dompdf sur mutualisé est le facteur limitant (voir [passage-gotenberg-redis.md](passage-gotenberg-redis.md)).
- Mesure **locale en processus** (SQLite en mémoire, sans serveur web ni réseau) : `php scripts/loadtest/local-p95.php [requêtes] [lignes]` et garde-fou `tests/Feature/LoadSmokeTest.php`. Résultat obtenu ici (500 requêtes par scénario) : `/health` p95 8,9 ms ; route authentifiée cloisonnée de 500 lignes p95 10,4 ms. Ces valeurs ne disent rien de MySQL, d'Apache/PHP-FPM ni du réseau.
