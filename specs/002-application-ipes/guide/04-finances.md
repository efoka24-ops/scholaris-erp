# 04. Frais et paiements

Ce chapitre explique les grilles de frais, le compte de scolarité de chaque étudiant, la caisse, les bourses, les échéanciers, les tiers payeurs, les reçus, l'attestation de paiement, le quitus et le paiement par téléphone.

**Qui fait quoi**

| Tâche | Qui |
|---|---|
| Grilles de frais, ouverture des comptes, bourses, échéanciers négociés, tiers payeurs, attestation, quitus | Comptable (R15) |
| Encaisser (espèces, mobile money, virement) et remettre le reçu | Caissier (R14) ; le Comptable peut encaisser les paiements autres qu'en espèces |
| Payer en ligne par Orange Money ou MTN MoMo | Étudiant (R12), Garant (R13) (chapitre 10) |
| Consulter | Direction (R01, R02, R04), scolarité (R07) |

**Où** : **IPES > Frais et paiements**. La rubrique a trois sections : **Grilles de frais** (`/superieur/frais`), **Comptes de scolarité** (`/superieur/comptes`), **Tiers payeurs** (`/superieur/garants`).

## 4.1 Exemple chiffré (fil rouge)

Inscription 50 000 FCFA + pension 450 000 FCFA en 3 tranches. Bourse de mérite de 20 % sur la pension : pension nette 360 000, soit 3 tranches de 120 000 (échéances 15 octobre, 15 janvier, 15 avril). **Total dû : 410 000 FCFA.**

- 10 octobre : paiement de 170 000 par Orange Money → inscription 50 000 + tranche 1 120 000 soldées.
- 16 janvier : tranche 2 en retard, relance automatique.
- 20 janvier : 100 000 par MTN MoMo → affectés à la tranche 2 ; il reste 20 000 sur la tranche 2.
- 2 février : 20 000 en espèces au guichet → à jour ; reste la tranche 3 (120 000).

## 4.2 Grilles de frais

**Qui** : Comptable (R15). **Où** : **Frais et paiements > Grilles de frais**.

Une grille fixe les frais par **école, filière, niveau et mode**. « Pension en trois tranches par défaut (15 octobre, 15 janvier, 15 avril). »

1. Formulaire **Nouvelle grille** : **École**, filière (ou **Toutes les filières**), niveau (ou **Tous les niveaux**), **Mode** (ou **Tous les modes**), année, **Intitulé de la grille**.
2. Section **Frais** : **Ajouter un frais** pour chaque ligne, choisissez le **Type de frais** (dossier, inscription, pension, examen national, soutenance, stage, carte, assurance…) et le **Montant (FCFA)**. **Retirer** supprime une ligne non enregistrée.
3. Section **Échéancier de la pension** : gardez l'**Échéancier par défaut en 3 tranches**, ou **Ajouter une tranche** avec sa date et son **Poids** (part de la pension).
4. Cliquez sur **Créer**.

Règles :
- Une seule grille **Active** par école, filière, niveau et mode.
- **Archiver** une grille : « Archiver cette grille ? Les comptes déjà ouverts gardent leur facture. »
- Message « Saisissez un montant entier positif en FCFA, par exemple 150 000. » : le montant doit être un nombre entier, sans virgule.

## 4.3 Comptes de scolarité

**Où** : **Frais et paiements > Comptes de scolarité**. La liste affiche pour chaque étudiant le **Net dû**, le **Payé**, le **Solde**, et **À jour** ou **En retard**. Le **Payeur** est l'étudiant ou un tiers payeur.

### Ouvrir un compte

**Qui** : Comptable (R15). À faire dès l'inscription, pour que l'étudiant puisse payer ses frais d'inscription.

1. Formulaire **Ouvrir un compte** : recherchez l'**Étudiant**, choisissez l'année, l'**École**, la **Filière**, le **Niveau** et le **Mode**.
2. **Tiers payeur (facultatif)** : laissez « Aucun : facture au nom de l'étudiant » ou choisissez l'entreprise ou l'organisme qui paie (4.7).
3. **Échéancier négocié validé (identifiant, facultatif)** : seulement si un échéancier individuel a été validé (4.6).
4. Cliquez sur **Ouvrir un compte**. La facture est calculée à partir de la grille active.

### Lire un compte

Cliquez sur un compte pour ouvrir l'écran **Compte de scolarité** :

- en-tête : **Payeur**, **Remise**, **Net dû**, **Payé**, **Solde**, état **À jour** ou **En retard** ; « Prochaine échéance : Tranche 2, 120 000 FCFA le 15/01/2027 » ; « Retard : … » ;
- **Échéancier** : chaque tranche avec son état **Payée**, **Partielle**, **À venir** ou **En retard**, et le **Reste** ;
- **Détail de la facture** : les lignes de frais et la remise ;
- **Reçus** : les reçus émis (ou **En attente** pour un paiement mobile non confirmé) ;
- **Blocages liés aux impayés** (4.9) ;
- **Bourses et remises**, **Attestation de paiement et quitus**.

Le lien **Dossier étudiant** ouvre le dossier de l'étudiant (chapitre 03).

## 4.4 La caisse et l'encaissement

### Ouvrir la caisse (espèces)

**Qui** : Caissier (R14). **Où** : menu général **Frais et paiements** (écran de facturation de SCHOLARIS, `/facturation`).

1. Cliquez sur **Ouvrir la caisse** et saisissez le **Fonds de caisse**.
2. Le bandeau « Caisse n°… ouverte » s'affiche.
3. En fin de journée : **Arrêter la caisse**, saisissez les **Espèces comptées (FCFA)**, puis confirmez. Le même écran montre **Encaissé aujourd'hui** et les **Reçus émis**.

Sans caisse ouverte, un paiement en espèces est refusé : « Les espèces exigent une caisse ouverte : ouvrez la caisse dans « Facturation » avant d'encaisser. »

### Encaisser un paiement

**Qui** : Caissier (R14) ; Comptable (R15) pour les paiements autres qu'en espèces. **Où** : **IPES > Frais et paiements > Comptes de scolarité**, puis le compte de l'étudiant.

1. Recherchez l'étudiant (nom ou matricule) et ouvrez son compte.
2. Vérifiez le **Solde** et l'**Échéancier** avec l'étudiant ou le garant.
3. Section **Encaisser un paiement** : choisissez le **Mode de paiement** : **Espèces**, **Orange Money**, **MTN MoMo**, **Virement** ou **Carte**.
4. Saisissez le **Montant (FCFA)** exact.
   - Mobile money : saisissez le **Numéro à débiter** ; le payeur confirme sur son téléphone. Si l'opérateur a déjà confirmé, saisissez la **Référence de l'opérateur (si déjà confirmé)**.
5. Cliquez **une seule fois** sur **Encaisser et émettre le reçu**.
6. Message « Paiement enregistré : reçu n° … » : remettez le **reçu numéroté** (DOC-27) à l'étudiant ou au garant.
   - Pour un paiement mobile non encore confirmé : « Paiement en attente de confirmation de l'opérateur. Le reçu sera émis à la confirmation. » N'encaissez pas une deuxième fois : attendez la confirmation.

**Affectation automatique (RG-FIN-2)** : « frais d'inscription, puis tranches échues de la plus ancienne à la plus récente », puis tranches à venir. Vous ne choisissez pas la tranche.

### Erreur d'encaissement

Vous avez encaissé deux fois, ou le mauvais montant : **ne supprimez rien** (c'est impossible) et prévenez le comptable. L'annulation d'un paiement exige la validation d'un second agent et reste tracée.

## 4.5 Bourses et remises

**Qui** : Comptable (R15), avec **double validation** : un second comptable, autre que le demandeur, valide.

1. Dans le compte de l'étudiant, section **Bourses et remises**, cliquez sur **Demander une bourse ou une remise**.
2. Choisissez la **Nature** : **Bourse de mérite**, **Réduction personnel**, **Réduction fratrie** ou **Partenariat entreprise**.
3. Choisissez le calcul (**Pourcentage** ou **Montant fixe**) et le **Taux ou montant**. « Laissez vide pour le taux par défaut (mérite : 20 %). »
4. Envoyez la demande : état **À valider**.
5. Un **autre** comptable ouvre le compte et clique sur **Valider** ou **Refuser**. Le demandeur voit : « Vous avez fait cette demande : un autre comptable doit la valider. »

Règles : « Sur la pension seulement, dans la limite du plafond » (RG-FIN-1) ; la remise est répartie sur les tranches restantes ; jamais sur les frais d'inscription. Une bourse au-delà du plafond est refusée.

## 4.6 Échéancier négocié

Pour un étudiant qui ne peut pas suivre les 3 tranches par défaut. « Tranches datées et pondérées de la pension ; un comptable autre que le demandeur le valide avant l'ouverture du compte. »

1. **Comptes de scolarité**, formulaire **Demander un échéancier individuel** : **Étudiant**, année, **École**.
2. Pour chaque tranche : **Date** et **Poids** ; **Ajouter une tranche** si besoin. Saisissez le **Motif**.
3. Cliquez sur **Demander un échéancier individuel**. Message : « Échéancier demandé (identifiant …) : un autre comptable doit le valider. » Notez l'identifiant.
4. Un autre comptable utilise **Valider ou refuser un échéancier** : il saisit l'**Identifiant de l'échéancier** puis **Valider** ou **Refuser**.
5. Ouvrez ensuite le compte (4.3) en indiquant cet identifiant.

## 4.7 Tiers payeurs (entreprises, bailleurs, parrains)

**Qui** : Comptable (R15). **Où** : **Frais et paiements > Tiers payeurs**.

« Entreprises, bailleurs et parrains : les comptes rattachés sont facturés à leur nom. »

1. Formulaire **Nouveau tiers payeur** : **Nature** (**Personne** ou **Organisation**), nom, **Référence**, **Contact**, puis **Créer**.
2. Rattachez le tiers au moment d'ouvrir le compte de l'étudiant (4.3).
3. La liste affiche « 3 compte(s) pris en charge · solde dû … ». Le **Relevé** donne la situation du tiers.

Attention : le **tiers payeur** (qui paie la facture) n'est pas forcément le **garant** de l'étudiant (personne rattachée à l'étudiant qui voit sa situation dans l'**Espace garant**, chapitre 10). Le garant est enregistré dans la fiche de la personne par la scolarité.

## 4.8 Attestation de paiement et quitus

**Où** : compte de l'étudiant, section **Attestation de paiement et quitus**.

- **Émettre l'attestation de paiement (DOC-29)** : récapitule les sommes payées. Jamais bloquée pour impayé.
- **Émettre le quitus financier (DOC-30)** : atteste que l'étudiant ne doit plus rien. Il est exigé pour la soutenance, l'attestation de réussite et le transfert. **Refusé si le solde n'est pas nul** : « Quitus refusé : le solde n'est pas nul (… restant dus, RG-FIN-6). Faites régler le solde, puis émettez le quitus. »

Chaque document porte un numéro unique et un QR (**Page de vérification**).

## 4.9 Relances et blocages liés aux impayés

- **Relances automatiques** chaque matin : 7 jours avant l'échéance, le lendemain et 15 jours après (valeurs réglables par l'administrateur : **Administration > Paramètres**, « Relances de paiement (jours) »). Elles partent par e-mail et dans l'application (les SMS ne sont pas encore actifs partout).
- Un **retard** commence le lendemain de la date limite.
- **Blocages** (réglés par l'administrateur, « Blocages en cas d'impayé ») : au choix **Consultation des notes**, **Délivrance des documents**, **Réinscription**. Le compte affiche **Bloqué**, **Libre** ou **Jamais bloqué**.
- « Aucun blocage n'empêche les cours ni la composition. Le certificat de scolarité, le reçu, le relevé de compte et l'attestation de paiement ne sont jamais bloqués. »

## 4.10 Paiement par l'étudiant ou le garant

L'étudiant et le garant peuvent payer depuis leur espace par **Orange Money** ou **MTN MoMo** (chapitre 10). Le paiement s'affecte selon la même règle et le reçu apparaît dans leur espace après la confirmation de l'opérateur. Le caissier n'a rien à saisir.

## 4.11 Soldes d'ouverture

Les sommes dues par les étudiants au moment du démarrage de SCHOLARIS sont reprises par fichier avec l'équipe SCHOLARIS (chapitre 09). **Un solde repris ne s'annule pas** (une facture émise ne se supprime pas) : le comptable vérifie le fichier ligne par ligne avant l'application.

## 4.12 Messages d'erreur fréquents

| Message | Solution |
|---|---|
| « Les espèces exigent une caisse ouverte… » | Ouvrez la caisse (4.4). |
| « Saisissez un numéro de 8 à 15 chiffres, par exemple 677112233. » | Corrigez le numéro mobile money, sans espaces ni lettres. |
| « Saisissez un montant entier positif en FCFA… » | Montant entier, sans virgule ni point. |
| « Quitus refusé : le solde n'est pas nul… » | Faites régler le solde, puis recommencez. |
| « Vous avez fait cette demande : un autre comptable doit la valider. » | Séparation des tâches : demandez à un collègue comptable. |
| Paiement « En attente » qui dure | Le payeur n'a pas confirmé sur son téléphone, ou l'opérateur tarde. Attendez ; n'encaissez pas une seconde fois. Prévenez le comptable si rien ne change dans la journée. |
| Le bouton **Ouvrir un compte** n'existe pas | Réservé au comptable. |
