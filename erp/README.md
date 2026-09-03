# SCHOLARIS ERP — PHP 8.1, sans framework

ERP scolaire multi-etablissement. Ecrit en PHP 8.1 strict, **sans aucune
dependance externe** : ni Composer, ni Node, ni etape de compilation. Le
deploiement est une copie de fichiers.

## Pourquoi sans framework

L'hebergement cible ne fournit que **PHP 8.1**. Laravel 12 exige 8.2, et les
branches 10 et 11 — seules compatibles 8.1 — sont refusees par Composer, toutes
leurs versions portant des avis de securite ouverts. Aucune version maintenue de
Laravel n'etant installable, l'application se passe de framework.

Voir [DEPLOIEMENT.md](DEPLOIEMENT.md) pour le detail de ce qui a ete constate
sur le serveur.

## Ce qui remplace le framework

Un framework apporte des garanties par defaut. Ici, elles sont portees par
l'architecture, pour ne dependre de la vigilance de personne :

| Risque | Ou il est traite |
|---|---|
| Fuite entre etablissements | `Database\Table` ajoute `tenant_id = ?` a **toute** requete et refuse de s'executer sans etablissement courant. Le defaut est le refus. |
| Injection SQL | Requetes preparees exclusivement, emulation PDO desactivee. Les identifiants, non liables, sont valides contre `^[a-z_][a-z0-9_]*$`. |
| CSRF | `Application` verifie le jeton sur toute requete non-GET, avant le controleur. |
| XSS | `View::e()` echappe ; le HTML brut passe par `raw()`, volontairement voyant. |
| Habilitations oubliees | La permission est declaree **avec la route** dans `routes.php`, pas dans le corps des actions. |
| IDOR | `findOrFail()` passe par le filtre d'etablissement : l'id d'une ligne d'une autre ecole donne 404. |
| Ecriture de masse | `UPDATE` et `DELETE` sans condition sont refuses. |

## Developpement local

Prerequis : **PHP 8.1** (la meme version que la production — c'est deliberé) avec
`pdo_sqlite` et `mbstring`.

```bash
cp .env.example .env
php artisan migrate
php artisan seed
php -S 127.0.0.1:8000 -t public
```

### Comptes de demonstration

| Role | Email | Mot de passe |
|---|---|---|
| Super Admin | `admin@scholaris.dev` | `ChangeMe123!` |
| Les 11 roles metier | `<role>@demo.scholaris.cm` | `Test123!` |

Exemples : `directeur@demo.scholaris.cm`, `secretaire@demo.scholaris.cm`.
Le Directeur n'a que `students:read` : c'est la Secretaire qui cree les
dossiers, conformement a la matrice d'origine.

## Tests

```bash
php tests/run.php
```

30 tests, 51 assertions, sans dependance. Ils tournent contre le schema SQL
reel, sur une base SQLite en memoire. Ils couvrent en priorite les invariants
de securite : isolation entre etablissements, anti-IDOR, CSRF, verrouillage de
compte, habilitations, echappement des sorties.

## Commandes

```bash
php artisan migrate   # applique les migrations non jouees
php artisan seed      # referentiel RBAC + etablissement de demonstration
php artisan status    # etat de la base
php artisan fresh     # remet a zero (refuse si APP_ENV=production)
```

L'executable s'appelle `artisan` parce que le shell CSHIELD de l'hebergement
n'autorise pas `php <fichier>` mais reconnait `artisan <args>`.

## Organisation

```
public/          racine web : point d'entree unique et actifs statiques
src/
  Application.php      assemblage, CSRF, authentification, permissions
  Database/Table.php   constructeur de requetes filtre par etablissement
  Tenant/              etablissement courant de la requete
  Auth/                authentification et RBAC
  Security/            session et CSRF
  Http/                requete, reponse, routeur
  View/                moteur de gabarits echappant par defaut
  Controller/          actions
templates/       gabarits PHP
database/
  migrations/    9 fichiers SQL portables (MySQL 8 et SQLite)
  rbac-matrix.php  122 permissions et 12 roles, extraits du seed d'origine
tests/           30 tests, lanceur maison
routes.php       routes et permission exigee par route
artisan          commandes en ligne de commande
```

### Schema

63 tables, portees du schema Prisma d'origine. SQL volontairement portable :
MySQL 8 en production, SQLite pour les tests, le migrateur ajoutant seul les
options InnoDB. Les enums sont des `VARCHAR` valides par `Validator`, et les
colonnes `order` / `rank` ainsi que la table `groups` sont renommees
(`sort_order`, `rank_position`, `class_groups`) : ce sont des mots reserves de
MySQL 8, et les eviter dispense d'echapper le moindre identifiant.

## Avancement

| Brique | Etat |
|---|---|
| Socle : routage, securite, isolation, RBAC | fait, teste |
| Schema : 63 tables | fait |
| Connexion, verrouillage, audit | fait, teste |
| Tableau de bord | fait |
| Module 4 : eleves | fait, teste |
| Les 29 autres modules metier | a faire |
