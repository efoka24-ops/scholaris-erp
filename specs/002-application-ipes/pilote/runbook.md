# Pilote IPES : runbook de mise en service

Public : l'équipe SCHOLARIS (opérations sur Camoo) et l'administrateur de chaque institut. Deux clients distincts, **un client = un IPES** :

1. Institut Supérieur du Septentrion de Garoua (ISSEG) ;
2. Institut de Formation Professionnelle les Étoiles du Septentrion (IFPES).

Les deux font partie du Complexe de Formation les Étoiles du Septentrion (quartier Ouro Mal-Amadou, Garoua). Le site est en HTTPS, l'e-mail SMTP est actif, les SMS partent par la passerelle TeeeOTP (quota de 500 par jour : prévenir TRU GROUP avant un envoi de masse ; vérifier la réception sur un numéro de test avant le pilote).

Règles de sécurité de ce runbook : aucun mot de passe, clé ou identifiant de connexion n'est écrit dans le dépôt ni dans ce document ; les noms et téléphones de personnes réelles non plus. Les identifiants sont échangés hors dépôt et changés après la première connexion. Les opérations sur Camoo (migrate, création des clients, import réel) sont faites par l'équipe, jamais par un assistant automatique.

## 0. Prérequis (avant le premier institut)

| Contrôle | Commande ou action | Attendu |
|---|---|---|
| Version déployée à jour | FTP puis `php artisan migrate` | « Appliquée : … » ou « Rien à migrer » |
| Base en bon état | `php artisan db:check` | tous les contrôles verts (migrations, schéma, utf8mb4, écriture/lecture annulées) |
| Statut du serveur | `php artisan status` | extensions ok, base ok |
| HTTPS actif | ouvrir le site | cadenas valide ; `/api/v1/health` répond |
| Clé de sauvegarde | `php artisan backup:keygen` (une seule fois), clé dans `BACKUP_KEY` du `.env` du serveur ET dans un coffre hors serveur | sans cette clé, les sauvegardes sont illisibles |
| SMTP | envoyer un message de test depuis l'écran Messagerie | reçu |
| Permission de reprise | voir §8 « Permission `higher.migration.manage` » | accordée à la direction d'école et à la scolarité |

## 1. Création du client et de l'établissement (par institut)

1. L'institut dépose une **demande d'accès** publique pour l'application « Supérieur » (`higher`) ; l'administrateur de plateforme l'accepte (2FA obligatoire pour lui). Le client démarre en **essai de 90 jours**.
2. Création du premier administrateur du client : `php artisan admin:create "<client>" "<prénom>" "<nom>" "<téléphone ou e-mail>"`. Le mot de passe affiché une seule fois est transmis hors dépôt et changé à la première connexion ; la double authentification est exigée.
3. Création de l'établissement de type `higher` si l'activation ne l'a pas créé : `php artisan establishment:create <client> "<nom de l'institut>" higher`.
4. Relever l'**identifiant du client** (utilisé par les commandes) et l'identifiant de l'établissement : `php artisan list` ne les affiche pas ; ils figurent dans l'écran Plateforme > Clients.
5. Se connecter avec l'administrateur du client et vérifier : « Mes applications » montre l'IPES en essai ; le client n'accède pas à `/platform`.

## 2. Gabarit de démarrage (`higher:pilot-setup`)

Après la création du client, l'équipe installe un squelette réutilisable. La commande est **idempotente** et ne crée **aucune autorisation MINESUP** : les filières restent **fermées et non inscriptibles** tant qu'une autorisation réelle n'est pas saisie.

```
php artisan higher:pilot-setup <id du client> <modèle> [--establishment=<id>] [--year=<id de l'année>] [--dry-run]
```

| Modèle | Contenu (gabarit à confirmer par l'établissement) |
|---|---|
| `sante` | école « santé et paramédical », département, filières : BTS Études médico-sanitaires (odontostomatologie ; sage-femme), BTS Sciences et techniques biomédicales (techniques pharmaceutiques), délégué médical, vendeur en pharmacie, auxiliaire de vie sociale, aide-soignant |
| `gestion` | école « gestion et administration », département, filières : BTS Gestion (assistant manager), BTS Gestion des collectivités territoriales, GRH, administration des collectivités territoriales décentralisées |
| `complet` | les deux écoles ci-dessus |

Ce que la commande crée : écoles internes et départements ; filières au statut **fermé**, nom suffixé « [à confirmer] » ; niveaux (BTS 1 et BTS 2, ou Niveau 1) ; un groupe G1 par niveau ; un profil documentaire par école (en-tête, logo et couleurs à compléter) ; avec `--year`, une **grille de frais vide en brouillon** par école.

Mode d'emploi :

1. Toujours commencer par `--dry-run` : le compte rendu liste ce qui serait créé, rien n'est écrit.
2. Lancer sans `--dry-run` ; relancer ne crée rien de plus (« existe déjà, conservé »). Si le client a plusieurs IPES, ajouter `--establishment=<id>`.
3. Comparer le résultat à la réalité : régime, durée, niveaux et intitulés de chaque filière sont à **confirmer ou corriger** par l'établissement (écran Paramétrage académique). Le gabarit n'a de valeur que de point de départ : régime BTS pour les BTS, « certificat » par défaut pour les autres, sans tutelle universitaire.
4. Ne rouvrir une filière (statut actif) qu'après saisie de l'**arrêté d'autorisation MINESUP réel** et de sa période de validité (voir `donnees-a-fournir.md`).

## 3. Paramétrage dans l'ordre

Réalisé par l'administrateur de l'institut avec la direction (écrans « Paramétrage académique », « Maquettes », « Frais et paiements », « Documents officiels »). Ne pas passer à l'étape suivante avant d'avoir validé la précédente.

| # | Étape | Où | Contrôle de sortie |
|---|---|---|---|
| 1 | Année académique et semestres | Administration > Années | l'année en cours est active |
| 2 | Écoles internes | Paramétrage académique | une école par pôle, code court (sert au matricule et aux numéros de documents) |
| 3 | Départements | idem | rattachés à leur école |
| 4 | Filières (régime, langue, mode, durée) | idem | régime jamais modifiable ensuite (RG-ORG-7) : se tromper = créer une nouvelle filière |
| 5 | Niveaux et groupes | idem | niveaux 1 à n, groupe(s) avec capacité |
| 6 | **Autorisations MINESUP** (arrêté, date, validité, niveaux couverts) | Autorisations | l'écran d'éligibilité ne signale plus de refus pour la filière ; alertes d'expiration à 90, 30 et 7 jours |
| 7 | **Tutelles** (filières LMD seulement : université, convention, échéance) | Tutelles | convention non expirée |
| 8 | **Règlement des études** (barèmes, grille de lettres, seuils) puis gel | Règlements | figé à la première inscription (RG-ACA-3) ; modifié ensuite seulement par le directeur académique avec 2FA |
| 9 | Calendrier (inscriptions, évaluations, délibérations) | Calendrier | dates cohérentes |
| 10 | **Maquettes** par filière et niveau (UE, EC, crédits ou coefficients, volumes horaires) saisies par le directeur d'école, **publiées** par le directeur académique | Maquettes | 30 crédits par semestre en LMD (RG-MAQ-2) ; BTS : chaque matière rattachée à une épreuve de l'examen national |
| 11 | **Grilles de frais** (dossier, inscription, pension, examen national, carte, assurance) et échéancier par défaut | Frais et paiements > Grilles | une grille active par école, filière, niveau et mode |
| 12 | Profils documentaires (en-tête, logo, couleurs, langue) | Documents officiels | un profil par école |
| 13 | Paramètres de l'établissement (paiement mobile, relances, blocages) | Administration | blocages jamais appliqués aux cours ni aux compositions |
| 14 | **Comptes du personnel** et rôles | Administration > Utilisateurs | voir tableau ci-dessous |

Rôles à attribuer (périmètre école, département, filière ou groupe selon le poste) : administrateur fonctionnel, directeur général, directeur académique, directeur d'école, chef de département, responsable de filière, service de la scolarité, service des examens, caissier, comptable, enseignants (permanents et vacataires), responsable des stages, jury. Un compte par personne, jamais partagé ; la **2FA** est exigée pour les rôles sensibles (direction, comptabilité).

Les fiches du personnel (module Personnel) doivent exister **avant** la reprise du personnel enseignant.

## 4. Reprise de données

Principe : **limitée à l'année en cours et aux UE capitalisées** ; pas de reprise d'années antérieures (les anciens relevés restent sur papier ou numérisés à part). Cinq jeux, dans cet ordre :

1. étudiants en cours et inscription (CSV ou XLSX) ;
2. UE capitalisées et dettes ;
3. soldes d'ouverture et échéanciers ;
4. personnel enseignant et affectations ;
5. notes de l'année en cours (verrouillées, motif « reprise »).

Procédure pour chaque jeu (API `/api/v1/higher/migration` appelée par l'équipe SCHOLARIS ; pas d'écran dans cette version ; permission `higher.migration.manage`) :

1. Télécharger le **modèle** (`GET /higher/migration/templates/{students|credits|balances|staff|marks}`, CSV UTF-8 séparateur « ; », ou `?format=xlsx`).
2. Remplir le fichier avec l'établissement, **sans données réelles dans le dépôt**.
3. **Simulation obligatoire** : `POST /higher/migration/batches` avec `dry_run=true` ; rien n'est écrit. Lire le rapport ligne par ligne (erreurs, doublons, avertissements). Corriger le fichier et refaire la simulation jusqu'à zéro erreur.
4. **Application** : `POST /higher/migration/batches/{id}/apply` (2FA récente). Une seule transaction : tout ou rien. Relancer le même fichier ne crée rien en double (clé de reprise : matricule, sinon nom + prénom + date de naissance).
5. **Annulation** si le fichier était faux : `POST /higher/migration/batches/{id}/revert` avec un motif, possible tant qu'aucune donnée dépendante n'existe (un lot d'étudiants ne s'annule pas tant que des soldes, notes ou UE reprises le référencent : annuler d'abord ces lots). Les **soldes d'ouverture ne s'annulent pas** (une facture émise ne se supprime pas) : vérifier très soigneusement la simulation.
6. Tout est journalisé (simulation, application, annulation). Contrôler par sondage 5 % des lignes dans les écrans (fiche étudiant, compte de scolarité, notes).

Mineurs : la reprise refuse les étudiants de moins de 18 ans (garant exigé) ; les inscrire par l'écran Inscriptions.

## 5. Checklist de bascule (jour J)

- [ ] Sauvegarde chiffrée faite avant la bascule : `php artisan backup:run`, fichier téléchargé et conservé hors serveur.
- [ ] `db:check` vert, `migrate:status` sans migration en attente.
- [ ] Paramétrage académique complet (étapes 1 à 14), autorisations réelles saisies, aucune alerte d'expiration.
- [ ] Maquettes publiées pour chaque filière et niveau ouvert.
- [ ] Grilles de frais actives ; exemple chiffré vérifié (ex. 450 000 FCFA de pension, bourse de 20 %, 3 tranches).
- [ ] Reprise des 5 jeux faite et contrôlée par sondage.
- [ ] Comptes du personnel créés, 2FA activée pour les rôles sensibles, mots de passe initiaux changés.
- [ ] Tâches planifiées créées (§6) et premier passage constaté dans les journaux.
- [ ] Formation des rôles faite (dossier `formation/`).
- [ ] TST-01 à TST-12 rejoués (voir `recette-pilote.md`).
- [ ] Sauvegarde restaurée **une fois sur une base de test** (voir §7).
- [ ] Information des étudiants et des garants : accès à l'espace étudiant, pas de SMS pour l'instant.
- [ ] Décision de bascule signée par la direction de l'institut ; ancien système en lecture seule.

## 6. Tâches planifiées à créer sur Camoo

Créer les tâches cron du panneau d'hébergement (chemin du projet : le dossier de l'application sur le serveur). Chaque ligne : `cd <chemin du projet> && php artisan <commande>`.

| Commande | Fréquence | Rôle |
|---|---|---|
| `queue:send` | chaque minute | reprend les envois de messages en échec |
| `documents:process-jobs` | chaque minute | génère les documents en lot (PDF) |
| `attendance:retry-absences` | chaque heure | rejoue les notifications d'absence échouées |
| `reminders:send` | chaque jour (7 h) | relances de tranches de scolarité |
| `platform:billing-run` | chaque jour (6 h) | relances, impayés et suspensions des abonnements |
| `reports:run` | chaque jour (5 h) | rapports planifiés |
| `higher:expiry-alerts` | chaque jour (6 h 30) | alertes d'expiration des autorisations et conventions (90/30/7 jours) |
| `higher:reserve-expiry` | chaque jour (6 h 45) | expiration des admissions « sous réserve » à 30 jours |
| `higher:grading-reminders` | chaque jour (7 h 15) | relances de saisie des notes |
| `higher:reminders` | chaque jour (7 h 30) | relances de l'application IPES |
| `backup:run` | chaque nuit (2 h) | sauvegarde chiffrée avec rétention |
| `retention:run --apply` | selon la politique de rétention (mensuel) | purge des données arrivées à échéance ; commencer par `retention:run` sans `--apply` |

Vérification : après 24 h, chaque tâche a laissé une trace dans `storage/logs` sans erreur ; la file de messages ne grossit pas.

## 7. Sauvegarde, restauration, surveillance

- **Sauvegarde** : `php artisan backup:run [--keep=N]` (fichier `.sbk` chiffré dans `storage/backups/`). Télécharger chaque semaine une copie hors serveur (FTP).
- **Restauration (à tester avant la bascule)** : sur une base de **test** (jamais la production pour un premier essai) : `php artisan backup:restore storage/backups/<fichier>.sbk --force`. Les nombres de lignes sont contrôlés ; une seule transaction.
- **Surveillance** : `php artisan db:check` chaque semaine et après chaque déploiement ; `php artisan status` ; `php artisan audit:verify <client>` (chaîne du journal d'audit intacte) ; lire `storage/logs` (erreurs 500, échecs d'envoi) ; contrôler que la file de messages est vide.
- **Alertes à surveiller** : abonnement en retard (lecture seule 423 pour les écritures), autorisation MINESUP proche de l'expiration, convention de tutelle expirée.

## 8. Permission `higher.migration.manage`

La reprise exige la permission `higher.migration.manage`. Elle n'est **pas encore** dans la matrice des rôles (`RoleMatrix`) : tant qu'elle n'y est pas, l'ajouter à `higher_dir_ecole` et `higher_scolarite` par une migration datée **et** dans `RoleMatrix::ROLES` (les deux ensemble, sinon `HigherRolesMigrationTest` échoue) :

```sql
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.migration.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.migration.manage');
```

Le périmètre de la personne (école, département, filière) borne les lignes qu'elle peut reprendre.

## 9. Plan de retour arrière

| Situation | Action |
|---|---|
| Fichier de reprise faux, lot appliqué | annuler le lot (§4, étape 5), corriger, refaire la simulation ; pour un solde d'ouverture, faire corriger le compte par le comptable |
| Déploiement défectueux | restaurer les fichiers de la version précédente (FTP) ; **ne jamais** revenir sur une migration de base : restaurer la sauvegarde d'avant déploiement sur la base si le schéma est en cause |
| Données corrompues | arrêt des saisies (suspendre l'application du client si besoin), restauration de la dernière sauvegarde saine sur une base de test, comparaison, puis décision de la direction |
| Échec de bascule | reprendre l'ancien système (resté en lecture seule), noter les écarts au registre des défauts (`recette-pilote.md`), nouvelle date |

Avant chaque opération risquée : sauvegarde `backup:run` et noter l'heure.

## 10. Protection des données (loi n° 2024/017 sur la protection des données personnelles)

- **Consentements** : le garant ne voit les notes et absences d'un étudiant majeur qu'avec son consentement enregistré (RG-INS-7) ; le consentement est collecté à l'inscription et peut être retiré. Les données de santé éventuelles sont chiffrées.
- **Finalités et accès** : accès par rôle et par périmètre (école, département, filière, groupe) ; refus par défaut ; un identifiant d'un autre client répond 404. L'université de tutelle n'a aucun accès direct par défaut (désactivé en V1).
- **Durées de conservation** : définies par la politique de rétention (`retention:run`) validée par la direction de l'institut avant le pilote ; à confirmer par l'établissement et à consigner dans le registre des traitements. Les relevés de notes et diplômes sont conservés durablement.
- **Droits des personnes** : accès, rectification, effacement dans les limites légales via les demandes de personnes concernées (module Conformité) ; export d'un client par `tenant:export`.
- **Pas de données réelles avant HTTPS** : le site est en HTTPS ; les données réelles peuvent donc être saisies, mais jamais dans le dépôt ni dans les jeux de test.
- **Journal d'audit** inaltérable (chaîné) : toute reprise, correction de note, réouverture de délibération y figure.

## 11. Incidents

1. **Qualifier** : indisponibilité, erreur de données, fuite possible, message en échec. Noter l'heure, l'institut, le `trace_id` affiché dans l'erreur.
2. **Contenir** : suspendre les écritures du client si les données sont en cause ; changer les mots de passe et révoquer les sessions si un compte est compromis.
3. **Diagnostiquer** : `storage/logs`, `db:check`, `audit:verify <client>`.
4. **Corriger ou restaurer** (§9), puis **informer** la direction de l'institut le jour même ; en cas de fuite de données personnelles, informer l'autorité compétente et les personnes concernées selon la loi.
5. **Consigner** dans le registre des défauts (`recette-pilote.md`) : cause, correction, prévention.
