# 03. Admissions et inscriptions

Ce chapitre suit le parcours d'un nouvel étudiant : campagne d'admission, candidature, décision, inscription, matricule, contrat pédagogique, puis réinscription les années suivantes.

**Qui fait quoi**

| Tâche | Qui |
|---|---|
| Créer et gérer les campagnes, saisir les candidatures, vérifier les pièces | Service de la scolarité (R07) |
| Décider de l'admission (admis, sous réserve, liste d'attente, refusé), accepter un dépassement de places | Directeur d'école (R04) |
| Créer et valider l'inscription, gérer le contrat pédagogique et les statuts | Scolarité (R07) ; le Directeur d'école (R04) valide aussi |
| Valider « sous réserve financière », accorder une dérogation, enregistrer une décision de passage | Directeur d'école (R04) |
| Consulter | Direction (R01, R02, R03), chef de département (R05), comptable (R15) |

Rappel : une filière **sans autorisation MINESUP valide** pour le niveau et l'année (ou une filière LMD sans tutelle pour l'année) ne peut recevoir ni candidature ni inscription (chapitre 02).

## 3.1 Campagnes d'admission

**Où** : **IPES > Admissions** (`/superieur/campagnes`), écran **Campagnes d'admission**.

### Créer une campagne

1. Remplissez le formulaire **Nouvelle campagne** : filière, niveau, année, **Intitulé**.
2. **Ouverture** et **Clôture** : dates de dépôt des candidatures.
3. **Places** : nombre de places offertes.
4. **Frais de dossier (FCFA)** : « Non remboursables (RG-ADM-4), sauf annulation de la campagne. »
5. **Mode de sélection** : **Sur dossier**, **Sur concours** ou **Dossier et concours**.
6. Cliquez sur **Créer**. La campagne est en **Brouillon**.

« Pièces exigées et prérequis par défaut selon le niveau ; ajustables tant que la campagne est en brouillon. » Par exemple : baccalauréat ou GCE A Level pour le niveau 1 ; BTS, HND ou licence pour le niveau 3 ou le master ; série, année d'obtention, mention minimale.

### Faire vivre la campagne

- **Ouvrir aux candidatures** : la campagne passe à l'état **Ouverte**.
- **Clore** : plus aucune candidature ; état **Close**.
- **Annuler la campagne** : « Les candidatures sont retirées et les frais de dossier payés deviennent remboursables. » État **Annulée**.

### Fiche de la campagne

Cliquez sur la campagne pour ouvrir sa fiche :

- **Statistiques** : Candidatures, Places prises, Places restantes, Dépassements, Frais encaissés, Taux d'inscription, En ligne, Au guichet.
- **Jury d'admission** : choisissez le **Président** et les **Membres** (**Ajouter ce membre**), puis **Enregistrer le jury**.
- **Concours** (campagnes sur concours) : **Programmer une épreuve** (Date de l'épreuve, Centre, Salle, Capacité de la salle), puis **Convoquer les candidats** (document DOC-02). Message : « N candidat(s) convoqué(s) ». Pour une campagne sur dossier : « Campagne sur dossier : pas d'épreuve de concours. »
- **Classement** : après la saisie des notes des candidats, **Classer les candidatures notées**. « Rangs avec ex æquo ; liste principale dans la limite des places, puis liste d'attente. » Le résultat apparaît en **Liste principale** et **Liste d'attente**.
- **Voir les candidatures** : ouvre la liste filtrée sur cette campagne.

## 3.2 Candidatures

**Où** : **IPES > Admissions > Candidatures** (`/superieur/candidatures`).

La liste montre la **Référence**, le **Candidat**, le **Canal** (En ligne ou Guichet), l'état et les **Frais de dossier**.

États d'une candidature : **Brouillon** → **Déposée** → **Complète** → **Admis**, **Admis sous réserve**, **Liste d'attente** ou **Refusé** → **Inscrit**. Une candidature peut aussi être **Retirée**.

### Saisir une candidature au guichet

**Qui** : scolarité (R07).

1. Formulaire **Candidature au guichet** : choisissez la **Campagne**.
2. Le candidat est-il déjà connu ? Choisissez **Personne déjà connue** (recherche par nom ou téléphone) ou **Nouvelle personne (le compte est créé)** : **Nom**, **Prénoms**, **Téléphone**, **E-mail (facultatif)**.
3. **Nature** : **Première inscription** ou **Transfert** ; **Établissement d'origine** si besoin.
4. **Diplôme** (Baccalauréat, GCE A Level, BTS, HND, Licence, Master 1, Master, Autre), **Série**, **Année d'obtention**, **Mention**.
5. Cliquez sur **Créer**. La candidature est en **Brouillon**.

Remarque : le dépôt d'une candidature **en ligne par le candidat lui-même** n'a pas encore d'écran dédié dans cette version. Les candidatures sont saisies au guichet par la scolarité.

### Compléter le dossier : les pièces

Ouvrez la candidature (fiche **Candidature …**). La section **Pièces du dossier** affiche « 3 / 5 vérifiées ». Certaines pièces sont **Bloquante** ou **Prouve le prérequis** (par exemple le diplôme).

1. Pour chaque pièce : **Déposer** (saisissez la **Référence du document numérisé**), ou **Remplacer**.
2. Après contrôle de l'original : **Vérifier**. Si la pièce n'est pas conforme : **Refuser**, avec le **Motif du refus de la pièce** (le candidat est prévenu).
3. État de chaque pièce : **Manquante**, **Déposée**, **Vérifiée**, **Refusée**.
4. Cliquez sur **Déposer la candidature**. « Le dépôt crée la facture des frais de dossier au nom du candidat. » Un accusé de réception (DOC-01) est produit quand le dossier est complet.

La section **Prérequis de la campagne** rappelle les conditions : « Diplômes acceptés : … », « Mention minimale : … », « Obtenu en … ou après ».

### Encaisser les frais de dossier

Dans la section **Frais de dossier** de la fiche :

1. **Mode de paiement** : **Espèces au guichet**, Orange Money ou MTN MoMo.
2. Pour un paiement mobile, saisissez le **Numéro à débiter** (8 à 15 chiffres, par exemple 677112233) ; le candidat confirme sur son téléphone.
3. Cliquez sur **Enregistrer le paiement**. Pour un paiement mobile, **Relire l'état du paiement** jusqu'à « Payés » et l'affichage du « Reçu n° … ».

États : **Non payés**, **En attente de l'opérateur**, **Payés**, **Sans frais**, **Remboursables (campagne annulée)**.

Les espèces exigent une caisse ouverte : « Les espèces exigent une caisse ouverte : ouvrez la caisse dans « Facturation » avant d'encaisser. » (chapitre 04).

### Note de concours ou de dossier

Saisissez la **Note /20** puis **Enregistrer la note** (« Note entre 0 et 20, au centième près. »). Elle sert au classement.

### Décider de l'admission

**Qui** : Directeur d'école (R04). « Réservée au directeur d'école. Aucune décision sur un dossier incomplet. »

1. Ouvrez la candidature, vérifiez les pièces.
2. Dans **Décision motivée**, choisissez : **Admis**, **Admis sous réserve de pièce (30 jours)**, **Liste d'attente** ou **Refusé**, et rédigez le **Motif**.
3. Si toutes les places sont prises, la case **Dépassement des places** apparaît : « Toutes les places sont prises : un dépassement exige un motif du directeur d'école (RG-ADM-3), tracé. » Remplissez le **Motif du dépassement**.
4. Cliquez sur **Enregistrer la décision**. Le candidat est prévenu ; la lettre de décision (DOC-03) est produite.

Règles :
- **« Admis » est impossible tant que les pièces bloquantes ne sont pas vérifiées** (RG-ADM-1). Vérifiez d'abord le diplôme.
- **« Admis sous réserve »** expire au bout de 30 jours : sans la pièce, la candidature passe automatiquement à « Refusé » et le candidat est prévenu.
- L'historique est visible dans **Décisions précédentes**.

### Retirer une candidature

Bouton **Retirer la candidature** : « Les frais de dossier ne sont pas remboursés (RG-ADM-4). »

## 3.3 Inscrire un étudiant admis

**Où** : **IPES > Inscriptions** (`/superieur/inscriptions`), ou bouton **Créer l'inscription** sur la fiche d'une candidature admise.

1. Onglet **Depuis une candidature admise** : choisissez la **Candidature admise** (« Aucune candidature admise en attente d'inscription. » si la liste est vide).
2. Choisissez le **Groupe** et le **Mode** d'études (cours du jour, du soir, alternance).
3. Cliquez sur **Créer l'inscription**. L'inscription est **En attente** ; le matricule affiche « Attribué à la validation ».
4. Ouvrez l'inscription (fiche **Inscription …**).
5. Vérifiez que les **frais d'inscription sont payés** (chapitre 04 : le comptable ouvre le compte de scolarité, le caissier encaisse).
6. Cliquez sur **Valider l'inscription**. Le **matricule** est attribué, l'état passe à **Inscrit**, l'étudiant et son garant sont prévenus.
7. Dans la même fiche, émettez la **Carte d'étudiant (DOC-05)** et la **Fiche d'inscription (DOC-04)** avec les boutons **Émettre**. « Disponibles après la validation de l'inscription. »

### Le matricule

- Il est **unique, définitif et jamais réattribué** (RG-INS-1), même après un changement de filière ou un abandon.
- Format par défaut : année sur 2 chiffres + code de l'école + code de la filière + numéro d'ordre sur 4 chiffres. Exemple fictif : `26EC-CF-0042`.
- On ne le saisit pas à la main (sauf reprise des matricules d'origine par l'équipe SCHOLARIS, chapitre 09).

### Frais non payés : validation sous réserve financière

**Qui** : Directeur d'école (R04) seulement (RG-INS-2).

« La validation exige les frais d'inscription payés. À défaut, seul le directeur d'école peut valider sous réserve financière, avec un motif. »

1. Sur la fiche de l'inscription **En attente**, cliquez sur **Valider sous réserve financière**.
2. Saisissez le **Motif de la réserve financière** et confirmez. L'état devient **Sous réserve financière** ; le bandeau « Réserve financière : … » s'affiche.
3. Une fois les frais payés, cliquez sur **Lever la réserve (frais payés)**.

### Le contrat pédagogique

La section **Contrat pédagogique** liste les UE que l'étudiant doit suivre (« 60 crédits à suivre ») avec leur origine : **Obligatoire**, **Optionnelle choisie**, **Dette** (UE non acquise d'une année précédente, inscrite d'office) ou **Capitalisée** (UE déjà acquise, reportée).

1. Pour les UE optionnelles : « UE optionnelles : 2 parmi 4 (groupe A) ». Cochez exactement le nombre demandé, puis **Enregistrer les choix**. Sinon : « Groupe A : choisissez exactement 2 UE. »
2. Quand le contrat est complet : **Arrêter le contrat**. Il affiche « Contrat arrêté le … ».

## 3.4 Dossier étudiant

**Où** : bouton **Dossier étudiant** sur la fiche d'une inscription (`/superieur/etudiants/…`).

Le dossier montre le **Parcours** de l'étudiant (un étudiant en double cursus a un seul compte, un seul matricule et deux parcours), son **Garant**, et :

- **Partage avec le garant** : « Le garant voit toujours la situation financière. Les notes et absences ne lui sont montrées qu'avec l'accord de l'étudiant (RG-INS-7). » Cochez « L'étudiant accepte de partager ses notes et absences avec son garant » seulement sur demande de l'étudiant. Le choix est daté (« Choix enregistré le … ») et l'étudiant peut le retirer à tout moment.
- **Dérogation (3e inscription)** : par défaut, deux inscriptions au plus dans le même niveau (RG-INS-5). Au-delà, seul le Directeur d'école peut **Accorder la dérogation**.
- **Décisions de passage** : la décision de fin d'année (Admis, Admis avec dettes, Redouble, Exclu), avec les **UE acquises** et **UE manquantes**. Elle est normalement enregistrée par les délibérations annuelles (chapitre 07) ; **Enregistrer une décision de passage** permet au Directeur d'école de la saisir à la main (par exemple pour un étudiant repris d'un ancien système).

## 3.5 Réinscription

**Où** : **IPES > Inscriptions**, onglet **Réinscription**.

**Règle (RG-INS-3)** : l'inscription en année supérieure exige la décision **Admis** ou **Admis avec dettes** de l'année précédente. Un étudiant qui **redouble** garde ses UE acquises (capitalisées) ; les UE manquantes sont inscrites d'office en dette.

1. Recherchez l'**Étudiant** (nom ou téléphone).
2. Choisissez le **Niveau** (une autre filière est possible), le **Groupe** et le **Mode**.
3. Cliquez sur **Créer l'inscription**, puis validez comme en 3.3 (étapes 4 à 7).

Un étudiant ne peut avoir qu'**une inscription par filière et par année**.

## 3.6 Changer de statut, de groupe ou de filière

Sur la fiche de l'inscription :

### Changer le statut

1. Cliquez sur **Changer le statut**, choisissez le **Nouveau statut** : **Suspendu**, **Abandon**, **Exclu**, **Transféré** ou **Diplômé**.
2. Saisissez le **Motif** (obligatoire, tracé et visible des personnes concernées).
3. Pour un abandon, l'écran affiche le remboursement prévu : « Remboursement : 50 % soit 205 000 FCFA ». **Après la date limite fixée par l'école, aucun remboursement** (RG-INS-6).

L'exclusion définitive pour motif disciplinaire passe par le conseil de discipline (chapitre 05) : elle exige un PV signé.

### Changer de filière, de groupe ou de mode

1. Section **Changer de filière, de groupe ou de mode** : choisissez le **Nouveau niveau (autre filière possible)**, le groupe ou le mode ; laissez **Inchangé** ce qui ne change pas.
2. Confirmez. Après un changement de filière ou de mode : « Frais à recalculer : prévenez la comptabilité pour ajuster le compte de l'étudiant. »

Le **Journal de l'inscription** garde la trace de chaque changement.

## 3.7 Messages d'erreur fréquents

| Message ou situation | Cause | Solution |
|---|---|---|
| Refus mentionnant RG-ACA-1 | La filière n'a pas d'autorisation MINESUP valide pour ce niveau et cette année. | Faire enregistrer l'arrêté (chapitre 02). Le blocage est volontaire. |
| Refus mentionnant RG-ACA-2 | Filière LMD sans tutelle pour l'année. | Le Directeur académique enregistre la tutelle (chapitre 02). |
| « Admis » refusé | Une pièce bloquante (diplôme) n'est pas vérifiée. | **Vérifier** la pièce après contrôle de l'original. |
| Validation de l'inscription refusée | Frais d'inscription non payés. | Faire encaisser, ou validation sous réserve financière par le Directeur d'école. |
| Réinscription refusée | Pas de décision « Admis » ou « Admis avec dettes » l'année précédente, ou limite d'inscriptions dans le niveau atteinte. | Vérifier les décisions de passage ; dérogation du Directeur d'école si besoin. |
| Doublon d'inscription | L'étudiant est déjà inscrit dans cette filière cette année. | Ouvrez l'inscription existante. |
