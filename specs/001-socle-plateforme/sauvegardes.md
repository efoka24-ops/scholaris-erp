# Sauvegardes chiffrées (T143)

Commandes (`php artisan ...`) : `backup:keygen`, `backup:run [--keep=N] [--path=dossier]`, `backup:restore <fichier.sbk> [--force]`. Code : `Audit/Application/BackupService`, `EncryptedSink`, `EncryptedSource`.

## Ce que fait la sauvegarde
- Dump **logique en PHP pur** (pas de `mysqldump` sur le serveur) : lignes de toutes les tables, sauf `schema_migrations`, `rate_limits`, `idempotency_keys`.
- Lecture dans une transaction (instantané cohérent sous InnoDB), curseur non tamponné sous MySQL, lots de 500 lignes : mémoire indépendante de la taille des tables.
- Compression gzip (si zlib), puis **chiffrement authentifié par flux** (libsodium secretstream XChaCha20-Poly1305, blocs de 64 Kio). Clé fausse, bloc modifié, fichier tronqué ou bloc final retiré sont détectés.
- Fichier `storage/backups/scholaris-AAAAMMJJ-HHMMSS.sbk` (droits 0640), écrit en `.partial` puis renommé. Le dossier `storage/` est interdit au web par le `.htaccess` racine.
- Rétention : les `BACKUP_KEEP` (14) plus récentes sont gardées, les autres supprimées après une sauvegarde réussie.
- La **structure** n'est pas sauvegardée : elle vient des migrations.

## Mise en service (action humaine)
1. `php artisan backup:keygen` ; placer la clé dans `BACKUP_KEY` du `.env` du serveur ET dans un coffre hors serveur. Sans elle, les sauvegardes sont définitivement illisibles. Garder les anciennes clés après un changement.
2. Test : `php artisan backup:run`, puis télécharger le fichier par FTP.
3. **Copie hors serveur** : le FTP étant limité et sans SSH, télécharger régulièrement les fichiers (poste ou stockage externe) ; une sauvegarde qui ne quitte pas le serveur ne protège pas d'une perte du serveur.

## Planification (cron)
Une exécution quotidienne à 02:00 (heure du serveur, à adapter) :
```
0 2 * * * cd /chemin/du/projet && php artisan backup:run >> storage/logs/backup.log 2>&1
```
Autres tâches planifiées existantes : `reminders:send` (quotidien), `queue:send` (chaque minute), `documents:process-jobs` (chaque minute), `reports:run` (quotidien), `attendance:retry-absences` (horaire), `retention:run --apply` (selon la politique).

## Restauration
1. Base vide, puis `php artisan migrate`.
2. `php artisan backup:restore storage/backups/scholaris-....sbk --force` (`--force` est nécessaire car les migrations posent déjà des lignes de référence ; il vide les tables sauvegardées avant de les remplir). Une seule transaction : en cas d'erreur rien n'est modifié. Les nombres de lignes sont contrôlés.
3. `php artisan db:check` puis `php artisan audit:verify <client>`.
Ne jamais restaurer sur la production sans avoir isolé le site (les données plus récentes que la sauvegarde sont perdues).

## Test de restauration
`tests/Feature/Audit/BackupTest.php` (SQLite, deux bases) : sauvegarde puis restauration sur une base neuve avec comparaison table par table (accents, caractères à 4 octets, valeurs binaires), refus sur tables non vides, clé fausse, octet modifié, troncature, rétention, commandes. À refaire par un humain sur MySQL, avec un test de restauration périodique sur une base jetable (au moins une fois par trimestre).

## Limites
Pas de sauvegarde incrémentale ni de point dans le temps (RPO = 24 h) ; les fichiers téléversés (`storage/uploads`) ne sont **pas** inclus : à copier à part. Le quota d'espace de l'hébergement borne le nombre de sauvegardes conservées.
