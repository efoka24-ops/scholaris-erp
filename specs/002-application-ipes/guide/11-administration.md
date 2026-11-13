# 11. Administration de l'institut

Ce chapitre s'adresse à l'**administrateur de l'institut** : le premier administrateur du client (appelé « super administrateur », créé par l'équipe SCHOLARIS) et l'**Administrateur fonctionnel** (R20). Il explique la création des comptes, l'attribution des rôles et des périmètres, l'activation des comptes, les paramètres et l'abonnement à l'application IPES.

**Qui peut quoi**

| Écran | Super administrateur du client | Administrateur fonctionnel (R20) |
|---|---|---|
| **Administration > Établissements**, **Années et périodes**, **Structures** | oui | lecture seule ou pas d'accès (ces écrans exigent le droit de gérer l'organisation) |
| **Administration > Utilisateurs et rôles** | oui | oui |
| **Administration > Paramètres** | oui | oui |
| **IPES > Paramétrage académique** (chapitre 02) | selon ses rôles | oui |
| **Administration > Circuits de validation** | oui | oui |
| **Administration > Importer des personnes** | oui | oui |
| **Mes applications** (abonnement, paiement), **Régularisation de paiement** | oui | lecture seule si son rôle le permet |

Le menu **Administration** (`/administration`) s'ouvre sur une **Vue d'ensemble** avec les sections : **Établissements**, **Années et périodes**, **Structures**, **Utilisateurs et rôles**, **Paramètres**, **Circuits de validation**, **Importer des personnes**. Choisissez d'abord l'établissement (l'institut) dans la barre du haut.

## 11.1 Première connexion de l'administrateur

1. L'équipe SCHOLARIS crée votre compte et vous envoie une invitation par e-mail (ou vous communique votre identifiant et le lien d'activation).
2. Activez votre compte sur `/activation` (chapitre 01, section 1.2), puis connectez-vous.
3. Si l'institut l'impose, activez la **double authentification** (chapitre 01, section 1.4).
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

### Étape 1 : créer la personne et son mot de passe provisoire

1. Formulaire **Créer une personne avec son compte** : nom, prénoms, **e-mail** (c'est l'identifiant de connexion : Gmail, Yahoo ou autre) ou **téléphone**, **Langue**.
2. Cliquez sur **Créer la personne**. Le compte est **utilisable tout de suite** : un cadre **Compte créé : mot de passe provisoire** affiche l'identifiant et un mot de passe de 12 caractères, **une seule fois**.
3. Remettez-le à la personne en main propre : **Copier le mot de passe**, ou **Imprimer la fiche** (à remettre sur papier). Cliquez sur **J'ai noté le mot de passe, fermer** : il ne s'affichera plus, n'est envoyé par aucun message et n'est conservé nulle part en clair.
4. À sa première connexion, la personne doit le remplacer par son propre mot de passe (chapitre 01, 1.2) : **vous ne connaîtrez jamais son mot de passe définitif**.

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

- **Mot de passe oublié ou provisoire perdu** : la personne peut utiliser **Mot de passe oublié ?** (`/mot-de-passe-oublie`, chapitre 01), ou vous générez un nouveau mot de passe provisoire : dans **Rôles attribués**, bouton **Nouveau mot de passe** sur sa ligne, puis confirmez (vous pouvez aussi effacer son application d'authentification si son téléphone est perdu). L'ancien mot de passe et toutes ses connexions sont supprimés ; le nouveau s'affiche une seule fois (à copier ou imprimer) et doit être changé à la prochaine connexion. Limites : pas pour votre propre compte, pas pour un compte bloqué, pas pour un administrateur du client (réservé au super administrateur), 5 fois par heure et par compte. Chaque émission est journalisée, sans le mot de passe.
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

1. **En retard** : tout fonctionne encore pendant le délai de grâce ; des relances sont envoyées. Un **bandeau** jaune apparaît en haut de tous les écrans : « Abonnement en retard : régularisez avant le 21/01/2027 pour éviter la suspension. (5 jour(s) restant(s)) ».
2. **Mode régularisation** : à la fin du délai de grâce, l'institut passe en régularisation de paiement, à l'un des deux niveaux choisis par TRU GROUP :
   - **Lecture seule** : bandeau « Abonnement suspendu : lecture et export seulement. » La consultation et l'export restent possibles ; toute modification est refusée (erreur 423). Si vous essayez d'enregistrer quelque chose, le bandeau affiche aussitôt le message du serveur, sans changer d'écran.
   - **Bloqué** : seul l'écran **Régularisation de paiement** (`/regularisation`) reste ouvert, avec le paiement et **Se déconnecter**. Si l'institut est bloqué pendant que vous travaillez, SCHOLARIS vous y conduit tout de suite, sans attendre que vous changiez d'écran.
3. **Aucune donnée n'est perdue**, à aucun niveau.
4. Dès le paiement confirmé, l'application redevient normale (déblocage automatique) et le bandeau disparaît.

**Qui peut payer ?** Seuls la **direction** et le **super administrateur** du client (droit de gérer les applications). Les autres personnes voient le bandeau avec « Prévenez la direction de votre établissement : seul le responsable peut régulariser. » et, sur l'écran de régularisation, le contact de TRU GROUP.

### L'écran Régularisation de paiement

**Où** : bouton **Régulariser** du bandeau, ou `/regularisation`. L'écran montre :
- le **Montant à régler**, le **Total mensuel**, la **Période réglée** et la date de début de la régularisation ;
- **Où payer** : les numéros Orange Money et MTN MoMo de TRU GROUP, avec le bénéficiaire (par exemple « Orange Money : payez au 6 99 00 00 02 (bénéficiaire : TRU GROUP SARL) ») ;
- **Payer par Orange Money** ou **Payer par MTN MoMo** : la demande arrive sur votre téléphone, comme au paragraphe « Payer l'abonnement » ;
- les **Derniers reçus** (**Voir le reçu**) et **Contacter TRU GROUP**.

### J'ai déjà payé : déclarer mon paiement

Si vous avez envoyé l'argent directement (transfert Orange Money ou MTN MoMo vers le numéro de TRU GROUP), déclarez-le pour que la plateforme le vérifie. **Déclarer ne débloque pas à lui seul** : seule la vérification débloque.

1. Sur l'écran de régularisation, cliquez sur **Déclarer mon paiement**.
2. **Type de paiement** :
   - **Total : le solde dû** (le montant est rempli pour vous) ;
   - **Partiel : un montant à saisir** : **Montant payé (FCFA)**, entre le minimum indiqué (1 000 FCFA par défaut) et le solde moins 1 FCFA.
3. **Moyen de paiement** : Orange Money ou MTN MoMo. Le **Numéro de TRU GROUP à créditer** est rappelé.
4. **Numéro payeur** : le numéro qui a envoyé l'argent, par exemple `6 99 00 00 01` (il est enregistré au format `+237…` et n'est jamais affiché en entier : `+2376*****001`).
5. **Motif ou référence du paiement** : la référence reçue par SMS, par exemple `OM240301.AB12` (4 lettres ou chiffres au moins).
6. **Valider ma déclaration**. Message : « Déclaration enregistrée. Nous vérifions votre paiement : l'accès sera rétabli dès sa confirmation. »

Une seule déclaration peut être en cours par application (« Une déclaration est déjà en cours de vérification pour cette application. »). Si vous cliquez deux fois, la déclaration n'est pas dupliquée.

**Suivi de vos déclarations** : chaque déclaration affiche son état : **Déclarée, en cours de vérification** (vérification attendue avant une date, 7 jours par défaut), **Confirmée** (« Confirmé automatiquement » ou « Confirmé par la plateforme »), **Rejetée** (avec le motif) ou **Expirée** (« Non confirmée à temps : déclarez à nouveau ou contactez-nous. »). Le bandeau indique aussi « Votre paiement déclaré est en cours de vérification. »

### Vérification par TRU GROUP, déblocage et notification

- **Vérification automatique** : quand l'opérateur mobile confirme un paiement dont la référence, le montant (à 100 FCFA près par défaut) et le numéro payeur correspondent à votre déclaration, elle est confirmée sans intervention.
- **Vérification par TRU GROUP** : sinon, l'équipe TRU GROUP compare la déclaration à son relevé, puis la **confirme** (avec le montant réellement reçu) ou la **rejette** avec un motif. Une même référence ne peut valider qu'une seule déclaration.
- **Paiement total confirmé** : l'accès est **rétabli tout de suite**, un reçu est émis et la direction reçoit un message (e-mail ou SMS) « paiement confirmé, accès rétabli ».
- **Paiement partiel confirmé** : le montant est porté au crédit (« Déjà versé : 20 000 FCFA ») et le **Solde à régler** diminue ; un reçu est émis et un message indique le solde restant. L'institut reste en régularisation jusqu'au paiement du solde, sauf promesse de paiement.
- **Paiement annulé** : si TRU GROUP annule un paiement déjà confirmé (par exemple un transfert jamais reçu), le montant est retiré du crédit, la déclaration correspondante est marquée annulée avec le motif, et l'institut revient en régularisation si le solde n'est plus couvert. La direction reçoit un message « paiement annulé » avec le motif et le solde restant.

### Promesse de paiement

Vous avez besoin de quelques jours ? Contactez TRU GROUP (**Contacter TRU GROUP** sur l'écran de régularisation). TRU GROUP peut accorder une **promesse de paiement** jusqu'à une date précise (90 jours au plus), par exemple avec un paiement partiel : l'accès est rétabli jusqu'à cette date. Si le solde n'est pas payé à la date promise, l'institut repasse en régularisation.

## 11.8 Circuits de validation

**Où** : **Administration > Circuits de validation** (`/administration/circuits`). **Qui** : le super administrateur, la direction et l'Administrateur fonctionnel (droit de gérer les circuits).

« Chaque circuit est livré avec le fonctionnement habituel de l'application. Vous pouvez l'ajuster dans les limites de sécurité indiquées : qui valide, étapes facultatives, confirmation récente. Sans réglage, rien ne change. »

Circuits de l'application IPES :

| Circuit | Étapes (par défaut) |
|---|---|
| **Notes : saisie, validation, verrouillage et publication** | Soumission par l'enseignant affecté ; validation par le chef de département (facultative) ; verrouillage par le chef de département ; publication par le service de la scolarité |
| **Correction d'une note verrouillée** | Demande motivée avec pièce par l'enseignant ; première validation par le chef de département ; seconde validation par le directeur d'école (facultative) |
| **Bourses, remises et échéanciers négociés** | Demande par un comptable ; validation par un comptable (ou la direction générale) |
| **Décision d'admission** | Décision motivée par le directeur d'école (ou la scolarité) |
| **Annulation d'un paiement** | Demande motivée par la caisse ; validation par le comptable (ou la direction uniquement) |

Chaque étape indique **Qui valide**, « Par défaut : … » et « Actuellement : … », et des badges (**Par défaut**, **Personnalisé**, **Facultative**, **Confirmation récente**, **Imposée**, **Étape désactivée**).

**Ajuster un circuit**

1. Cliquez sur **Ajuster** sur le circuit.
2. Pour chaque étape modifiable : **Qui valide cette étape** (choix parmi les fonctions proposées), **Étape active** (seulement pour une étape facultative), **Exiger une confirmation récente**.
3. **Motif du changement** (5 à 500 caractères, conservé dans l'historique), puis **Vérifier**.
4. Relisez « Voici ce qui va changer… », puis **Enregistrer le circuit**. Une double authentification récente est demandée (**Confirmer mon identité**).

**Revenir au défaut** rétablit le fonctionnement d'origine. **Historique** montre chaque version, sa date, son auteur et son motif.

**Règles verrouillées** (cadenas) : elles valent quel que soit le réglage et ne se modifient pas, par exemple : « Séparation des tâches : qui a saisi une note de l'évaluation ne la verrouille pas. », « Les notes d'examen ne sont publiées qu'après la signature du procès-verbal du jury. », « Le demandeur ne valide jamais sa propre demande. », le verrouillage des notes et la clôture d'une année exigent toujours une confirmation récente. Une étape **Imposée** par votre organisation ou par la plateforme ne se modifie pas au niveau de l'établissement.

## 11.9 Importer des personnes (fichier Excel)

**Où** : **Administration > Importer des personnes** (`/administration/import`). **Qui** : super administrateur, direction, Administrateur fonctionnel. Choisissez d'abord l'institut dans la barre du haut.

« Créez d'un coup les comptes du personnel et des autres acteurs de l'établissement à partir d'un fichier. Rien n'est créé avant votre validation. »

1. **Fichier** : téléchargez le **Modèle Excel (.xlsx)** ou le **Modèle CSV**. Remplissez une ligne par personne : nom, prénoms, e-mail ou téléphone, langue (fr ou en), rôle, périmètre (établissement, unité ou structure), code du périmètre, date de début, date de fin (obligatoire pour Membre de jury, Université de tutelle et Auditeur, 90 jours au plus), note. **Supprimez les deux lignes d'exemple.** Déposez le fichier (.csv ou .xlsx, 2 Mo et 1000 lignes au plus) puis cliquez sur **Analyser le fichier**.
2. **Vérification** : « 120 lignes : 112 valides, 5 en erreur, 3 doublons. » Filtrez **Valides**, **Erreurs**, **Doublons**. **Télécharger les erreurs (CSV)** donne les lignes à corriger ; corrigez-les et déposez un nouveau fichier. Les doublons (personne déjà connue) sont ignorés : aucun compte existant n'est modifié.
3. **Identifiants** : **Mot de passe provisoire (recommandé)** ou **Code d'activation** (avec la case **Envoyer les codes** par SMS ou e-mail). Cliquez sur **Valider l'import**, puis confirmez « Créer 112 comptes maintenant ? ».
4. **Bilan** : les mots de passe provisoires s'affichent **une seule fois** : copiez-les, téléchargez-les en CSV ou imprimez les fiches, puis remettez-les en main propre. Ils ne seront plus affichables (en cas de perte : **Nouveau mot de passe**, section 11.4).

Vous ne pouvez importer que les rôles que vous pouvez déjà attribuer à la main (section 11.3), une personne par ligne avec un rôle ; les rôles supplémentaires s'ajoutent ensuite dans **Utilisateurs et rôles**.

## 11.10 Routines de l'administrateur

Chaque semaine :
1. Regarder les **alertes d'expiration** des autorisations MINESUP et des conventions de tutelle (chapitre 02).
2. Vérifier la liste des **rôles attribués** : retirer les rôles des personnes parties, des vacataires sans contrat, des membres de jury dont la session est finie.
3. Vérifier l'échéance de l'abonnement (**Mes applications**).

En cas de problème : notez l'heure, l'écran, le message et la **Référence à communiquer au support**, puis écrivez à l'équipe SCHOLARIS. Pour une erreur de données, ne « bricolez » pas : demandez conseil.
