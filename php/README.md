# SCHOLARIS ERP — version PHP / Laravel

Reecriture PHP de l'ERP scolaire SCHOLARIS, destinee a l'hebergement mutualise
cPanel (Camoo). Remplace la pile Node du depot (`apps/api` NestJS +
`apps/web` Next.js + PostgreSQL), qui exigeait un hebergement Node.

- **Framework** : Laravel 12 (PHP >= 8.2), vues Blade
- **Base** : MySQL en production, SQLite en developpement
- **Front** : Blade + CSS statique, **sans etape de build** — le deploiement
  reste un simple envoi de fichiers

## Etat d'avancement

| Brique | Etat |
|---|---|
| Schema : 63 tables portees de Prisma vers des migrations Laravel | fait |
| 60 modeles Eloquent avec relations | fait |
| Isolation multi-etablissement (scope global) | fait, teste |
| RBAC : 122 permissions, 12 roles | fait |
| Connexion, verrouillage apres echecs, journal d'audit | fait, teste |
| Tableau de bord (effectifs, finances) | fait |
| Les 30 modules metier (ecrans CRUD) | a faire |

Les 30 modules de la version Node sont portes ensuite, un par un, sur ce socle.

## Developpement local

Prerequis : PHP >= 8.2 et Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

L'application repond sur <http://localhost:8000>.

### Comptes de demonstration

| Role | Email | Mot de passe |
|---|---|---|
| Super Admin | `admin@scholaris.dev` | `ChangeMe123!` |
| Les 11 roles metier | `<role>@demo.scholaris.cm` | `Test123!` |

Exemples : `directeur@demo.scholaris.cm`, `enseignant@demo.scholaris.cm`,
`intendant@demo.scholaris.cm`.

## Tests

```bash
php artisan test
```

Couvrent la connexion (echecs, verrouillage, email partage entre etablissements)
et l'isolation multi-etablissement, y compris l'anti-IDOR : connaitre
l'identifiant d'un eleve d'un autre etablissement ne permet pas de le lire.

## Organisation

```
app/
  Models/            60 modeles Eloquent
    Concerns/        BelongsToTenant : scope global par etablissement
  Http/
    Controllers/     connexion, tableau de bord
    Middleware/      ResolveTenant, EnsurePermission
  Support/           TenantContext : etablissement courant de la requete
database/
  migrations/        9 migrations, une par domaine metier
  seeders/           RbacSeeder (permissions + roles), DemoTenantSeeder
  rbac-matrix.php    matrice RBAC, extraite du seed de la version Node
```

### Deux points a connaitre

- **`GuardianParent`** est le modele de la table `parents` : `Parent` est un mot
  reserve de PHP et ne peut pas nommer une classe.
- **Le scoping est porte par le modele**, pas par les controleurs : le trait
  `BelongsToTenant` filtre toute requete sur `tenant_id` et renseigne la colonne
  a la creation. Un controleur ne peut donc pas oublier le filtre. Le
  contournement est explicite et delimite : `TenantContext::global()`.

## Deploiement

Voir [DEPLOIEMENT.md](DEPLOIEMENT.md).
