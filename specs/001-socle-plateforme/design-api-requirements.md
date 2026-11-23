# Exigences d'API déduites des maquettes (24 écrans, 8 octobre 2026)

Source : maquette HTML « SCHOLARIS ERP » (écrans Login, Otp, TwoFA, TwoFASetup, Main, Dashboard, Fondations…). Le front n'est pas encore construit : ces comportements sont des **exigences de contrat d'API**. Les textes entre guillemets sont ceux de la maquette.

## Connexion (écran Login)
- Identifiant : « Numéro de téléphone ou e-mail » + mot de passe. Un seul champ identifiant ; le serveur reconnaît téléphone (format +237…) ou e-mail.
- Mauvais mot de passe : message clair avec le nombre d'essais restants : « Mot de passe incorrect. Il vous reste 2 essais avant un verrouillage de 15 minutes. » → la réponse d'erreur expose `attempts_left` et, après 5 échecs, `locked_until` (verrouillage 15 minutes, HTTP 423 ou 429 avec `Retry-After`).
- « Rester connecté sur cet appareil » : option `remember` qui allonge la durée de vie du jeton.
- « Mot de passe oublié ? » : démarrage d'une réinitialisation par code (voir OTP).
- « Première connexion ? Votre établissement vous a inscrit avec votre numéro. » : le compte existe déjà (créé par l'établissement) et s'active par SMS.
- Langue FR/EN au choix.

## Activation du compte par code (écran Otp, 3 étapes)
1. Demande d'activation (identifiant).
2. « Entrez le code reçu par SMS » : **6 chiffres, valable 5 minutes (affiche le temps restant), 3 essais maximum**. Numéro masqué dans la réponse (« +237 6•• •• 34 56 »). « Renvoyer le SMS » possible après 60 secondes (compte à rebours affiché) ; alternative « Par e-mail » si une adresse est enregistrée.
3. « Nouveau mot de passe » : 10 caractères minimum, vérifié contre les mots de passe compromis (liste locale fournie en fichier, sans appel réseau).
- La réponse d'envoi expose `expires_in` (secondes) et `resend_in` (secondes).

## Double authentification (écran TwoFA, mobile)
- Rôles sensibles (direction, finances, notes, administration) : après le mot de passe, un code TOTP à 6 chiffres. « Code incorrect · 2 essais restants. »
- « Nouvel appareil détecté » (nom de l'appareil, lieu, heure) : une alerte est envoyée par e-mail et par SMS ; la réponse de connexion indique `new_device: true`.
- « Faire confiance à cet appareil 30 jours » : option `trust_device` qui évite la 2FA pendant 30 jours sur cet appareil.
- Autres méthodes : « Recevoir un code par SMS » ; « Utiliser un code de secours ».
- « Ce n'est pas moi : bloquer et déconnecter tous les appareils » : action qui verrouille le compte et révoque tous les jetons.

## Activation de la 2FA (écran TwoFASetup)
- Bandeau : « La double authentification est obligatoire pour votre rôle » (nom du rôle et de l'établissement).
- Étapes : installer une application TOTP ; scanner le code QR (`otpauth://`) ou saisir la clé affichée par groupes de 4 lettres (« JBSW Y3DP EHPK 3PXP ») ; saisir le code à 6 chiffres pour confirmer ; alternative SMS.
- **Codes de secours** : 10 codes à usage unique de 8 caractères, affichés une seule fois après activation, stockés hachés.
- **Appareils connectés** : liste (appareil, lieu, dernière activité, « Celui-ci »), « Révoquer » un appareil, « Déconnecter partout ».

## Lanceur « Mes établissements » (écran Main) et en-tête
- Après connexion, `GET /me/contexts` renvoie les tuiles : pour chaque établissement accessible : id, nom, type (nursery, primary, secondary, higher → étiquette « Supérieur · IPES », « Secondaire », « Maternelle »), rôles de la personne, **couleur d'accent** et logo, période en cours (« S1 · Semaine 6 », « T1 · Séquence 2 »), et deux indicateurs propres à l'application (renseignés plus tard par les applications ; absents tant qu'aucune application ne les fournit).
- Une tuile **« Tout le groupe »** (vue consolidée) n'apparaît que pour le rôle de direction de groupe.
- Sélecteurs de l'en-tête : établissement (« Tout le groupe » possible), rôle, langue FR/EN, notifications (compteur). `POST /me/context` change d'établissement et de rôle **sans nouvelle connexion**.
- Une personne avec un seul établissement arrive directement dans son application (le client le déduit d'une liste à un élément).
- Salutation : prénom de la personne.

## Identité visuelle (écran Fondations)
- Chaque établissement a une couleur d'accent paramétrable (défaut `#F26B1D`). Le texte sur l'accent est toujours noir : l'API **refuse** une couleur dont le contraste avec du texte noir est inférieur à 4,5:1 (formule WCAG).

## Vocabulaire (écran Fondations)
`GET /vocabulary?type=higher|secondary|primary|nursery` renvoie les termes : enfant/élève/étudiant ; section/classe/filière ; trimestre/séquence/semestre ; carnet de suivi/bulletin APC/bulletin/relevé.

## Ton des messages
Français soutenu, phrases courtes, sans jargon technique ; les messages d'erreur disent quoi faire.
