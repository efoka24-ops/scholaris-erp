# 11. Administration de l'institut

Ce chapitre s'adresse à l'**administrateur de l'institut** : le premier administrateur du client (appelé « super administrateur », créé par l'équipe SCHOLARIS) et l'**Administrateur fonctionnel** (R20). Il explique la création des comptes, l'attribution des rôles et des périmètres, l'activation des comptes, les paramètres et l'abonnement à l'application IPES.

**Qui peut quoi**

| Écran | Super administrateur du client | Administrateur fonctionnel (R20) |
|---|---|---|
| **Administration > Établissements**, **Années et périodes**, **Structures** | oui | lecture seule ou pas d'accès (ces écrans exigent le droit de gérer l'organisation) |
| **Administration > Utilisateurs et rôles** | oui | oui |
| **Administration > Paramètres** | oui | oui |
| **IPES > Paramétrage académique** (chapitre 02) | selon ses rôles | oui |
| **Mes applications** (abonnement, paiement) | oui | lecture seule si son rôle le permet |

Le menu **Administration** (`/administration`) s'ouvre sur une **Vue d'ensemble** avec les sections : **Établissements**, **Années et périodes**, **Structures**, **Utilisateurs et rôles**, **Paramètres**. Choisissez d'abord l'établissement (l'institut) dans la barre du haut.

## 11.1 Première connexion de l'administrateur

1. L'équipe SCHOLARIS crée votre compte et vous envoie une invitation par e-mail (ou vous communique votre identifiant et le lien d'activation).
2. Activez votre compte sur `/activation` (chapitre 01, section 1.2), puis connectez-vous.
3. Activez la **double authentification** (obligatoire pour un administrateur, chapitre 01, section 1.4).
4. Ouvrez **Mes applications** : l'application IPES doit apparaître dans **Applications ouvertes**, en essai (« 90 jours d'essai restants »).
5. Vérifiez dans **Administration > Établissements** que votre institut existe, avec le type **Supérieur** (le type ne change plus après la création).

## 11.2 Années académiques et semestres

**Où** : **Administration > Années et périodes** (`/administration/annees`). **Qui** : super administrateur.

1. **Nouvelle année** : **Libellé** (par exemple 2026-2027), **Début**, **Fin**, puis **Créer l'année**. « Une année créée est immédiatement ouverte. Les dates ne doivent pas chevaucher une autre année de cet établissement. »
2. Cliquez sur **Gérer** à côté de l'année, puis **Nouvelle période** : **Nature** **Semestre**, **Numéro** (1 ou 2), dates, puis **Ajouter la période**.

La **clôture** d'une année se fait en deux temps : une personne la demande, une **autre** l'approuve, avec une double authentification de moins de dix minutes (« Vous ne pouvez pas approuver votre propre demande »). Des **Contrôles avant clôture** signalent les points **Bloquant**.

## 11.3 Créer les comptes du personnel

**Où** : **Administration > Utilisateurs et rôles** (`/administration/utilisateurs`). « Créez une personne avec son compte, puis attribuez-lui un rôle dans un établissement ou pour tout le groupe. »

**Règle d'or : un compte par personne, jamais partagé.** Une secrétaire qui aide un chef de département a son propre compte.

### Étape 1 : créer la personne

1. Formulaire **Créer une personne avec son compte** : nom, prénoms, **téléphone** et/ou **e-mail**, **Langue**.
   « Indiquez au moins un téléphone ou un e-mail pour que la personne reçoive son code d'activation. » Préférez l'**e-mail** tant que les SMS ne sont pas actifs pour votre institut.
2. Cliquez sur **Créer la personne**. Message : « … est créée. Son compte attend l'activation ; vous pouvez maintenant lui attribuer un rôle. »

« Son compte reste en attente : elle l'active elle-même avec un code envoyé par SMS ou par e-mail. » **Vous ne connaissez jamais son mot de passe**, et vous n'avez pas de code à lui transmettre : dites-lui simplement d'aller sur `https://scholariserp.trugroup.cm/activation` et de saisir le téléphone ou l'e-mail que vous avez enregistré (chapitre 01).

### Étape 2 : attribuer un rôle et un périmètre

1. Formulaire **Attribuer un rôle** : recherchez la **Personne** (« Personne choisie : … »).
2. **Rôle** : choisissez parmi les rôles de l'application (Directeur d'école, Chef de département, Service de la scolarité, Enseignant permanent, Caissier, etc. ; voir chapitre 12).
3. **Portée** (périmètre) : « Établissement entier, ou limité à une unité ou à une classe. »
   - **Établissement** : tout l'institut (par exemple Promoteur, Directeur académique, Comptable, Administrateur fonctionnel, Étudiant, Garant) ;
   - **Unité** : une école ou un département (par exemple Directeur d'école sur son école, Chef de département sur son département, Scolarité sur une école) ;
   - **Classe** : un groupe (par exemple un enseignant limité à un groupe).
   - **Groupe (tous les établissements)** : uniquement pour les rôles de niveau client, comme le Directeur général.
4. Choisissez la cible (**Établissement concerné**, **Unité concernée** ou **Classe concernée**).
5. **À partir du** et, si besoin, **Jusqu'au** (obligatoire pour un rôle temporaire comme Membre de jury, Université de tutelle ou Auditeur).
6. Cliquez sur **Attribuer le rôle**. Message : « Le rôle « … » est attribué à … ».

Une personne peut cumuler plusieurs rôles (par exemple Enseignant permanent et Chef de département ; ou Enseignant permanent et Enseignant vacataire pour un vacataire). Elle choisit le rôle actif dans la barre du haut.

Pour un **enseignant**, l'affectation aux EC et groupes se fait ensuite par le chef de département (chapitre 05) : c'est elle qui limite ce qu'il voit et saisit.

### Déléguer temporairement

Cochez **Délégation temporaire (je confie un rôle que j'exerce)**, par exemple pendant un congé. « Une délégation dure 90 jours au plus et ne peut pas dépasser la fin de votre propre rôle. » La date de fin est obligatoire. Bouton **Déléguer le rôle**.

### Retirer un rôle

Dans **Rôles attribués** (filtrable par rôle), cliquez sur **Retirer**. Faites-le **le jour même** du départ d'un employé ou de la fin d'un contrat de vacataire. « La liste présente les rôles attribués, pas les comptes eux-mêmes. »

### Rôles à attribuer au démarrage

Administrateur fonctionnel, Directeur général, Directeur académique, Directeurs d'école, Chefs de département, Responsables de filière, Service de la scolarité, Service des examens, Caissiers, Comptables, Enseignants permanents et vacataires, Responsable des stages, Responsable de la discipline, Responsable RH, et les Membres de jury à chaque session.

Pensez à attribuer le rôle **Membre de jury** au **directeur d'école qui préside** un jury : sans ce rôle, il ne peut pas signer le procès-verbal (chapitre 07).

### Comptes des étudiants et des garants

Les comptes des étudiants sont créés à la candidature (« Nouvelle personne (le compte est créé) », chapitre 03). Les étudiants et garants activent leur compte de la même façon (chapitre 01). Le rattachement d'un garant à un étudiant et le consentement de partage sont gérés par la scolarité.

## 11.4 Accès perdu, téléphone perdu, compte bloqué

- **Mot de passe oublié** : la personne le réinitialise elle-même (`/mot-de-passe-oublie`, chapitre 01). Personne ne doit communiquer son mot de passe, pas même à l'administrateur.
- **Téléphone d'authentification perdu sans code de secours** : la réinitialisation de la double authentification d'un compte se demande à l'équipe SCHOLARIS (pour un administrateur du client, « Réinitialiser l'accès » efface mot de passe et double authentification et renvoie une invitation). Vérifiez l'identité de la personne avant de transmettre la demande.
- **Compte bloqué** après « Ce n'est pas moi » : la personne réinitialise son mot de passe pour le rouvrir.
- **Départ** d'un employé : retirez tous ses rôles.

## 11.5 Paramètres de l'établissement

**Où** : **Administration > Paramètres** (`/administration/parametres`). « Règles appliquées à l'établissement. Une valeur définie ici l'emporte sur celle du client ou du socle. »

Paramètres utiles à l'IPES :

| Groupe | Paramètre | Exemple |
|---|---|---|
| Évaluations et moyennes | **Barème des notes**, **Décimales conservées**, **Pondération des matières** (par crédits pour le supérieur), **Mentions** | 20 ; 2 ; Par crédits |
| Assiduité et discipline | **Seuil d'absences non justifiées (%)**, **Seuil d'alerte préventive (%)**, **Prévenir les parents d'une absence**, **Validité du code QR de séance (minutes)** | 30 ; 25 ; oui |
| Frais et relances | **Relances de paiement (jours)**, **Préfixe des reçus**, **Remise de fratrie**, **Blocages en cas d'impayé** | -7, 1, 15 ; REC |
| Messages | **Début de la plage d'envoi (heure)** et fin | 7 h à 20 h |

Chaque ligne indique d'où vient la valeur (« défini pour cet établissement », « valeur du socle : … »). Les règles propres aux délibérations (poids CC/examen, note éliminatoire, compensation, points de jury) se règlent dans le **Règlement des études** de chaque école (chapitre 02).

Les **blocages en cas d'impayé** ne s'appliquent jamais aux cours ni aux compositions, ni au certificat de scolarité, au reçu, au relevé de compte et à l'attestation de paiement.

## 11.6 Modèles de documents et signataires

Le nom et le cachet des signataires, et l'apparence des documents, se règlent dans les modèles de documents du socle (droit « gérer les modèles », détenu par l'Administrateur fonctionnel et le Directeur général) et dans **Documents officiels > Profil documentaire de l'école** (chapitre 07). Dans **Administration > Établissements > Configurer**, la partie **Apparence et en-têtes** contient aussi les **En-têtes des documents**, les **Numéros d'autorisation** et les **Signataires des documents** de l'établissement.

## 11.7 Abonnement et paiement de l'application IPES

**Où** : **Mes applications** (`/applications`). **Qui** : la direction et le super administrateur ouvrent et paient ; les autres rôles autorisés consultent (« Votre rôle permet de consulter les applications ; l'ouverture et le paiement sont réservés à la direction et au super administrateur. »).

L'écran **Mes applications** montre les **Applications ouvertes** et les **Autres applications**, avec pour chacune :
- l'**essai** : « 90 jours d'essai restants », « Essai jusqu'au … » ;
- l'abonnement payé : « Payé jusqu'au … », « Prochaine échéance : … », le prix (« … par mois ») ;
- les alertes : « Paiement en retard : réglez l'abonnement avant la fin du délai de grâce pour éviter la suspension. » ou « Application suspendue : les écritures sont refusées, la consultation et l'export restent possibles. Réglez l'abonnement pour la réactiver. »

### Payer l'abonnement

1. Choisissez l'établissement dans la barre du haut (« Pour ouvrir ou payer une application, choisissez d'abord un établissement… »).
2. Cliquez sur **Payer l'abonnement** de l'application IPES.
3. **Nombre de mois**, **Moyen de paiement** (MTN Mobile Money ou Orange Money), **Numéro du payeur** (« Le numéro mobile money qui recevra la demande de validation, par exemple 6 99 00 00 00. »).
4. Vérifiez le **Montant à payer**, puis cliquez sur **Demander le paiement**.
5. Message « Paiement demandé (référence …). Validez-le sur votre téléphone ; la confirmation est automatique. » Validez sur le téléphone.
6. **Historique et reçus** : chaque paiement et son **Reçu** (période couverte, date, référence), imprimable avec **Imprimer**.

Si vous cliquez deux fois : « Ce paiement avait déjà été demandé (référence …) : il n'est pas dupliqué. »

### Que se passe-t-il si l'abonnement n'est pas payé ?

1. **En retard** : tout fonctionne encore pendant le délai de grâce ; des relances sont envoyées.
2. **Suspendu** : bandeau « Abonnement suspendu : lecture et export seulement » sur tous les écrans IPES. Les modifications sont refusées (erreur 423) ; la consultation et l'export restent possibles. **Aucune donnée n'est perdue.**
3. Dès le paiement confirmé, l'application redevient normale.

## 11.8 Routines de l'administrateur

Chaque semaine :
1. Regarder les **alertes d'expiration** des autorisations MINESUP et des conventions de tutelle (chapitre 02).
2. Vérifier la liste des **rôles attribués** : retirer les rôles des personnes parties, des vacataires sans contrat, des membres de jury dont la session est finie.
3. Vérifier l'échéance de l'abonnement (**Mes applications**).

En cas de problème : notez l'heure, l'écran, le message et la **Référence à communiquer au support**, puis écrivez à l'équipe SCHOLARIS. Pour une erreur de données, ne « bricolez » pas : demandez conseil.
