# 02. Paramétrage académique

Le paramétrage décrit votre institut dans SCHOLARIS : ses écoles, ses filières, ses autorisations, ses règles d'évaluation et ses maquettes. **Il se fait avant les premières inscriptions**, dans l'ordre de ce chapitre. Une erreur de paramétrage bloque souvent les inscriptions : c'est voulu, pour respecter la réglementation.

**Qui modifie quoi**

| Écran | Modifier | Consulter |
|---|---|---|
| Écoles, départements, filières, niveaux, groupes | Directeur d'école (R04), Administrateur fonctionnel (R20) | Direction, scolarité, comptable, chefs de département… |
| Autorisations MINESUP | R04, R20 | idem |
| Tutelles universitaires | Directeur académique (R03) | idem |
| Règlement des études | R04, R20 (brouillon) ; R03 (modification d'un règlement figé) | idem |
| Calendrier académique | R03, R04, R20 | idem |
| Maquettes | R04, Chef de département (R05), Responsable de filière (R06) saisissent ; R02, R03 publient | enseignants, scolarité |

Avant l'IPES, l'administrateur crée l'**année académique et ses semestres** dans **Administration > Années et périodes** (chapitre 11).

## 2.0 Ordre conseillé

1. Année académique et semestres (Administration).
2. Écoles, puis départements, puis filières, puis niveaux, puis groupes.
3. Autorisations MINESUP.
4. Tutelles (filières LMD seulement).
5. Règlement des études, puis gel.
6. Calendrier académique.
7. Maquettes (saisie puis publication).
8. Grilles de frais (chapitre 04) et profil documentaire de chaque école (chapitre 07).
9. Comptes du personnel et rôles (chapitre 11).

## 2.1 Écoles, départements, filières, niveaux, groupes

**Où** : **IPES > Paramétrage académique** (`/superieur/structure`). L'écran a cinq onglets : **Écoles**, **Départements**, **Filières**, **Niveaux**, **Groupes**.

L'organisation suit cet ordre : Institut > École > Département > Filière > Niveau > Groupe.

### Créer une école

1. Onglet **Écoles**, formulaire **Nouvelle école**.
2. **Code** : court (12 caractères au plus), par exemple `EC` ou `SANTE`. Il figure dans le **matricule** et le **numéro des documents** : choisissez-le avec soin.
3. **Nom** : par exemple « École de commerce ».
4. Cliquez sur **Créer**.

### Créer un département

1. Onglet **Départements**, **Nouveau département**.
2. Choisissez l'**École**, saisissez **Code** et **Nom**, puis **Créer**.

### Créer une filière

1. Onglet **Filières**, **Nouvelle filière**.
2. Choisissez le **Département**, saisissez **Code** et **Nom**.
3. **Régime** : LMD, BTS, HND ou Certificat. Attention : « Choix définitif : le régime ne pourra plus être modifié. » (règle RG-ORG-7). Si vous vous trompez, créez une nouvelle filière.
4. **Mode** : Cours du jour, Cours du soir ou Alternance.
5. **Langue d'enseignement** : Français, Anglais ou Bilingue. Elle fixe la langue par défaut des documents.
6. **Durée (années)** : entre 1 et 8.
7. Cliquez sur **Créer**.

Une filière LMD porte l'étiquette **Tutelle exigée** : elle ne pourra recevoir d'inscriptions que si une tutelle est enregistrée pour l'année (2.3).

Pour corriger une filière : bouton **Modifier** (code, nom, mode, langue, durée). Le régime reste bloqué : « Le régime ne change plus après la création de la filière (RG-ORG-7). Pour un autre régime, créez une nouvelle filière. »

### Créer un niveau

1. Onglet **Niveaux**, **Nouveau niveau**.
2. Choisissez la **Filière**, puis le **Rang** (1 pour la première année…). « Le rang est compris entre 1 et la durée de la filière. »
3. **Code** (par exemple `L1`, `BTS1`) et **Nom**, puis **Créer**.

### Créer un groupe

1. Onglet **Groupes**, **Nouveau groupe**.
2. Choisissez le **Niveau**, saisissez **Code** (par exemple `G1`), **Nom** et **Capacité** (nombre de places), puis **Créer**.

La capacité sert au contrôle des salles dans l'emploi du temps.

Remarque : si l'équipe SCHOLARIS a installé un gabarit de démarrage, des filières existent déjà avec la mention « [à confirmer] » dans leur nom. Vérifiez-les une à une (régime, durée, niveaux, intitulés) et corrigez-les.

## 2.2 Autorisations MINESUP

**Où** : **IPES > Autorisations MINESUP** (`/superieur/autorisations`).

**Règle essentielle (RG-ACA-1)** : une filière sans autorisation valide pour un niveau et une année ne peut recevoir **ni candidature ni inscription**. Le blocage se lève seulement en enregistrant l'arrêté.

### Enregistrer une autorisation

1. Cliquez sur **Enregistrer une autorisation**.
2. Choisissez la **Filière**.
3. Saisissez le **N° de l'arrêté**, la **Date de l'arrêté** et l'**Autorité** (par exemple MINESUP).
4. **Valide du** / **Valide jusqu'au** : période de validité. Laissez la fin vide si l'arrêté n'a pas de date de fin.
5. **Niveaux couverts** : cochez les niveaux autorisés. « Aucune case cochée : l'autorisation couvre tous les niveaux de la filière. »
6. **Référence de l'arrêté numérisé** (facultatif) : identifiant du document scanné.
7. Cliquez sur **Enregistrer**.

L'état de chaque autorisation s'affiche : **Valide**, **Expirée**, **À venir** ou **Introuvable au registre**.

### Alertes d'expiration

La zone **Alertes d'expiration** liste les arrêtés qui expirent bientôt : **Moins de 90 jours**, **Moins de 30 jours**, **Moins de 7 jours**. Exemple de ligne : « Licence Comptabilité : arrêté 26-0001 expire le 30/06/2027 (dans 25 jours). » La direction reçoit aussi une alerte par e-mail. Lancez le renouvellement dès l'alerte à 90 jours.

### Supprimer une autorisation saisie par erreur

Bouton **Supprimer** : « Supprimer cette autorisation saisie par erreur ? Le blocage des inscriptions revient pour ses niveaux. » N'utilisez pas la suppression pour une autorisation expirée : elle reste en historique.

## 2.3 Tutelles universitaires

**Où** : **IPES > Paramétrage académique**, section **Tutelles universitaires** (`/superieur/tutelles`). **Qui** : Directeur académique (R03).

Une filière LMD est rattachée à une **université de tutelle par année académique** (RG-ORG-8). Sans tutelle pour l'année, pas d'inscription (RG-ACA-2). « Seules les filières LMD exigent une tutelle. »

1. Cliquez sur **Enregistrer une tutelle**.
2. Choisissez la **Filière** et l'**Année académique**.
3. Saisissez l'**Université de tutelle**, la **Référence de la convention**, la **Fin de la convention**, le **Programme approuvé (référence)** et le **Contact (nom, fonction, téléphone)**.
4. Cliquez sur **Enregistrer**.

L'université d'une année ne change plus : pour changer d'université, enregistrez la tutelle de l'année suivante. À l'approche de l'échéance : « Convention à renouveler : N jours restants ».

Le suivi des transmissions à l'université est décrit au chapitre 08.

## 2.4 Règlement des études

**Où** : **IPES > Règlement des études** (`/superieur/reglements`).

Le règlement fixe les paramètres d'évaluation et de passage par école, régime et année : poids du contrôle continu et de l'examen, note éliminatoire, compensation, rattrapage, seuils de passage, plafond des points de jury, grille de lettres, etc. Les valeurs proposées par défaut sont celles du cahier des charges (par exemple 40 % contrôle continu / 60 % examen, aucune UE sous 7) ; remplacez-les par celles de votre tutelle ou de votre institut.

### Créer et figer un règlement

1. **Nouveau règlement** : choisissez l'école, le régime et l'année, puis **Créer**. Le règlement est en **Brouillon**.
2. Dans **Paramètres**, ajustez chaque valeur (un nombre, ou oui/non). Message d'erreur : « Valeur invalide : saisissez un nombre ou oui/non. »
3. Enregistrez.
4. Quand tout est juste, cliquez sur **Figer le règlement** : « Figer ce règlement ? Il ne pourra plus être modifié qu'avec une confirmation, un motif et une double authentification récente. »

Le règlement est de toute façon **figé à l'ouverture des inscriptions** (RG-ACA-3).

### Modifier un règlement figé

**Qui** : Directeur académique (R03) seulement.

1. Cliquez sur **Modifier le règlement figé**.
2. Modifiez les valeurs, saisissez le **Motif**, cochez « Je confirme la modification de ce règlement figé. »
3. Enregistrez. Une nouvelle version est créée et tracée. Si le message **Double authentification à ressaisir** apparaît, cliquez sur **Ressaisir mon code** (double authentification de moins de 10 minutes), revenez sur la page et enregistrez de nouveau.

L'**Historique des modifications** liste chaque version avec sa date et son motif.

## 2.5 Calendrier académique

**Où** : **IPES > Paramétrage académique**, section **Calendrier académique** (`/superieur/calendrier`).

1. Cliquez sur **Ajouter une entrée**.
2. Choisissez la **Nature** : Semestre, Période d'examens, Rattrapage, Délibération, Férié, Période d'inscription ou Autre.
3. Pour un semestre, indiquez le **N° de semestre** (« Indiquez le numéro du semestre. »).
4. Saisissez **Début** et **Fin** (« La date de fin doit suivre la date de début. »), puis **Enregistrer**.

Calendrier type (valeurs 2026-2027, à adapter) : candidatures de mars à septembre ; inscriptions et 1re tranche en septembre-octobre ; semestre 1 d'octobre à janvier ; examens S1 en janvier ; semestre 2 de février à mai ; examens S2 et rattrapage en juin ; délibérations annuelles en juillet ; stages et soutenances de juillet à septembre.

## 2.6 Maquettes pédagogiques

**Où** : **IPES > Maquettes** (`/superieur/maquettes`).

Une maquette décrit, pour une filière, un niveau et une année : les semestres, les **UE** (unités d'enseignement), leurs **EC** (éléments constitutifs), les crédits (LMD) ou coefficients (BTS, HND), et les volumes horaires CM, TD, TP et travail personnel. Les étudiants suivent la version de leur année d'inscription.

### Créer une maquette

**Qui** : R04, R05, R06.

1. Cliquez sur **Nouvelle maquette**.
2. Choisissez la **Filière**, le **Niveau** et l'**Année académique** ; saisissez l'**Intitulé**.
3. En LMD, saisissez la **Référence du programme approuvé par la tutelle** : « Obligatoire en LMD pour publier (RG-MAQ-3). »
4. Cliquez sur **Créer**, puis **Ouvrir l'éditeur**.

### Remplir la maquette dans l'éditeur

1. Pour chaque semestre, cliquez sur **Ajouter une UE** : code, intitulé, **Type d'UE** (Fondamentale, Transversale, Optionnelle, Stage, Projet), **Crédits** ou **Coefficient**.
   - UE optionnelles « N parmi M » : même **Groupe à choix**, même nombre de crédits, et le nombre d'**UE à choisir**.
2. Dans chaque UE, cliquez sur **Ajouter un EC** : code, intitulé, crédits ou coefficient, **CM (h)**, **TD (h)**, **TP (h)**, **Travail personnel (h)**.
3. En BTS ou HND, rattachez chaque matière à son **Épreuve de l'examen national**.
4. Enregistrez régulièrement.

Le panneau **Contrôle de cohérence** se met à jour à chaque modification. Messages courants :

| Message | Que faire |
|---|---|
| « Semestre 1 : 28 crédits au lieu de 30 (RG-MAQ-2). » | En LMD, chaque semestre doit faire 30 crédits. Ajustez les crédits. |
| « UE UE12 sans élément constitutif (EC) : ajoutez au moins un EC. » | Ajoutez un EC à l'UE. |
| « UE UE12 : 6 crédits déclarés, 5 crédits dans ses EC. » | La somme des crédits des EC doit égaler les crédits de l'UE. |
| « EC EC121 : aucun volume horaire (CM, TD ou TP). » | Saisissez au moins un volume. |
| « … groupe à choix … : les options doivent avoir le même nombre de crédits. » | Harmonisez les crédits des UE optionnelles. |

Quand le contrôle affiche « Aucune incohérence : la maquette peut être publiée. », prévenez la personne qui publie.

### Publier une maquette

**Qui** : Directeur général (R02) ou Directeur académique (R03).

1. Ouvrez la maquette, vérifiez le contrôle de cohérence.
2. Cliquez sur **Publier**. Si des incohérences restent : « Publication impossible : corrigez d'abord les incohérences signalées. »

L'état passe de **Brouillon** à **Publiée**. Seules les maquettes publiées servent aux affectations, à l'emploi du temps et aux notes.

### Faire évoluer une maquette publiée

« Maquette publiée : elle n'est plus modifiable. Créez une nouvelle version pour la faire évoluer (RG-MAQ-1). »

- **Nouvelle version** : crée une copie modifiable ; l'ancienne passera à l'état **Remplacée** à la publication de la nouvelle.
- **Dépublier** : possible seulement si aucune note n'a été saisie ; la version précédente redevient en vigueur.
- **Copier vers une autre année** : choisissez l'**Année cible** pour préparer la rentrée suivante.
- **Exporter** : télécharge la maquette (Export de la maquette) pour l'imprimer ou la transmettre.
