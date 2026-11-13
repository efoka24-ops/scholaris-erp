# 08. Conseil de classe et bulletins

Ce chapitre explique comment préparer les règles du conseil, tenir le conseil de classe d'un trimestre, enregistrer les décisions, émettre les bulletins et les consulter.

**Où** : **Secondaire > Conseils de classe** (`/secondaire/conseils-de-classe`) et **Secondaire > Bulletins** (`/secondaire/bulletins`).

**Qui fait quoi**

| Tâche | Qui |
|---|---|
| Régler les types de bulletin (une fois) | Chef d'établissement |
| Préparer et publier les règles, ouvrir le conseil, recalculer, valider, émettre les bulletins | Censeur |
| Présider le conseil, valider les bulletins | Chef d'établissement |
| Appréciation générale et synthèse de la classe | Professeur principal |
| Consulter les bulletins | Direction, surveillant général, professeur principal, parents (portail) |

**Avant le conseil** : toutes les notes des séquences du trimestre doivent être **verrouillées** (chapitre 07).

## 8.1 Régler les types de bulletin

**Qui** : le chef d'établissement, une seule fois.

1. Ouvrez **Secondaire > Conseils de classe**.
2. Dans le bloc **Bulletins**, si le message « Les types de bulletin ne sont pas encore réglés pour cet établissement » s'affiche, cliquez sur **Régler les types de bulletin**.

![Écran du conseil de classe vu par le chef d'établissement](images/08-conseil-chef-accueil.png)

## 8.2 Préparer et publier les règles du niveau

Les règles reprennent les **coefficients** saisis au paramétrage (chapitre 02) et le règlement intérieur (distinctions, conditions de passage).

1. Le censeur choisit l'**Année scolaire**, la **Classe** et la **Période** (**Trimestre 1**).
2. Dans **Règles du niveau**, laissez coché **Pour tout le niveau** (ou **Pour cette classe (série propre)**).
3. Cliquez sur **Préparer le brouillon**. Le tableau montre chaque **Matière**, son **Coef.** et son **Rôle**. Une classe de série à coefficients propres (par exemple la 2nde C) reçoit sa propre version.
4. Relisez, puis cliquez sur **Publier les règles**. Message : « Règles publiées. »

![Brouillon des règles : coefficients de la 2nde A](images/08-conseil-brouillon.png)

Le bandeau jaune « Valeurs d'exemple à confirmer » rappelle que les seuils proposés viennent d'un exemple : remplacez-les par les textes du MINESEC en vigueur et votre règlement intérieur.

## 8.3 Tenir le conseil

1. Cliquez sur **Ouvrir le conseil**.
2. Chaque élève apparaît avec sa **Moyenne**, son rang et des **propositions** en pastilles : travail (par exemple « Encouragements et tableau d'honneur »), passage, absences justifiées et non justifiées, conduite, matières sous le seuil.
3. Dans **Décision**, la proposition est préremplie. Pendant la séance, modifiez-la si le conseil en décide autrement : le **Motif** est alors obligatoire. Cliquez sur **Enregistrer** sur la ligne.
4. Après une correction de note ou d'absence, cliquez sur **Recalculer**.
5. Le lien **Aperçu** montre le bulletin de l'élève avant émission.

![Conseil ouvert : moyennes, propositions et décisions](images/08-conseil-ouvert.png)

## 8.4 Valider et émettre les bulletins

1. Cliquez sur **Valider les décisions**. « La validation exige que toutes les notes de la période soient verrouillées. »
2. Cliquez sur **Émettre les bulletins**. L'émission peut prendre quelques minutes pour une grande classe.
3. Le bloc **Bulletins émis** liste chaque bulletin avec son état : **En attente**, **Émis** ou **Échec**.

![Bulletins émis](images/08-bulletins-emis.png)

Chaque bulletin reçoit un **numéro unique**. Le parent le retrouve sur son portail (chapitre 15).

## 8.5 Publier aux familles

**Qui** : le censeur, le chef d'établissement ou le professeur principal (droit de présider, de préparer le conseil ou de valider les bulletins).

Une fois le conseil validé et au moins un bulletin émis, le bouton **Publier aux familles** apparaît à côté de **Émettre les bulletins**. « Les bulletins émis sont visibles dans l'espace des parents. La publication prévient chaque famille par SMS (moyenne, rang, décision), une seule fois par bulletin : la refaire ne renvoie pas le SMS. »

1. Cliquez sur **Publier aux familles**.
2. Le résumé s'affiche, par exemple : « 2 bulletin(s) publié(s), visibles dans l'espace des parents. 2 famille(s) prévenue(s) par SMS cette fois, sur 2. »
3. Si des familles n'ont pas reçu le SMS, le résumé en donne le motif et le nombre : **sans numéro de téléphone**, **désinscrite(s) des SMS**, **ayant retiré leur consentement aux communications**, **au plafond annuel de SMS**, **en échec d'envoi (nouvel essai à la prochaine publication)**, ou **sans consentement aux communications (à recueillir sur la fiche du parent)** si l'établissement a désactivé la règle du message essentiel.

![Publication aux familles : résumé des familles prévenues](images/08-conseil-publication.png)

**Le SMS « bulletin disponible »** donne au parent le nom de l'enfant, la période, la moyenne (par exemple « 14,50 »), le rang, la décision du conseil et le lien vers l'espace famille. Il part **une seule fois par bulletin et par famille** : republier, après un bulletin émis plus tard, ne prévient que les familles qui ne l'ont pas encore été. Un envoi en échec est retenté à la publication suivante.

C'est un **message de service essentiel** : il part même si le consentement du parent aux communications n'a pas encore été recueilli, mais jamais vers un parent **désinscrit des SMS** ou ayant **retiré son consentement**. La règle est réglable par l'établissement : paramètre `secondary.report_card.sms_essential` (**oui** par défaut), modifiable dans **Administration > Paramètres**. S'il est désactivé, seuls les parents dont le consentement est enregistré reçoivent le SMS.

## 8.6 Consulter un bulletin

Depuis le conseil, cliquez sur **Aperçu** à côté de l'élève : l'écran **Bulletin** affiche les tableaux **Matières**, **Récapitulatif**, **Profil de la classe**, **Discipline** et **Travail**, la **Décision du conseil** et les **Observations**. Choisissez la **Langue** (français ou anglais). Après émission, l'aperçu indique « Instantané enregistré à l'émission le … », avec la date et l'heure écrites en clair dans la langue de l'écran (par exemple « 13/11/2026 08:28 ») : le bulletin émis ne change plus.

L'aperçu est calculé à la demande : l'écran affiche « Calcul du bulletin en cours… » pendant quelques secondes, puis le bulletin. « Aucun bulletin à afficher » signale un élève sans bulletin pour cette période.

![Aperçu du bulletin d'un élève](images/08-bulletin-apercu.png)

Cliquez sur **Retour au conseil** pour revenir.
