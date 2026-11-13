# 05. Enseignement et présences

Ce chapitre couvre l'organisation des cours (dossiers enseignants, salles, affectations, emploi du temps), le suivi des heures (cahier de textes, validation mensuelle), l'appel et l'assiduité, la discipline et le planning des examens.

**Où** : deux rubriques de l'application IPES.
- **IPES > Enseignement** : **Enseignants**, **Salles**, **Affectations**, **Emploi du temps**, **Volumes horaires**, **Cahier de textes**, **Validation des heures**, **Planning des examens**. Cartes associées : **Délégués de groupe**, **Emploi du temps imprimable**.
- **IPES > Présences et discipline** : **Assiduité**, **Épreuves de remplacement**, **Discipline**.
- L'appel lui-même se fait dans le menu général **Appel** (5.6).

**Qui fait quoi**

| Tâche | Qui |
|---|---|
| Dossiers enseignants | Responsable RH (R17), Chef de département (R05) |
| Salles, emploi du temps, délégués de groupe | Chef de département (R05), Responsable de filière (R06), Scolarité (R07) |
| Affectations des enseignants | Chef de département (R05) |
| Accord de dépassement d'heures, levée d'une interdiction de composer | Directeur d'école (R04) |
| Appel, cahier de textes | Enseignant (R09, R10) |
| Contre-signature du cahier de textes, validation mensuelle des heures | Chef de département (R05) ; le Service des examens (R08) peut contre-signer |
| Assiduité : alertes et transmission des listes, demandes d'épreuve de remplacement | Scolarité (R07) |
| Planning des examens, convocations, émargement, réception des listes, programmation des remplacements | Service des examens (R08) |
| Sanctions et conseil de discipline | Responsable de la discipline (R11) |

## 5.1 Dossiers des enseignants

**Où** : **Enseignement > Enseignants** (`/superieur/enseignement/enseignants`).

La fiche du personnel de la personne doit exister au préalable (menu **Personnel**, géré par les ressources humaines).

1. Cliquez sur **Nouveau dossier enseignant** et choisissez la personne.
2. **Statut** : **Permanent**, **Vacataire** ou **Invité** ; **Grade** ; **Diplômes** (« Séparez les diplômes par des virgules. ») ; **Établissement d'origine**.
3. Pour un vacataire fonctionnaire : cochez **Fonctionnaire**, puis saisissez l'**Autorisation de l'employeur (référence)** et **Autorisation valable jusqu'au**. Sinon, le dossier affiche « Autorisation de l'employeur à fournir ».
4. Enregistrez. **Modifier le dossier** pour le mettre à jour.

Le grade sert au calcul du taux de vacation (chapitre 08).

## 5.2 Salles

**Où** : **Enseignement > Salles**.

1. **Nouvelle salle** : école (« Choisissez l'école de la salle. »), nom, **Capacité**, puis **Créer**.
2. Une salle en travaux : **Mettre hors service** (puis **Remettre en service**).

La capacité est contrôlée à chaque créneau de l'emploi du temps.

## 5.3 Affectations des enseignants

**Qui** : Chef de département (R05). **Où** : **Enseignement > Affectations**.

« Le chef de département affecte un enseignant à chaque EC, groupe et nature de séance, avec le volume prévu. »

1. **Nouvelle affectation** : choisissez la **Maquette** (une maquette publiée), l'**Élément constitutif (EC)**, le **Groupe** et l'**Enseignant**.
2. **Nature** : **CM**, **TD**, **TP** ou **Autre**.
3. **Volume prévu (heures)** : entre 0,5 et 1 000 heures. Exemple : CM 18 h.
4. Cliquez sur **Créer**. L'affectation est **Active**.

Conséquence importante : **un enseignant ne voit et ne saisit que les EC et groupes qui lui sont affectés**. Sans affectation, il ne peut ni faire l'appel du cours, ni consigner la séance, ni saisir les notes.

- **Mettre fin** : arrête l'affectation (état **Terminée**), par exemple au départ d'un vacataire.
- **Accord de dépassement** (Directeur d'école) : au-delà du volume prévu, les heures ne sont payées qu'après accord écrit (RG-ENS-4). « Le directeur d'école autorise un dépassement du volume prévu, sur décision écrite. » Saisissez les **Heures supplémentaires** et la **Référence de la décision**.

## 5.4 Emploi du temps

**Où** : **Enseignement > Emploi du temps** (`/superieur/enseignement/emploi-du-temps`).

### Consulter

Choisissez **Par groupe**, **Par enseignant** ou **Mon emploi du temps** (pour un enseignant), puis la **Semaine du …** ; **Semaine précédente** / **Semaine suivante** pour naviguer. La zone **Modifications de la semaine** liste les reports et annulations.

### Créer un créneau

**Qui** : R05, R06, R07.

1. **Nouveau créneau** : choisissez l'**Affectation (EC, groupe, enseignant)**.
2. **Jour**, **Début**, **Fin** (format HH:MM, « la fin après le début »), **Salle** (ou **Sans salle**), **À partir du** / **Jusqu'au** (période de validité).
3. Cliquez sur **Vérifier les conflits**. Réponse attendue : « Aucun conflit : 35 étudiants, salle de 40 places. »
4. Enregistrez.

Le panneau **Conflits** liste les conflits de votre périmètre : **Enseignant** (déjà en cours ailleurs), **Salle** (déjà occupée), **Groupe** (déjà en cours), **Capacité** (salle trop petite). Corrigez avant de publier l'emploi du temps aux étudiants.

### Reporter, annuler, remplacer

Sur un créneau : **Report ou annulation**, choisissez la **Modification** (**Report**, **Annulation**, **Remplacement**, **Changement de salle**), la **Séance du** (date concernée) et, selon le cas, la **Nouvelle date**, l'**Enseignant remplaçant** ou la **Nouvelle salle**. Les étudiants du groupe et l'enseignant sont prévenus.

**Supprimer le créneau** retire un créneau saisi par erreur.

### Imprimer

Carte **Emploi du temps imprimable** (`/superieur/enseignement/emploi-du-temps/impression`) : choisissez un groupe ou un enseignant, puis **Imprimer** (mise en page A4 paysage). C'est aussi le document DOC-07.

### Volumes horaires

**Enseignement > Volumes horaires** : « Pour chaque EC et groupe : volume prévu, programmé, tenu (appel) et consigné au cahier de textes. » Colonnes **Prévu**, **Programmé**, **Tenu**, **Consigné**, **Reste**, **Dépassement**. Choisissez une période (**Du** / **Au**).

## 5.5 Délégués de groupe

**Où** : carte **Délégués de groupe** (`/superieur/enseignement/delegues`). **Qui** : R05, R06, R07 désignent.

1. Choisissez le **Groupe** et l'**Étudiant délégué**.
2. Cliquez sur **Désigner** (« Délégué désigné. »). **Retirer** pour changer de délégué.

« Chaque groupe a ses délégués : ils contresignent le cahier de textes des séances de leur groupe. »

## 5.6 Faire l'appel

**Qui** : Enseignant (R09, R10). **Où** : menu général **Appel** (`/appel`), ou lien **Faire l'appel** sur un créneau de l'emploi du temps, ou **Mon espace enseignant**.

1. Ouvrez **Appel** : la liste **Mes créneaux du jour** s'affiche.
2. Cliquez sur **Faire l'appel** pour la séance en cours.
3. Pour chaque étudiant : **Présent**, **Absent** ou **En retard** (avec l'**Heure d'arrivée**). Le bouton **Tous présents** coche tout le monde d'un coup ; ne changez ensuite que les absents et les retards.
4. Cliquez sur **Valider l'appel (35/35)** avant la fin de la séance.
5. Message « L'appel est enregistré et envoyé. » Les absences sont notifiées à l'étudiant (et au garant s'il a le consentement de l'étudiant).

**Sans réseau** : « Chaque appui est enregistré sur l'appareil. L'appel sera synchronisé au retour du réseau. » Le message « L'appel est enregistré sur l'appareil. Il sera synchronisé au retour du réseau. » confirme l'enregistrement. Vérifiez plus tard la **File d'envoi** (chapitre 01).

Messages :
- « Aucun créneau n'est prévu aujourd'hui. » : aucun cours ne vous est affecté aujourd'hui dans l'emploi du temps. Voyez le chef de département.
- « Votre compte n'est rattaché à aucune fiche personne : contactez l'établissement. »

## 5.7 Cahier de textes

**Où** : **Enseignement > Cahier de textes**.

« Séances consignées par l'enseignant, signées puis contre-signées par le délégué ou le chef de département. »

### Pour l'enseignant

1. **Consigner une séance** : choisissez votre affectation, la **Date de la séance**, la **Durée**, le **Contenu de la séance** et le **Travail demandé**.
2. Enregistrez (état **Brouillon**).
3. Cliquez sur **Signer** : « Une séance signée ne se modifie plus. Signer cette séance ? » (état **Signée**).

### Pour le chef de département

Ouvrez les séances signées de votre périmètre et cliquez sur **Contre-signer** (état **Contre-signée**).

**Règle de paiement (RG-ENS-1)** : une heure n'est payable que si la séance a eu lieu, est consignée et contre-signée. Un cahier de textes non tenu retarde le paiement des vacations.

## 5.8 Validation mensuelle des heures

**Qui** : Chef de département (R05). **Où** : **Enseignement > Validation des heures**.

1. Saisissez le **Mois** (format AAAA-MM, par exemple 2026-11).
2. Le tableau affiche, par enseignant, les **Séances**, les **Heures** et les **Heures éq. TD** (HETD).
3. Vérifiez le message éventuel « 2 séance(s) non contre-signée(s) restent à régulariser. » : contre-signez-les d'abord si elles sont justes.
4. Cliquez sur **Valider le mois**. « Seules les séances contre-signées sont validées. »

Les **Validations enregistrées** listent les mois validés (« Validé le … »). Les heures validées alimentent les états de vacation (chapitre 08).

## 5.9 Assiduité

**Où** : **Présences et discipline > Assiduité** (`/superieur/presences/assiduite`).

**Règle (RG-PRE-1)** : au-delà de 30 % d'absences non justifiées sur les séances d'un EC (valeur réglable), l'étudiant est **non autorisé à composer** cet EC (note 0 à l'examen, rattrapage selon le règlement). Une alerte préventive part avant ce seuil (par défaut à 25 %).

1. Choisissez un **Groupe**, un **EC** et une année, puis **Afficher**.
2. Le tableau donne pour chaque étudiant : **Matricule**, **Séances**, **Abs. non justifiées**, **Taux**, **Retards** et l'état : **Assidu**, **Alerte**, **Non autorisé à composer** ou **Interdiction levée**.
3. Cochez **Seulement les non-autorisés** pour filtrer.
4. Scolarité : **Envoyer les alertes** (« 3 étudiant(s) alerté(s), 1 déjà alerté(s). »), puis **Transmettre au service des examens**.
5. Service des examens : dans **Listes transmises**, **Accuser réception** (colonnes « Transmise le », « Reçue le », « Non-autorisés »).
6. Directeur d'école : pour un cas justifié, **Lever l'interdiction** : « Dérogation du directeur d'école, tracée avec son motif. » Saisissez le motif et la **Pièce justificative (référence)**.

La justification d'une absence après coup (certificat médical…) est faite par la scolarité ; une absence justifiée ne compte pas dans le taux.

## 5.10 Épreuves de remplacement

**Où** : **Présences et discipline > Épreuves de remplacement**.

« Après une absence justifiée à un examen (ABJ), l'étudiant compose une épreuve de remplacement. »

1. Scolarité : **Demander une épreuve de remplacement** : **Étudiant**, EC, **Date de l'absence**, **Justificatif (référence)**. État **Demandée**.
2. Service des examens : **Programmer** (date, salle). État **Programmée**.
3. Après l'épreuve : **Tenue** (ou **Annuler**).

## 5.11 Discipline

**Qui** : Responsable de la discipline (R11). **Où** : **Présences et discipline > Discipline**.

« L'exclusion définitive n'est prononcée que par le conseil, après PV signé. »

### Sanctions simples

1. Onglet **Sanctions**, **Prononcer une sanction** : étudiant, **Sanction** (**Avertissement**, **Blâme**, **Exclusion temporaire**), motif, dates pour une exclusion temporaire.
2. **Émettre la notification** : produit la notification de sanction (DOC-31) remise à l'étudiant.

### Conseil de discipline

1. Onglet **Conseils de discipline**, **Convoquer un conseil** : étudiant, **Date de la séance**, **Incident (référence)**, **Membres** (« Au moins trois membres, séparés par des virgules. »). État **Convoqué**.
2. Après la séance : **Consigner la séance** : **Procès-verbal**, **Décision** (de **Aucune sanction** à **Exclusion définitive**), **Motif de la décision**, **Exclusion du** / **Exclusion jusqu'au** si besoin. État **Séance tenue**.
3. **Signer le PV** : « Le PV signé est définitif ; une exclusion définitive clôt l'inscription. Signer ? » État **PV signé**, puis **Effective**.
4. Si le message « Exclusion à reporter sur l'inscription » apparaît, prévenez la scolarité pour mettre à jour le statut de l'inscription (chapitre 03).

Une note **FR** (fraude) saisie par un enseignant est transmise automatiquement au conseil de discipline (chapitre 06).

## 5.12 Planning des examens

**Qui** : Service des examens (R08). **Où** : **Enseignement > Planning des examens**.

1. **Programmer une épreuve** : EC, groupe, **Session** (**Normale**, **Rattrapage**, **Remplacement**), **Date de l'épreuve**, heures, salle, **Surveillants** (« Identifiants des surveillants, séparés par des virgules. »), **Référence du sujet**. État **Planifiée**.
2. **Publier** : l'épreuve devient visible (état **Publiée**).
3. **Émettre les convocations** : « 35 convocation(s) émise(s). » (DOC-13).
4. **Feuille d'émargement** : liste à faire signer en salle (DOC-08).

Les étudiants **non autorisés à composer** (5.9) apparaissent dans les listes transmises par la scolarité. L'anonymat des copies et sa levée sont décrits au chapitre 06.
