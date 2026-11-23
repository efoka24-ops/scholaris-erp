# Tests sur une vraie base MySQL (suite jetable)

La suite normale (`phpunit.xml`) tourne sur SQLite en mémoire. La suite MySQL rejoue **les tests d'isolation et de contrat existants** sur MySQL 8, sans les modifier.

## Principe
`phpunit.mysql.xml` charge `tests/Mysql/bootstrap.php`, qui :
- **refuse de tourner** (code 2) sans `TEST_DB_DRIVER=mysql` ;
- substitue à `Scholaris\Core\Database\ConnectionFactory` la version de `tests/Mysql/Support/ConnectionFactory.php`, qui redirige toute connexion de test vers MySQL.

Au premier accès : toutes les tables de la base de test sont supprimées, les migrations sont rejouées, les lignes de référence (rôles, modèles) sont mémorisées. Avant chaque test suivant : tables vidées, lignes de référence restaurées.

## Garde-fous
- Le nom de la base doit contenir « test » (sinon `TEST_DB_ALLOW_ANY_NAME=yes`, déconseillé).
- Une base non vide qui n'a pas été créée par cette suite (table repère `scholaris_test_marker`) est **refusée, jamais effacée**.
- Aucun secret dans le dépôt : tout vient de l'environnement. N'utilisez jamais la base de production.

## Exécution
```bash
export TEST_DB_DRIVER=mysql TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306
export TEST_DB_DATABASE=scholaris_test TEST_DB_USERNAME=... TEST_DB_PASSWORD=...
php vendor/bin/phpunit -c phpunit.mysql.xml
```
PowerShell : `$env:TEST_DB_DRIVER='mysql'` etc. Créer au préalable une base vide `CREATE DATABASE scholaris_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` et un compte limité à cette base.

## Contenu
- `MysqlSchema` : MySQL 8, migrations toutes appliquées et rejouables sans effet, `db:check` vert (utf8mb4, InnoDB, écriture/lecture annulées), restauration des données de référence.
- `Isolation` : tout `tests/Isolation` (sauf `TenantScopeTest`, qui construit sa propre connexion).
- `Contracts` : `tests/Feature/Contracts`, `BillingMessagingContractTest`, exports de client, période en cours.

## État
Écrite et vérifiée ici uniquement pour son garde-fou (sans variable, la suite s'arrête avec un message) : **aucune base MySQL n'était joignable**, la suite n'a donc jamais tourné sur MySQL. Premier passage à faire par un humain ; les écarts éventuels (SQL propre à SQLite dans un test, par exemple) se corrigent test par test.
