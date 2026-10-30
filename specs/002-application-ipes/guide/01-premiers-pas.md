# 01. Premiers pas

Ce chapitre concerne **tout le monde** : personnel, étudiants, garants.

## 1.1 Ce qu'il vous faut

- Un ordinateur, une tablette ou un téléphone avec un navigateur récent (Chrome, Firefox, Edge, Safari). L'application fonctionne aussi en 3G.
- Votre **adresse e-mail** (Gmail, Yahoo ou autre) telle que l'institut l'a enregistrée, ou votre numéro de téléphone : c'est votre identifiant. Pas de pseudo.
- Le **mot de passe provisoire** que l'administrateur de l'institut vous remet en main propre (ou sur une fiche imprimée).
- Seulement si l'institut l'impose pour votre rôle (voir 1.4) : un téléphone avec une **application d'authentification** (Google Authenticator ou Microsoft Authenticator, gratuites).

Adresse du site : **https://scholariserp.trugroup.cm**. Vérifiez toujours que l'adresse commence par `https://` et que le cadenas est affiché. N'ouvrez jamais un lien reçu d'un inconnu qui imite cette adresse.

Astuce : sur téléphone, l'application peut être **installée** comme une application (menu du navigateur > « Ajouter à l'écran d'accueil »).

## 1.2 Première connexion avec le mot de passe provisoire

**Qui** : toute personne dont l'administrateur de l'institut vient de créer le compte.
**Où** : écran **Connexion** (`/connexion`).

1. Dans **E-mail ou téléphone**, saisissez votre e-mail. Dans **Mot de passe**, saisissez le mot de passe provisoire reçu (12 caractères, sans 0, O, 1, l ni I). Cliquez sur **Se connecter**.
2. L'écran **Choisissez votre mot de passe** s'ouvre : rien d'autre n'est accessible tant que vous ne l'avez pas rempli. Recopiez le mot de passe provisoire dans **Mot de passe provisoire**, puis choisissez un **Nouveau mot de passe** (10 caractères au minimum, ni courant, ni contenant votre nom ou votre e-mail) et confirmez-le.
3. Cliquez sur **Enregistrer et continuer**. Le mot de passe provisoire ne sert plus ; vos autres appareils éventuels sont déconnectés.

Après 5 erreurs, la connexion est bloquée 15 minutes. Mot de passe provisoire perdu : demandez-en un nouveau à l'administrateur (chapitre 11, 11.4).

### Activation par code (candidats en ligne, comptes sans mot de passe provisoire)

**Qui** : toute personne dont le compte vient d'être créé par l'institut (personnel, étudiant, garant).
**Où** : `https://scholariserp.trugroup.cm/activation`, ou le lien **Activer mon compte par SMS** sur la page de connexion.

Votre compte a été créé par l'administrateur de l'institut (ou, pour un étudiant, par la scolarité). Il est « en attente » : c'est vous qui choisissez votre mot de passe. Personne d'autre ne le connaît.

L'écran **Activation du compte** a trois étapes : **Identifiant**, **Code reçu**, **Mot de passe**.

1. Saisissez le numéro de téléphone ou l'adresse e-mail que l'institut a enregistré, puis cliquez sur **Recevoir le code**.
2. Un **code à 6 chiffres** vous est envoyé par e-mail ou par SMS. Le message « Entrez le code reçu par message » s'affiche.
3. Saisissez le code dans **Code à 6 chiffres**, puis cliquez sur **Vérifier le code**.
4. Choisissez votre **Nouveau mot de passe** (10 caractères au minimum ; les mots de passe trop courants comme « 1234567890 » sont refusés), puis retapez-le dans **Confirmez le mot de passe**.
5. Cliquez sur **Enregistrer le mot de passe**. Le message « Votre mot de passe est enregistré. Vous pouvez vous connecter. » s'affiche. Cliquez sur **Aller à la connexion**.

| Problème | Solution |
|---|---|
| Je ne reçois pas le SMS | Les SMS ne sont pas encore actifs pour tous les instituts. Cliquez sur **Recevoir par e-mail**, ou attendez puis **Renvoyer le SMS**. Vérifiez aussi le dossier « Courrier indésirable » de votre messagerie. |
| « Le code a expiré. Demandez-en un nouveau. » | Le code n'est valable que quelques minutes. Recommencez l'étape 1. |
| « Il vous reste N essai(s). » | Le code saisi est faux. Vérifiez chaque chiffre. Au bout des essais, demandez un nouveau code. |
| « Les deux mots de passe ne sont pas identiques. » | Retapez le même mot de passe dans les deux cases. |
| Rien ne se passe avec mon numéro | Votre numéro n'est peut-être pas celui enregistré. Demandez à la scolarité (étudiant, garant) ou à l'administrateur (personnel) de vérifier votre fiche. |

## 1.3 Se connecter

**Où** : `https://scholariserp.trugroup.cm/connexion` (écran **Connexion**, titre « Accéder à mon espace »).

1. Dans **E-mail ou téléphone**, saisissez votre identifiant.
2. Dans **Mot de passe**, saisissez votre mot de passe.
3. Cochez **Rester connecté sur cet appareil** seulement sur votre appareil personnel, jamais sur un ordinateur partagé (cybercafé, secrétariat).
4. Cliquez sur **Se connecter**.
5. Avec un mot de passe provisoire, l'écran de changement s'ouvre d'abord (1.2). Si l'institut impose la double authentification pour votre rôle, l'écran **Double authentification** s'affiche ensuite (voir 1.4).

Après plusieurs mots de passe faux, la connexion est bloquée un moment : « Nouvel essai possible dans … ». Attendez, ou utilisez **Mot de passe oublié ?**.

## 1.4 La double authentification (2FA)

### Pourquoi ?

La double authentification protège les notes et l'argent. En plus du mot de passe, vous saisissez un code à 6 chiffres qui change toutes les 30 secondes, affiché par une application de votre téléphone. Un voleur de mot de passe ne peut donc pas entrer sans votre téléphone.

### Qui doit l'activer ?

Pour le personnel des établissements, elle n'est **plus obligatoire par défaut** : l'institut peut l'exiger (réglage d'exploitation). Elle reste **toujours obligatoire** pour l'administrateur de la plateforme (connexion par le code de l'application). Quand elle est exigée, elle concerne les rôles dits sensibles : Directeur général (R02), Directeur académique (R03), Directeur d'école (R04), Enseignant permanent (R09), Caissier (R14), Comptable (R15), ainsi que les administrateurs. L'écran vous l'indique : « La double authentification est obligatoire pour votre rôle : … ».

Même pour un autre rôle, certaines actions demandent un code récent (voir 1.4.4).

### 1.4.1 Activer la double authentification avec le code QR (une seule fois)

L'écran **Activer la double authentification** s'ouvre automatiquement à la première connexion d'un rôle sensible.

1. **Installez une application d'authentification** sur votre téléphone (Google Authenticator ou Microsoft Authenticator, depuis Play Store ou App Store).
2. Dans l'application du téléphone, appuyez sur « + » puis « Scanner un code QR ». **Scannez le code QR** affiché à l'écran de SCHOLARIS.
   - Si vous ne pouvez pas scanner (vous êtes sur le téléphone lui-même), utilisez **Ouvrir dans l'application**, ou recopiez la **clé** affichée sous le code QR.
3. L'application du téléphone affiche maintenant une ligne « SCHOLARIS » avec un code à 6 chiffres. **Saisissez ce code** dans la case **Code à 6 chiffres**, puis cliquez sur **Activer**.
4. Le message « La double authentification est activée. » s'affiche, suivi de vos **Codes de secours**.
5. **Conservez les codes de secours** : recopiez-les sur papier ou cliquez sur **Copier les codes**, et rangez-les en lieu sûr (pas dans le téléphone lui-même). Ils ne s'afficheront plus. Cochez **J'ai conservé mes codes de secours**, puis **Continuer**.

### 1.4.2 Se connecter avec la double authentification

Après le mot de passe, l'écran **Double authentification** demande le **Code de votre application d'authentification** :

1. Ouvrez l'application du téléphone et lisez le code de la ligne SCHOLARIS.
2. Saisissez-le et cliquez sur **Valider**.
3. Sur votre appareil personnel, vous pouvez cocher **Faire confiance à cet appareil pendant 30 jours** : le code ne vous sera plus demandé à chaque connexion sur cet appareil (il reste demandé pour les actions sensibles).

Autres choix (lien **Autre méthode**) :
- **Utiliser un code de secours** : saisissez un de vos codes de secours. Chaque code ne sert qu'une fois.
- **Recevoir un code par SMS** : seulement si l'envoi de SMS est actif pour votre institut.

| Problème | Solution |
|---|---|
| « Code incorrect. Il vous reste N essai(s). » | Le code a peut-être changé pendant la saisie : attendez le code suivant. Si l'erreur continue : « Vérifiez l'heure de votre téléphone. » Mettez l'heure du téléphone en mode automatique. |
| « Nouvel appareil détecté » | Normal si vous vous connectez depuis un nouveau téléphone ou ordinateur. Une alerte vous est envoyée. Si ce n'est pas vous, voir 1.8. |

### 1.4.3 J'ai perdu ou changé de téléphone

1. Connectez-vous avec un **code de secours** (lien **Utiliser un code de secours**).
2. Prévenez **tout de suite** l'administrateur de votre institut : il peut faire réinitialiser votre accès. Vous referez alors l'activation de la double authentification (1.4.1) avec le nouveau téléphone.
3. Si vous n'avez plus de code de secours, vous ne pouvez pas entrer seul : contactez l'administrateur de l'institut, qui s'adressera à l'équipe SCHOLARIS si nécessaire.
4. Si le téléphone a été **volé**, faites aussi « Déconnecter partout » (1.8) dès que vous avez accès à un autre appareil.

### 1.4.4 « Confirmation récente exigée »

Si vous n'avez **pas** de double authentification, la confirmation se fait par votre **mot de passe** : l'écran **Confirmez avec votre mot de passe** s'ouvre (bouton **Confirmer avec mon mot de passe**). Elle vaut 10 minutes, et votre connexion par mot de passe compte déjà pendant 10 minutes. Après 5 erreurs, le compte est bloqué 15 minutes. Si vous avez la double authentification, c'est votre code qui est demandé, comme ci-dessous.

Certaines actions demandent de ressaisir votre code, même si vous êtes déjà connecté : verrouiller des notes, clôturer une séance de jury, signer un procès-verbal, publier des résultats, rouvrir une délibération, modifier un règlement figé, exporter la liste des candidats aux examens nationaux, appliquer une reprise de données.

Le message indique par exemple « Cette action exige une double authentification de moins de 15 minutes ». Cliquez sur le bouton proposé (**Saisir mon code**, **Confirmer mon code**, **Ressaisir mon code** ou **Valider la double authentification**), saisissez le code, puis **recommencez l'action** : vous revenez sur le même écran.

## 1.5 Choisir son établissement, son rôle et son école

Après la connexion, la page **Accueil** affiche **Mes établissements**.

1. Cliquez sur **Ouvrir** à côté de votre institut. Vous pourrez en changer à tout moment depuis la barre du haut (**Établissement**), sans vous reconnecter.
2. Si vous avez plusieurs rôles (par exemple enseignant et chef de département), choisissez le rôle actif dans la barre du haut (**Rôle**). Les menus changent selon le rôle choisi.
3. Ouvrez **IPES** dans le menu principal. L'accueil de l'application affiche une carte par rubrique autorisée, avec un bouton **Ouvrir**.
4. Sur la plupart des écrans IPES, en haut à droite, le sélecteur **École** permet de choisir une école de l'institut ou **Toutes les écoles**. Les listes ne montrent que l'école choisie. Ce sélecteur n'apparaît que si vous avez accès à plusieurs écoles.

La page d'accueil générale contient aussi **À traiter** : ce qui vous attend (par exemple des notes à saisir), avec un bouton **Traiter**.

| Message | Signification |
|---|---|
| « Aucun établissement ne vous est rattaché. Contactez l'administration pour obtenir un rôle. » | Votre compte existe mais n'a aucun rôle. L'administrateur doit vous en attribuer un (chapitre 11). |
| « Vous n'avez aucun rôle dans l'application IPES pour cet établissement. Demandez un rôle à l'administrateur fonctionnel. » | Vous avez un rôle ailleurs dans SCHOLARIS, mais pas dans l'IPES. |
| « L'application IPES n'est pas ouverte » | L'abonnement de l'institut n'est ni en essai ni actif. Voir chapitre 11 (abonnement). |
| Bandeau « Abonnement suspendu : lecture et export seulement » | Vous pouvez consulter et exporter, pas modifier, tant que l'abonnement n'est pas réglé. |

## 1.6 Les menus de l'application IPES

Selon votre rôle, l'accueil **IPES** propose tout ou partie de ces cartes :

| Carte | Contenu | Chapitre |
|---|---|---|
| **Paramétrage académique**, **Autorisations MINESUP**, **Règlement des études** | organisation de l'institut | 02 |
| **Maquettes** | maquettes pédagogiques | 02 |
| **Admissions**, **Inscriptions** | campagnes, candidatures, inscriptions | 03 |
| **Frais et paiements** | grilles, comptes de scolarité, tiers payeurs | 04 |
| **Documents officiels** | émission, registre, profil documentaire | 07 |
| **Enseignement**, **Présences et discipline**, **Délégués de groupe**, **Emploi du temps imprimable** | enseignement et assiduité | 05 |
| **Notes** | évaluations et notes | 06 |
| **Délibérations**, **Mes jurys** | jurys, PV, relevés | 07 |
| **Conventions de stage**, **Soutenances**, **Vacations**, **Examens nationaux**, **Tutelle** | fin d'études | 08 |
| **Tableaux de bord** | pilotage | 09 |
| **Mon espace étudiant**, **Mes notes**, **Mes résultats**, **Mon stage**, **Espace garant**, **Mes vacations** | portails personnels | 10 |
| **Aide** | fiches rapides | 00 |

D'autres menus généraux de SCHOLARIS servent aussi à l'IPES : **Appel** (faire l'appel), **Frais et paiements** (ouvrir la caisse), **Documents**, **Messages et annonces**, **Personnel**, **Administration**, **Mes applications**, **Sécurité**, **File d'envoi**.

## 1.7 Mot de passe oublié

**Où** : lien **Mot de passe oublié ?** sur la page de connexion, ou `https://scholariserp.trugroup.cm/mot-de-passe-oublie`.

1. Saisissez votre numéro ou votre adresse e-mail, puis **Recevoir le code**.
2. Saisissez le code à 6 chiffres reçu par e-mail ou SMS, puis **Vérifier le code**.
3. Choisissez un **Nouveau mot de passe** (10 caractères au moins), confirmez, puis **Enregistrer le mot de passe**.

Pour **changer** un mot de passe que vous connaissez encore : menu **Sécurité** > **Changer mon mot de passe** (ancien puis nouveau mot de passe).

Ne demandez jamais à un collègue de vous « prêter » son compte en attendant.

## 1.8 Sécurité de votre compte

**Où** : menu **Sécurité** (`/securite`).

- **Appareils connectés** : la liste des appareils où votre compte est ouvert, avec la dernière activité. Cliquez sur **Révoquer** pour déconnecter un appareil que vous ne reconnaissez pas.
- **Déconnecter partout** : déconnecte tous vos appareils, y compris celui-ci. À faire après un vol de téléphone ou si vous pensez qu'on connaît votre mot de passe. Changez ensuite votre mot de passe (1.7).
- Sur l'écran de double authentification, le lien **Ce n'est pas moi : bloquer et déconnecter tous les appareils** bloque immédiatement votre compte si quelqu'un d'autre essaie d'entrer. Pour le rouvrir, réinitialisez votre mot de passe (1.7).

Bonnes pratiques :
1. Un mot de passe long, connu de vous seul, différent de celui de votre messagerie.
2. Ne communiquez jamais un code reçu par SMS ou e-mail, même à quelqu'un qui dit appeler de la part de SCHOLARIS ou de l'institut. L'équipe ne vous le demandera jamais.
3. Cliquez sur **Se déconnecter** (barre du haut) en quittant un ordinateur partagé.
4. Signalez à l'administrateur toute activité anormale.

## 1.9 Travailler sans réseau

L'**appel** et la **saisie des notes** fonctionnent même sans connexion. Le bandeau « Vous êtes hors ligne. » s'affiche ; vos saisies sont gardées sur l'appareil et partent dès le retour du réseau.

Le menu **File d'envoi** (`/synchronisation`) montre ce qui attend d'être envoyé : **En attente**, **Conflit** ou **Refusé**. Bouton **Envoyer maintenant** pour forcer l'envoi. En cas de **Conflit** (quelqu'un a modifié la même note entre-temps), l'application ne choisit jamais à votre place : comparez, puis **Garder la version du serveur** ou **Renvoyer ma version**. Détails au chapitre 06.

Ne videz pas les données du navigateur et ne fermez pas l'onglet tant que la file d'envoi n'est pas vide.

## 1.10 Langue

La barre du haut propose **Langue** : français ou anglais. Les documents officiels sont émis dans la langue de la filière, sauf choix contraire au moment de l'émission.
