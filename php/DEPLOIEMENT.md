# Deploiement sur Camoo (cPanel mutualise)

Cible : `https://scholaris-erp.trugroup.cm`, servi depuis
`/home/trugro9159/scholaris-erp`.

## 0. Prealable de securite

Les identifiants FTP ne vivent **jamais** dans le depot. Ils sont stockes en
secrets GitHub (`Settings` > `Secrets and variables` > `Actions`) :

| Secret | Valeur |
|---|---|
| `CAMOO_FTP_SERVER` | l'hote FTP indique par Camoo (`ftp-XX.camoo.net`) |
| `CAMOO_FTP_USERNAME` | le compte FTP cPanel |
| `CAMOO_FTP_PASSWORD` | le mot de passe FTP |

Ces trois valeurs figurent dans cPanel > `Comptes FTP`. Elles ne sont
volontairement pas recopiees ici : ce fichier est versionne.

> Le mot de passe FTP a circule en clair pendant la mise en place de ce projet.
> Regenerez-le dans cPanel (`Comptes FTP` > `Changer le mot de passe`) avant la
> mise en production, puis mettez le secret GitHub a jour.

## 1. Racine du sous-domaine

Laravel n'expose que son dossier `public/` : tout le reste (code, `.env`,
dependances) doit rester **hors** de la racine web, sinon `.env` est
telechargeable.

Dans cPanel > `Domaines` > `scholaris-erp.trugroup.cm` > modifier la racine :

```
/home/trugro9159/scholaris-erp/public
```

Un `.htaccess` a la racine du projet sert de filet : si la racine du
sous-domaine reste sur `/home/trugro9159/scholaris-erp`, il redirige vers
`public/` et refuse l'acces a `app/`, `config/`, `database/`, `storage/`,
`vendor/` et `.env`. Le site fonctionne donc dans les deux cas, mais **pointer
la racine sur `public/` reste la configuration a privilegier** : elle place le
code hors d'atteinte du serveur web au lieu de compter sur des regles de refus.

## 2. Version de PHP

cPanel > `MultiPHP Manager` > selectionner **PHP 8.2** (minimum) pour
`scholaris-erp.trugroup.cm`. Extensions requises : `mbstring`, `pdo_mysql`,
`bcmath`, `zip`, `intl`, `gd`, `openssl`, `fileinfo`.

Si Camoo ne propose pas 8.2, le projet ne demarrera pas : signalez-le, il faudra
retrograder les dependances.

## 3. Base de donnees

cPanel > `Bases de donnees MySQL` (MySQL 8.0 sur cet hebergement) :

1. Creer la base, par exemple `trugro9159_scholariserp`.
2. Creer un utilisateur et lui donner **tous les privileges** sur cette base.
3. Noter le nom complet (prefixe compris) et le mot de passe.

Le schema vise MySQL 8 : les tableaux PostgreSQL du schema d'origine sont
devenus des colonnes JSON, et les enums restent des enums MySQL.

## 4. Fichier `.env` sur le serveur

Le `.env` n'est pas deploye par le pipeline : il est cree une fois a la main,
par FTP, dans `/home/trugro9159/scholaris-erp/.env`.

```dotenv
APP_NAME=SCHOLARIS
APP_ENV=production
APP_DEBUG=false
APP_KEY=
APP_URL=https://scholaris-erp.trugroup.cm

APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_TIMEZONE=Africa/Douala

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=trugro9159_scholariserp
DB_USERNAME=trugro9159_norep
DB_PASSWORD=le-mot-de-passe-mysql

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
LOG_LEVEL=error
```

`APP_KEY` se genere a l'etape suivante. `APP_DEBUG=false` est imperatif : a
`true`, la moindre erreur affiche la configuration complete, mots de passe
compris.

## 5. Premiere mise en service

Si cPanel offre un `Terminal` ou un acces SSH, depuis `~/scholaris-erp` :

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Sans terminal, passer par une tache cron cPanel executee une seule fois
(`Taches Cron` > commande ponctuelle, puis supprimer la tache) :

```
cd /home/trugro9159/scholaris-erp && /usr/local/bin/php artisan migrate --force && /usr/local/bin/php artisan db:seed --force
```

Le seed cree le Super Admin (`admin@scholaris.dev` / `ChangeMe123!`) et
l'etablissement de demonstration `DEMO` avec un compte par role
(mot de passe `Test123!`).

**Changez le mot de passe du Super Admin des la premiere connexion**, et
supprimez les comptes de demonstration avant toute mise en service reelle.

## 6. Droits sur les dossiers

`storage/` et `bootstrap/cache/` doivent etre inscriptibles par le serveur web
(permissions `755`, ou `775` si le site tourne sous un autre utilisateur) :

```bash
chmod -R 755 storage bootstrap/cache
```

## 7. Deploiements suivants

Chaque push sur `main` touchant `php/` declenche le workflow
`.github/workflows/deploy-php.yml` : il installe les dependances de production,
puis envoie les fichiers par FTP. Il ne touche ni `.env`, ni `storage/`, ni la
base.

Apres un deploiement qui ajoute des migrations, rejouer :

```bash
php artisan migrate --force && php artisan config:cache
```

## 8. Taches planifiees (optionnel)

Pour les rappels et les envois differes, ajouter une tache cron cPanel toutes
les minutes :

```
cd /home/trugro9159/scholaris-erp && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```
