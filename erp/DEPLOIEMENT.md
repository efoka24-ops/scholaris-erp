# Deploiement sur Camoo

Cible : `http://scholaris-erp.trugroup.cm`, servi depuis
`/home/trugro9159/scholaris-erp`.

## Ce qui a ete constate sur ce serveur

Trois contraintes decouvertes au premier deploiement, qui expliquent la plupart
des choix techniques du projet :

**PHP 8.1.34 uniquement.** Les handlers `ea-php82` a `ea-php84` ont ete testes
un a un dans le `.htaccess` : la version rapportee ne change pas. Le binaire est
`/usr/bin/php8.1`, chemin Debian et non `/opt/cpanel/ea-php*/`, ce qui indique
une machine ou une seule version est installee. PHP 8.1 est en fin de vie depuis
decembre 2025 : demander PHP 8.2 ou plus au support Camoo reste souhaitable, et
permettrait de revenir a un framework maintenu.

**Pas d'acces SSH.** Le compte `ssh_trugro9159` s'authentifie, mais le serveur
refuse le canal `exec`, le shell interactif et le sous-systeme `sftp`. Les
transferts passent donc par FTP.

**AllowOverride restreint.** Les directives `Options`, `FilesMatch` et `Require`
dans un `.htaccess` provoquent une **erreur 500 sur tout le site**, fichiers
statiques compris. C'est le cas du `.htaccess` livre par defaut avec Laravel
(`Options -MultiViews -Indexes`). Les deux `.htaccess` du projet n'emploient
donc que `mod_rewrite`.

**Le shell CSHIELD** (console web cPanel) n'autorise que certaines commandes :
`artisan`, `composer`, `git`, `ls`, `cd`, `cat`, `rm`, `unzip`... mais pas
`php <fichier>`. D'ou le nom `artisan` donne a l'executable en ligne de commande.

## Mise en service

### 1. Base de donnees

Deja provisionnee : `trugro9159_scholariserp`, utilisateur `trugro9159_norep`,
hote `localhost`.

### 2. Fichier `.env`

Non versionne, depose une fois par FTP a la racine du projet. Voir
`.env.example` pour la liste des cles ; en production :

```dotenv
APP_ENV=production
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=trugro9159_scholariserp
DB_USERNAME=trugro9159_norep
DB_PASSWORD=...
SESSION_SECURE=0
```

Passer `SESSION_SECURE=1` des que le certificat HTTPS du sous-domaine est
delivre : le cookie de session cesse alors de circuler en clair.

### 3. Migrations et donnees initiales

Dans le terminal CSHIELD :

```
cd /home/trugro9159/scholaris-erp
artisan migrate
artisan seed
artisan status
```

`seed` cree le Super Admin et l'etablissement de demonstration. **Changer le
mot de passe du Super Admin des la premiere connexion** et supprimer les comptes
de demonstration avant toute mise en service reelle.

### 4. Racine du sous-domaine

Le `.htaccess` a la racine du projet redirige vers `public/` et refuse l'acces
a `src/`, `templates/`, `database/`, `storage/`, `tests/`, `.env` et `artisan`.
Verifie en ligne : ces chemins repondent 403, `/.env` repond 404.

Pointer la racine du sous-domaine sur `/home/trugro9159/scholaris-erp/public`
reste preferable : le code se retrouve alors hors d'atteinte du serveur web, au
lieu de dependre de regles de refus.

## Deploiements suivants

L'application ne compte que 57 fichiers et aucune dependance : un envoi FTP
complet prend moins de deux minutes. Apres un deploiement ajoutant des
migrations :

```
cd /home/trugro9159/scholaris-erp
artisan migrate
```

## Reliquats de la tentative Laravel

Le dossier a d'abord recu une application Laravel, abandonnee faute de PHP 8.2.
Ses fichiers sont inertes (l'application PHP pure ne les charge jamais) mais
occupent de la place et pretent a confusion. A supprimer depuis CSHIELD :

```
cd /home/trugro9159/scholaris-erp
rm -rf vendor app bootstrap config resources routes
rm -f composer.json composer.lock package.json phpunit.xml vite.config.js scholaris.zip
rm -f database/migrations/*.php database/factories -r database/seeders -r
```

Attention a ne pas supprimer `routes.php` (fichier, garde) en visant `routes/`
(dossier Laravel, a supprimer), ni `database/migrations/*.sql` (gardes) en
visant `database/migrations/*.php`.
