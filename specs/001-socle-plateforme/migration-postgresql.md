# Migration MySQL vers PostgreSQL (T144) : état et reste à faire

**Rien n'est migré.** Ce document établit ce qui est déjà portable (lecture des migrations et du code, avril-octobre 2026) et ce qui reste. Cible : jeu de migrations validé sur les deux moteurs, RLS, bascule documentée.

## Déjà portable
- Les migrations `database/migrations/*.sql` et `src/Modules/*/*/Database/Migrations/*.sql` sont écrites en SQL commun MySQL 8 / SQLite : ids `CHAR(26)`, dates en `VARCHAR(32)` ISO 8601, montants entiers XAF, pas d'`AUTO_INCREMENT`, ni `ENGINE`, ni clé étrangère, une colonne par `ALTER TABLE` (`tests/Architecture/MysqlCompatibilityTest`).
- Recensement des types : `MEDIUMTEXT` (4 colonnes, documents), `BIGINT`, `SMALLINT`, `INTEGER`, `VARCHAR`, `CHAR`, `TEXT`. Aucun `JSON` natif, `DATETIME`, `TINYINT`, `UNSIGNED`, `ON UPDATE`, `DOUBLE`, `ENUM`.
- Requêtes préparées partout (valeurs liées), pas de `ON DUPLICATE KEY`, `INSERT IGNORE`, `REPLACE`, `GROUP_CONCAT`, `IFNULL`. La détection d'unicité accepte les codes `23000` et `23505` (PostgreSQL).

## À faire
1. **`MEDIUMTEXT`** n'existe pas : remplacer par `TEXT` (PostgreSQL n'a pas de limite à ce niveau). Soit une conversion dans un jeu de migrations dédié, soit un pilote de migration qui traduit le type par moteur (`Migrator` exécute aujourd'hui le SQL tel quel).
2. **Guillemets d'identifiants** : le code PHP utilise surtout des identifiants nus ; `BackupService` et `tests/Mysql` emploient des apostrophes inverses (rejetées par PostgreSQL) : utiliser des guillemets doubles par moteur.
3. **DSN** : `ConnectionFactory` ne construit que `mysql:` et `sqlite:` (`pgsql:` à ajouter, jeu de caractères `client_encoding`). Extension `pdo_pgsql` à vérifier sur l'hébergement.
4. **Mots réservés** : la liste interdite de `MysqlCompatibilityTest` est propre à MySQL ; ajouter celle de PostgreSQL (`user`, `order`, `group`, `limit`, `table`, `desc`... : ici seules `users` et `order`-like sont évitées, à revérifier colonne par colonne).
5. **Sensibilité à la casse** : MySQL compare sans casse par défaut (`utf8mb4_unicode_ci`), PostgreSQL avec casse. Les recherches (`LIKE`, doublons de personnes, identifiants de connexion e-mail) supposent aujourd'hui l'insensibilité : prévoir `citext`, `lower()` ou `ILIKE`.
6. **Tri et collation** des noms accentués (collation `fr-x-icu`).
7. **`LIMIT ... OFFSET`** et `FOR UPDATE` : syntaxe compatible ; les verrous de numérotation (`SequenceService`) à retester sous PostgreSQL (niveau d'isolement par défaut différent).
8. **RLS** : ajouter une politique `tenant_id = current_setting('scholaris.tenant_id')` par table et poser le paramètre par requête dans `TenantContext` ; l'isolation est aujourd'hui assurée par `TenantTable` et les tests d'isolation.
9. **Données** : outil d'export/import (`backup:run` produit un format logique indépendant du moteur : la restauration sur PostgreSQL est un bon candidat après les points 1 à 3).
10. **Tests** : déclinaison de `phpunit.mysql.xml` en suite PostgreSQL (même principe de fabrique de connexions jetable).

## Bascule (proposée)
Gel des écritures, `backup:run`, migrations sur PostgreSQL vide, `backup:restore`, `db:check` adapté, `audit:verify` de chaque client, bascule de `DB_CONNECTION`, retour arrière possible tant que MySQL est conservé en lecture seule.
