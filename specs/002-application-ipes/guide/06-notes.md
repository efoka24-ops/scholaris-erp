# 06. Notes

Ce chapitre explique la saisie des notes (à l'écran, sans réseau ou par fichier), le cycle de validation, les corrections après verrouillage, les requêtes des étudiants, le suivi de la saisie et les statistiques.

**Où** : **IPES > Notes** (`/superieur/notes`), écran **Évaluations et notes**. Sections : **Évaluations**, **Suivi de la saisie**, **Corrections**, **Requêtes**. L'étudiant a sa propre page **Mes notes** (chapitre 10).

**Qui fait quoi**

| Étape | Qui |
|---|---|
| Créer l'évaluation, saisir, importer, soumettre, demander une correction | Enseignant affecté à l'EC et au groupe (R09, R10) |
| Renvoyer à l'enseignant, valider, verrouiller ; traiter les requêtes ; 1re validation des corrections | Chef de département (R05) |
| 2e validation des corrections | Directeur d'école (R04) |
| Publier aux étudiants | Service de la scolarité (R07) |
| Lever l'anonymat des copies | Service des examens (R08) |
| Consulter | Direction (R01 à R04), scolarité, service des examens, responsable de filière |

**Séparation des tâches** : celui qui saisit une note ne la verrouille pas ; celui qui demande une correction ne la valide pas.

## 6.1 Le cycle d'une évaluation

```
Brouillon  →  Soumise  →  Validée  →  Verrouillée  →  Publiée
(enseignant)  (enseignant)  (chef de dépt)  (chef de dépt, 2FA)  (scolarité)
```

- **Brouillon** : l'enseignant saisit et corrige librement.
- **Soumise** : la saisie est terminée ; l'enseignant ne peut plus modifier. Le chef de département peut la **Renvoyer à l'enseignant** avec un motif (retour en brouillon).
- **Validée** : le chef de département a contrôlé.
- **Verrouillée** : les notes sont figées. Elles ne changent plus que par une **demande de correction** (6.6).
- **Publiée** : les étudiants voient leurs notes ; le **délai de requête** (7 jours par défaut) commence.

Les notes d'examen alimentent ensuite les délibérations (chapitre 07). Une délibération ne s'ouvre que si **toutes** les notes des EC concernés sont verrouillées.

## 6.2 Créer une évaluation

**Qui** : l'enseignant affecté. **Où** : **Notes > Évaluations**, formulaire **Nouvelle évaluation**. « Réservé à l'enseignant affecté à l'EC (et au groupe). Le poids par défaut dépend de la nature. »

1. Renseignez la **Maquette (identifiant)**, l'**Élément constitutif (identifiant)** et le **Groupe (facultatif)**.
2. **Nature** : **Contrôle continu**, **Travaux pratiques**, **Examen** ou **Rattrapage**.
3. **Intitulé**, **Date de l'épreuve**, **Saisie attendue le** (date limite de saisie).
4. **Barème** (par défaut 20) et **Poids (%)**. Par défaut : contrôle continu 40 %, examen 60 %. « Barème de 1 à 100, poids de 0 à 100. »
5. Cochez **Copies anonymes** pour un examen à copies anonymes.
6. Cliquez sur **Créer l'évaluation**.

Remarque : cette version demande des **identifiants** pour la maquette et l'EC. Si vous ne les connaissez pas, demandez-les au chef de département ou à la scolarité. Seuls les enseignants (rôle avec droit de saisie) voient ce formulaire.

La liste des évaluations se filtre par **EC (code)**, **Groupe**, **État** et **Nature**. Une évaluation dont la date limite est passée sans soumission porte l'étiquette **En retard**.

## 6.3 Saisir les notes à l'écran

**Où** : **Notes > Évaluations**, bouton **Ouvrir** sur l'évaluation : écran **Saisie des notes**.

1. La grille liste les étudiants (**Étudiant**, **Matricule**, ou **N° d'anonymat** pour des copies anonymes). Le compteur indique « 28 / 35 notes saisies ». La case **Rechercher (nom, matricule, n° d'anonymat)** aide à retrouver une ligne.
2. Dans la colonne **Note / 20**, saisissez chaque note. Touche **Entrée** : ligne suivante.
3. Pour une absence ou une fraude, saisissez le **code** dans la case de la note :
   - **ABJ** : absence justifiée (donne droit à une épreuve de remplacement ou au rattrapage) ;
   - **ABI** : absence injustifiée (compte 0) ;
   - **NA** : non autorisé à composer (assiduité insuffisante, compte 0) ;
   - **FR** : fraude (compte 0 et saisine du conseil de discipline).
4. Cliquez sur **Enregistrer N modification(s)**. Message : « 5 note(s) enregistrée(s), 30 inchangée(s). » Si des FR ont été saisies : « 1 fraude(s) transmise(s) au conseil de discipline. »

**Règle des notes (RG-NOT-1)** : de 0 au barème, **au quart de point** : 13 ; 13,25 ; 13,5 ; 13,75. Une note comme 13,3 est refusée : « Note invalide (0 à 20, pas de 0,25). » Corrigez les cases signalées avant d'enregistrer : « Corrigez les cases signalées avant d'enregistrer. »

Le bandeau « Modifications non enregistrées » rappelle de cliquer sur **Enregistrer** avant de quitter la page.

Le bouton **Historique** d'une ligne montre toute la vie de la note : **Saisie**, **Import**, **Synchronisation**, **Correction**, avec auteur et date.

### Copies anonymes

Pour un examen anonyme, la grille montre les **N° d'anonymat** : « Copies anonymes : les noms resteront masqués jusqu'à la levée de l'anonymat. » Le service des examens clique sur **Lever l'anonymat** (panneau **Cycle de validation**) après le verrouillage.

## 6.4 Saisir sans réseau

La saisie fonctionne sur téléphone ou ordinateur même sans connexion, si vous avez ouvert la grille au moins une fois avec du réseau.

1. Bandeau : « Hors ligne : vos saisies sont enregistrées sur cet appareil et partiront au retour du réseau. » Si la grille vient de la mémoire de l'appareil : « Grille affichée depuis la dernière lecture sur cet appareil. »
2. Saisissez normalement, puis cliquez sur **Enregistrer sur l'appareil (N)**. Message : « 5 note(s) enregistrée(s) sur l'appareil, en attente d'envoi. »
3. Au retour du réseau, l'envoi est automatique. Vous pouvez aussi cliquer sur **Envoyer maintenant**.
4. Chaque note affiche son état : **En attente**, **Envoyé**, **Conflit** ou **Refusé**.

### En cas de conflit

« Conflit : ces notes ont changé sur le serveur pendant votre saisie hors ligne. Rien n'a été écrasé : choisissez. »

Pour chaque ligne, l'écran affiche par exemple : « Étudiant fictif · serveur : 12 (version 3) · votre saisie : 14 ». Choisissez :
- **Garder la valeur du serveur** : votre saisie est abandonnée ;
- **Renvoyer ma valeur** : votre note remplace celle du serveur (si l'évaluation est encore modifiable).

En cas de doute, parlez-en au collègue qui a saisi l'autre valeur avant de choisir.

Une note **Refusé** l'a été par le serveur (par exemple, l'évaluation a été soumise entre-temps, ou la note est invalide). Corrigez la cause ou abandonnez l'envoi depuis la **File d'envoi** (chapitre 01).

### Conflit de version en ligne

Si deux personnes modifient la même grille en même temps : « Conflit de version : rien n'a été enregistré. Ces notes ont été modifiées par quelqu'un d'autre depuis votre lecture. » Vos valeurs restent à l'écran à côté de celles du serveur (« Sur le serveur : … », « Votre saisie : … »). Cliquez sur **Recharger la grille (garder mes saisies en regard)**, comparez, puis enregistrez de nouveau.

## 6.5 Importer un fichier de notes

Panneau **Importer un fichier** dans l'écran de saisie.

1. Cliquez sur **Télécharger le modèle** : un fichier CSV (séparateur « ; ») avec une ligne par étudiant.
2. Remplissez la **dernière colonne** avec la note ou le code d'absence (ABJ, ABI, NA, FR). Ne changez ni l'ordre ni les autres colonnes. Vous pouvez travailler dans Excel et enregistrer en CSV, ou envoyer un classeur XLSX de même disposition.
3. Dans **Fichier CSV ou XLSX**, choisissez votre fichier, puis **Importer**.
4. Résultat : « Import : 35 ligne(s) lue(s), 35 note(s) écrite(s). »

**Tout ou rien** : « Le lot est refusé en entier à la moindre erreur. » La liste **Lignes refusées** indique chaque ligne fautive et la raison. Corrigez le fichier et réimportez. « Fichier illisible : utilisez le modèle CSV ou un classeur XLSX simple. » signifie que le format n'est pas reconnu.

## 6.6 Soumettre, valider, verrouiller, publier

Panneau **Cycle de validation** de l'écran de saisie. Chaque bouton n'apparaît qu'à la bonne personne et au bon moment.

| Bouton | Qui | Effet |
|---|---|---|
| **Soumettre** | Enseignant | Brouillon → Soumise. Faites-le quand toutes les cases sont remplies. |
| **Renvoyer à l'enseignant** | Chef de département | Soumise → Brouillon, avec **Motif du renvoi** obligatoire. |
| **Valider** | Chef de département | Soumise → Validée. |
| **Verrouiller** | Chef de département | Validée → Verrouillée. « Le verrouillage fige les notes ; il exige une double authentification récente. » |
| **Publier aux étudiants** | Scolarité | Verrouillée → Publiée. « La publication rend les notes visibles aux étudiants et ouvre le délai de requête. » |
| **Lever l'anonymat** | Service des examens | Affiche les noms d'une évaluation anonyme verrouillée ou publiée. |

Chaque action demande **Confirmer**. L'**Historique du cycle** garde chaque étape avec son auteur, sa date et son motif.

Messages :
- **Double authentification requise** : « Cette action exige une double authentification de moins de 15 minutes. » Cliquez sur **Saisir mon code**, puis recommencez.
- « Publication refusée : le procès-verbal de délibération doit d'abord être signé par le président du jury (RG-DEL-3). Les notes restent verrouillées. » Pour les notes d'examen soumises à délibération, publiez après la signature du PV (chapitre 07).
- « Saisie fermée : l'évaluation est « Verrouillée ». Une note ne se modifie plus que par une demande de correction motivée. »

## 6.7 Corriger une note verrouillée

Une note verrouillée **n'est jamais écrasée** (RG-NOT-2) : la correction crée une nouvelle valeur liée, avec auteur, motif, pièce et validations.

### Demander une correction (enseignant)

1. Dans la grille, sur la ligne concernée, cliquez sur **Demander une correction**.
2. Saisissez la **Nouvelle note** (ou le **code d'absence**) et le **Motif**.
3. Joignez la **Pièce justificative (PDF, JPG ou PNG, 5 Mo au plus)** : par exemple la copie corrigée scannée.
4. Cliquez sur **Envoyer la demande** (« Demande envoyée. »).

Erreurs : « Joignez la pièce justificative. », « Le fichier est vide ou dépasse 5 Mo. », « Format refusé : PDF, JPG ou PNG seulement. », « Indiquez une note ou un code d'absence. »

### Valider une correction (chef de département puis directeur d'école)

**Où** : **Notes > Corrections** (`/superieur/notes/corrections`). « Demandes motivées sur des notes verrouillées : chef de département puis directeur d'école ; jamais le demandeur. »

1. Chaque demande affiche l'ancienne et la nouvelle note (« 9 → 12 »), le motif, et **Télécharger la pièce**.
2. « Étape suivante : … » indique qui doit agir.
3. Cliquez sur **Approuver**, ou **Refuser** avec le **Motif du refus**.
4. Après les deux approbations, la note corrigée remplace l'ancienne dans les calculs ; l'historique garde les deux.

Le demandeur voit : « Votre demande : vous ne pouvez pas la décider (séparation des tâches). »

Si la délibération du semestre est déjà signée, une correction ne suffit pas : la délibération doit être rouverte par le directeur académique (chapitre 07).

## 6.8 Requêtes des étudiants

**Où** : **Notes > Requêtes** (`/superieur/notes/requetes`). **Qui traite** : Chef de département (R05).

L'étudiant dépose une requête depuis **Mes notes** pendant le délai (7 jours après la publication par défaut). « Une requête acceptée se traduit par une demande de correction. »

1. Lisez le **Message** de l'étudiant.
2. Rédigez la **Réponse**.
3. Cliquez sur **Accepter** ou **Rejeter**. Une requête acceptée doit être suivie d'une demande de correction (6.7) par l'enseignant.

États : **Ouverte**, **Acceptée**, **Rejetée** (« Répondu le … »). Le document DOC-19 retrace la requête et sa décision.

## 6.9 Suivi de la saisie

**Où** : **Notes > Suivi de la saisie** (`/superieur/notes/suivi`).

« Avancement par évaluation ; en retard = date limite dépassée sans soumission. » Compteurs **Évaluations**, **Complètes**, **En retard** ; case **Seulement les retards**.

Des relances automatiques partent chaque matin vers les enseignants en retard (et leur chef de département). Le chef de département relance aussi de vive voix : le paiement du solde des vacations d'un EC dépend de la saisie complète de ses notes (RG-ENS-5).

## 6.10 Statistiques

**Où** : bouton **Statistiques** d'une évaluation, ou **Statistiques de l'EC** (toutes les évaluations d'un EC).

Indicateurs : **Notes**, **Moyenne**, **Écart type**, **Minimum**, **Maximum**, **Réussite (≥ 10)**, **Absences**, **Répartition des notes (sur 20)**, et le détail **Par groupe**.

Utilisez-les avant la validation : une moyenne anormalement basse ou une répartition étrange peut révéler une erreur de barème ou de saisie.

## 6.11 Messages d'erreur fréquents

| Message | Solution |
|---|---|
| « Note invalide (0 à 20, pas de 0,25). » | Saisissez une note au quart de point, ou un code ABJ, ABI, NA, FR. |
| 403 « Vous n'avez pas le droit d'effectuer cette action. » en ouvrant une évaluation | L'EC ou le groupe ne vous est pas affecté. La tentative est journalisée. Voyez votre chef de département. |
| « Aucune évaluation dans ce périmètre. » | Aucune évaluation n'existe pour vos affectations, ou le sélecteur **École** filtre une autre école. |
| « Conflit de version : rien n'a été enregistré » | Rechargez la grille, comparez, réenregistrez (6.4). |
| « Saisie fermée… » | L'évaluation est soumise ou verrouillée. Demandez un renvoi (si soumise) ou une correction (si verrouillée). |
| « Double authentification requise » | Saisissez votre code, puis recommencez. |
