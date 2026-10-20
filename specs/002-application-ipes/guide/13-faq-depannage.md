# 13. Questions fréquentes et dépannage

## 13.1 Avant d'appeler à l'aide

1. Lisez le message en entier : la plupart disent quoi faire.
2. Vérifiez votre **rôle actif** et votre **établissement** dans la barre du haut, et le sélecteur **École** de l'écran.
3. Actualisez la page (touche F5, ou tirer vers le bas sur téléphone).
4. Si l'erreur continue, notez : l'heure, l'écran, ce que vous faisiez, le message, et la **Référence à communiquer au support** affichée dans le message. Transmettez-les à l'administrateur de l'institut.

## 13.2 Messages d'erreur par code

L'application affiche parfois un code (le « statut »). Voici ce qu'il veut dire, en clair.

| Code | Message affiché | Ce que cela veut dire | Que faire |
|---|---|---|---|
| 401 | « Votre session a expiré. » | Vous êtes resté longtemps sans activité ou vous avez été déconnecté. | « Reconnectez-vous pour continuer. » |
| Confirmation | « Confirmation requise », « Double authentification récente exigée » | L'action est sensible : il faut une confirmation de moins de 10 minutes. | Cliquez sur le bouton proposé. Sans double authentification : **Confirmer avec mon mot de passe**. Avec : saisissez le code de votre application. Puis recommencez l'action. |
| 403 | « Remplacez votre mot de passe provisoire avant de continuer. » | Votre mot de passe est provisoire. | Choisissez votre propre mot de passe sur l'écran **Choisissez votre mot de passe** (chapitre 01, 1.2). |
| 403 | « Vous n'avez pas le droit d'effectuer cette action. » ou page **Accès refusé** | Votre rôle ou votre périmètre ne le permet pas (par exemple un enseignant sur un EC qui ne lui est pas affecté). La tentative est journalisée. | Changez de rôle si vous en avez plusieurs ; sinon demandez à l'administrateur de vérifier votre rôle ou au chef de département votre affectation. |
| 404 | « Cet élément est introuvable. » ou page **Page introuvable** | L'élément n'existe pas, a été supprimé, ou appartient à un autre institut (vous n'y avez jamais accès). | « Vérifiez l'adresse ou revenez à l'accueil. » Vérifiez l'établissement choisi. |
| 409 | « Cet élément a été modifié entre-temps. » / « Conflit de version : rien n'a été enregistré » | Quelqu'un a modifié la même chose pendant que vous travailliez. Rien n'a été écrasé. | « Rechargez la page, comparez les deux versions, puis décidez. » Pour les notes, voir 13.6. |
| 422 | « Certaines informations sont incorrectes. » | Un champ est vide ou mal rempli. Les règles métier (autorisation absente, frais non payés…) donnent aussi ce type de refus avec une explication. | « Corrigez les champs signalés, puis validez à nouveau. » |
| 423 | « Abonnement suspendu : lecture et export seulement » | L'abonnement de l'institut n'est pas réglé : les modifications sont refusées. Rien n'est perdu. | Consultez et exportez normalement. Le responsable de l'abonnement paie dans **Mes applications** (chapitre 11). |
| 423 | « Votre compte est verrouillé. » | Trop d'essais de connexion ou de code. | « Attendez la fin du délai ou réinitialisez votre mot de passe. » |
| 429 | « Trop de tentatives. Patientez N secondes avant de réessayer. » | Vous (ou votre réseau) avez fait trop de demandes à la suite. | « Rien ne sera perdu : relancez simplement une fois le délai écoulé. » |
| 500 | « Le service rencontre une difficulté. » | Problème du serveur. | Réessayez dans quelques minutes ; si cela continue, transmettez la référence affichée. |
| réseau | « Le réseau est indisponible. » / « Vous êtes hors ligne. » | Pas de connexion. | Vérifiez la connexion. L'appel et les notes restent sur l'appareil et partiront au retour du réseau. |

## 13.3 Compte et connexion

**Mon mot de passe provisoire ne marche pas.**
Vérifiez l'identifiant (l'e-mail enregistré par l'établissement, sans espace) et recopiez le mot de passe lettre par lettre : il ne contient jamais de 0, O, 1, l ni I. Après 5 erreurs, la connexion est bloquée 15 minutes. Si le mot de passe est perdu, l'administrateur en génère un nouveau (chapitre 11, 11.4).

**Je n'ai jamais reçu de code d'activation.**
(Concerne les candidats en ligne et le parcours « Activer mon compte » : un compte créé par l'administrateur n'a pas besoin de code.)
Vérifiez le dossier « Courrier indésirable ». Les SMS ne sont pas encore actifs partout : choisissez **Recevoir par e-mail**. Si votre téléphone ou votre e-mail n'est pas celui enregistré, demandez à la scolarité ou à l'administrateur de corriger votre fiche.

**J'ai oublié mon mot de passe.**
Lien **Mot de passe oublié ?** sur la page de connexion (chapitre 01). Personne d'autre ne peut le lire, pas même l'administrateur.

**Je veux changer mon mot de passe.**
Menu **Sécurité** > **Changer mon mot de passe** : saisissez l'actuel puis le nouveau (vos autres appareils sont déconnectés). Si vous ne connaissez plus l'actuel, utilisez **Mot de passe oublié ?**.

**Le code de double authentification est toujours « incorrect ».**
L'heure du téléphone est sans doute décalée : réglez-la en mode automatique. Saisissez le code dès qu'il apparaît.

**J'ai perdu mon téléphone d'authentification.**
Connectez-vous avec un **code de secours**, puis prévenez l'administrateur. Sans code de secours, l'administrateur fait réinitialiser votre double authentification par l'équipe SCHOLARIS (chapitre 01, 1.4.3).

**Quelqu'un essaie d'entrer dans mon compte.**
Sur l'écran de double authentification : **Ce n'est pas moi : bloquer et déconnecter tous les appareils**. Ou menu **Sécurité** > **Déconnecter partout**. Changez ensuite votre mot de passe.

**Je ne vois pas un menu que mon collègue voit.**
Chaque rôle a ses menus, et chaque personne son périmètre. Vérifiez votre rôle actif (barre du haut). Sinon, demandez à l'administrateur.

**« Vous n'avez aucun rôle dans l'application IPES pour cet établissement. »**
Votre compte n'a pas de rôle IPES. L'administrateur fonctionnel doit vous en attribuer un (chapitre 11).

**« L'application IPES n'est pas ouverte »**
L'abonnement de l'institut n'est ni en essai ni actif. Voir **Mes applications** (chapitre 11).

## 13.4 Paramétrage et inscriptions

**Pourquoi je ne peux pas inscrire dans cette filière ?**
L'autorisation MINESUP de la filière est absente, expirée, ou ne couvre pas ce niveau (RG-ACA-1) ; ou, pour une filière LMD, la tutelle de l'année manque (RG-ACA-2). C'est une règle volontaire. Faites enregistrer l'arrêté ou la tutelle (chapitre 02).

**Je me suis trompé de régime pour une filière.**
Le régime ne change jamais (RG-ORG-7). Créez une nouvelle filière avec le bon régime.

**« Admis » est refusé.**
Une pièce bloquante (le diplôme) n'est pas **Vérifiée** (RG-ADM-1). Vérifiez-la après contrôle de l'original.

**La validation de l'inscription est refusée.**
Les frais d'inscription ne sont pas payés. Faites encaisser ; à défaut, seul le directeur d'école peut valider « sous réserve financière » avec un motif.

**Peut-on modifier un matricule ?**
Non : il est unique, définitif et jamais réattribué, même après un changement de filière (RG-INS-1).

**La maquette ne veut pas se publier.**
Le **Contrôle de cohérence** signale une incohérence (30 crédits par semestre en LMD, UE sans EC, crédits des EC différents de ceux de l'UE, EC sans volume horaire, référence du programme manquante en LMD). Corrigez chaque ligne signalée.

**Je dois modifier une maquette publiée.**
Créez une **Nouvelle version**. **Dépublier** n'est possible que si aucune note n'a été saisie.

## 13.5 Finances

**J'ai encaissé deux fois le même montant.**
Ne tentez rien vous-même : prévenez le comptable. L'annulation passe par une opération tracée validée par un second agent.

**Le paiement mobile reste « En attente ».**
Le payeur doit confirmer sur son téléphone (code secret mobile money). Attendez la confirmation : le reçu s'émet alors automatiquement. Ne refaites pas le paiement.

**L'étudiant ne paie pas : que se passe-t-il ?**
Des relances partent automatiquement. Les blocages choisis par l'institut (consultation des notes, documents, réinscription) peuvent s'appliquer, mais **jamais** aux cours ni aux compositions, ni au certificat de scolarité, au reçu, au relevé de compte et à l'attestation de paiement.

**Le quitus est refusé.**
Le solde n'est pas nul (RG-FIN-6). Faites régler le solde, puis émettez le quitus.

**Je ne peux pas valider la bourse que j'ai demandée.**
C'est la séparation des tâches : un autre comptable doit la valider.

## 13.6 Notes et hors ligne

**Pourquoi ma note est refusée ?**
Elle doit être entre 0 et le barème, au quart de point (13 ; 13,25 ; 13,5 ; 13,75), ou un code ABJ, ABI, NA, FR.

**Je ne trouve pas mon évaluation, ou j'ai une erreur 403.**
L'EC ou le groupe ne vous est pas affecté. Demandez au chef de département de vérifier votre affectation (chapitre 05).

**Je me suis trompé après le verrouillage.**
Faites une **Demande de correction** avec un motif et une pièce justificative (PDF, JPG ou PNG, 5 Mo au plus). Elle est validée par le chef de département puis le directeur d'école.

**J'ai saisi sans réseau : mes notes sont-elles perdues ?**
Non, elles sont sur l'appareil et partent au retour du réseau. Vérifiez la **File d'envoi**. Ne videz pas les données du navigateur et n'utilisez pas la navigation privée pour saisir hors ligne.

**« Conflit » après une saisie hors ligne : que choisir ?**
Quelqu'un a modifié la même note pendant que vous étiez hors ligne. L'application n'écrase rien. Comparez la valeur du serveur et la vôtre ; en cas de doute, parlez-en avec le collègue. Puis **Garder la valeur du serveur** ou **Renvoyer ma valeur**.

**Mon import de fichier est entièrement refusé.**
C'est voulu : à la moindre erreur, aucune note n'est écrite. Lisez les **Lignes refusées**, corrigez le fichier, réimportez. Utilisez toujours le modèle téléchargé.

**L'étudiant ne voit pas ses notes.**
Elles ne sont visibles qu'après **Publier aux étudiants**, et pour les résultats de semestre, après la signature du PV. Un blocage pour impayé sur la consultation des notes peut aussi les masquer.

## 13.7 Délibérations et documents

**La séance de délibération ne s'ouvre pas.**
Toutes les notes des EC concernés doivent être **verrouillées** (RG-DEL-1). Regardez **Notes > Suivi de la saisie**.

**« Quorum non atteint : la séance ne peut pas délibérer. »**
Cochez les membres présents et **Enregistrer les présents** ; s'il manque des membres, reportez la séance.

**Le président ne voit pas le bouton « Signer le procès-verbal ».**
La signature exige le rôle **Membre de jury** (R21) pour la session, et la présence à la séance. L'administrateur doit lui attribuer ce rôle.

**La publication des résultats est refusée.**
Le procès-verbal n'est pas signé par le président (RG-DEL-3). Faites-le signer, puis publiez.

**Il faut modifier une note après la signature du PV.**
Seul le directeur académique peut rouvrir la délibération, avec un motif ; le directeur général est alerté ; un nouveau PV est établi et signé.

**Un document a été émis avec une erreur.**
**Annuler** le document avec un motif (il n'est jamais supprimé), puis émettre le document corrigé et l'indiquer comme remplaçant. La page de vérification affichera « annulé » avec le motif.

**Comment vérifier qu'un relevé est authentique ?**
Scannez son QR, ou saisissez son code sur `https://scholariserp.trugroup.cm/verification`. La page dit s'il est authentique, révoqué (avec le motif) ou remplacé, sans jamais montrer les notes. « Document introuvable » signale probablement un faux.

**Le relevé ne peut pas être émis.**
Le PV du semestre n'est pas encore signé (RG-DOC-3).

**Peut-on délivrer le diplôme de BTS ou de licence ?**
Non. L'application délivre l'**attestation de fin de formation** (BTS, HND) ou l'**attestation de réussite** (licence, master). Le diplôme est délivré par l'État ou par l'université de tutelle.

## 13.8 Reprise de données

**Peut-on reprendre les anciennes années ?**
Non : seulement l'année en cours et les UE capitalisées.

**La simulation indique des erreurs.**
Corrigez le fichier et refaites la simulation. Rien n'est écrit tant que l'application n'est pas lancée, et elle est refusée s'il reste une erreur.

**Un mauvais fichier a été appliqué.**
L'équipe SCHOLARIS annule le lot (avec un motif) tant qu'aucune donnée ne dépend de lui. Exception : **les soldes d'ouverture ne s'annulent pas**.

**Où est l'écran de reprise ?**
Il n'y en a pas dans cette version : la reprise est faite par l'équipe SCHOLARIS (chapitre 09).

## 13.9 Messages et SMS

**Les étudiants ne reçoivent pas de SMS.**
L'envoi de SMS n'est pas encore branché pour tous les instituts : les messages partent par e-mail et dans l'application. Vérifiez l'adresse e-mail dans la fiche.

## 13.10 Protection des données

**Mes données personnelles sont-elles protégées ?**
Oui (loi n° 2024/017) : accès par rôle et par périmètre, journal d'audit inaltérable, consentements, exports filigranés. Un utilisateur d'un autre institut ne peut jamais voir vos données (réponse 404).

**Comment exercer mes droits (accès, rectification, effacement) ?**
Menu **Confidentialité**, ou demande écrite à la scolarité.

**Qui voit les notes d'un étudiant ?**
L'étudiant (après publication), ses enseignants (leurs EC et groupes), l'encadrement de son périmètre. Le garant seulement avec le consentement de l'étudiant majeur.
